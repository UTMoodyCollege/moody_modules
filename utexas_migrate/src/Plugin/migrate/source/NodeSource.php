<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;

/**
 * Provides a 'utexas_node_source' migrate source.
 *
 * This provides a base source plugin for migrating
 * node types from UTDK 7.
 *
 * @MigrateSource(
 *  id = "utexas_migrate_node_source",
 *  source_module = "utexas_migrate"
 * )
 */
class NodeSource extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('node', 'n');
    $query->fields('n', array_keys($this->fields()));

    if (isset($this->configuration['node_type'])) {
      // Use the migration's .yml file's 'node_type' declaration
      // To filter nodes by bundle.
      $query->condition('n.type', $this->configuration['node_type'], 'IN');
    }
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'title' => $this->t('Title'),
      'nid' => $this->t('Node ID'),
      'vid' => $this->t('Vid'),
      'type' => $this->t('Type'),
      'language' => $this->t('Language'),
      'created' => $this->t('Created'),
      'changed' => $this->t('Changed'),
      'status' => $this->t('Status'),
      'uid' => $this->t('Author'),
      'sticky' => $this->t('Sticky'),
      'promote' => $this->t('Promote'),
      'show_breadcrumb' => $this->t('Show breadcrumb'),
    ];
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'nid' => [
        'type' => 'integer',
        'alias' => 'n',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $show_breadcrumb = $row->getSourceProperty('show_breadcrumb');
    if ($show_breadcrumb != NULL) {
      $source_type = $this->configuration['node_type'];
      // Unlikely edge case where breadcrumb display has not been set.
      if ($show_breadcrumb === NULL && in_array($source_type, ['landing_page', 'standard_page'])) {
        $type = $row->getSourceProperty('type');
        // Check what the default value for the content type is.
        $default_display = \Drupal::config('utexas_breadcrumbs_visibility.content_type.utexas_flex_page')->get('display_breadcrumbs');
        // If a node-type default is set, use it.
        if ($default_display !== NULL) {
          $row->setSourceProperty('show_breadcrumb', $default_display);
        }
      }
    }
    return parent::prepareRow($row);
  }

}
