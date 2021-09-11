<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;

/**
 * A SQL-based source plugin, to retrieve category data from a source database.
 *
 * @MigrateSource(
 *   id = "utexas_terms_source",
 *   source_module = "utexas_migrate"
 * )
 */
class UtexasTerms extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $fields = [
      'categoryid',
      'type',
      'name',
      'details',
      'category_parent',
      'ordering',
    ];
    return $this->select('utexas_vocabularies', 'ut')
      ->fields('ut', $fields)
      // This sort assures that parents are saved before children.
      ->orderBy('category_parent', 'ASC');
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'categoryid' => $this->t('Unique ID of the category'),
      'type' => $this->t('Category type corresponding to Drupal vocabularies'),
      'name' => $this->t('Category name'),
      'details' => $this->t('Description of the category'),
      'category_parent' => $this->t('ID of the parent category'),
      'ordering' => $this->t('Order in which to display this category'),
    ];
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return ['categoryid' => ['type' => 'integer']];
  }

}
