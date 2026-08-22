<?php

declare(strict_types=1);

namespace Drupal\moody_ai_base;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Provides the guarded AI generation API used by Moody feature modules.
 */
final class AiGenerationService {

  public const ALLOWED_ATTACHMENT_EXTENSIONS = 'pdf txt md csv json html xml doc docx rtf odt ppt pptx xls xlsx png jpg jpeg gif webp';

  public const MAX_ATTACHMENTS = 3;

  public const MAX_ATTACHMENT_BYTES = 5242880;

  public const MAX_TOTAL_ATTACHMENT_BYTES = 10485760;

  private const OPENAI_RESPONSES_URL = 'https://api.openai.com/v1/responses';

  private const OPENAI_IMAGES_URL = 'https://api.openai.com/v1/images/generations';

  /**
   * The Moody AI logger channel.
   */
  private LoggerInterface $logger;

  /**
   * Constructs the generation service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly SecretResolver $secretResolver,
    private readonly PromptContext $promptContext,
    private readonly HtmlSanitizer $htmlSanitizer,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->logger = $logger_factory->get('moody_ai');
  }

  /**
   * Returns providers implemented by the shared service.
   */
  public function providerOptions(): array {
    return ['openai' => 'OpenAI'];
  }

  /**
   * Returns the configured server-side model allowlist.
   */
  public function modelOptions(): array {
    $models = $this->configFactory->get('moody_ai_base.settings')->get('openai.models');
    $options = [];
    foreach (is_array($models) ? $models : [] as $model) {
      if (is_array($model) && is_string($model['id'] ?? NULL) && is_string($model['label'] ?? NULL)) {
        $options[$model['id']] = $model['label'];
      }
    }
    return $options;
  }

  /**
   * Returns the configured default model.
   */
  public function defaultModel(): string {
    $config = $this->configFactory->get('moody_ai_base.settings');
    $default = (string) $config->get('openai.default_model');
    $models = $this->modelOptions();
    return isset($models[$default]) ? $default : (string) array_key_first($models);
  }

  /**
   * Returns the maximum prompt length.
   */
  public function maxPromptCharacters(): int {
    $value = (int) $this->configFactory->get('moody_ai_base.settings')->get('max_prompt_characters');
    return max(200, min($value ?: 2000, 10000));
  }

  /**
   * Returns the shared per-user hourly request limit.
   */
  public function hourlyRequestLimit(): int {
    $value = (int) $this->configFactory->get('moody_ai_base.settings')->get('hourly_request_limit');
    return max(1, min($value ?: 20, 100));
  }

  /**
   * Returns TRUE when administrators have enabled Moody AI.
   *
   * A missing value keeps existing installations operational until the new
   * setting is explicitly saved. New installations start disabled.
   */
  public function isEnabled(): bool {
    return $this->configFactory->get('moody_ai_base.settings')->get('enabled') !== FALSE;
  }

  /**
   * Returns the administrator-configured message shown while AI is offline.
   */
  public function offlineMessage(): string {
    $message = trim((string) $this->configFactory->get('moody_ai_base.settings')->get('offline_message'));
    return $message !== ''
      ? $message
      : 'Moody AI is temporarily offline. Please try again later or contact a site administrator.';
  }

  /**
   * Returns TRUE when the configured provider secret is available.
   */
  public function isConfigured(): bool {
    $secret_name = (string) $this->configFactory->get('moody_ai_base.settings')->get('openai.secret_name');
    return $this->secretResolver->get($secret_name) !== NULL;
  }

  /**
   * Returns the shared, non-secret form contract for Moody AI interfaces.
   */
  public function uiSettings(): array {
    return [
      'enabled' => $this->isEnabled(),
      'offlineMessage' => $this->offlineMessage(),
      'providerOptions' => $this->providerOptions(),
      'modelOptions' => $this->modelOptions(),
      'defaultProvider' => 'openai',
      'defaultModel' => $this->defaultModel(),
      'maxPromptCharacters' => $this->maxPromptCharacters(),
      'attachmentAccept' => implode(',', array_map(
        static fn(string $extension): string => '.' . $extension,
        explode(' ', self::ALLOWED_ATTACHMENT_EXTENSIONS),
      )),
      'maxAttachments' => self::MAX_ATTACHMENTS,
      'maxAttachmentBytes' => self::MAX_ATTACHMENT_BYTES,
      'maxTotalAttachmentBytes' => self::MAX_TOTAL_ATTACHMENT_BYTES,
      'privacyNotice' => 'Your prompt and selected references may be sent to the configured AI provider. Do not include student records, personnel data, credentials, unpublished research, or other restricted information.',
    ];
  }

