<?php

namespace Drupal\utexas_migrate\CustomWidgets;

use Drupal\Core\Database\Database;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Convert D7 custom compound field to D8.
 */
class PhotoContentArea {

  /**
   * Prepare an array for saving a block.
   *
   * @param array $data
   *   The D7 fields.
   *
   * @return array
   *   D8 block format.
   */
  public static function createBlockDefinition(array $data) {
    $block_definition = [
      'type' => 'utexas_photo_content_area',
      'info' => $data['field_identifier'],
      'field_block_pca' => $data['block_data'],
      'reusable' => FALSE,
    ];
    return $block_definition;
  }

  /**
   * Convert D7 data to D8 structure.
   *
   * @param int $source_nid
   *   The node ID from the source data.
   *
   * @return array
   *   Returns an array of field data for the widget.
   */
  public static function getFromNid($source_nid) {
    $source_data = self::getRawSourceData($source_nid);
    return self::massageFieldData($source_data);
  }

  /**
   * Query the source database for data.
   *
   * @param int $source_nid
   *   The node ID from the source data.
   *
   * @return array
   *   Returns an array of IDs of the widget
   */
  public static function getRawSourceData($source_nid) {
    // Get all instances from the legacy DB.
    Database::setActiveConnection('utexas_migrate');
    $source_data = Database::getConnection()->select('field_data_field_utexas_photo_content_area', 'f')
      ->fields('f')
      ->condition('entity_id', $source_nid)
      ->execute()
      ->fetchAll();
    $prepared = [];
    foreach ($source_data as $delta => $item) {
      $prepared[$delta] = [
        'headline' => $item->{'field_utexas_photo_content_area_headline'},
        'image_fid' => $item->{'field_utexas_photo_content_area_image_fid'},
        'copy' => $item->{'field_utexas_photo_content_area_copy_value'},
        'links' => $item->{'field_utexas_photo_content_area_links'},
        'credit' => $item->{'field_utexas_photo_content_area_credit'},
      ];
    }
    return $prepared;
  }

  /**
   * Rearrange data as necessary for destination import.
   *
   * @param array $source
   *   A simple key-value array of subfield & value.
   *
   * @return array
   *   A simple key-value array returned the metadata about the field.
   */
  protected static function massageFieldData(array $source) {
    $destination = [];
    foreach ($source as $delta => $instance) {
      $destination[$delta]['image'] = $instance['image_fid'] != 0 ? MigrateHelper::getMediaIdFromFid($instance['image_fid']) : 0;
      if (!empty($instance['headline'])) {
        $destination[$delta]['headline'] = $instance['headline'];
      }
      if (!empty($instance['credit'])) {
        $destination[$delta]['photo_credit'] = $instance['credit'];
      }
      if (!empty($instance['copy'])) {
        $destination[$delta]['copy_value'] = $instance['copy'];
        $destination[$delta]['copy_format'] = 'flex_html';
      }
      $links = unserialize($instance['links']);
      if (!empty($links)) {
        $prepared_links = [];
        foreach ($links as $i => $link) {
          $prepared_links[] = [
            'url' => MigrateHelper::prepareLink($link['link_url']),
            'title' => $link['link_title'],
          ];
        }
        if (!empty($prepared_links)) {
          $destination[$delta]['links'] = serialize($prepared_links);
        }
      }
    }
    return $destination;
  }

}
