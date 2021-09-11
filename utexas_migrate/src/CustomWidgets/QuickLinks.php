<?php

namespace Drupal\utexas_migrate\CustomWidgets;

use Drupal\Core\Database\Database;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Convert D7 custom compound field to D8 paragraph.
 */
class QuickLinks {

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
      'type' => 'utexas_quick_links',
      'info' => $data['field_identifier'],
      'field_block_ql' => $data['block_data'],
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
    $source_data = Database::getConnection()->select('field_data_field_utexas_quick_links', 'f')
      ->fields('f')
      ->condition('entity_id', $source_nid)
      ->execute()
      ->fetchAll();
    $prepared = [];
    foreach ($source_data as $delta => $item) {
      $prepared[$delta] = [
        'headline' => $item->{'field_utexas_quick_links_headline'},
        'copy' => $item->{'field_utexas_quick_links_copy_value'},
        'links' => $item->{'field_utexas_quick_links_links'},
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
    // Technically, there should only ever be one delta for Quick Links.
    // See explanation in getQuickLinksSource().
    $instances = [];
    foreach ($source as $delta => $instance) {
      $instances[$delta]['headline'] = $instance['headline'];
      $instances[$delta]['copy_value'] = $instance['copy'];
      $instances[$delta]['copy_format'] = 'restricted_html';

      $links = unserialize($instance['links']);
      if (!empty($links)) {
        foreach ($links as $i => $link) {
          $prepared_links[] = [
            'url' => MigrateHelper::prepareLink($link['link_url']),
            'title' => $link['link_title'],
          ];
        }
        $instances[$delta]['links'] = serialize($prepared_links);
      }
    }

    return $instances;
  }

}
