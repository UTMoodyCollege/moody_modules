<?php

namespace Drupal\utexas_migrate\CustomWidgets;

use Drupal\Core\Database\Database;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Convert D7 custom compound field to D8.
 */
class SocialLinks {

  /**
   * Prepare an array for saving a block.
   *
   * @param array $data
   *   The fields for the social link block.
   *
   * @return array
   *   An array of field data for the widget.
   */
  public static function createBlockDefinition(array $data) {
    $block_definition = [
      'type' => 'social_links',
      'info' => $data['field_identifier'],
      'field_utexas_sl_social_links' => [
        'headline' => $data['block_data'][0]['headline'],
        'social_account_links' => serialize($data['block_data'][0]['links']),
      ],
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
    $field_data = self::massageDataForD8($source_data);
    return $field_data;
  }

  /**
   * Query the source database for data.
   *
   * @param int $source_nid
   *   The node ID from the source data.
   *
   * @return array
   *   Returns an array of widget data
   */
  public static function getRawSourceData($source_nid) {
    // Get all instances from the legacy DB.
    Database::setActiveConnection('utexas_migrate');
    $source_data = Database::getConnection()->select('field_data_field_utexas_social_links', 'f')
      ->fields('f')
      ->condition('entity_id', $source_nid)
      ->execute()
      ->fetchAll();
    $output = [];
    foreach ($source_data as $delta => $item) {
      $output[$delta] = [
        'headline' => $item->field_utexas_social_links_headline,
        'links' => $item->field_utexas_social_links_links,
      ];
    }
    return $output;
  }

  /**
   * Get data in format for D8 custom field type.
   *
   * @param array $source_data
   *   A simple key-value array of subfield & value.
   *
   * @return array
   *   A simple key-value array.
   */
  protected static function massageDataForD8(array $source_data) {
    $destination = [];
    $allowed_providers = [
      'facebook',
      'flickr',
      'googleplus',
      'instagram',
      'linkedin',
      'pinterest',
      'reddit',
      'snapchat',
      'tumblr',
      'twitter',
      'vimeo',
      'youtube',
    ];
    foreach ($source_data as $delta => $instance) {
      $links = unserialize($instance['links']);
      foreach ($links as $provider => $link) {
        if ($link != '' && in_array(strtolower($provider), $allowed_providers)) {
          $prepared_links[] = [
            'social_account_url' => MigrateHelper::prepareLink($link),
            'social_account_name' => strtolower($provider),
          ];
        }
      }
      if (!empty($prepared_links)) {
        $destination[] = [
          'headline' => $instance['headline'],
          'links' => $prepared_links,
        ];
      }
    }
    return $destination;
  }

}
