<?php

namespace Drupal\moody_page_launch\Plugin\EntityReferenceSelection;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\Plugin\EntityReferenceSelection\NodeSelection;

/**
 * Finds launchable pages by title, node ID, or active URL alias.
 */
#[EntityReferenceSelection(
  id: 'moody_page_launch:node_alias',
  label: new TranslatableMarkup('Moody page title and alias selection'),
  group: 'moody_page_launch',
  weight: 0,
  entity_types: ['node'],
)]
final class PageAliasSelection extends NodeSelection {

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    $query = parent::buildEntityQuery(NULL, $match_operator);
    if (!isset($match) || trim($match) === '') {
      return $query;
    }

    $alias_query = $this->entityTypeManager->getStorage('path_alias')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1);
    $alias_conditions = $alias_query->orConditionGroup();
    foreach ($this->aliasMatchTerms($match) as $term) {
      $alias_conditions->condition('alias', $term, $match_operator);
    }
    $alias_ids = $alias_query->condition($alias_conditions)->execute();

    $node_ids = [];
    foreach ($this->entityTypeManager->getStorage('path_alias')->loadMultiple($alias_ids) as $alias) {
      if (preg_match('@^/node/(\d+)$@', $alias->getPath(), $matches)) {
        $node_ids[] = (int) $matches[1];
      }
    }

    $conditions = $query->orConditionGroup()
      ->condition('title', $match, $match_operator);
    if ($node_ids) {
      $conditions->condition('nid', array_unique($node_ids), 'IN');
    }
    if (ctype_digit(trim($match))) {
      $conditions->condition('nid', (int) trim($match));
    }
    return $query->condition($conditions);
  }

  /**
   * {@inheritdoc}
   */
  public function getReferenceableEntities($match = NULL, $match_operator = 'CONTAINS', $limit = 0) {
    $options = parent::getReferenceableEntities($match, $match_operator, $limit);
    $node_ids = [];
    foreach ($options as $bundle_options) {
      $node_ids = array_merge($node_ids, array_keys($bundle_options));
    }
    if (!$node_ids) {
      return $options;
    }

    $paths = array_map(static fn($id): string => '/node/' . $id, $node_ids);
    $alias_ids = $this->entityTypeManager->getStorage('path_alias')->getQuery()
      ->accessCheck(FALSE)
      ->condition('path', $paths, 'IN')
      ->condition('status', 1)
      ->execute();
    $aliases = [];
    foreach ($this->entityTypeManager->getStorage('path_alias')->loadMultiple($alias_ids) as $alias) {
      $aliases[$alias->getPath()] = $alias->getAlias();
    }

    foreach ($options as &$bundle_options) {
      foreach ($bundle_options as $node_id => &$label) {
        if (isset($aliases['/node/' . $node_id])) {
          $label .= Html::escape(' — ' . $aliases['/node/' . $node_id]);
        }
      }
    }
    return $options;
  }

  /**
   * Accepts pasted URLs and humanized slugs as alias searches.
   */
  protected function aliasMatchTerms(string $match): array {
    $match = trim($match);
    $path = parse_url($match, PHP_URL_PATH);
    $path = is_string($path) ? rawurldecode($path) : $match;
    $slug = '/' . ltrim((string) preg_replace('/[\s_]+/u', '-', $path), '/');
    return array_values(array_unique(array_filter([$match, $path, $slug])));
  }

}
