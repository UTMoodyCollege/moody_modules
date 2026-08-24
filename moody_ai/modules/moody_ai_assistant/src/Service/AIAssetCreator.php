<?php

namespace Drupal\moody_ai_assistant\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\moody_ai_base\AiGenerationService;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AIAssetCreator {
  const MAX_UPLOAD_BYTES = AiGenerationService::MAX_ATTACHMENT_BYTES;

  const ALLOWED_UPLOAD_EXTENSIONS = [
    'png',
    'gif',
    'jpg',
    'jpeg',
    'webp',
    'pdf',
    'doc',
    'docx',
    'txt',
    'csv',
  ];

  protected $entityTypeManager;
  protected $fileRepository;
  protected $fileSystem;
  protected $logger;
  protected $planner;
  protected $database;

  protected $currentUser;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    FileRepositoryInterface $file_repository,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
    AssistantPlanner $planner,
    Connection $database,
    AccountProxyInterface $current_user
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileRepository = $file_repository;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('moody_ai_assistant');
    $this->planner = $planner;
    $this->database = $database;
    $this->currentUser = $current_user;
  }

  /**
   * Finds an existing media image by fuzzy matching alt/title/name text.
   *
   * @param array $field_data
   *   The requested asset data.
   * @param string $media_bundle
   *   The media bundle.
   *
   * @return int|null
   *   The matched media ID, or NULL.
   */
  public function findExistingMediaImageIdFromFieldData(array $field_data, $media_bundle = 'utexas_image') {
    if ($media_bundle !== 'utexas_image') {
      return NULL;
    }

    $queries = $this->buildMediaSearchQueries($field_data);
    if (!$queries) {
      return NULL;
    }

    $candidate_ids = [];
    foreach ($queries as $query_text) {
      foreach ($this->lookupMediaCandidateIds($query_text, $media_bundle) as $candidate_id) {
        $candidate_ids[$candidate_id] = $candidate_id;
      }
    }

    if (!$candidate_ids) {
      return NULL;
    }

    $best_id = NULL;
    $best_score = 0;
    $media_storage = $this->entityTypeManager->getStorage('media');
    $media_entities = $media_storage->loadMultiple(array_values($candidate_ids));

    foreach ($media_entities as $media) {
      $haystacks = $this->extractMediaSearchHaystacks($media);
      $score = $this->scoreMediaMatch($queries, $haystacks);
      if ($score > $best_score) {
        $best_score = $score;
        $best_id = (int) $media->id();
      }
    }

    if ($best_id && $best_score >= 45) {
      $this->logger->notice('Matched existing media @id using fuzzy media lookup.', [
        '@id' => $best_id,
      ]);
      return $best_id;
    }

    return NULL;
  }

  public function createMediaAssetFromFieldData(array $field_data, $media_bundle = 'utexas_image') {
    $source_url = trim((string) ($field_data['source_url'] ?? $field_data['image_url'] ?? ''));
    if ($source_url !== '' && $this->isSupportedExternalVideoUrl($source_url)) {
      $video_bundle = $media_bundle === 'utexas_video_external' ? $media_bundle : $this->resolveUploadedMediaBundle('external_video');
      return $this->createMediaFromExternalVideoUrl($source_url, $video_bundle, $field_data);
    }

    $directory = 'public://moody_ai_assistant/generated';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    if ($source_url !== '') {
      throw new \Exception('Direct remote image imports are not available. Select existing Media or request a generated image.');
    }
    else {
      $image_prompt = trim((string) ($field_data['image_prompt'] ?? $field_data['prompt'] ?? ''));
      if ($image_prompt === '') {
        throw new \Exception('Missing image prompt or image URL for media asset creation.');
      }

      $image = $this->planner->generateImage($image_prompt);
      $filename = 'ai-block-' . date('Ymd-His') . '-' . substr(hash('sha256', $image_prompt . microtime(TRUE)), 0, 12) . '.' . $image['extension'];
      $destination = $directory . '/' . $filename;
      $file = $this->fileRepository->writeData($image['binary'], $destination, FileSystemInterface::EXISTS_RENAME);
    }

    if (!$file || !$file->id()) {
      throw new \Exception('Failed to persist image file.');
    }

    $file->setPermanent();
    $file->save();

    return $this->createMediaFromFileEntity($file, $media_bundle, [
      'alt' => trim((string) ($field_data['alt'] ?? 'AI generated image')),
      'title' => trim((string) ($field_data['title'] ?? 'AI generated image')),
    ]);
  }

  public function createMediaImageFromFieldData(array $field_data, $media_bundle = 'utexas_image') {
    return $this->createMediaAssetFromFieldData($field_data, $media_bundle);
  }

  /**
   * Prepares prompt-ready uploaded asset metadata from request files.
   *
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile[] $uploaded_files
   *   Uploaded files.
   *
   * @return array
   *   Uploaded asset metadata.
   */
  public function prepareUploadedAssets(array $uploaded_files) {
    $assets = [];

    foreach ($uploaded_files as $uploaded_file) {
      if (!$uploaded_file instanceof UploadedFile) {
        continue;
      }

      $assets[] = $this->createMediaFromUploadedFile($uploaded_file);
    }

    return $assets;
  }

  /**
   * Prepares previously uploaded private files owned by the current user.
   */
  public function prepareStoredUploadAssets(array $file_ids) {
    $file_ids = array_values(array_unique(array_filter(array_map('intval', $file_ids))));
    if (count($file_ids) > AiGenerationService::MAX_ATTACHMENTS) {
      throw new \InvalidArgumentException('Too many private uploads were selected.');
    }

    $uid = (int) $this->currentUser->id();
    $files = $this->entityTypeManager->getStorage('file')->loadMultiple($file_ids);
    $assets = [];
    $total_bytes = 0;

    foreach ($file_ids as $file_id) {
      $file = $files[$file_id] ?? NULL;
      $uri = $file instanceof FileInterface ? $file->getFileUri() : '';
      $extension = strtolower(pathinfo($file instanceof FileInterface ? $file->getFilename() : '', PATHINFO_EXTENSION));
      $size = $file instanceof FileInterface ? (int) $file->getSize() : 0;
      $total_bytes += $size;

      if (
        !$file instanceof FileInterface
        || $uid < 1
        || (int) $file->getOwnerId() !== $uid
        || !preg_match('#^private://' . $uid . '/\d{4}-\d{2}-\d{2}/moody-ai-ckeditor-uploads/[^/]+$#D', $uri)
        || !in_array($extension, self::ALLOWED_UPLOAD_EXTENSIONS, TRUE)
        || $size < 1
        || $size > self::MAX_UPLOAD_BYTES
        || $total_bytes > AiGenerationService::MAX_TOTAL_ATTACHMENT_BYTES
      ) {
        throw new \InvalidArgumentException('One or more private uploads are unavailable.');
      }

      $path = $this->fileSystem->realpath($uri);
      if (!is_string($path) || !is_readable($path)) {
        throw new \InvalidArgumentException('One or more private uploads could not be read.');
      }

      $assets[] = $this->createMediaFromStoredFile($file, $file->getFilename());
    }

    return $assets;
  }

  /**
   * Builds prompt-ready metadata for accessible existing Media selections.
   */
  public function prepareExistingMediaAssets(array $media_ids, string $intent = 'inspiration') {
    if (!in_array($intent, ['inspiration', 'content'], TRUE)) {
      throw new \InvalidArgumentException('The selected Media use is invalid.');
    }

    $media_ids = array_values(array_unique(array_filter(array_map('intval', $media_ids))));
    if (count($media_ids) > AiGenerationService::MAX_ATTACHMENTS) {
      throw new \InvalidArgumentException('Too many Media items were selected.');
    }

    $storage = $this->entityTypeManager->getStorage('media');
    $loaded = $storage->loadMultiple($media_ids);
    if (count($loaded) !== count($media_ids)) {
      throw new \InvalidArgumentException('One or more selected Media items are unavailable.');
    }

    $assets = [];
    foreach ($media_ids as $media_id) {
      $media = $loaded[$media_id] ?? NULL;
      if (!$media || !$media->access('view', $this->currentUser)) {
        throw new \InvalidArgumentException('One or more selected Media items are unavailable.');
      }

      $type = $this->entityTypeManager->getStorage('media_type')->load($media->bundle());
      if (!$type) {
        throw new \InvalidArgumentException('A selected Media type is unavailable.');
      }

      $source = $type->getSource();
      $plugin_id = $source->getPluginId();
      $source_field = $source->getSourceFieldDefinition($type)->getName();
      $source_item = $media->hasField($source_field) ? $media->get($source_field)->first() : NULL;
      $asset_type = $plugin_id === 'image' ? 'image' : (str_contains($plugin_id, 'video') ? 'external_video' : 'document');
      $asset = [
        'media_bundle' => $media->bundle(),
        'asset_type' => $asset_type,
        'title' => (string) $media->label(),
        'source' => 'existing_media',
        'intent' => $intent,
      ];

      if ($intent === 'content') {
        $asset['target_id'] = (int) $media->id();
        $asset['media_id'] = (int) $media->id();
      }
      if ($asset_type === 'image' && $source_item) {
        $asset['alt'] = trim((string) ($source_item->alt ?? ''));
      }
      if ($source_item && isset($source_item->target_id)) {
        $file = $this->entityTypeManager->getStorage('file')->load((int) $source_item->target_id);
        if ($file instanceof FileInterface && $file->access('download', $this->currentUser)) {
          $asset['file_name'] = $file->getFilename();
          $asset['mime_type'] = $file->getMimeType();
        }
      }

      $assets[] = $asset;
    }

    return $assets;
  }

  /**
   * Creates a Drupal media entity from an uploaded file.
   *
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $uploaded_file
   *   The uploaded file.
   *
   * @return array
   *   Prompt-ready asset metadata.
   */
  public function createMediaFromUploadedFile(UploadedFile $uploaded_file) {
    $this->assertValidUploadedFile($uploaded_file);

    $original_name = $uploaded_file->getClientOriginalName() ?: $uploaded_file->getFilename();
    $extension = $this->determineUploadedFileExtension($uploaded_file, $original_name);
    $directory = sprintf(
      'private://%d/%s/moody-ai-ckeditor-uploads',
      (int) $this->currentUser->id(),
      gmdate('Y-m-d'),
    );

    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $binary = file_get_contents($uploaded_file->getRealPath());
    if ($binary === FALSE || $binary === '') {
      throw new \Exception(sprintf('Failed to read uploaded file "%s".', $original_name));
    }

    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename(str_replace('\\', '/', $original_name)));
    $filename = trim((string) $filename, '.-');
    if ($filename === '' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== $extension) {
      $filename = 'attachment-' . substr(hash('sha256', $original_name . microtime(TRUE)), 0, 12) . '.' . $extension;
    }
    $destination = $directory . '/' . $filename;
    $file = $this->fileRepository->writeData($binary, $destination, FileSystemInterface::EXISTS_RENAME);

    if (!$file || !$file->id()) {
      throw new \Exception(sprintf('Failed to save uploaded file "%s".', $original_name));
    }

    $file->setOwnerId((int) $this->currentUser->id());
    $file->save();

    return $this->createMediaFromStoredFile($file, $original_name);
  }

  /**
   * Creates or reuses Media for a validated private upload.
   */
  protected function createMediaFromStoredFile(FileInterface $file, $original_name) {
    $mime_type = strtolower((string) ($file->getMimeType() ?: 'application/octet-stream'));
    $extension = strtolower((string) pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    $asset_type = in_array($extension, ['png', 'gif', 'jpg', 'jpeg', 'webp'], TRUE) || str_starts_with($mime_type, 'image/')
      ? 'image'
      : 'document';

    $media_bundle = $this->resolveUploadedMediaBundle($asset_type);
    $existing_media_id = $this->findExistingMediaIdForFile($file, $media_bundle);
    if ($existing_media_id) {
      $existing_media = $this->entityTypeManager->getStorage('media')->load($existing_media_id);
      if ($existing_media) {
        return $this->buildUploadedAssetSummary($existing_media, $asset_type, $mime_type, $original_name, [], $file);
      }
    }

    $field_data = [
      'title' => $this->buildTitleFromFilename($original_name),
    ];

    if ($asset_type === 'image') {
      $field_data['alt'] = $field_data['title'];
      try {
        $path = $this->fileSystem->realpath($file->getFileUri());
        $binary = is_string($path) && is_readable($path) ? file_get_contents($path) : FALSE;
        if (!is_string($binary) || $binary === '') {
          throw new \Exception('The uploaded image could not be read.');
        }
        $metadata = $this->planner->generateImageMetadata($binary, $mime_type, $original_name);
        if (!empty($metadata['alt'])) {
          $field_data['alt'] = $metadata['alt'];
        }
        if (!empty($metadata['title'])) {
          $field_data['title'] = $metadata['title'];
        }
      }
      catch (\Exception $exception) {
        $this->logger->warning('Failed to generate alt text for uploaded image @name: @message', [
          '@name' => $original_name,
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    $file->setPermanent();
    $file->save();
    $media = $this->createMediaFromFileEntity($file, $media_bundle, $field_data);
    return $this->buildUploadedAssetSummary($media, $asset_type, $mime_type, $original_name, $field_data, $file);
  }

  /**
   * Creates a media entity from a stored file entity.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity.
   * @param string $media_bundle
   *   The media bundle.
   * @param array $field_data
   *   Asset metadata.
   *
   * @return \Drupal\media\MediaInterface
   *   The created media entity.
   */
  protected function createMediaFromFileEntity(FileInterface $file, $media_bundle, array $field_data = []) {
    /** @var \Drupal\media\Entity\MediaType|null $media_type */
    $media_type = $this->entityTypeManager->getStorage('media_type')->load($media_bundle);
    if (!$media_type) {
      throw new \Exception(sprintf('Media bundle "%s" does not exist.', $media_bundle));
    }
    if (!$this->entityTypeManager->getAccessControlHandler('media')->createAccess($media_bundle, $this->currentUser)) {
      throw new \Exception(sprintf('You do not have permission to create %s Media.', $media_type->label()));
    }

    $media_type_values = $media_type->toArray();
    $source_configuration = (array) ($media_type_values['source_configuration'] ?? []);
    $source_field = $source_configuration['source_field'] ?? NULL;
    if (!$source_field) {
      throw new \Exception(sprintf('Media bundle "%s" has no source field configured.', $media_bundle));
    }

    $title = trim((string) ($field_data['title'] ?? $this->buildTitleFromFilename($file->getFilename())));
    $media_values = [
      'bundle' => $media_bundle,
      'name' => $title,
    ];

    if ((string) ($media_type_values['source'] ?? '') === 'image') {
      $media_values[$source_field] = [
        'target_id' => $file->id(),
        'alt' => trim((string) ($field_data['alt'] ?? $title)),
        'title' => $title,
      ];
    }
    else {
      $media_values[$source_field] = [
        'target_id' => $file->id(),
      ];
    }

    $media = $this->entityTypeManager->getStorage('media')->create($media_values);
    $media->save();
    if (!$media->id()) {
      throw new \Exception('Failed to create media entity.');
    }

    $this->logger->notice('Created media asset @id for bundle @bundle.', [
      '@id' => $media->id(),
      '@bundle' => $media_bundle,
    ]);

    return $media;
  }

  /**
   * Creates an external video media entity from a supported video URL.
   *
   * @param string $video_url
   *   The YouTube or Vimeo URL.
   * @param string $media_bundle
   *   The media bundle.
   * @param array $field_data
   *   Optional asset metadata.
   *
   * @return \Drupal\media\MediaInterface
   *   The created or reused media entity.
   */
  protected function createMediaFromExternalVideoUrl($video_url, $media_bundle, array $field_data = []) {
    if (!$this->isSupportedExternalVideoUrl($video_url)) {
      throw new \Exception('Only YouTube and Vimeo URLs are supported for external video media.');
    }

    /** @var \Drupal\media\Entity\MediaType|null $media_type */
    $media_type = $this->entityTypeManager->getStorage('media_type')->load($media_bundle);
    if (!$media_type) {
      throw new \Exception(sprintf('Media bundle "%s" does not exist.', $media_bundle));
    }

    $media_type_values = $media_type->toArray();
    $source_configuration = (array) ($media_type_values['source_configuration'] ?? []);
    $source_field = $source_configuration['source_field'] ?? NULL;
    if (!$source_field) {
      throw new \Exception(sprintf('Media bundle "%s" has no source field configured.', $media_bundle));
    }

    $existing_media_id = $this->findExistingMediaIdForSourceValue($video_url, $media_bundle, $source_field);
    if ($existing_media_id) {
      $existing_media = $this->entityTypeManager->getStorage('media')->load($existing_media_id);
      if ($existing_media) {
        return $existing_media;
      }
    }

    $title = trim((string) ($field_data['title'] ?? $this->buildTitleFromUrl($video_url)));
    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => $media_bundle,
      'name' => $title,
      $source_field => $video_url,
    ]);
    $media->save();
    if (!$media->id()) {
      throw new \Exception('Failed to create external video media entity.');
    }

    $this->logger->notice('Created external video media asset @id for bundle @bundle.', [
      '@id' => $media->id(),
      '@bundle' => $media_bundle,
    ]);

    return $media;
  }

  /**
   * Determines whether an uploaded file should be treated as an image.
   */
  protected function determineUploadedAssetType(UploadedFile $uploaded_file, $original_name, $mime_type, $extension) {
    if (in_array($extension, ['png', 'gif', 'jpg', 'jpeg', 'webp'], TRUE)) {
      return 'image';
    }

    if (strpos($mime_type, 'image/') === 0) {
      return 'image';
    }

    $real_path = $uploaded_file->getRealPath();
    if ($real_path && function_exists('getimagesize')) {
      $image_info = @getimagesize($real_path);
      if (!empty($image_info['mime']) && strpos((string) $image_info['mime'], 'image/') === 0) {
        return 'image';
      }
    }

    return 'document';
  }

  /**
   * Validates a request-uploaded file.
   */
  protected function assertValidUploadedFile(UploadedFile $uploaded_file) {
    if (!$uploaded_file->isValid()) {
      throw new \Exception('One of the uploaded files could not be received successfully.');
    }

    $size = (int) ($uploaded_file->getSize() ?? 0);
    if ($size <= 0) {
      throw new \Exception('One of the uploaded files was empty.');
    }

    if ($size > self::MAX_UPLOAD_BYTES) {
      throw new \Exception(sprintf('Uploaded files must be %d MB or smaller.', (int) (self::MAX_UPLOAD_BYTES / 1048576)));
    }

    $extension = $this->determineUploadedFileExtension($uploaded_file, $uploaded_file->getClientOriginalName() ?: $uploaded_file->getFilename());
    if (!in_array($extension, self::ALLOWED_UPLOAD_EXTENSIONS, TRUE)) {
      throw new \Exception(sprintf('Unsupported uploaded file type: .%s', $extension));
    }

    $image_extensions = ['png', 'gif', 'jpg', 'jpeg', 'webp'];
    $real_path = $uploaded_file->getRealPath();
    $image_info = in_array($extension, $image_extensions, TRUE) && $real_path
      ? @getimagesize($real_path)
      : FALSE;
    $mime_type = strtolower((string) (($image_info['mime'] ?? NULL) ?: $uploaded_file->getMimeType() ?: ''));
    $allowed_mime_types = [
      'png' => ['image/png'],
      'gif' => ['image/gif'],
      'jpg' => ['image/jpeg'],
      'jpeg' => ['image/jpeg'],
      'webp' => ['image/webp'],
      'pdf' => ['application/pdf'],
      'doc' => ['application/msword', 'application/CDFV2', 'application/x-ole-storage'],
      'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
      'txt' => ['text/plain'],
      'csv' => ['text/csv', 'text/plain', 'application/csv'],
    ];
    $normalized_allowed = array_map('strtolower', $allowed_mime_types[$extension] ?? []);
    if (!in_array($mime_type, $normalized_allowed, TRUE)) {
      throw new \Exception(sprintf('The contents of "%s" do not match its file type.', $uploaded_file->getClientOriginalName()));
    }

    if (in_array($extension, $image_extensions, TRUE) && $image_info === FALSE) {
      throw new \Exception('An uploaded image could not be validated.');
    }
  }

  /**
   * Resolves a normalized extension for an uploaded file.
   */
  protected function determineUploadedFileExtension(UploadedFile $uploaded_file, $original_name) {
    $extension = strtolower((string) pathinfo($original_name, PATHINFO_EXTENSION));
    if ($extension !== '') {
      return $extension;
    }

    $guessed = strtolower((string) $uploaded_file->guessExtension());
    return $guessed !== '' ? $guessed : 'bin';
  }

  /**
   * Chooses a media bundle for an uploaded file.
   */
  protected function resolveUploadedMediaBundle($asset_type) {
    if ($asset_type === 'image') {
      $candidates = ['utexas_image'];
    }
    elseif ($asset_type === 'external_video') {
      $candidates = ['utexas_video_external'];
    }
    else {
      $candidates = ['utexas_document', 'document'];
    }

    foreach ($candidates as $candidate) {
      if ($this->entityTypeManager->getStorage('media_type')->load($candidate)) {
        return $candidate;
      }
    }

    throw new \Exception(sprintf('No supported media bundle is configured for uploaded %s files.', $asset_type));
  }

  /**
   * Looks for an existing media entity already referencing the given file.
   */
  protected function findExistingMediaIdForFile(FileInterface $file, $media_bundle) {
    $media_type = $this->entityTypeManager->getStorage('media_type')->load($media_bundle);
    if (!$media_type) {
      return NULL;
    }

    $media_type_values = $media_type->toArray();
    $source_configuration = (array) ($media_type_values['source_configuration'] ?? []);
    $source_field = $source_configuration['source_field'] ?? NULL;
    if (!$source_field) {
      return NULL;
    }

    $ids = $this->entityTypeManager->getStorage('media')->getQuery()
      ->accessCheck(TRUE)
      ->condition('bundle', $media_bundle)
      ->condition($source_field . '.target_id', $file->id())
      ->range(0, 1)
      ->execute();

    if (!$ids) {
      return NULL;
    }

    return (int) reset($ids);
  }

  /**
   * Looks for an existing media entity already referencing the given source URL.
   */
  protected function findExistingMediaIdForSourceValue($source_value, $media_bundle, $source_field) {
    $ids = $this->entityTypeManager->getStorage('media')->getQuery()
      ->accessCheck(TRUE)
      ->condition('bundle', $media_bundle)
      ->condition($source_field, $source_value)
      ->range(0, 1)
      ->execute();

    if (!$ids) {
      return NULL;
    }

    return (int) reset($ids);
  }

  /**
   * Builds prompt-ready metadata for an uploaded media asset.
   */
  protected function buildUploadedAssetSummary($media, $asset_type, $mime_type, $original_name, array $field_data = [], ?FileInterface $file = NULL) {
    $summary = [
      'target_id' => (int) $media->id(),
      'media_id' => (int) $media->id(),
      'media_bundle' => $media->bundle(),
      'asset_type' => $asset_type,
      'mime_type' => $mime_type,
      'title' => trim((string) ($field_data['title'] ?? $media->label())),
      'file_name' => $original_name,
      'source' => 'uploaded_file',
    ];

    if ($asset_type === 'image') {
      $alt = trim((string) ($field_data['alt'] ?? ''));
      if ($alt === '' && $media->hasField('field_utexas_media_image') && !$media->get('field_utexas_media_image')->isEmpty()) {
        $alt = (string) ($media->get('field_utexas_media_image')->first()->alt ?? '');
      }
      $summary['alt'] = $alt;
    }

    if ($file) {
      $summary += $this->buildPrivateUploadSummary($file);
    }

    return $summary;
  }

  /**
   * Builds safe display metadata for one private upload owned by the user.
   */
  public function buildPrivateUploadSummary(FileInterface $file) {
    $mime_type = strtolower((string) ($file->getMimeType() ?: 'application/octet-stream'));
    $extension = strtolower((string) pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    $is_image = str_starts_with($mime_type, 'image/') || in_array($extension, ['png', 'gif', 'jpg', 'jpeg', 'webp'], TRUE);
    $preview_url = '';
    if ($is_image && $this->entityTypeManager->hasDefinition('image_style')) {
      $thumbnail = $this->entityTypeManager->getStorage('image_style')->load('thumbnail');
      $preview_url = $thumbnail ? $thumbnail->buildUrl($file->getFileUri()) : '';
    }

    $size = (int) $file->getSize();
    return [
      'id' => (int) $file->id(),
      'name' => $file->getFilename(),
      'size' => $size,
      'type' => $mime_type,
      'extension' => strtoupper($extension ?: 'FILE'),
      'created' => (int) $file->getCreatedTime(),
      'uploaded' => gmdate('M j, Y', (int) $file->getCreatedTime()),
      'url' => $file->createFileUrl(FALSE),
      'preview_url' => $preview_url,
      'is_image' => $is_image,
      'label' => sprintf('%s · %s · %s', $file->getFilename(), gmdate('M j, Y', (int) $file->getCreatedTime()), $this->formatFileSize($size)),
    ];
  }

  /**
   * Formats a compact file size for upload displays.
   */
  protected function formatFileSize($bytes) {
    return $bytes >= 1048576
      ? rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB'
      : max(1, (int) round($bytes / 1024)) . ' KB';
  }

  /**
   * Builds a readable title from a filename.
   */
  protected function buildTitleFromFilename($filename) {
    $base = pathinfo((string) $filename, PATHINFO_FILENAME);
    $base = preg_replace('/[_-]+/', ' ', $base);
    $base = preg_replace('/\s+/', ' ', (string) $base);
    $base = trim((string) $base);

    return $base !== '' ? ucwords($base) : 'Uploaded Asset';
  }

  /**
   * Builds a readable title from a media URL.
   */
  protected function buildTitleFromUrl($url) {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (strpos($host, 'youtube.com') !== FALSE || strpos($host, 'youtu.be') !== FALSE) {
      return 'YouTube Video';
    }
    if (strpos($host, 'vimeo.com') !== FALSE) {
      return 'Vimeo Video';
    }

    return 'External Video';
  }

  /**
   * Determines whether a URL points to a supported external video provider.
   */
  protected function isSupportedExternalVideoUrl($url) {
    $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
    if ($host === '') {
      return FALSE;
    }

    return strpos($host, 'youtube.com') !== FALSE
      || strpos($host, 'youtu.be') !== FALSE
      || strpos($host, 'vimeo.com') !== FALSE;
  }

  /**
   * Builds prioritized text queries for fuzzy media lookup.
   */
  protected function buildMediaSearchQueries(array $field_data) {
    $queries = [];
    foreach (['existing_media_search', 'alt', 'title', 'prompt', 'image_prompt'] as $key) {
      $value = trim((string) ($field_data[$key] ?? ''));
      if ($value !== '') {
        $queries[] = $this->normalizeMediaSearchText($value);
      }
    }

    $derived = $this->deriveSearchTextFromPrompt((string) ($field_data['image_prompt'] ?? $field_data['prompt'] ?? ''));
    if ($derived !== '') {
      $queries[] = $this->normalizeMediaSearchText($derived);
    }

    $queries = array_values(array_unique(array_filter($queries)));
    return $queries;
  }

  /**
   * Looks up rough candidate media IDs with SQL LIKE matching.
   */
  protected function lookupMediaCandidateIds($query_text, $media_bundle) {
    $tokens = array_slice(array_values(array_filter(explode(' ', $query_text), function ($token) {
      return strlen($token) >= 3;
    })), 0, 5);

    if (!$tokens) {
      return [];
    }

    $query = $this->database->select('media_field_data', 'm');
    $query->leftJoin('media__field_utexas_media_image', 'img', 'img.entity_id = m.mid');
    $query->fields('m', ['mid']);
    $query->condition('m.bundle', $media_bundle);
    $query->condition('m.status', 1);

    $conditions = $query->orConditionGroup();
    foreach ($tokens as $token) {
      $like = '%' . $this->database->escapeLike($token) . '%';
      $conditions->condition('m.name', $like, 'LIKE');
      $conditions->condition('img.field_utexas_media_image_alt', $like, 'LIKE');
      $conditions->condition('img.field_utexas_media_image_title', $like, 'LIKE');
    }
    $query->condition($conditions);
    $query->range(0, 20);

    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Extracts name/alt/title text from a media entity for scoring.
   */
  protected function extractMediaSearchHaystacks($media) {
    $haystacks = [];
    $haystacks[] = $this->normalizeMediaSearchText((string) $media->label());

    if ($media->hasField('field_utexas_media_image') && !$media->get('field_utexas_media_image')->isEmpty()) {
      $item = $media->get('field_utexas_media_image')->first();
      $haystacks[] = $this->normalizeMediaSearchText((string) ($item->alt ?? ''));
      $haystacks[] = $this->normalizeMediaSearchText((string) ($item->title ?? ''));
    }

    return array_values(array_filter(array_unique($haystacks)));
  }

  /**
   * Scores a set of media haystacks against the desired queries.
   */
  protected function scoreMediaMatch(array $queries, array $haystacks) {
    $score = 0;

    foreach ($queries as $query_text) {
      $query_tokens = array_values(array_filter(explode(' ', $query_text)));
      foreach ($haystacks as $haystack) {
        if ($haystack === '') {
          continue;
        }

        if ($haystack === $query_text) {
          $score += 100;
          continue;
        }

        if (strpos($haystack, $query_text) !== FALSE || strpos($query_text, $haystack) !== FALSE) {
          $score += 60;
        }

        $matched_tokens = 0;
        foreach ($query_tokens as $token) {
          if (strlen($token) >= 3 && strpos($haystack, $token) !== FALSE) {
            $matched_tokens++;
          }
        }
        $score += $matched_tokens * 12;
      }
    }

    return $score;
  }

  /**
   * Normalizes text for media fuzzy search.
   */
  protected function normalizeMediaSearchText($text) {
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\s]+/i', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
  }

  /**
   * Tries to derive a more searchable subject phrase from an image prompt.
   */
  protected function deriveSearchTextFromPrompt($prompt) {
    $prompt = trim($prompt);
    if ($prompt === '') {
      return '';
    }

    $derived = preg_replace('/\b(photo|image|portrait|editorial|illustration|graphic|showing|of|with|featuring|students?|people|person)\b/i', ' ', $prompt);
    $derived = preg_replace('/\s+/', ' ', $derived);
    $derived = trim($derived);

    return mb_strlen($derived) >= 4 ? $derived : '';
  }
}
