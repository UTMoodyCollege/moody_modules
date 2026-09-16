<?php

namespace Drupal\moody_ai_assistant\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/** Read-only, bounded discovery. Never exposes entity bodies or account secrets. */
final class ContentLookup {

  public function __construct(private EntityTypeManagerInterface $entityTypeManager) {}

  public function catalog(): array {
    $catalog = [];
    foreach (['node', 'media', 'taxonomy_term', 'user'] as $type) {
      if ($this->entityTypeManager->hasDefinition($type)) {
        foreach (\Drupal::service('entity_type.bundle.info')->getBundleInfo($type) as $bundle => $info) {
          $catalog[$type][$bundle] = (string) $info['label'];
        }
      }
    }
    return $catalog;
  }

  public function lookup(array $queries, AccountInterface $account): array {
    // Entity query access uses the active account, not an arbitrary supplied UID.
    if (!$account->hasPermission('use moody ai assistant') || (string) $account->id() !== (string) \Drupal::currentUser()->id()) {
      throw new \RuntimeException('Content discovery requires the active assistant user.');
    }
    if (count($queries) > 3) {
      throw new \InvalidArgumentException('At most three content lookups are allowed.');
    }
    $catalog = $this->catalog();
    $results = [];
    foreach ($queries as $request) {
      if (!is_array($request) || array_diff(array_keys($request), ['entity_type', 'bundle', 'text', 'limit'])) {
        throw new \InvalidArgumentException('Unsupported content lookup arguments.');
      }
      $type = $request['entity_type'] ?? '';
      $bundle = $request['bundle'] ?? '';
      $text = $request['text'] ?? '';
      $limit = $request['limit'] ?? 6;
      if (!is_string($type) || !isset($catalog[$type]) || !is_string($bundle) || ($bundle !== '' && !isset($catalog[$type][$bundle])) || !is_string($text) || mb_strlen($text) > 200 || !is_int($limit) || $limit < 1 || $limit > 20) {
        throw new \InvalidArgumentException('Invalid content lookup filters.');
      }
      if ($type === 'user' && !$account->hasPermission('access user profiles')) {
        $results[] = ['query' => $request, 'items' => [], 'unavailable' => TRUE];
        continue;
      }
      $definition = $this->entityTypeManager->getDefinition($type);
      $storage = $this->entityTypeManager->getStorage($type);
      $query = $storage->getQuery()->accessCheck(TRUE);
      if ($bundle !== '' && $definition->getKey('bundle')) {
        $query->condition($definition->getKey('bundle'), $bundle);
      }
      if ($text !== '') {
        $query->condition($definition->getKey('label'), $text, 'CONTAINS');
      }
      // Discovery is deliberately published/active only, even for administrators.
      if ($status_key = ($definition->getKey('published') ?: ($type === 'user' ? 'status' : NULL))) {
        $query->condition($status_key, 1);
      }
      if (in_array($type, ['node', 'media', 'user'], TRUE)) {
        $query->sort('created', 'DESC');
      }
      $ids = $query->sort($definition->getKey('id'), 'DESC')->range(0, $limit)->execute();
      $items = [];
      $entities = $storage->loadMultiple($ids);
      foreach ($ids as $id) {
        $entity = $entities[$id] ?? NULL;
        if (!$entity || !$entity->access('view', $account) || !$entity->get($definition->getKey('label'))->access('view', $account)) {
          continue;
        }
        $items[] = [
          'entity_type' => $type, 'id' => (int) $id, 'bundle' => $entity->bundle(),
          'label' => mb_substr((string) $entity->label(), 0, 250),
          'url' => $entity->hasLinkTemplate('canonical') ? $entity->toUrl()->toString() : '',
          'created' => $entity->hasField('created') ? (int) $entity->get('created')->value : NULL,
        ];
      }
      $results[] = ['query' => $request, 'items' => $items];
    }
    return $results;
  }

}
