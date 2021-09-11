<?php

namespace Drupal\utexas_migrate\CustomWidgets;

use Drupal\Core\Database\Database;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Convert D7 custom compound field to D8 paragraph.
 */
class MoodyFlexTabs {

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
      'field_block_moody_flex_tabs' => $data['block_data'],
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
        'set_active' => $item->{'field_tabs_active'},
        'tab_title' => $item->{'field_tabs_title'},
        'copy_value' => $item->{'field_tabs_body'},
        'copy_format' => $item->{'field_tabs_format'},
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
      if (!empty($instance['set_active'])) {
        $destination[$delta]['set_active'] = $instance['set_active'];
      }
      if (!empty($instance['tab_title'])) {
        $destination[$delta]['tab_title'] = $instance['tab_title'];
      }
      if (!empty($instance['copy_value'])) {
        $markup = $instance['copy_value'];
        // Send to helper function to update standard classes.
        $markup = MigrateHelper::wysiwygTransformCssClasses($markup);
        // Regex to find file entities. Returns array of file entities.
        preg_match_all('/\[\[{"fid"(.*)}}\]\]/', $markup, $matches);
        foreach ($matches[0] as $key => $value) {
          $media_embed = MigrateHelper::transformMediaEmbed($value);
          $updated_source = str_replace($value, $media_embed, $markup);
          $markup = $updated_source;
        }
        // Regex to find videos embedd with video_filter.
        preg_match_all('/\[video:(.*)\]/', $markup, $matches);
        foreach ($matches[0] as $key => $value) {
          $media_embed = MigrateHelper::transformVideoFilterEmbed($value);
          $updated_source = str_replace($value, $media_embed, $markup);
          $markup = $updated_source;
        }
        $destination[$delta]['copy_value'] = $markup;
        $destination[$delta]['copy_format'] = 'flex_html';
      }
    }
    return $destination;
  }

}
