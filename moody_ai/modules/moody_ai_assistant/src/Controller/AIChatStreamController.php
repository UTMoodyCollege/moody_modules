<?php

namespace Drupal\moody_ai_assistant\Controller;

use Drupal\moody_ai_assistant\Service\AIChatManager;
use Drupal\moody_ai_assistant\Entity\AIChatThread;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Render\AttachmentsResponseProcessorInterface;
use Drupal\moody_ai_base\AiGenerationService;
use Drupal\moody_ai_assistant\Service\LayoutContextCollector;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AIChatStreamController extends ControllerBase {

  /**
   * The chat manager.
   *
   * @var \Drupal\moody_ai_assistant\Service\AIChatManager
   */
  protected $chatManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The CSRF token generator.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected $csrfToken;

  /**
   * The shared per-user request limiter.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * The shared generation service.
   *
   * @var \Drupal\moody_ai_base\AiGenerationService
   */
  protected $generator;

  /**
   * The Layout Builder context collector.
   *
   * @var \Drupal\moody_ai_assistant\Service\LayoutContextCollector
   */
  protected $layoutContextCollector;

  /**
   * The Drupal AJAX attachment processor.
   *
   * @var \Drupal\Core\Render\AttachmentsResponseProcessorInterface
   */
  protected $ajaxAttachmentsProcessor;

  /**
   * Constructs the controller.
   */
  public function __construct(AIChatManager $chat_manager, EntityTypeManagerInterface $entity_type_manager, AccountProxyInterface $current_user, CsrfTokenGenerator $csrf_token, FloodInterface $flood, AiGenerationService $generator, LayoutContextCollector $layout_context_collector, AttachmentsResponseProcessorInterface $ajax_attachments_processor) {
    $this->chatManager = $chat_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->csrfToken = $csrf_token;
    $this->flood = $flood;
    $this->generator = $generator;
    $this->layoutContextCollector = $layout_context_collector;
    $this->ajaxAttachmentsProcessor = $ajax_attachments_processor;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('moody_ai_assistant.chat_manager'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('csrf_token'),
      $container->get('flood'),
      $container->get('moody_ai_base.generator'),
      $container->get('moody_ai_assistant.layout_context_collector'),
      $container->get('ajax_response.attachments_processor')
    );
  }

  /**
   * Streams AI progress events for a submitted message.
   */
  public function stream(Request $request) {
    $token = (string) $request->headers->get('X-CSRF-Token');
    if (!$this->csrfToken->validate($token, 'moody_ai_assistant.chat_stream')) {
      return new Response('Invalid CSRF token.', 403);
    }
    if (!$this->generator->isEnabled()) {
      return new Response($this->generator->offlineMessage(), 503, [
        'Cache-Control' => 'no-store, private',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'X-Content-Type-Options' => 'nosniff',
      ]);
    }

    $entity_type = trim((string) $request->request->get('entity_type'));
    $entity_id = trim((string) $request->request->get('entity_id'));
    $message = trim((string) $request->request->get('message'));
    $resume_thread_id = (int) $request->request->get('resume_thread_id');
    $resume_action_id = trim((string) $request->request->get('resume_action_id'));
    $existing_media_ids = AiGenerationService::normalizeMediaIds($request->request->all('existing_media'));
    $stored_upload_ids = $this->normalizeStoredUploadIds($request->request->all('previous_uploads'));
    $provider = trim((string) $request->request->get('provider')) ?: 'openai';
    $model = trim((string) $request->request->get('model')) ?: $this->generator->defaultModel();
    $runtime_context = [
      'site_host' => $request->getHost(),
      'is_layout_builder_context' => $this->toBoolean($request->request->get('is_layout_builder_context')),
      'prefer_ai_images' => $this->toBoolean($request->request->get('prefer_ai_images')),
      'selected_block_references' => $this->extractSelectedBlockReferences((string) $request->request->get('selected_block_references_json', '[]')),
      'provider' => $provider,
      'model' => $model,
      'existing_media_ids' => $existing_media_ids,
      'stored_upload_ids' => $stored_upload_ids,
      'existing_media_intent' => (string) ($request->request->get('existing_media_intent') ?: 'inspiration'),
    ];
    $uploaded_files = $request->files->get('attachments', []);

    if ($uploaded_files instanceof UploadedFile) {
      $uploaded_files = [$uploaded_files];
    }
    elseif (!is_array($uploaded_files)) {
      $uploaded_files = [];
    }

    $uploaded_files = array_values(array_filter($uploaded_files, function ($file) {
      return $file instanceof UploadedFile;
    }));

    if ($entity_type === '' || $entity_id === '' || ($message === '' && (!$resume_thread_id || $resume_action_id === ''))) {
      return new Response('Missing chat request data.', 400);
    }
    if ($message !== '' && mb_strlen($message) > $this->generator->maxPromptCharacters()) {
      return new Response('The message exceeds the configured limit.', 400);
    }
    if (!isset($this->generator->providerOptions()[$provider]) || !isset($this->generator->modelOptions()[$model])) {
      return new Response('Select an available AI provider and model.', 400);
    }
    if (!in_array($runtime_context['existing_media_intent'], ['inspiration', 'content'], TRUE)) {
      return new Response('The selected Media use is invalid.', 400);
    }
    if (count($uploaded_files) + count($existing_media_ids) + count($stored_upload_ids) > AiGenerationService::MAX_ATTACHMENTS) {
      return new Response('Too many reference files or Media items were provided.', 400);
    }
    $upload_bytes = array_sum(array_map(static fn (UploadedFile $file): int => (int) ($file->getSize() ?? 0), $uploaded_files));
    if ($upload_bytes > AiGenerationService::MAX_TOTAL_ATTACHMENT_BYTES) {
      return new Response('The attachments exceed the combined size limit.', 400);
    }

    try {
      $storage = $this->entityTypeManager->getStorage($entity_type);
      $entity = $storage->load($entity_id);
    }
    catch (\Exception $exception) {
      $entity = NULL;
    }
    if (!$entity instanceof ContentEntityInterface || !$entity->hasField('layout_builder__layout')) {
      return new Response('The target page could not be loaded.', 404);
    }
    if (!$entity->access('update', $this->currentUser)) {
      return new Response('Access denied.', 403);
    }
    if (!$this->currentUser->hasPermission('use moody ai assistant')) {
      return new Response('Access denied.', 403);
    }

    $identifier = $this->currentUser->id() . ':' . $request->getClientIp();
    if (!$this->flood->isAllowed('moody_ai_assistant.generate', $this->generator->hourlyRequestLimit(), 3600, $identifier)) {
      return new Response('The hourly Moody AI request limit has been reached.', 429);
    }
    $this->flood->register('moody_ai_assistant.generate', 3600, $identifier);

    $response = new StreamedResponse(function () use ($entity, $message, $runtime_context, $uploaded_files, $resume_thread_id, $resume_action_id) {
      @set_time_limit(0);
      @ini_set('output_buffering', 'off');
      @ini_set('zlib.output_compression', '0');

      $emit = function ($event, array $payload) use ($entity, $runtime_context) {
        if ($event === 'block' && ($payload['status'] ?? '') === 'complete' && !empty($runtime_context['is_layout_builder_context'])) {
          $payload['layout_commands'] = $this->buildLayoutCommands($entity, $runtime_context);
        }
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload) . "\n\n";
        @ob_flush();
        flush();
      };

      $emit('status', ['message' => 'Thinking...']);

      try {
        if ($resume_thread_id && $resume_action_id !== '') {
          $thread = $this->entityTypeManager->getStorage('moody_ai_chat_thread')->load($resume_thread_id);
          if (!$thread instanceof AIChatThread) {
            throw new \Exception('The saved AI operation could not be loaded.');
          }
          $this->chatManager->resumeDeferredLayoutBuilderRequestStream($entity, $thread, $this->currentUser, $resume_action_id, $emit, $runtime_context);
        }
        else {
          $this->chatManager->processUserMessageStream($entity, $this->currentUser, $message, $emit, $runtime_context, $uploaded_files);
        }
      }
      catch (\Throwable $exception) {
        $emit('error', [
          'message' => $exception->getMessage(),
        ]);
      }
    });

    $response->headers->set('Content-Type', 'text/event-stream');
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
    $response->headers->set('X-Accel-Buffering', 'no');

    return $response;
  }

  /**
   * Builds Drupal AJAX commands for the current Layout Builder draft.
   */
  protected function buildLayoutCommands(ContentEntityInterface $entity, array $runtime_context) {
    $section_storage = $this->layoutContextCollector->getResolvedSectionStorage($entity, $runtime_context);
    if (!$section_storage) {
      return [];
    }

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#layout-builder', [
      '#type' => 'layout_builder',
      '#section_storage' => $section_storage,
    ]));
    $response = $this->ajaxAttachmentsProcessor->processAttachments($response);
    return json_decode((string) $response->getContent(), TRUE) ?: [];
  }

  /**
   * Extracts selected block references from the request payload.
   */
  protected function extractSelectedBlockReferences($raw_value) {
    if ($raw_value === '') {
      return [];
    }

    $decoded = json_decode($raw_value, TRUE);
    if (!is_array($decoded)) {
      return [];
    }

    $references = [];
    foreach ($decoded as $item) {
      if (!is_array($item)) {
        continue;
      }

      $reference_id = trim((string) ($item['reference_id'] ?? $item['plugin_id'] ?? $item['uuid'] ?? ''));
      $label = trim((string) ($item['label'] ?? ''));
      if ($reference_id === '' || $label === '') {
        continue;
      }

      $references[$reference_id] = array_filter([
        'reference_id' => $reference_id,
        'uuid' => trim((string) ($item['uuid'] ?? '')),
        'label' => $label,
        'type_label' => trim((string) ($item['type_label'] ?? '')),
        'plugin_id' => trim((string) ($item['plugin_id'] ?? '')),
        'block_type' => trim((string) ($item['block_type'] ?? '')),
        'selection_mode' => trim((string) ($item['selection_mode'] ?? 'new')),
        'group_label' => trim((string) ($item['group_label'] ?? '')),
        'existing_count' => isset($item['existing_count']) ? (int) $item['existing_count'] : NULL,
        'can_edit' => !empty($item['can_edit']),
      ], function ($value) {
        return $value !== NULL && $value !== '';
      });
    }

    return array_values($references);
  }

  /**
   * Converts a request value into a boolean.
   */
  protected function toBoolean($value) {
    if (is_bool($value)) {
      return $value;
    }

    if (is_numeric($value)) {
      return ((int) $value) === 1;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], TRUE);
  }

  /**
   * Normalizes checkbox values for previously uploaded private files.
   */
  protected function normalizeStoredUploadIds($value) {
    if (!is_array($value)) {
      return [];
    }

    return array_values(array_unique(array_filter(array_map('intval', $value))));
  }

}