  /**
   * Extracts unique entity IDs from Drupal Media Library form values.
   */
  public static function normalizeMediaIds(mixed $value): array {
    $ids = [];
    $collect_list = static function (mixed $item) use (&$ids): void {
      foreach (explode(',', (string) $item) as $candidate) {
        $candidate = trim($candidate);
        if (ctype_digit($candidate) && (int) $candidate > 0) {
          $ids[(int) $candidate] = (int) $candidate;
        }
      }
    };
    $collect = function (mixed $item, string|int|null $key = NULL) use (&$collect, $collect_list): void {
      if (in_array($key, ['target_id', 'media_selection_id', 'media_library_selection'], TRUE)) {
        $collect_list($item);
        return;
      }
      if (is_array($item)) {
        foreach ($item as $child_key => $child) {
          $collect($child, $child_key);
        }
      }
      elseif ($key === NULL) {
        $collect_list($item);
      }
    };
    $collect($value);
    return array_values($ids);
  }

  /**
   * Generates a validated JSON object for an agent-style feature module.
   *
   * The returned envelope contains `data` and normalized `usage` keys. Feature
   * modules remain responsible for allowlisting and validating every value in
   * `data` before using it in Drupal.
   */
  public function generateStructured(array $messages, ?string $model = NULL): array {
    $this->assertEnabled();

    if ($messages === [] || count($messages) > 16) {
      throw new \InvalidArgumentException('The structured request has an invalid message count.');
    }

    $model = $model ?: $this->defaultModel();
    if (!isset($this->modelOptions()[$model])) {
      throw new \InvalidArgumentException('The selected model is not allowed.');
    }

    $config = $this->configFactory->get('moody_ai_base.settings');
    $secret_name = (string) $config->get('openai.secret_name');
    $api_key = $this->secretResolver->get($secret_name);
    if ($api_key === NULL) {
      throw new \RuntimeException('The AI provider is not configured.');
    }

    $additional_context = mb_substr((string) $config->get('additional_context'), 0, 5000);
    $instructions = [$this->promptContext->assistantInstructions($additional_context)];
    $input = [];
    $text_characters = 0;

    foreach ($messages as $message) {
      if (!is_array($message) || !in_array($message['role'] ?? NULL, ['system', 'user', 'assistant'], TRUE)) {
        throw new \InvalidArgumentException('A structured request message is invalid.');
      }

      if (($message['role'] ?? '') === 'system') {
        if (!is_string($message['content'] ?? NULL)) {
          throw new \InvalidArgumentException('A system message is invalid.');
        }
        $text_characters += mb_strlen($message['content']);
        $instructions[] = $message['content'];
        continue;
      }

      $content = $this->normalizeStructuredContent($message['content'] ?? NULL, $text_characters);
      $input[] = [
        'role' => $message['role'],
        'content' => $content,
      ];
    }

    if ($input === [] || $text_characters > 200000) {
      throw new \InvalidArgumentException('The structured request is empty or too large.');
    }

    try {
      $response = $this->httpClient->request('POST', self::OPENAI_RESPONSES_URL, [
        'connect_timeout' => 5,
        'timeout' => 180,
        'http_errors' => FALSE,
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'model' => $model,
          'instructions' => implode("\n\n", $instructions),
          'input' => $input,
          'max_output_tokens' => max(200, min((int) $config->get('max_output_tokens') ?: 1800, 4000)),
          'text' => [
            'format' => ['type' => 'json_object'],
          ],
          'store' => FALSE,
        ],
      ]);
    }
    catch (GuzzleException $exception) {
      $this->logger->error('OpenAI structured request failed before a response was received.');
      throw new \RuntimeException('The AI provider could not be reached.', 0, $exception);
    }

    $status = $response->getStatusCode();
    if ($status < 200 || $status >= 300) {
      $this->logger->error('OpenAI returned HTTP @status for a structured request (request @request_id).', [
        '@status' => $status,
        '@request_id' => $response->getHeaderLine('x-request-id') ?: 'unknown',
      ]);
      throw new \RuntimeException('The AI provider could not complete this request.');
    }

