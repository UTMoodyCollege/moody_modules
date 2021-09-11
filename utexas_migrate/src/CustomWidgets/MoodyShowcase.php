<?php

namespace Drupal\utexas_migrate\CustomWidgets;

use Drupal\Core\Database\Database;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Convert D7 custom compound field to D8 paragraph.
 */
class MoodyShowcase {

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
      'type' => $data['block_type'],
      'info' => $data['field_identifier'],
      'field_block_moody_showcase' => $data['block_data'],
      'reusable' => FALSE,
    ];
    return $block_definition;
  }

  /**
   * Convert D7 data to D8 structure.
   *
   * @param string $instance
   *   Will be: moody_showcase.
   * @param int $source_nid
   *   The node ID from the source data.
   *
   * @return array
   *   Returns an array of field data for the widget.
   */
  public static function getFromNid($instance, $source_nid) {
    $source_data = self::getRawSourceData($instance, $source_nid);
    return self::massageFieldData($source_data);
  }

  /**
   * Query the source database for data.
   *
   * @param string $instance
   *   Whether this is flex_content_area_ 'a' or 'b'.
   * @param int $source_nid
   *   The node ID from the source data.
   *
   * @return array
   *   Returns an array of Paragraph ID(s) of the widget
   */
  public static function getRawSourceData($instance, $source_nid) {
    // Get all instances from the legacy DB.
    Database::setActiveConnection('utexas_migrate');
    $source_data = Database::getConnection()->select('field_data_field_' . $instance, 'ms')
      ->fields('ms')
      ->condition('entity_id', $source_nid)
      ->execute()
      ->fetchAll();
    $prepared = [];
    foreach ($source_data as $delta => $item) {
      $prepared[$delta] = [
        'headline' => $item->{'field_' . $instance . '_headline'},
        'image_fid' => $item->{'field_' . $instance . '_image_fid'},
        'copy' => $item->{'field_' . $instance . '_copy_value'},
        'cta_title' => $item->{'field_' . $instance . '_cta_title'},
        'cta_uri' => $item->{'field_' . $instance . '_cta_link'},
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
      if (!empty($instance['copy'])) {
        $destination[$delta]['copy_value'] = $instance['copy'];
        $destination[$delta]['copy_format'] = 'flex_html';
      }
      if (!empty($instance['cta_uri'])) {
        $destination[$delta]['link_uri'] = MigrateHelper::prepareLink($instance['cta_uri']);
        $destination[$delta]['link_title'] = $instance['cta_title'];
      }
    }
    return $destination;
  }

}
