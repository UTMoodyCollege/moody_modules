<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Provides a 'utexas_basic_page_source' source plugin.
 *
 * @MigrateSource(
 *   id = "utexas_basic_page_source",
 *   source_module="utexas_migrate"
 * )
 */
class BasicPageSource extends NodeSource {

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    // Query for body-specific fields.
    // @todo -- run the body content through a process plugin for media etc.
    $body = $this->select('field_data_body', 'body')
      ->fields('body', ['body_value', 'body_format'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    $row->setSourceProperty('body', [
      'value' => $body['body_value'],
      'format' => MigrateHelper::getDestinationTextFormat($body['body_format']),
    ]);
  }

}