    $response_data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($response_data)) {
      throw new \RuntimeException('The AI provider returned an invalid response.');
    }

    $output = $this->extractOutputText($response_data);
    $decoded = $this->decodeJsonObject($output);
    if ($decoded === NULL) {
      throw new \RuntimeException('The AI provider returned invalid structured data.');
    }

    $usage = is_array($response_data['usage'] ?? NULL) ? $response_data['usage'] : [];
    return [
      'data' => $decoded,
      'usage' => [
        'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
        'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
        'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
      ],
      'model' => $model,
    ];
  }

  /**
   * Generates one image through the provider owned by the base module.
   */
  public function generateImage(string $prompt): array {
    $this->assertEnabled();

    $prompt = trim($prompt);
    if ($prompt === '' || mb_strlen($prompt) > $this->maxPromptCharacters()) {
      throw new \InvalidArgumentException('The image prompt is empty or exceeds the configured limit.');
    }

    $config = $this->configFactory->get('moody_ai_base.settings');
    $api_key = $this->secretResolver->get((string) $config->get('openai.secret_name'));
    if ($api_key === NULL) {
      throw new \RuntimeException('The AI provider is not configured.');
    }

    try {
      $response = $this->httpClient->request('POST', self::OPENAI_IMAGES_URL, [
        'connect_timeout' => 5,
        'timeout' => 180,
        'http_errors' => FALSE,
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'model' => (string) ($config->get('openai.image_model') ?: 'gpt-image-2'),
          'prompt' => $prompt,
          'size' => '1024x1024',
        ],
      ]);
    }
    catch (GuzzleException $exception) {
      $this->logger->error('OpenAI image request failed before a response was received.');
      throw new \RuntimeException('The AI provider could not be reached.', 0, $exception);
    }

    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      $this->logger->error('OpenAI returned HTTP @status for an image request (request @request_id).', [
        '@status' => $response->getStatusCode(),
        '@request_id' => $response->getHeaderLine('x-request-id') ?: 'unknown',
      ]);
      throw new \RuntimeException('The AI provider could not generate the image.');
    }

    $data = json_decode((string) $response->getBody(), TRUE);
    $binary = isset($data['data'][0]['b64_json']) && is_string($data['data'][0]['b64_json'])
      ? base64_decode($data['data'][0]['b64_json'], TRUE)
      : FALSE;
    if (!is_string($binary) || $binary === '') {
      throw new \RuntimeException('The AI provider returned invalid image data.');
    }

    return [
      'binary' => $binary,
      'extension' => 'png',
      'mime_type' => 'image/png',
    ];
  }

  /**
   * Generates and sanitizes one HTML fragment.
   *
   * @throws \InvalidArgumentException
   *   When the prompt, provider, or model is not allowed.
   * @throws \RuntimeException
   *   When the provider is unavailable or returns no safe HTML.
   */
  public function generateHtml(string $prompt, string $provider, string $model, array $attachments = [], array $media = [], bool $prefer_ai_images = FALSE): string {
    $this->assertEnabled();

    $prompt = trim($prompt);
    if ($prompt === '' || mb_strlen($prompt) > $this->maxPromptCharacters()) {
      throw new \InvalidArgumentException('The prompt is empty or exceeds the configured limit.');
    }
    if ($provider !== 'openai') {
      throw new \InvalidArgumentException('The selected provider is not available.');
    }
    if (!isset($this->modelOptions()[$model])) {
      throw new \InvalidArgumentException('The selected model is not allowed.');
    }
    if (count($attachments) + count($media) > self::MAX_ATTACHMENTS) {
      throw new \InvalidArgumentException('Too many references were provided.');
    }

    $attachment_bytes = 0;
    $allowed_extensions = explode(' ', self::ALLOWED_ATTACHMENT_EXTENSIONS);
    foreach ($attachments as $attachment) {
      $filename = $attachment['filename'] ?? NULL;
      $mime_type = $attachment['mime_type'] ?? NULL;
      $data = $attachment['data'] ?? NULL;
      $attachment_bytes += is_string($data) ? strlen($data) : 0;
      if (!is_string($filename) || preg_match('#[\\\\/]#', $filename) || !in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), $allowed_extensions, TRUE) || !is_string($mime_type) || !preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mime_type) || !is_string($data) || $data === '' || strlen($data) > self::MAX_ATTACHMENT_BYTES || $attachment_bytes > self::MAX_TOTAL_ATTACHMENT_BYTES) {
        throw new \InvalidArgumentException('An attachment is invalid.');
      }
    }

    foreach ($media as $item) {
      if (!is_array($item)) {
        throw new \InvalidArgumentException('A Media reference is invalid.');
      }
      $uuid = $item['uuid'] ?? NULL;
      $label = $item['label'] ?? NULL;
      $bundle = $item['bundle'] ?? NULL;
      $intent = $item['intent'] ?? NULL;
      $filename = $item['filename'] ?? NULL;
      $mime_type = $item['mime_type'] ?? NULL;
      $data = $item['data'] ?? NULL;
      $has_file = $filename !== NULL || $mime_type !== NULL || $data !== NULL;
      $valid = is_string($uuid)
        && Uuid::isValid($uuid)
        && is_string($label)
        && $label !== ''
        && is_string($bundle)
        && $bundle !== ''
        && in_array($intent, ['inspiration', 'content'], TRUE);
      if (!$valid) {
        throw new \InvalidArgumentException('A Media reference is invalid.');
      }
      if ($has_file) {
        $attachment_bytes += is_string($data) ? strlen($data) : 0;
        if (!is_string($filename) || preg_match('#[\\\\/]#', $filename) || !in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), $allowed_extensions, TRUE) || !is_string($mime_type) || !preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mime_type) || !is_string($data) || $data === '' || strlen($data) > self::MAX_ATTACHMENT_BYTES || $attachment_bytes > self::MAX_TOTAL_ATTACHMENT_BYTES) {
          throw new \InvalidArgumentException('A Media reference file is invalid.');
        }
      }
    }

    $html = $this->requestOpenAi($prompt, $model, $attachments, $media, $prefer_ai_images);
    $html = preg_replace('/^\s*```(?:html)?\s*|\s*```\s*$/i', '', $html) ?? $html;
    $html = $this->htmlSanitizer->sanitize($html);
    if ($html === '' || (trim(strip_tags($html)) === '' && !str_contains($html, '<drupal-media '))) {
      throw new \RuntimeException('The provider returned no safe content.');
    }
    return $html;
  }

  /**
   * Requests an HTML fragment from OpenAI's Responses API.
   */
  private function requestOpenAi(string $prompt, string $model, array $attachments, array $media, bool $prefer_ai_images): string {
    $config = $this->configFactory->get('moody_ai_base.settings');
    $secret_name = (string) $config->get('openai.secret_name');
    $api_key = $this->secretResolver->get($secret_name);
    if ($api_key === NULL) {
      throw new \RuntimeException('The AI provider is not configured.');
    }

    $max_tokens = max(200, min((int) $config->get('max_output_tokens') ?: 1800, 4000));
    $additional_context = mb_substr((string) $config->get('additional_context'), 0, 5000);

    $content = [['type' => 'input_text', 'text' => $prompt]];
    foreach ($attachments as $index => $attachment) {
      $content[] = [
        'type' => 'input_text',
        'text' => sprintf(
          'Attachment %d (system metadata): filename "%s"; Drupal Media eligible: %s.',
          $index + 1,
          $attachment['filename'],
          !empty($attachment['media_eligible']) ? 'yes' : 'no',
        ),
      ];
      $data_uri = 'data:' . $attachment['mime_type'] . ';base64,' . base64_encode($attachment['data']);
      $content[] = str_starts_with($attachment['mime_type'], 'image/')
        ? ['type' => 'input_image', 'image_url' => $data_uri, 'detail' => 'auto']
        : [
          'type' => 'input_file',
          'filename' => $attachment['filename'],
          'file_data' => $data_uri,
        ];
    }
    foreach ($media as $index => $item) {
      $content[] = [
        'type' => 'input_text',
        'text' => sprintf(
          'Existing Media %d (system metadata): label "%s"; type "%s"; editor intent: %s.',
          $index + 1,
          $item['label'],
          $item['bundle'],
          $item['intent'] === 'content' ? 'may insert in content' : 'inspiration only; do not insert',
        ),
      ];
      if (isset($item['data'])) {
        $data_uri = 'data:' . $item['mime_type'] . ';base64,' . base64_encode($item['data']);
        $content[] = str_starts_with($item['mime_type'], 'image/')
          ? ['type' => 'input_image', 'image_url' => $data_uri, 'detail' => 'auto']
          : [
            'type' => 'input_file',
            'filename' => $item['filename'],
            'file_data' => $data_uri,
          ];
      }
    }

    try {
      $response = $this->httpClient->request('POST', self::OPENAI_RESPONSES_URL, [
        'connect_timeout' => 5,
        'timeout' => 45,
        'http_errors' => FALSE,
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'model' => $model,
          'instructions' => $this->promptContext->htmlInstructions($additional_context, $prefer_ai_images),
          'input' => [
            [
              'role' => 'user',
              'content' => $content,
            ],
          ],
          'max_output_tokens' => $max_tokens,
          'store' => FALSE,
        ],
      ]);
    }
    catch (GuzzleException $exception) {
      $this->logger->error('OpenAI request failed before a response was received.');
      throw new \RuntimeException('The AI provider could not be reached.', 0, $exception);
    }

    $status = $response->getStatusCode();
    if ($status < 200 || $status >= 300) {
      $this->logger->error('OpenAI returned HTTP @status (request @request_id).', [
        '@status' => $status,
        '@request_id' => $response->getHeaderLine('x-request-id') ?: 'unknown',
      ]);
      throw new \RuntimeException('The AI provider could not complete this request.');
    }

    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data)) {
      throw new \RuntimeException('The AI provider returned an invalid response.');
    }
    if (isset($data['output_text']) && is_string($data['output_text'])) {
      return $data['output_text'];
    }

    $parts = [];
    foreach ($data['output'] ?? [] as $output) {
      if (!is_array($output) || ($output['type'] ?? '') !== 'message') {
        continue;
      }
      foreach ($output['content'] ?? [] as $content) {
        if (is_array($content) && ($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? NULL)) {
          $parts[] = $content['text'];
        }
      }
    }
    return implode("\n", $parts);
  }

  /**
   * Stops every provider request while the global switch is off.
   */
  private function assertEnabled(): void {
    if (!$this->isEnabled()) {
      throw new \RuntimeException($this->offlineMessage());
    }
  }

  /**
   * Converts a feature message into Responses API content parts.
   */
  private function normalizeStructuredContent(mixed $content, int &$text_characters): array {
    if (is_string($content)) {
      $text_characters += mb_strlen($content);
      return [['type' => 'input_text', 'text' => $content]];
    }
    if (!is_array($content) || count($content) > self::MAX_ATTACHMENTS + 4) {
      throw new \InvalidArgumentException('A structured request message is invalid.');
    }

    $parts = [];
    foreach ($content as $part) {
      if (!is_array($part)) {
        throw new \InvalidArgumentException('A structured request content part is invalid.');
      }
      if (($part['type'] ?? '') === 'text' && is_string($part['text'] ?? NULL)) {
        $text_characters += mb_strlen($part['text']);
        $parts[] = ['type' => 'input_text', 'text' => $part['text']];
        continue;
      }
      if (($part['type'] ?? '') === 'image_url' && is_string($part['image_url']['url'] ?? NULL)) {
        $image_url = $part['image_url']['url'];
        if (!preg_match('#^data:image/(?:gif|jpeg|png|webp);base64,[A-Za-z0-9+/=]+$#D', $image_url) || strlen($image_url) > (self::MAX_ATTACHMENT_BYTES * 2)) {
          throw new \InvalidArgumentException('A structured request image is invalid.');
        }
        $parts[] = ['type' => 'input_image', 'image_url' => $image_url, 'detail' => 'auto'];
        continue;
      }
      throw new \InvalidArgumentException('A structured request content part is not supported.');
    }

    if ($parts === []) {
      throw new \InvalidArgumentException('A structured request message is empty.');
    }
    return $parts;
  }

  /**
   * Extracts output text from a Responses API payload.
   */
  private function extractOutputText(array $data): string {
    if (is_string($data['output_text'] ?? NULL)) {
      return $data['output_text'];
    }

    $parts = [];
    foreach ($data['output'] ?? [] as $output) {
      if (!is_array($output) || ($output['type'] ?? '') !== 'message') {
        continue;
      }
      foreach ($output['content'] ?? [] as $content) {
        if (is_array($content) && ($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? NULL)) {
          $parts[] = $content['text'];
        }
      }
    }
    return trim(implode("\n", $parts));
  }

  /**
   * Decodes a JSON object, tolerating a single Markdown fence.
   */
  private function decodeJsonObject(string $content): ?array {
    $content = trim($content);
    if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $content, $matches)) {
      $content = trim($matches[1]);
    }
    $decoded = json_decode($content, TRUE);
    return is_array($decoded) ? $decoded : NULL;
  }

}
