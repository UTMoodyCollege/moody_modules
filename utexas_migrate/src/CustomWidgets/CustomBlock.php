<?php

namespace Drupal\utexas_migrate\CustomWidgets;

use Drupal\Core\Database\Database;

/**
 * Convert D7 custom blocks to D8 Inline blocks.
 */
class CustomBlock {

  /**
   * Convert D7 data to D8 structure.
   *
   * @return array
   *   Returns an array of field data for the widget.
   */
  public static function getBlockData($views_block, $row) {
    // print_r('CUCKOOBANANPANTSCUCKOOBANANPANTS' . PHP_EOL);
    // print_r($row . PHP_EOL);
    // print_r('CUCKOOBANANPANTSCUCKOOBANANPANTS' . PHP_EOL);
    $source_data = self::getSourceData($views_block, $row);
    return $source_data;
  }

  /**
   * Query the source database for data.
   *
   * @param string $views_block_id
   *   The Views block ID as defined in the source site.
   *
   * @return array
   *   Returns contextual data about the Views block being used.
   */
  public static function getSourceData($views_block_id, $row) {
    $data = [];
    // All views blocks in D7 displayed their label.
    $data['label'] = TRUE;
    $data['id'] = $views_block_id;
    // print_r('!!!!!!!!!!!!!!!!!' . PHP_EOL);
    // print_r($row . PHP_EOL);
    // print_r('!!!!!!!!!!!!!!!!!' . PHP_EOL);

    // switch ($views_block_id) {
    //   case 'views-news-news_with_thumbnails':
    //     $data['thumbnails'] = TRUE;
    //     $data['dates'] = TRUE;
    //     $data['summaries'] = FALSE;
    //     $data['count'] = self::getVariable('utexas_news_number_items_thumbnails') ?? 4;
    //     $data['title'] = self::getVariable('utexas_news_thumbnails_view_title') ?? 'Latest News';
    //     $data['block_type'] = 'utnews_article_listing';
    //     break;

    // }
    return $data;
  }

  /**
   * Helper function for DB queries.
   *
   * @return array
   *   The unserialized value.
   */
  public static function getVariable($name) {
    Database::setActiveConnection('utexas_migrate');
    $query = Database::getConnection()->select('variable', 'v')
      ->fields('v', ['value'])
      ->condition('name', $name, '=')
      ->execute()
      ->fetch();
    return unserialize($query->value);
  }

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
      'reusable' => FALSE,
    ];

    return $block_definition;
  }

}
