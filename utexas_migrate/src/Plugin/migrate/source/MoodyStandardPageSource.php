<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;

/**
 * Provides a 'moody_standard_page_source' migrate source.
 *
 * This provides a base source plugin for migrating
 * article type nodes from Drupal 7.
 *
 * @MigrateSource(
 *  id = "moody_standard_page_source",
 *  source_module = "utexas_migrate"
 * )
 */
class MoodyStandardPageSource extends NodeSource {

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {

    // URL generator.
    $url_generator = $this->select('field_data_field_moody_url_generator', 't')
      ->fields('t', ['field_moody_url_generator_tid'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    if ($url_generator != '') {
      $row->setSourceProperty('url_generator', $url_generator);
    }

  }

}
