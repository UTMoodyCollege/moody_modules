<?php

namespace Drupal\utexas_migrate\CustomWidgets;

use Drupal\Core\Database\Database;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Convert D7 custom compound field to D8 field type.
 *
 * @see doc/decisions/0002-migration-processors-for-custom-components.md
 */
class PromoUnits {

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
      'type' => 'utexas_promo_unit',
      'info' => $data['field_identifier'],
      'field_block_pu' => $data['block_data'],
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
   *   Returns an array for the field type
   */
  public static function getRawSourceData($source_nid) {
    // Get all instances from the legacy DB.
    Database::setActiveConnection('utexas_migrate');
    $source_data = Database::getConnection()->select('field_data_field_utexas_promo_units', 'f')
      ->fields('f')
      ->condition('entity_id', $source_nid)
      ->execute()
      ->fetchAll();
    $prepared = [];
    foreach ($source_data as $delta => $item) {
      $prepared[$delta] = [
        'title' => $item->{'field_utexas_promo_units_title'},
        'headline' => $item->{'field_utexas_promo_units_headline'},
        'image_fid' => $item->{'field_utexas_promo_units_image_fid'},
        'copy' => $item->{'field_utexas_promo_units_copy_value'},
        'cta_title' => $item->{'field_utexas_promo_units_cta'},
        'cta_uri' => $item->{'field_utexas_promo_units_link'},
        'size_option' => $item->{'field_utexas_promo_units_size_option'},
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
        $tmp_headline = $instance['headline'];
        $find = ["<i>", "</i>", "<b>", "</b>", "<em>", "</em>", "<strong>", "</strong>"];
        $replace = ["", "", "", "", "", "", "", ""];
        $headline = str_replace($find, $replace, $tmp_headline);
        $items[$delta]['item']['headline'] = $headline;
      }
      $items[$delta]['item']['image'] = $instance['image_fid'] != 0 ? MigrateHelper::getMediaIdFromFid($instance['image_fid']) : 0;
      if (isset($instance['copy'])) {
        $items[$delta]['item']['copy']['value'] = $instance['copy'];
        $items[$delta]['item']['copy']['format'] = 'flex_html';
      }
      $items[$delta]['item']['link']['uri'] = MigrateHelper::prepareLink($instance['cta_uri']);
      $items[$delta]['item']['link']['title'] = $instance['cta_title'] != "" ? $instance['cta_title'] : 'Read story';
    }
    if (!empty($items)) {
      $destination['promo_unit_items'] = serialize($items);
    }
    $style_map = [
      'utexas_promo_unit_landscape_image' => 'default',
      'utexas_promo_unit_portrait_image' => 'utexas_promo_unit_2',
      'utexas_promo_unit_square_image' => 'utexas_promo_unit_3',
      'utexas_promo_unit_no_image' => 'default',
    ];
    if (!empty($source[0]['size_option'])) {
      $style = $source[0]['size_option'];
      $destination[0]['view_mode'] = $style_map[$style];
    }
    // Per https://github.austin.utexas.edu/eis1-wcs/utdk_profile/issues/1176,
    // Promo Units need to retain 1 item per row behavior.
    $destination['additional'] = [
      'layout_builder_styles_style' => [
        'utexas_onecol' => 'utexas_onecol',
      ],
    ];
    return $destination;
  }

}
