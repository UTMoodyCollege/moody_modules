<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;

/**
 * Provides a 'utexas_media_source' migrate source.
 *
 * This provides a base source plugin for migrating
 * files from D7 into D8 media entities.
 */
abstract class MediaSource extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('file_managed', 'f')
      ->fields('f', array_keys($this->fields()));
    $query->orConditionGroup()
      ->condition('uri', 'public://%', 'LIKE');
    $query->orConditionGroup()
      ->condition('uri', 'private://%', 'LIKE');
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'fid' => $this->t('File ID'),
      'uid' => $this->t('User ID'),
      'filename' => $this->t('Vid'),
      'uri' => $this->t('Type'),
      'filemime' => $this->t('MIME type'),
      'filesize' => $this->t('Filesize'),
      'status' => $this->t('Status'),
      'timestamp' => $this->t('Timestamp'),
      'type' => $this->t('Type'),
    ];
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'fid' => [
        'type' => 'integer',
        'alias' => 'f',
      ],
    ];
  }

}
