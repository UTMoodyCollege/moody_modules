<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;

/**
 * Query metatags in Drupal 7 database and prepare them..
 *
 * @MigrateSource(
 *   id = "flex_page_metatags_source",
 *   source_module = "utexas_migrate"
 * )
 */
class MetatagsSource extends NodeSource {

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Inherit SQL joins from NodeSource.
    $query = parent::query();
    // Add joins, as necessary, per each field you want to migrate.
    // For fields with multiple deltas, do a separate query in a callback.
    $query->leftJoin('metatag', 'metatag', 'metatag.revision_id = n.vid');

    // We limit this to D7 node types which have these fields.
    $query->condition('type', ['landing_page', 'standard_page'], 'IN');

    // Identify what source column(s) to return.
    $query->fields('metatag');
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'vid' => [
        'type' => 'integer',
        'alias' => 'n',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    // In D7, the metatags are stored as a serialized array like
    // the following:
    // a:4:{s:5:"title";a:1:{s:5:"value";s:19:"Here is an override";}s:11:"description";a:1:{s:5:"value";s:22:"Here is my description";}s:8:"abstract";a:1:{s:5:"value";s:19:"here is my abstract";}s:8:"keywords";a:1:{s:5:"value";s:7:"keyword";}}
    $tag_map = [
      "geo.placename" => "geo_placename",
      "geo.position" => "geo_position",
      "geo.region" => "geo_region",
      "canonical" => "canonical_url",
      "content-language" => "content_language",
      "original-source" => "original_source",
    ];
    $metatags = unserialize($row->getSourceProperty('data'));
    if (!empty($metatags)) {
      $d8_metatags = [];
      foreach ($metatags as $tag => $value) {
        // In D8, the format needs to be:
        // a:1:{s:5:"title";s:3:"foo";}
        if (in_array($tag, array_keys($tag_map))) {
          $tag = $tag_map[$tag];
        }
        $d8_metatags[$tag] = $value['value'];
      }
      $row->setSourceProperty('metatags', $d8_metatags);
    }
    return parent::prepareRow($row);
  }

}
