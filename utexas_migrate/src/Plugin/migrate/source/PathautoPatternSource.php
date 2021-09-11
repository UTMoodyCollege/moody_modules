<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;

/**
 * Provides a 'utexas_pathauto_pattern_source' migrate source.
 *
 * This provides a base source plugin for migrating Pathauto patterns
 * from D7 into D8.
 *
 * @MigrateSource(
 *  id = "utexas_pathauto_pattern_source",
 *  source_module = "utexas_migrate"
 * )
 */
class PathautoPatternSource extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    // There are no theme settings defined.
    // Return a query object that will evaluate as count = 0.
    $query = $this->select('variable', 'v')
      ->fields('v', array_keys($this->fields()))
      ->condition('name', 'pathauto_node_pattern', '=');
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'name' => $this->t('Name'),
      'value' => $this->t('Value'),
    ];
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'name' => [
        'type' => 'string',
        'alias' => 'v',
      ],
    ];
  }

}
