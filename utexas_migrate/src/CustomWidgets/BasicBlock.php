<?php

namespace Drupal\utexas_migrate\CustomWidgets;

use Drupal\Core\Database\Database;
use Drupal\utexas_migrate\MigrateHelper;
use Drupal\Component\Utility\Html;
use Drupal\Core\Language\Language;


/**
 * Convert D7 custom compound field to D8 Inline blocks.
 */
class BasicBlock {

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
      'body' => [
        'value' => isset($data['block_data'][0]['value']) ? $data['block_data'][0]['value'] : '',
        'format' => isset($data['block_data'][0]['format']) ? $data['block_data'][0]['format'] : 'plain_text',
      ],
      'reusable' => FALSE,
    ];

    return $block_definition;
  }

  /**
   * Convert D7 data to D8 structure.
   *
   * @param string $instance
   *   Whether this is image_link_ 'a' or 'b'.
   * @param int $source_nid
   *   The node ID from the source data.
   *
   * @return array
   *   Returns an array of field data for the widget.
   */
  public static function getFromNid($instance, $source_nid) {
    $source_data = self::getSourceData($instance, $source_nid);
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
   *   Returns an array of Paragraph ID(s) of the widget
   */
  public static function getSourceData($instance, $source_nid) {
    // Get all instances from the legacy DB.
    Database::setActiveConnection('utexas_migrate');
    $source_data = Database::getConnection()->select('field_data_field_' . $instance, 'f')
      ->fields('f')
      ->condition('entity_id', $source_nid)
      ->execute()
      ->fetchAll();
    $prepared = [];
    foreach ($source_data as $delta => $field) {
      $prepared[$delta] = [
        'value' => $field->{'field_' . $instance . '_value'},
        'format' => $field->{'field_' . $instance . '_format'},
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
    foreach ($source as $delta => $instance) {
      $source[$delta]['format'] = MigrateHelper::prepareTextFormat($instance['format']);
      // Send to helper function to update standard classes.
      $source[$delta]['value'] = MigrateHelper::wysiwygTransformCssClasses($source[$delta]['value']);
      // Regex to find file entities. Returns array of file entities.
      preg_match_all('/\[\[{"fid"(.*)}}\]\]/', $source[$delta]['value'], $matches);
      foreach ($matches[0] as $key => $value) {
        $media_embed = MigrateHelper::transformMediaEmbed($value);
        $updated_source = str_replace($value, $media_embed, $source[$delta]['value']);
        $source[$delta]['value'] = $updated_source;
      }
      // Regex to find videos embedd with video_filter.
      preg_match_all('/\[video:(.*)\]/', $source[$delta]['value'], $matches);
      foreach ($matches[0] as $key => $value) {
        $media_embed = MigrateHelper::transformVideoFilterEmbed($value);
        $updated_source = str_replace($value, $media_embed, $source[$delta]['value']);
        $source[$delta]['value'] = $updated_source;
      }
    }
    return $source;
  }

}
