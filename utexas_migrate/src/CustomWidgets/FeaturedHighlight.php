<?php

namespace Drupal\utexas_migrate\CustomWidgets;

use Drupal\Core\Database\Database;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Convert D7 custom compound field to D8.
 */
class FeaturedHighlight {

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
      'field_block_featured_highlight' => $data['block_data'],
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
   *   Returns an array of the D7 widget data.
   */
  public static function getRawSourceData($source_nid) {
    // Get all instances from the legacy DB.
    Database::setActiveConnection('utexas_migrate');
    $source_data = Database::getConnection()->select('field_data_field_utexas_featured_highlight', 'f')
      ->fields('f')
      ->condition('entity_id', $source_nid)
      ->execute()
      ->fetchAll();
    $prepared = [];
    foreach ($source_data as $delta => $item) {
      $prepared[$delta] = [
        'type' => $item->bundle,
        'image_fid' => $item->field_utexas_featured_highlight_image_fid,
        'date' => $item->field_utexas_featured_highlight_date,
        'headline' => $item->field_utexas_featured_highlight_headline,
        'copy' => $item->field_utexas_featured_highlight_copy_value,
        'link_href' => $item->field_utexas_featured_highlight_link,
        'link_title' => $item->field_utexas_featured_highlight_cta,
        'style' => $item->field_utexas_featured_highlight_highlight_style,
      ];
    }
    return $prepared;
  }

  /**
   * Save data as paragraph(s) & return the paragraph ID(s)
   *
   * @param array $source
   *   A simple key-value array of subfield & value.
   *
   * @return array
   *   A simple key-value array returned the metadata about the paragraph.
   */
  protected static function massageFieldData(array $source) {
    $destination = [];
    $style_map = [
      'light' => 'default',
      'navy' => 'utexas_featured_highlight_2',
      'dark' => 'utexas_featured_highlight_3',
    ];
    foreach ($source as $delta => $instance) {
      $destination[$delta]['media'] = $instance['image_fid'] != 0 ? MigrateHelper::getMediaIdFromFid($instance['image_fid']) : 0;
      if (!empty($instance['link_href'])) {
        $destination[$delta]['link_uri'] = MigrateHelper::prepareLink($instance['link_href']);
        $destination[$delta]['link_text'] = $instance['link_title'];
      }
      if (!empty($instance['copy'])) {
        $destination[$delta]['copy_value'] = $instance['copy'];
        $destination[$delta]['copy_format'] = 'flex_html';
      }
      if (!empty($instance['headline'])) {
        $destination[$delta]['headline'] = $instance['headline'];
      }
      if ($instance['date'] != 0) {
        $destination[$delta]['date'] = $instance['date'];
      }
      $destination[$delta]['view_mode'] = $style_map{$source[0]['style']} ?? 'utexas_featured_highlight_2';
    }
    return $destination;
  }

}
