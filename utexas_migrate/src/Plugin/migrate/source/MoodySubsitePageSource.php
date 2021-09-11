<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Provides a 'moody_subsite_page_source' migrate source.
 *
 * This provides a base source plugin for migrating
 * article type nodes from Drupal 7.
 *
 * @MigrateSource(
 *  id = "moody_subsite_page_source",
 *  source_module = "utexas_migrate"
 * )
 */
class MoodySubsitePageSource extends NodeSource {

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

    // Subtitle.
    $subtitle = $this->select('field_data_field_subtitle', 't')
      ->fields('t', ['field_subtitle_value'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    if ($subtitle != '') {
      $row->setSourceProperty('subtitle', $subtitle);
    }

    // Subsite hero photo.
    $subsite_hero = $this->select('field_data_field_utexas_hero_photo', 'h')
      ->fields('h', ['field_utexas_hero_photo_image_fid'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->condition('bundle', 'moody_subsite_page')
      ->execute()
      ->fetchCol();
    if (isset($subsite_hero['0'])) {
      $mid = MigrateHelper::getMediaIdFromFid($subsite_hero['0']);
      $row->setSourceProperty('subsite_hero', $mid);
    }

  }

}
