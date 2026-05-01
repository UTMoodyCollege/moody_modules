<?php

namespace Drupal\moody_media_image_helper;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\media\MediaInterface;

/**
 * Creates cropped media duplicates for image media entities.
 */
class MediaCropManager {

  /**
   * Constructs the manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected FileRepositoryInterface $fileRepository,
    protected ImageFactory $imageFactory,
  ) {}

  /**
   * Determines whether the given media item can be cropped.
   */
  public function supportsMedia(?MediaInterface $media): bool {
    if (!$media instanceof MediaInterface) {
      return FALSE;
    }

    $source_file = $this->getSourceFile($media);
    if (!$source_file instanceof FileInterface) {
      return FALSE;
    }

    $image = $this->imageFactory->get($source_file->getFileUri());
    return $image->isValid();
  }

  /**
   * Returns the source field name for a media entity.
   */
  public function getSourceFieldName(MediaInterface $media): ?string {
    $media_type = $media->bundle->entity;
    $source_field = $media->getSource()->getSourceFieldDefinition($media_type);
    return $source_field?->getName();
  }

  /**
   * Returns original image dimensions for the source image.
   */
  public function getImageDimensions(MediaInterface $media, ?int $sourceFileId = NULL): array {
    $source_file = $this->getSourceFile($media, $sourceFileId);
    return $source_file ? $this->getFileDimensions($source_file) : ['width' => 0, 'height' => 0];
  }

  /**
   * Returns the source file for a media entity.
   */
  public function getSourceFile(MediaInterface $media, ?int $sourceFileId = NULL): ?FileInterface {
    if ($sourceFileId) {
      $file = $this->entityTypeManager->getStorage('file')->load($sourceFileId);
      return $file instanceof FileInterface ? $file : NULL;
    }

    $source_field = $this->getSourceFieldName($media);
    if ($source_field === NULL || !$media->hasField($source_field) || $media->get($source_field)->isEmpty()) {
      return NULL;
    }

    $item = $media->get($source_field)->first();
    return $item && !empty($item->entity) && $item->entity instanceof FileInterface ? $item->entity : NULL;
  }

  /**
   * Returns original image dimensions for a file-backed image.
   */
  public function getFileDimensions(FileInterface $file): array {
    $image = $this->imageFactory->get($file->getFileUri());

    return [
      'width' => $image && $image->isValid() ? (int) $image->getWidth() : 0,
      'height' => $image && $image->isValid() ? (int) $image->getHeight() : 0,
    ];
  }

  /**
   * Creates a duplicate file with crop and optional resize applied.
   */
  public function createDerivedFile(MediaInterface $media, array $crop, array $resize = [], ?int $sourceFileId = NULL): FileInterface {
    $source_field = $this->getSourceFieldName($media);
    if ($source_field === NULL || !$this->supportsMedia($media)) {
      throw new \InvalidArgumentException('The provided media entity does not contain a supported image source.');
    }

    $crop = $this->normalizeCrop($media, $crop, $sourceFileId);
    $resize = $this->normalizeResize($resize, [
      'width' => $crop['width'],
      'height' => $crop['height'],
    ]);
    $source_file = $this->getSourceFile($media, $sourceFileId);
    if (!$source_file instanceof FileInterface) {
      throw new \InvalidArgumentException('The selected source file is not available for editing.');
    }

    $destination_uri = $this->buildDestinationUri($source_file->getFileUri());

    $new_file = $this->fileRepository->copy($source_file, $destination_uri, FileExists::Error);
    $image = $this->imageFactory->get($new_file->getFileUri());
    if (!$image->isValid()) {
      throw new \RuntimeException('The copied image file could not be loaded for cropping.');
    }

    $image->crop($crop['x'], $crop['y'], $crop['width'], $crop['height']);
    if ($resize['width'] !== $crop['width'] || $resize['height'] !== $crop['height']) {
      $image->resize($resize['width'], $resize['height']);
    }
    $image->save();

    return $new_file;
  }

  /**
   * Creates a cropped duplicate media entity and file.
   */
  public function createCroppedMedia(MediaInterface $media, array $crop, array $resize = [], ?int $sourceFileId = NULL): MediaInterface {
    $source_field = $this->getSourceFieldName($media);
    if ($source_field === NULL || !$this->supportsMedia($media)) {
      throw new \InvalidArgumentException('The provided media entity does not contain a supported image source.');
    }

    $crop = $this->normalizeCrop($media, $crop, $sourceFileId);
    $resize = $this->normalizeResize($resize, [
      'width' => $crop['width'],
      'height' => $crop['height'],
    ]);
    $image_item = $media->get($source_field)->first();
    $new_file = $this->createDerivedFile($media, $crop, $resize, $sourceFileId);

    $new_media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => $media->bundle(),
      'name' => $this->buildDerivedMediaName($media->label()),
      'uid' => $media->getOwnerId(),
      'status' => $media->isPublished(),
      'langcode' => $media->language()->getId(),
    ]);

    $new_media->set($source_field, [
      'target_id' => $new_file->id(),
      'alt' => (string) ($image_item->alt ?? ''),
      'title' => (string) ($image_item->title ?? ''),
      'width' => $resize['width'],
      'height' => $resize['height'],
    ]);
    $new_media->save();

    return $new_media;
  }

  /**
   * Normalizes a crop selection to integer pixel bounds.
   */
  public function normalizeCrop(MediaInterface $media, array $crop, ?int $sourceFileId = NULL): array {
    $dimensions = $this->getImageDimensions($media, $sourceFileId);
    $width = max(1, min((int) ($crop['width'] ?? 0), $dimensions['width']));
    $height = max(1, min((int) ($crop['height'] ?? 0), $dimensions['height']));
    $x = max(0, min((int) ($crop['x'] ?? 0), max(0, $dimensions['width'] - $width)));
    $y = max(0, min((int) ($crop['y'] ?? 0), max(0, $dimensions['height'] - $height)));

    return [
      'x' => $x,
      'y' => $y,
      'width' => $width,
      'height' => $height,
    ];
  }

  /**
   * Normalizes requested resize dimensions using a crop-sized fallback.
   */
  public function normalizeResize(array $resize, array $fallbackDimensions): array {
    $width = max(1, (int) ($resize['width'] ?? $fallbackDimensions['width'] ?? 1));
    $height = max(1, (int) ($resize['height'] ?? $fallbackDimensions['height'] ?? 1));

    return [
      'width' => $width,
      'height' => $height,
    ];
  }

  /**
   * Builds a deterministic derived media label.
   */
  protected function buildDerivedMediaName(string $original_name): string {
    return trim($original_name) . ' edited';
  }

  /**
   * Builds a unique destination URI in the same directory as the source file.
   */
  protected function buildDestinationUri(string $source_uri): string {
    $directory = $this->fileSystem->dirname($source_uri);
    $extension = pathinfo($source_uri, PATHINFO_EXTENSION);
    $basename = pathinfo($source_uri, PATHINFO_FILENAME);

    $counter = 1;
    do {
      $suffix = '_cropped_' . $counter;
      $candidate = $directory . '/' . $basename . $suffix . ($extension ? '.' . $extension : '');
      $counter++;
    } while ($this->fileSystem->getDestinationFilename($candidate, FileExists::Error) === FALSE);

    return $candidate;
  }

}
