<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

/**
 * Provides a 'utexas_media_audio_source' migrate source.
 *
 * This provides a source plugin for migrating files
 * that can be referenced in subsequent migrations.
 *
 * @MigrateSource(
 *  id = "utexas_media_audio_source",
 *  source_module = "utexas_migrate"
 * )
 */
class AudioSource extends MediaSource {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = parent::query();
    // ImageSource should only map files with filemime of type "audio/".
    // This includes mp3.
    $query->condition('filemime', 'audio/%', 'LIKE');
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
