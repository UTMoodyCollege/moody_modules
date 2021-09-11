<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;

/**
 * Retrieve the "pathauto_state" values & the source alias.
 *
 * @MigrateSource(
 *   id = "utexas_path_alias_source",
 *   source_module = "utexas_migrate"
 * )
 */
class PathAliasSource extends NodeSource {

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Inherit SQL joins from NodeSource.
    $query = parent::query();
    // The "pathauto_state" table in D7 records nodes with pathauto settings
    // that *differ* from the node type default. So, this table will either have
    // a value of "0" or "1" or will not have a row for the node (if it doesn't)
    // differ from the node_type default. A value of "0" means "NO PATHAUTO". A
    // value of "1" means "YES PATHAUTO".
    $query->leftJoin('pathauto_state', 'pathauto', 'pathauto.entity_id = n.nid');
    $query->fields('pathauto', ['pathauto']);
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $source_nid = $row->getSourceProperty('nid');
    $alias = $this->select('url_alias', 'ua')
      ->fields('ua', ['alias'])
      ->condition('source', 'node/' . $source_nid)
      ->execute()
      ->fetchField();
    $row->setSourceProperty('alias', '/' . $alias);
    return parent::prepareRow($row);
  }

}
