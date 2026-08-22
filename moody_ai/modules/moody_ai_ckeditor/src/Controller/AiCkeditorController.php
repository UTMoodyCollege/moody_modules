<?php

declare(strict_types=1);

namespace Drupal\moody_ai_ckeditor\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\file\Upload\FileUploadHandlerInterface;
use Drupal\file\Upload\FormUploadedFile;
use Drupal\moody_ai_base\AiGenerationService;
use Drupal\moody_ai_base\HtmlSanitizer;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Generates guarded HTML for CKEditor without exposing provider credentials.
 */
final class AiCkeditorController implements ContainerInjectionInterface {

  private const MAX_UPLOADS_PER_HOUR = 60;

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly AiGenerationService $generator,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly FloodInterface $flood,
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileUploadHandlerInterface $fileUploadHandler,
    private readonly FileSystemInterface $fileSystem,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly HtmlSanitizer $htmlSanitizer,
    private readonly Connection $database,
    private readonly ImageFactory $imageFactory,
    private readonly FileRepositoryInterface $fileRepository,
  ) {}

  /**
   * Creates the controller from the service container.
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('moody_ai_base.generator'),
      $container->get('csrf_token'),
      $container->get('flood'),
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get(FileUploadHandlerInterface::class),
      $container->get('file_system'),
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
      $container->get('moody_ai_base.html_sanitizer'),
      $container->get('database'),
      $container->get('image.factory'),
      $container->get('file.repository'),
    );
  }

  /**
   * Stores one temporary attachment in the current user's private directory.
   */
  public function upload(Request $request): JsonResponse {
    if (!$this->validToken($request)) {
      return $this->response(['message' => 'Your session token is no longer valid. Reload the page and try again.'], 403);
    }

    $uid = (int) $this->currentUser->id();
    $upload = $request->files->get('upload');
    if ($uid < 1 || !$upload instanceof UploadedFile || !$upload->isValid()) {
      return $this->response(['message' => 'The attachment could not be uploaded.'], 400);
    }
    $identifier = 'uid-' . $uid;
    if (!$this->flood->isAllowed('moody_ai_ckeditor.upload', self::MAX_UPLOADS_PER_HOUR, 3600, $identifier)) {
      return $this->response(['message' => 'You have reached the hourly attachment upload limit. Try again later.'], 429);
    }

    $destination = sprintf(
      'private://%d/%s/moody-ai-ckeditor-uploads',
      $uid,
      gmdate('Y-m-d', $this->time->getRequestTime()),
    );
    if (!$this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY)) {
      return $this->response(['message' => 'The private attachment directory is not available.'], 500);
    }

    try {
      $result = $this->fileUploadHandler->handleFileUpload(
        new FormUploadedFile($upload),
        [
          'FileExtension' => ['extensions' => AiGenerationService::ALLOWED_ATTACHMENT_EXTENSIONS],
          'FileSizeLimit' => ['fileLimit' => AiGenerationService::MAX_ATTACHMENT_BYTES],
        ],
        $destination,
        FileExists::Rename,
      );
    }
    catch (\Exception) {
      return $this->response(['message' => 'The attachment could not be saved.'], 500);
    }

    if ($result->hasViolations() || !$result->getFile() instanceof FileInterface) {
      return $this->response(['message' => 'Use a supported attachment no larger than 5 MB.'], 422);
    }

    $file = $result->getFile();
    $this->flood->register('moody_ai_ckeditor.upload', 3600, $identifier);
    return $this->response([
      'id' => (int) $file->id(),
      'name' => $file->getFilename(),
      'size' => (int) $file->getSize(),
    ], 201);
  }

  /**
   * Generates one sanitized HTML fragment.
   */
  public function generate(Request $request): JsonResponse {
    if (!$this->validToken($request)) {
      return $this->response(['message' => 'Your session token is no longer valid. Reload the page and try again.'], 403);
    }

    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->response(['message' => 'The generation request was not valid.'], 400);
    }

    try {
      $attachments = $this->loadAttachments($payload['attachments'] ?? []);
    }
    catch (\InvalidArgumentException) {
      return $this->response(['message' => 'A selected reference file is no longer available. Select it again and retry.'], 400);
    }
    try {
      $media = $this->loadMediaReferences($payload['media'] ?? [], TRUE);
    }
    catch (\InvalidArgumentException) {
      return $this->response(['message' => 'A selected Media item is no longer available. Select it again and retry.'], 400);
    }
    try {
      $this->validateReferenceCount($attachments, $media);
    }
    catch (\InvalidArgumentException) {
      return $this->response(['message' => 'Select no more than three total reference files and Media items.'], 400);
    }

    $limit = (int) $this->configFactory->get('moody_ai_base.settings')->get('hourly_request_limit');
    $limit = max(1, min($limit ?: 20, 100));
    $identifier = 'uid-' . $this->currentUser->id();
    if (!$this->flood->isAllowed('moody_ai_ckeditor.generate', $limit, 3600, $identifier)) {
      return $this->response(['message' => 'You have reached the hourly AI generation limit. Try again later.'], 429);
    }
    $this->flood->register('moody_ai_ckeditor.generate', 3600, $identifier);

    try {
      $html = $this->generator->generateHtml(
        (string) ($payload['prompt'] ?? ''),
        (string) ($payload['provider'] ?? ''),
        (string) ($payload['model'] ?? ''),
        $attachments,
        $media,
        !empty($payload['preferAiImages']),
      );
      return $this->response(['html' => $html]);
    }
    catch (\InvalidArgumentException) {
      return $this->response(['message' => 'Check the prompt, reference sources, and selected model, then try again.'], 400);
    }
    catch (\RuntimeException) {
      return $this->response(['message' => 'Moody AI could not generate content right now. Try again or contact a site administrator.'], 502);
    }
  }

  /**
   * Returns display metadata for one Media Library selection.
   */
  public function mediaInfo(Request $request): JsonResponse {
    if (!$this->validToken($request)) {
      return $this->response(['message' => 'Your session token is no longer valid. Reload the page and try again.'], 403);
    }

    $payload = json_decode($request->getContent(), TRUE);
    try {
      $media = $this->loadMediaReferences([
        [
          'uuid' => is_array($payload) ? ($payload['uuid'] ?? '') : '',
          'intent' => 'inspiration',
        ],
      ], FALSE)[0];
      return $this->response([
        'uuid' => $media['uuid'],
        'label' => $media['label'],
        'type' => $media['bundle'],
        'contextAvailable' => $media['context_available'],
      ]);
    }
    catch (\InvalidArgumentException) {
      return $this->response(['message' => 'That Media item is unavailable. Select it again or choose another item.'], 400);
    }
  }

  /**
   * Replaces approved image placeholders with saved Drupal Media markup.
   */
  public function finalize(Request $request): JsonResponse {
    if (!$this->validToken($request)) {
      return $this->response(['message' => 'Your session token is no longer valid. Reload the page and try again.'], 403);
    }

    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->response(['message' => 'The insertion request was not valid.'], 400);
    }

    $html = $this->htmlSanitizer->sanitize((string) ($payload['html'] ?? ''));
    if ($html === '') {
      return $this->response(['message' => 'The generated content is no longer available. Generate it again.'], 400);
    }

    try {
      $attachments = $this->loadAttachments($payload['attachments'] ?? [], FALSE);
      $media = $this->loadMediaReferences($payload['media'] ?? [], FALSE);
      $this->validateReferenceCount($attachments, $media);
      [$html, $media_count] = $this->finalizeMediaPlaceholders($html, $attachments, $media);
      return $this->response([
        'html' => $html,
        'mediaCount' => $media_count,
      ]);
    }
    catch (\InvalidArgumentException) {
      return $this->response(['message' => 'An image could not be added as Media. Generate the preview again and retry.'], 400);
    }
    catch (\RuntimeException) {
      return $this->response(['message' => 'Moody AI could not create the requested Media item. Contact a site administrator if this continues.'], 500);
    }
  }

  /**
   * Loads accessible existing Media selected through Drupal's Media Library.
   */
  private function loadMediaReferences(mixed $references, bool $include_data): array {
    if (!is_array($references) || count($references) > AiGenerationService::MAX_ATTACHMENTS) {
      throw new \InvalidArgumentException('The Media reference list is invalid.');
    }

    $storage = $this->entityTypeManager->getStorage('media');
    $file_extensions = explode(' ', AiGenerationService::ALLOWED_ATTACHMENT_EXTENSIONS);
    $loaded = [];
    $seen = [];
    foreach ($references as $reference) {
      $uuid = is_array($reference) ? ($reference['uuid'] ?? NULL) : NULL;
      $intent = is_array($reference) ? ($reference['intent'] ?? NULL) : NULL;
      if (!is_string($uuid) || !Uuid::isValid($uuid) || !in_array($intent, ['inspiration', 'content'], TRUE) || isset($seen[$uuid])) {
        throw new \InvalidArgumentException('A Media reference is invalid.');
      }
      $seen[$uuid] = TRUE;

      $matches = $storage->loadByProperties(['uuid' => $uuid]);
      $media = count($matches) === 1 ? reset($matches) : NULL;
      if (!$media instanceof MediaInterface || !$media->access('view', $this->currentUser)) {
        throw new \InvalidArgumentException('A Media reference is unavailable.');
      }

      $type = $this->entityTypeManager->getStorage('media_type')->load($media->bundle());
      if (!$type instanceof MediaTypeInterface) {
        throw new \InvalidArgumentException('A Media type is unavailable.');
      }
      $label = preg_replace('/\s+/u', ' ', trim((string) $media->label())) ?: 'Media item';
      $item = [
        'uuid' => $media->uuid(),
        'label' => mb_substr($label, 0, 255),
        'bundle' => (string) $type->label(),
        'intent' => $intent,
        'context_available' => FALSE,
      ];

      $source_field = $type->getSource()->getSourceFieldDefinition($type)->getName();
      $source_item = $media->get($source_field)->first();
      $target_id = $source_item?->getProperties(TRUE)['target_id'] ?? NULL;
      $file = $target_id?->getValue()
        ? $this->entityTypeManager->getStorage('file')->load((int) $target_id->getValue())
        : NULL;
      if ($file instanceof FileInterface && $file->access('view', $this->currentUser)) {
        $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
        $path = $this->fileSystem->realpath($file->getFileUri());
        // Managed-file size metadata can be stale after image optimization.
        $actual_size = is_string($path) && is_readable($path) ? filesize($path) : FALSE;
        $item['context_available'] = in_array($extension, $file_extensions, TRUE)
          && is_int($actual_size)
          && $actual_size > 0
          && $actual_size <= AiGenerationService::MAX_ATTACHMENT_BYTES;
        if ($include_data && $item['context_available']) {
          $data = file_get_contents($path);
          if (!is_string($data) || $data === '' || strlen($data) > AiGenerationService::MAX_ATTACHMENT_BYTES) {
            $item['context_available'] = FALSE;
          }
          else {
            $item += [
              'filename' => $file->getFilename(),
              'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
              'data' => $data,
            ];
          }
        }
      }
      $loaded[] = $item;
    }
    return $loaded;
  }

  /**
   * Enforces the shared reference count across uploads and existing Media.
   */
  private function validateReferenceCount(array $attachments, array $media): void {
    if (count($attachments) + count($media) > AiGenerationService::MAX_ATTACHMENTS) {
      throw new \InvalidArgumentException('Too many references were provided.');
    }
  }

  /**
   * Loads private attachments owned by the current user.
   */
  private function loadAttachments(mixed $ids, bool $include_data = TRUE): array {
    if (!is_array($ids) || count($ids) > AiGenerationService::MAX_ATTACHMENTS) {
      throw new \InvalidArgumentException('The attachment list is invalid.');
    }

    $uid = (int) $this->currentUser->id();
    $storage = $this->entityTypeManager->getStorage('file');
    $attachments = [];
    $total = 0;
    $seen = [];
    $extensions = explode(' ', AiGenerationService::ALLOWED_ATTACHMENT_EXTENSIONS);
    $media_context = $this->imageMediaContext();

    foreach ($ids as $id) {
      if ((!is_int($id) && !ctype_digit((string) $id)) || (int) $id < 1 || isset($seen[(int) $id])) {
        throw new \InvalidArgumentException('An attachment ID is invalid.');
      }
      $seen[(int) $id] = TRUE;

      $file = $storage->load((int) $id);
      $uri = $file instanceof FileInterface ? $file->getFileUri() : '';
      $owned_path = preg_match(
        '#^private://' . $uid . '/\d{4}-\d{2}-\d{2}/moody-ai-ckeditor-uploads/[^/]+$#D',
        $uri,
      );
      $extension = strtolower(pathinfo($file instanceof FileInterface ? $file->getFilename() : '', PATHINFO_EXTENSION));
      $size = $file instanceof FileInterface ? (int) $file->getSize() : 0;
      $total += $size;

      if (!$file instanceof FileInterface || $uid < 1 || (int) $file->getOwnerId() !== $uid || !$owned_path || !in_array($extension, $extensions, TRUE) || $size < 1 || $size > AiGenerationService::MAX_ATTACHMENT_BYTES || $total > AiGenerationService::MAX_TOTAL_ATTACHMENT_BYTES) {
        throw new \InvalidArgumentException('An attachment is unavailable.');
      }

      $data = '';
      if ($include_data) {
        $path = $this->fileSystem->realpath($uri);
        $data = is_string($path) && is_readable($path) ? file_get_contents($path) : FALSE;
        if (!is_string($data) || strlen($data) !== $size) {
          throw new \InvalidArgumentException('An attachment could not be read.');
        }
      }
      $attachments[] = [
        'file_id' => (int) $file->id(),
        'filename' => $file->getFilename(),
        'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
        'data' => $data,
        'media_eligible' => $media_context !== NULL
        && str_starts_with((string) $file->getMimeType(), 'image/')
        && in_array($extension, $media_context['extensions'], TRUE)
        && $this->imageFactory->get($uri)->isValid(),
      ];
    }

    return $attachments;
  }

  /**
   * Returns the site's one usable image Media bundle and source field.
   */
  private function imageMediaContext(): ?array {
    $types = array_filter(
      $this->entityTypeManager->getStorage('media_type')->loadMultiple(),
      static fn(MediaTypeInterface $type): bool => $type->getSource()->getPluginId() === 'image',
    );
    if (count($types) !== 1) {
      return NULL;
    }

    /** @var \Drupal\media\MediaTypeInterface $type */
    $type = reset($types);
    if (!$this->entityTypeManager->getAccessControlHandler('media')->createAccess($type->id(), $this->currentUser)) {
      return NULL;
    }

    $definition = $type->getSource()->getSourceFieldDefinition($type);
    return [
      'bundle' => $type->id(),
      'field' => $definition->getName(),
      'extensions' => preg_split('/\s+/', strtolower((string) $definition->getSetting('file_extensions')), flags: PREG_SPLIT_NO_EMPTY) ?: [],
    ];
  }

  /**
   * Creates Media for valid placeholders and returns standard CKEditor markup.
   */
  private function finalizeMediaPlaceholders(string $html, array $attachments, array $media_references): array {
    $document = new \DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(TRUE);
    $loaded = $document->loadHTML(
      '<!doctype html><html><head><meta charset="utf-8"></head><body><div id="moody-ai-root">' . $html . '</div></body></html>',
      LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $root = $document->getElementById('moody-ai-root');
    if (!$loaded || !$root instanceof \DOMElement) {
      throw new \InvalidArgumentException('The generated markup is invalid.');
    }

    $xpath = new \DOMXPath($document);
    $placeholders = iterator_to_array($xpath->query('.//drupal-media', $root) ?: []);
    if ($placeholders === []) {
      return [$html, 0];
    }
    $generated_placeholders = array_filter($placeholders, static fn($placeholder): bool => $placeholder instanceof \DOMElement && $placeholder->getAttribute('data-moody-ai-generated-image') === '1');
    if (count($generated_placeholders) > 1) {
      throw new \InvalidArgumentException('Only one generated image may be inserted per request.');
    }

    $media_context = $this->imageMediaContext();

    $transaction = $this->database->startTransaction();
    $media_by_file = [];
    $generated_count = 0;
    try {
      foreach ($placeholders as $placeholder) {
        if (!$placeholder instanceof \DOMElement) {
          continue;
        }
        $media_index = $placeholder->getAttribute('data-moody-ai-media');
        if ($media_index !== '') {
          $reference = $media_references[(int) $media_index - 1] ?? NULL;
          if (!is_array($reference) || $reference['intent'] !== 'content') {
            throw new \InvalidArgumentException('An existing Media placeholder is invalid.');
          }
          $uuid = $reference['uuid'];
        }
        elseif ($placeholder->getAttribute('data-moody-ai-generated-image') === '1') {
          if ($media_context === NULL) {
            throw new \InvalidArgumentException('Image Media is not available.');
          }
          $identifier = 'uid-' . $this->currentUser->id();
          if (!$this->flood->isAllowed('moody_ai_ckeditor.image', $this->generator->hourlyRequestLimit(), 3600, $identifier)) {
            throw new \RuntimeException('The hourly image generation limit has been reached.');
          }
          $generated = $this->createGeneratedImageMedia(
            trim($placeholder->getAttribute('data-moody-ai-image-prompt')),
            trim($placeholder->getAttribute('data-moody-ai-alt')),
            $media_context,
          );
          $this->flood->register('moody_ai_ckeditor.image', 3600, $identifier);
          $uuid = $generated->uuid();
          $generated_count++;
        }
        else {
          $attachment_index = (int) $placeholder->getAttribute('data-moody-ai-attachment') - 1;
          $attachment = $attachments[$attachment_index] ?? NULL;
          $alt = trim($placeholder->getAttribute('data-moody-ai-alt'));
          if (!is_array($attachment) || empty($attachment['media_eligible']) || $alt === '') {
            throw new \InvalidArgumentException('A Media placeholder is invalid.');
          }
          if ($media_context === NULL) {
            throw new \InvalidArgumentException('Image Media is not available.');
          }

          $file_id = (int) $attachment['file_id'];
          if (!isset($media_by_file[$file_id])) {
            $media_by_file[$file_id] = $this->loadOrCreateMedia($attachment, $alt, $media_context);
          }
          $uuid = $media_by_file[$file_id]->uuid();
        }
        $align = $placeholder->getAttribute('data-moody-ai-align');
        foreach (iterator_to_array($placeholder->attributes) as $attribute) {
          $placeholder->removeAttributeNode($attribute);
        }
        $placeholder->setAttribute('data-entity-type', 'media');
        $placeholder->setAttribute('data-entity-uuid', $uuid);
        if ($align !== '') {
          $placeholder->setAttribute('data-align', $align);
        }
      }
    }
    catch (\Exception $exception) {
      $transaction->rollBack();
      if ($exception instanceof \InvalidArgumentException) {
        throw $exception;
      }
      throw new \RuntimeException('Media creation failed.', 0, $exception);
    }
    unset($transaction);

    $result = '';
    foreach ($root->childNodes as $child) {
      $result .= $document->saveHTML($child);
    }
    return [trim($result), count($media_by_file) + $generated_count];
  }

  /**
   * Generates one approved image and stores it as Drupal Media.
   */
  private function createGeneratedImageMedia(string $prompt, string $alt, array $media_context): MediaInterface {
    if ($prompt === '' || $alt === '' || mb_strlen($prompt) > $this->generator->maxPromptCharacters()) {
      throw new \InvalidArgumentException('The generated image request is invalid.');
    }
    if (!in_array('png', $media_context['extensions'], TRUE)) {
      throw new \InvalidArgumentException('The site image Media type does not accept generated PNG files.');
    }

    $image = $this->generator->generateImage($prompt);
    $directory = sprintf('public://moody-ai-ckeditor/generated/%d', (int) $this->currentUser->id());
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException('The generated image directory is not available.');
    }
    $filename = sprintf(
      'moody-ai-%s-%s.png',
      gmdate('Ymd-His', $this->time->getRequestTime()),
      substr(hash('sha256', $prompt . microtime(TRUE)), 0, 12),
    );
    $file = $this->fileRepository->writeData($image['binary'], $directory . '/' . $filename, FileExists::Rename);
    if (!$file instanceof FileInterface) {
      throw new \RuntimeException('The generated image could not be saved.');
    }
    $file->setOwnerId((int) $this->currentUser->id());
    $file->setPermanent();
    $file->save();

    return $this->loadOrCreateMedia([
      'file_id' => (int) $file->id(),
      'filename' => $file->getFilename(),
    ], $alt, $media_context);
  }

  /**
   * Reuses Media for a private upload or creates it exactly once.
   */
  private function loadOrCreateMedia(array $attachment, string $alt, array $media_context): MediaInterface {
    $storage = $this->entityTypeManager->getStorage('media');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', $media_context['bundle'])
      ->condition($media_context['field'] . '.target_id', $attachment['file_id'])
      ->range(0, 1)
      ->execute();
    if ($ids) {
      $media = $storage->load(reset($ids));
      if ($media instanceof MediaInterface) {
        return $media;
      }
    }

    $name = pathinfo($attachment['filename'], PATHINFO_FILENAME);
    $name = trim(preg_replace('/[_-]+/', ' ', $name) ?? $name) ?: 'AI-generated image attachment';
    $media = $storage->create([
      'bundle' => $media_context['bundle'],
      'name' => mb_substr($name, 0, 255),
      'status' => 1,
      'uid' => (int) $this->currentUser->id(),
      $media_context['field'] => [
        'target_id' => (int) $attachment['file_id'],
        'alt' => mb_substr($alt, 0, 512),
        'title' => '',
      ],
    ]);
    if (!$media instanceof MediaInterface) {
      throw new \RuntimeException('Media could not be initialized.');
    }
    $media->save();
    return $media;
  }

  /**
   * Validates the private token shared by the Moody AI editor endpoints.
   */
  private function validToken(Request $request): bool {
    return $this->csrfToken->validate(
      $request->headers->get('X-Moody-AI-Token', ''),
      'moody_ai_ckeditor.generate',
    );
  }

  /**
   * Creates a non-cacheable JSON response.
   */
  private function response(array $data, int $status = 200): JsonResponse {
    return new JsonResponse($data, $status, [
      'Cache-Control' => 'no-store, private',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

}
