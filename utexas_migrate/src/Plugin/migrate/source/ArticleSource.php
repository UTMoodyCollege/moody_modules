<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Provides a 'utexas_article_source' migrate source.
 *
 * This provides a base source plugin for migrating
 * article type nodes from Drupal 7.
 *
 * @MigrateSource(
 *  id = "utexas_article_source",
 *  source_module = "utexas_migrate"
 * )
 */
class ArticleSource extends NodeSource {

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    // Query for article-specific fields.
    $body = $this->select('field_data_body', 'body')
      ->fields('body', ['body_value', 'body_format'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    $row->setSourceProperty('body', [
      'value' => $body['body_value'],
      'format' => MigrateHelper::getDestinationTextFormat($body['body_format']),
    ]);

    $image = $this->select('field_data_field_image', 'i')
      ->fields('i', ['field_image_fid', 'field_image_alt', 'field_image_title'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    $destination_fid = MigrateHelper::getMediaIdFromFid($image['field_image_fid']);
    $row->setSourceProperty('field_image', [
      'target_id' => $destination_fid,
      'alt' => '@to be replaced with media reference',
    ]);

    $tags = $this->select('field_data_field_tags', 't')
      ->fields('t', ['field_tags_tid'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    $row->setSourceProperty('tag_list', $tags);

  }

}
