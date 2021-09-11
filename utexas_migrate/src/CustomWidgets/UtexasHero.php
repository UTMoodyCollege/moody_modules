<?php

namespace Drupal\utexas_migrate\CustomWidgets;

use Drupal\Core\Database\Database;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Convert D7 custom compound field to D8 paragraph.
 */
class UtexasHero {

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
      'field_block_hero' => $data['block_data'],
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
  public static function getFromNid($instance, $source_nid) {
    $source_data = self::getRawSourceData($instance, $source_nid);
    $field_data = self::massageFieldData($source_data);
    return $field_data;
  }

  /**
   * Query the source database for data.
   *
   * @param string $instance
   *   The field name from the source site, without the 'utexas' portion
   *   of the string included.
   * @param int $source_nid
   *   The node ID from the source data.
   *
   * @return array
   *   Returns an array of source data.
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
    foreach ($source_data as $delta => $item) {
      $prepared[$delta] = [
        'type' => $item->bundle,
        'image_fid' => $item->field_utexas_hero_photo_image_fid,
        'caption' => $item->field_utexas_hero_photo_caption,
        'enable_image_styles' => $item->field_utexas_hero_photo_image_style,
        'display_style' => $item->field_utexas_hero_photo_hero_image_style,
        'position' => $item->field_utexas_hero_photo_hero_image_position,
        'photo_credit' => $item->field_utexas_hero_photo_credit,
        'subheading' => $item->field_utexas_hero_photo_subhead,
        'heading' => $item->field_utexas_hero_photo_subhead,
        'link_href' => $item->field_utexas_hero_photo_link_href,
        'link_title' => $item->field_utexas_hero_photo_link_text,
      ];
    }
    return $prepared;
  }

  /**
   * Return field data as an array.
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
      if (!empty($instance['photo_credit'])) {
        $destination[$delta]['credit'] = $instance['photo_credit'];
      }
      if (!empty($instance['caption'])) {
        $destination[$delta]['caption'] = $instance['caption'];
      }
      if (!empty($instance['enable_image_styles'])) {
        $destination[$delta]['disable_image_styles'] = 0;
      }
      else {
        $destination[$delta]['disable_image_styles'] = 1;
      }
      if (!empty($instance['subheading'])) {
        $destination[$delta]['subheading'] = $instance['subheading'];
      }
      if (!empty($instance['link_href'])) {
        $destination[$delta]['link_uri'] = MigrateHelper::prepareLink($instance['link_href']);
        $destination[$delta]['link_title'] = $instance['link_title'];
      }
      $destination[$delta]['media'] = $instance['image_fid'] != 0 ? MigrateHelper::getMediaIdFromFid($instance['image_fid']) : 0;
    }
    return $destination;
  }

}
