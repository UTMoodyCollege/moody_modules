<?php

use Drupal\node\Entity\Node;
use Drupal\redirect\Entity\Redirect;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * @file
 * Transactional smoke test for Moody Page Launch.
 *
 * Run with: drush php:script web/modules/custom/moody_modules/moody_page_launch/tests/page_launch_smoke.php
 */

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};

$database = \Drupal::database();
$entity_type_manager = \Drupal::entityTypeManager();
$node_storage = $entity_type_manager->getStorage('node');
$alias_storage = $entity_type_manager->getStorage('path_alias');
$redirect_storage = $entity_type_manager->getStorage('redirect');
$account_switcher = \Drupal::service('account_switcher');
$account_switcher->switchTo(User::load(1));
$transaction = $database->startTransaction();

try {
  $suffix = substr(hash('sha256', microtime(TRUE)), 0, 10);
  $live_alias = '/moody-page-launch-smoke-' . $suffix;
  $preview_alias = $live_alias . '-2';
  $legacy_alias = '/moody-page-launch-legacy-' . $suffix;

  $current = Node::create([
    'type' => 'article',
    'title' => 'Page launch smoke current',
    'status' => TRUE,
    'path' => ['alias' => $live_alias, 'pathauto' => 0],
  ]);
  $current->save();
  $replacement = Node::create([
    'type' => 'article',
    'title' => 'Page launch smoke replacement',
    'status' => FALSE,
    'path' => ['alias' => $preview_alias, 'pathauto' => 0],
  ]);
  $replacement->save();

  $legacy_redirect = Redirect::create();
  $legacy_redirect->setSource($legacy_alias);
  $legacy_redirect->setRedirect('/node/' . $current->id());
  $legacy_redirect->setLanguage('und');
  $legacy_redirect->setStatusCode(301);
  $legacy_redirect->save();

  $selection = \Drupal::service('plugin.manager.entity_reference_selection')->getInstance([
    'target_type' => 'node',
    'handler' => 'moody_page_launch:node_alias',
  ]);
  foreach ([$preview_alias, 'https://example.test' . $preview_alias, str_replace('-', ' ', ltrim($preview_alias, '/'))] as $search) {
    $matches = $selection->getReferenceableEntities($search, 'CONTAINS', 20);
    $match_ids = [];
    foreach ($matches as $bundle_matches) {
      $match_ids = array_merge($match_ids, array_keys($bundle_matches));
    }
    $assert(in_array((int) $replacement->id(), $match_ids, TRUE), sprintf('Autocomplete did not find the replacement using %s.', $search));
  }

  $launcher = \Drupal::service('moody_page_launch.launcher');
  $plan = $launcher->buildPlan($current, $replacement);
  $assert($plan['current']['alias'] === $live_alias, 'Preview did not identify the live alias.');
  $assert($plan['replacement']['alias'] === $preview_alias, 'Preview did not identify the redesign alias.');
  $assert($plan['archive_alias'] === $live_alias . '-old-v1', 'Preview did not reserve the expected archive alias.');
  $retarget_ids = array_column($plan['retarget_redirects'], 'id');
  $assert(in_array((int) $legacy_redirect->id(), $retarget_ids, TRUE), 'Preview did not identify the legacy redirect.');

  // Prewarm the route cache with the live alias pointing to the current node.
  $route_provider = \Drupal::service('router.route_provider');
  $current_path = \Drupal::service('path.current');
  $before_request = Request::create($live_alias);
  $route_provider->getRouteCollectionForRequest($before_request);
  $assert($current_path->getPath($before_request) === '/node/' . $current->id(), 'The route cache was not prewarmed with the current node.');

  $launcher->launch($current, $replacement, $launcher->fingerprint($plan));

  $after_request = Request::create($live_alias);
  $route_provider->getRouteCollectionForRequest($after_request);
  $assert($current_path->getPath($after_request) === '/node/' . $replacement->id(), 'The live alias retained a stale route to the current node.');

  $node_storage->resetCache([$current->id(), $replacement->id()]);
  $current = $node_storage->load($current->id());
  $replacement = $node_storage->load($replacement->id());
  $assert(!$current->isPublished(), 'The current page was not unpublished.');
  $assert($replacement->isPublished(), 'The replacement page was not published.');

  $load_alias = static function (int $node_id) use ($alias_storage) {
    $ids = $alias_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('path', '/node/' . $node_id)
      ->condition('status', 1)
      ->execute();
    return $alias_storage->load(reset($ids));
  };
  $assert($load_alias((int) $current->id())->getAlias() === $live_alias . '-old-v1', 'The current page did not receive its archive alias.');
  $assert($load_alias((int) $replacement->id())->getAlias() === $live_alias, 'The replacement did not receive the live alias.');

  $get_redirect = static function (string $source) use ($redirect_storage) {
    $ids = $redirect_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('redirect_source.path', ltrim($source, '/'))
      ->execute();
    return $ids ? $redirect_storage->load(reset($ids)) : NULL;
  };
  foreach ([$preview_alias, '/node/' . $current->id(), $legacy_alias] as $source) {
    $redirect = $get_redirect($source);
    $assert($redirect !== NULL, sprintf('Missing redirect for %s.', $source));
    $assert($redirect->getRedirect()['uri'] === 'internal:/node/' . $replacement->id(), sprintf('Redirect %s does not target the replacement.', $source));
  }
  $assert($get_redirect($live_alias) === NULL, 'The live alias is incorrectly shadowed by a redirect.');
  $assert($get_redirect($live_alias . '-old-v1') === NULL, 'The archive alias is incorrectly shadowed by a redirect.');

  print "Moody Page Launch smoke test passed.\n";
}
finally {
  $transaction->rollBack();
  $account_switcher->switchBack();
  $node_storage->resetCache();
  $alias_storage->resetCache();
  $redirect_storage->resetCache();
  \Drupal::service('cache_tags.invalidator')->invalidateTags(['route_match']);
}
