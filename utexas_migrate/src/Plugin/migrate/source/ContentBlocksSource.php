<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;

/**
 * Provides a 'utexas_content_blocks_source' migrate source.
 *
 * This provides a base source plugin for migrating Social Links field
 * from D7 into D8 Social Links blocks.
 *
 * @MigrateSource(
 *  id = "utexas_content_blocks_source",
 *  source_module = "utexas_migrate"
 * )
 */
class ContentBlocksSource extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('block_custom', 'b')
      ->fields('b', array_keys($this->fields()));
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'bid' => $this->t('Block ID'),
      'body' => $this->t('Body'),
      'info' => $this->t('Title'),
    ];
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'bid' => [
        'type' => 'integer',
        'alias' => 'b',
      ],
    ];
  }

}
