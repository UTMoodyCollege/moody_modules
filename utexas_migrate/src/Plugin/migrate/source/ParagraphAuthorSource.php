<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Provides a 'moody_paragraph_author_source' migrate source.
 *
 * This provides a base source plugin for migrating
 * article type nodes from Drupal 7.
 *
 * @MigrateSource(
 *  id = "moody_paragraph_author_source",
 *  source_module = "utexas_migrate"
 * )
 */
class ParagraphAuthorSource extends NodeSource {

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {

    // URL generator.
    $feature_page_author = $this->select('field_data_field_credit', 't')
      ->fields('t', ['entity_id', 'revision_id', 'field_credit_credits'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    $data = unserialize($feature_page_author['field_credit_credits']);
    $first_name = $data[0]['credit_first_name'];
    $last_name = $data[0]['credit_last_name'];
    $title = $data[0]['credit_author_title'];
    // $eid = $feature_page_author['entity_id'];
    // $rid = $feature_page_author['revision_id'];
    if ($feature_page_author != '') {
      $row->setSourceProperty('field_author_first_name', $first_name);
      $row->setSourceProperty('field_author_last_name', $last_name);
      $row->setSourceProperty('field_author_title', $title);
      // $row->setSourceProperty('entity_id', $eid);
      // $row->setSourceProperty('revision_id', $rid);
    }

  }

}
