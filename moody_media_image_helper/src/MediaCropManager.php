<?php

namespace Drupal\moody_media_image_helper;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Image\ImageFactory;
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

    $source_field = $this->getSourceFieldName($media);
    if ($source_field === NULL || !$media->hasField($source_field) || $media->get($source_field)->isEmpty()) {
      return FALSE;
    }

    $image_item = $media->get($source_field)->first();
    if (!$image_item || empty($image_item->entity)) {
      return FALSE;
    }

    $image = $this->imageFactory->get($image_item->entity->getFileUri());
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
  public function getImageDimensions(MediaInterface $media): array {
    $source_field = $this->getSourceFieldName($media);
    $item = $source_field ? $media->get($source_field)->first() : NULL;
    $image = $item && !empty($item->entity) ? $this->imageFactory->get($item->entity->getFileUri()) : NULL;

    return [
      'width' => $image && $image->isValid() ? (int) $image->getWidth() : 0,
      'height' => $image && $image->isValid() ? (int) $image->getHeight() : 0,
    ];
  }

  /**
   * Creates a cropped duplicate media entity and file.
   */
  public function createCroppedMedia(MediaInterface $media, array $crop): MediaInterface {
    $source_field = $this->getSourceFieldName($media);
    if ($source_field === NULL || !$this->supportsMedia($media)) {
      throw new \InvalidArgumentException('The provided media entity does not contain a supported image source.');
    }

    $crop = $this->normalizeCrop($media, $crop);
    $image_item = $media->get($source_field)->first();
    $source_file = $image_item->entity;
    $destination_uri = $this->buildDestinationUri($source_file->getFileUri());

    $new_file = $this->fileRepository->copy($source_file, $destination_uri, FileExists::Error);
    $image = $this->imageFactory->get($new_file->getFileUri());
    if (!$image->isValid()) {
      throw new \RuntimeException('The copied image file could not be loaded for cropping.');
    }

    $image->crop($crop['x'], $crop['y'], $crop['width'], $crop['height']);
    $image->save();

    $new_media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => $media->bundle(),
      'name' => $this->buildCroppedMediaName($media->label()),
      'uid' => $media->getOwnerId(),
      'status' => $media->isPublished(),
      'langcode' => $media->language()->getId(),
    ]);

    $new_media->set($source_field, [
      'target_id' => $new_file->id(),
      'alt' => (string) ($image_item->alt ?? ''),
      'title' => (string) ($image_item->title ?? ''),
      'width' => $crop['width'],
      'height' => $crop['height'],
    ]);
    $new_media->save();

    return $new_media;
  }

  /**
   * Normalizes a crop selection to integer pixel bounds.
   */
  public function normalizeCrop(MediaInterface $media, array $crop): array {
    $dimensions = $this->getImageDimensions($media);
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
   * Builds a deterministic cropped media label.
   */
  protected function buildCroppedMediaName(string $original_name): string {
    return trim($original_name) . ' cropped';
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
