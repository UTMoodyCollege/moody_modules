<?php

namespace Drupal\utexas_migrate\CustomWidgets;

use Drupal\Core\Database\Database;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Convert D7 custom compound field to D8 inline block.
 */
class ImageLink {

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
    if (isset($data['block_data'][0])) {
      $block_definition = [
        'type' => 'utexas_image_link',
        'info' => $data['field_identifier'],
        'field_block_il' => [
          'image' => $data['block_data'][0]['image'],
          'link' => $data['block_data'][0]['link'],
        ],
        'reusable' => FALSE,
      ];
      return $block_definition;
    }
  }

  /**
   * Convert D7 data to D8 structure.
   *
   * @param string $instance
   *   The field identifier -- image_link_a or image_link_b.
   * @param int $source_nid
   *   The node ID from the source data.
   *
   * @return array
   *   Returns an array of field data for the widget.
   */
  public static function getFromNid($instance, $source_nid) {
    $source_data = self::getRawSourceData($instance, $source_nid);
    $field_data = self::massageFieldData($source_data);
    return $field_data;
  }

  /**
   * Query the source database for data.
   *
   * @param string $instance
   *   Whether this is image_link_ 'a' or 'b'.
   * @param int $source_nid
   *   The node ID from the source data.
   *
   * @return array
   *   Returns an array of IDs of the widget
   */
  public static function getRawSourceData($instance, $source_nid) {
    // Get all instances from the legacy DB.
    Database::setActiveConnection('utexas_migrate');
    $source_data = Database::getConnection()->select('field_data_field_utexas_' . $instance, 'f')
      ->fields('f')
      ->condition('entity_id', $source_nid)
      ->execute()
      ->fetchAll();
    $prepared = [];
    foreach ($source_data as $delta => $i) {
      $prepared[$delta] = [
        'image' => $i->{'field_utexas_' . $instance . '_image_fid'},
        'link' => $i->{'field_utexas_' . $instance . '_link_href'},
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
      $destination[$delta]['link'] = MigrateHelper::prepareLink($instance['link']);

      $destination[$delta]['image'] = $instance['image'] != 0 ? MigrateHelper::getMediaIdFromFid($instance['image']) : 0;
    }
    return $destination;
  }

}
