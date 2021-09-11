<?php

namespace Drupal\utexas_migrate\Plugin\migrate\destination;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Language\Language;
use Drupal\media\Entity\Media;
use Drupal\migrate\Plugin\migrate\destination\DestinationBase;
use Drupal\migrate\Plugin\MigrateDestinationInterface;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;

/**
 * Provides a 'utexas_media_video_destination' destination plugin.
 *
 * @MigrateDestination(
 *   id = "utexas_media_video_destination"
 * )
 */
class VideoDestination extends DestinationBase implements MigrateDestinationInterface {

  const YOUTUBE_SCHEME = 'youtube://';
  const YOUTUBE_BASE_URL = 'https://youtube.com/watch?';
  const VIMEO_SCHEME = 'vimeo://v/';
  const VIMEO_BASE_URL = 'https://vimeo.com/';

  /**
   * Import function that runs on each row.
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    $video_media = Media::create([
      'name' => $row->getSourceProperty('filename'),
      'bundle' => 'utexas_video_external',
      'uid' => $row->getDestinationProperty('uid'),
      'langcode' => Language::LANGCODE_NOT_SPECIFIED,
      'status' => '1',
      'field_media_oembed_video' => [
        'value' => $this->prepareUri($row->getSourceProperty('uri')),
      ],
      'created' => $row->getSourceProperty('timestamp'),
      'changed' => $row->getSourceProperty('timestamp'),
    ]);
    $video_media->save();
    return [$video_media->id()];
  }

  /**
   * Helper function to change video Uri format.
   */
  public function prepareUri($uri) {
    // Convert youtube scheme uri to video url.
    if (strpos($uri, static::YOUTUBE_SCHEME) !== FALSE) {
      $uri = static::YOUTUBE_BASE_URL . implode('=', explode('/', str_replace(static::YOUTUBE_SCHEME, '', $uri), 2));
    }
    // Convert vimeo scheme uri to video url.
    elseif (strpos($uri, static::VIMEO_SCHEME) !== FALSE) {
      $uri = str_replace(static::VIMEO_SCHEME, static::VIMEO_BASE_URL, $uri);
    }
    return $uri;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'id' => [
        'type' => 'integer',
        'unsigned' => FALSE,
        'size' => 'big',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function supportsRollback() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function rollbackAction() {
    return MigrateIdMapInterface::ROLLBACK_DELETE;
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    try {
      $entity = Media::load($destination_identifier['id']);
      if ($entity != NULL) {
        $entity->delete();
      }
    }
    catch (EntityStorageException $e) {
      \Drupal::logger('utexas_migrate')->warning("Rollback of node with nid of :nid failed: :error - Code: :code", [
        ':nid' => $destination_identifier['id'],
        ':error' => $e->getMessage(),
        ':code' => $e->getCode(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fields(MigrationInterface $migration = NULL) {
    // Not needed; must be implemented to respect MigrateDestinationInterface.
  }

}
