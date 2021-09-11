<?php

namespace Drupal\utexas_migrate\CustomWidgets;

use Drupal\Core\Database\Database;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Convert D7 custom compound field to D8 field.
 */
class Resource {

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
      'type' => 'utexas_resources',
      'info' => $data['field_identifier'],
      'field_block_resources' => $data['block_data'],
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
   *   Returns an array of custom compound field data.
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
   *   Returns a prepared array of D8 custom compound field data.
   */
  public static function getRawSourceData($source_nid) {
    // Get all instances from the legacy DB.
    Database::setActiveConnection('utexas_migrate');
    $source_data = Database::getConnection()->select('field_data_field_utexas_resource', 'f')
      ->fields('f')
      ->condition('entity_id', $source_nid)
      ->execute()
      ->fetchAll();
    $prepared = [];
    foreach ($source_data as $delta => $item) {
      $prepared[$delta] = [
        'title' => $item->{'field_utexas_resource_title'},
        'headline' => $item->{'field_utexas_resource_headline'},
        'image_fid' => $item->{'field_utexas_resource_image_fid'},
        'links' => $item->{'field_utexas_resource_links'},
      ];
    }
    return $prepared;
  }

  /**
   * Rearrange schema as needed from D7 to D8.
   *
   * @param array $source
   *   A simple key-value array of subfield & value.
   *
   * @return array
   *   A simple key-value array of D8 field data.
   */
  protected static function massageFieldData(array $source) {
    $destination = [];
    if (isset($source[0]['title'])) {
      $destination['headline'] = $source[0]['title'];
    }
    $items = [];
    foreach ($source as $delta => $instance) {
      if (isset($instance['headline'])) {
        $items[$delta]['item']['headline'] = $instance['headline'];
      }
      if ($instance['image_fid'] != 0) {
        $destination_mid = MigrateHelper::getMediaIdFromFid($instance['image_fid']);
        $items[$delta]['item']['image'] = $destination_mid;
      }
      else {
        $items[$delta]['item']['image'] = 0;
      }
      $links = unserialize($instance['links']);
      if (!empty($links)) {
        $prepared_links = [];
        foreach ($links as $i => $link) {
          $prepared_links[] = [
            'uri' => MigrateHelper::prepareLink($link['link_url']),
            'title' => $link['link_title'],
          ];
        }
        if (!empty($prepared_links)) {
          $items[$delta]['item']['links'] = $prepared_links;
        }
      }
    }
    if (!empty($items)) {
      $destination['resource_items'] = serialize($items);
    }
    return $destination;
  }

}
