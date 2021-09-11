<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

/**
 * Provides a 'utexas_media_image_source' migrate source.
 *
 * This provides a source plugin for migrating files
 * that can be referenced in subsequent migrations.
 *
 * @MigrateSource(
 *  id = "utexas_media_image_source",
 *  source_module = "utexas_migrate"
 * )
 */
class ImageSource extends MediaSource {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = parent::query();
    // ImageSource should only map files with filemime of type "image/".
    $query->condition('filemime', 'image/%', 'LIKE');

    // Map alt & title fields from Standard UT Drupal Kit image file entity.
    $query->leftJoin('field_data_field_file_image_alt_text', 'field_file_image_alt_text', 'field_file_image_alt_text.entity_id = f.fid');
    $query->fields('field_file_image_alt_text');
    $query->leftJoin('field_data_field_file_image_title_text', 'field_file_image_title_text', 'field_file_image_title_text.entity_id = f.fid');
    $query->fields('field_file_image_title_text');
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
