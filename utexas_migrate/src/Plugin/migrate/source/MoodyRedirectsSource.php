<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\path\Plugin\migrate\source\d7\UrlAlias;

/**
 * Provides a 'moody_redirects_source' migrate source.
 *
 * This provides a base source plugin for migrating
 * article type nodes from Drupal 7.
 *
 * @MigrateSource(
 *  id = "moody_redirects_source",
 *  source_module = "node"
 * )
 */
class MoodyRedirectsSource extends UrlAlias {

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Get the database query from the UrlAlias class.
    $query = parent::query();

    // Add our condition to filter for only node paths.
    $query->condition('ua.source', 'node/%', 'LIKE');

    // Return the modified query.
    return $query;

  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    // Get the source field from the row.
    $source = $row->getSourceProperty('source');

    // If it matches node/nodeID...
    if (preg_match('/node\/[0-9]+/', $source)) {
      // Get the node ID from the string.
      $nid = substr($source, 5);

      // Provide it to the migration as the "nid" field.
      $row->setSourceProperty('nid', $nid);
    }

    // Return the result.
    return parent::prepareRow($row);
  }

}
