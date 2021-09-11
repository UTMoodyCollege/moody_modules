<?php

namespace Drupal\utexas_migrate\CustomWidgets;

use Drupal\Core\Database\Database;

/**
 * Convert D7 Views block to D8 Inline blocks.
 */
class ViewsBlock {

  /**
   * Convert D7 data to D8 structure.
   *
   * @param string $views_block
   *   Whether the Views block ID as defined in $includedViewsBlocks.
   *
   * @return array
   *   Returns an array of field data for the widget.
   */
  public static function getBlockData($views_block) {
    $source_data = self::getSourceData($views_block);
    // print_r('POOPPOOPPPOOPPOOPOOPOPPPOPPOOPPOPP' . PHP_EOL);
    // print_r($source_data . PHP_EOL);
    // print_r('POOPPOOPPPOOPPOOPOOPOPPPOPPOOPPOPP' . PHP_EOL);
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
  public static function getSourceData($views_block_id) {
    $data = [];
    // All views blocks in D7 displayed their label.
    $data['label'] = TRUE;

    switch ($views_block_id) {
      case 'views-news-news_with_thumbnails':
        $data['thumbnails'] = TRUE;
        $data['dates'] = TRUE;
        $data['summaries'] = FALSE;
        $data['count'] = self::getVariable('utexas_news_number_items_thumbnails') ?? 4;
        $data['title'] = self::getVariable('utexas_news_thumbnails_view_title') ?? 'Latest News';
        $data['block_type'] = 'utnews_article_listing';
        break;

      case 'views-news-news_titles_only':
        $data['thumbnails'] = FALSE;
        $data['dates'] = TRUE;
        $data['summaries'] = FALSE;
        $data['count'] = self::getVariable('utexas_news_number_items_titles') ?? 4;
        $data['title'] = self::getVariable('utexas_news_titles_view_title') ?? 'Latest News';
        $data['block_type'] = 'utnews_article_listing';
        break;

      case 'views-events-block_1':
        // Upcoming Events (teasers).
        $data['block_type'] = 'utevent_event_listing';
        $data['thumbnails'] = TRUE;
        $data['summaries'] = TRUE;
        $data['featured'] = 'all';
        $data['cta'] = TRUE;
        $data['count'] = self::getVariable('utexas_events_upcoming_block_count') ?? 5;
        $data['title'] = self::getVariable('utexas_events_upcoming_block_title') ?? 'Upcoming Events';
        break;

      case 'views-events-block_2':
        // Upcoming Events (titles).
        $data['thumbnails'] = FALSE;
        $data['summaries'] = FALSE;
        $data['featured'] = 'all';
        $data['cta'] = TRUE;
        $data['count'] = self::getVariable('utexas_events_upcoming_block_count') ?? 5;
        $data['title'] = self::getVariable('utexas_events_upcoming_block_title') ?? 'Upcoming Events';
        $data['block_type'] = 'utevent_event_listing';
        break;

      case 'views-events-block_3':
        // Featured Events (titles).
        $data['thumbnails'] = FALSE;
        $data['summaries'] = FALSE;
        $data['featured'] = 'featured';
        $data['cta'] = FALSE;
        $data['count'] = self::getVariable('utexas_events_featured_block_count') ?? 5;
        $data['title'] = self::getVariable('utexas_events_featured_block_title') ?? 'Featured Events';
        $data['block_type'] = 'utevent_event_listing';
        break;

      case 'views-events-block_4':
        // Featured Events (teasers).
        $data['thumbnails'] = TRUE;
        $data['summaries'] = TRUE;
        $data['featured'] = 'featured';
        $data['cta'] = FALSE;
        $data['count'] = self::getVariable('utexas_events_featured_block_count') ?? 5;
        $data['title'] = self::getVariable('utexas_events_featured_block_title') ?? 'Featured Events';
        $data['block_type'] = 'utevent_event_listing';
        break;

      case 'views-news-news_titles_only':
        $data['thumbnails'] = FALSE;
        $data['dates'] = TRUE;
        $data['summaries'] = FALSE;
        $data['count'] = self::getVariable('utexas_news_number_items_titles');
        $data['title'] = self::getVariable('utexas_news_titles_view_title');
        break;
    }
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
    switch ($data['block_type']) {
      case 'utnews_article_listing':
        // If no source variable has been defined, use the D7 defaults.
        $block_definition['field_utnews_display_count']['value'] = $data['block_data']['count'];
        $block_definition['field_utnews_display_dates']['value'] = $data['block_data']['dates'];
        $block_definition['field_utnews_display_summaries']['value'] = $data['block_data']['summaries'];
        $block_definition['field_utnews_display_thumbnails']['value'] = $data['block_data']['thumbnails'];
        break;

      case 'utevent_event_listing':
        // If no source variable has been defined, use the v2 defaults.
        $block_definition['field_utevent_display_count']['value'] = $data['block_data']['count'];
        $block_definition['field_utevent_limit_featured']['value'] = $data['block_data']['featured'];
        $block_definition['field_utevent_display_summaries']['value'] = $data['block_data']['summaries'];
        $block_definition['field_utevent_display_thumbnails']['value'] = $data['block_data']['thumbnails'];
        $block_definition['field_utevent_display_cta']['value'] = $data['block_data']['cta'];
        break;

    }
    return $block_definition;
  }

}
