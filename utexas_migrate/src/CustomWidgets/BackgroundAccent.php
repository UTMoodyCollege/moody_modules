<?php

namespace Drupal\utexas_migrate\CustomWidgets;

use Drupal\Core\Database\Database;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Retrieve information about D7 background accents.
 */
class BackgroundAccent {

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
  public static function getFromNid($source_nid) {
    $source_data = self::getRawSourceData($source_nid);
    $field_data = self::massageFieldData($source_data);
    return $field_data;
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
    $source_data = Database::getConnection()->select('field_data_field_utexas_background_accent', 'f')
      ->fields('f')
      ->condition('entity_id', $source_nid)
      ->execute()
      ->fetchAll();
    $prepared = [];
    foreach ($source_data as $delta => $i) {
      $prepared[$delta] = [
        'image' => $i->{'field_utexas_background_accent_image_fid'},
        'blur' => $i->{'field_utexas_background_accent_blur'},
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
    if (!empty($source)) {
      $destination['blur'] = $source[0]['blur'];
      $destination['image'] = $source[0]['image'] != 0 ? MigrateHelper::getMediaIdFromFid($source[0]['image']) : 0;
    }
    return $destination;
  }

}
