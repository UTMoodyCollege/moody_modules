<?php

use Drupal\node\Entity\Node;
use Drupal\redirect\Entity\Redirect;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;
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
$view_storage = $entity_type_manager->getStorage('view');
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
  $current_target = ['type' => 'node', 'id' => (int) $current->id()];
  $replacement_target = ['type' => 'node', 'id' => (int) $replacement->id()];
  $plan = $launcher->buildPlan($current_target, $replacement_target);
  $assert($plan['current']['path'] === $live_alias, 'Preview did not identify the live alias.');
  $assert($plan['replacement']['path'] === $preview_alias, 'Preview did not identify the redesign alias.');
  $assert($plan['archive_path'] === $live_alias . '-old-v1', 'Preview did not reserve the expected archive alias.');
  $retarget_ids = array_column($plan['retarget_redirects'], 'id');
  $assert(in_array((int) $legacy_redirect->id(), $retarget_ids, TRUE), 'Preview did not identify the legacy redirect.');

  // Prewarm the route cache with the live alias pointing to the current node.
  $route_provider = \Drupal::service('router.route_provider');
  $current_path = \Drupal::service('path.current');
  $before_request = Request::create($live_alias);
  $route_provider->getRouteCollectionForRequest($before_request);
  $assert($current_path->getPath($before_request) === '/node/' . $current->id(), 'The route cache was not prewarmed with the current node.');

  $launcher->launch($current_target, $replacement_target, $launcher->fingerprint($plan));

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

  $create_view = static function (string $id, string $label, string $path, bool $enabled): View {
    $view = View::create([
      'id' => $id,
      'label' => $label,
      'base_table' => 'node_field_data',
      'base_field' => 'nid',
      'status' => TRUE,
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => [
            'access' => ['type' => 'none'],
            'cache' => ['type' => 'tag'],
            'query' => ['type' => 'views_query'],
            'exposed_form' => ['type' => 'basic'],
            'pager' => ['type' => 'full'],
            'style' => ['type' => 'default'],
            'row' => ['type' => 'fields'],
            'fields' => [],
            'filters' => [],
            'sorts' => [],
            'header' => [],
            'footer' => [],
            'empty' => [],
            'relationships' => [],
            'arguments' => [],
            'display_extenders' => [],
          ],
        ],
        'page_1' => [
          'id' => 'page_1',
          'display_title' => 'Page',
          'display_plugin' => 'page',
          'position' => 1,
          'display_options' => [
            'path' => ltrim($path, '/'),
            'enabled' => $enabled,
            'display_extenders' => [],
          ],
        ],
        'block_1' => [
          'id' => 'block_1',
          'display_title' => 'Block',
          'display_plugin' => 'block',
          'position' => 2,
          'display_options' => [
            'enabled' => TRUE,
            'display_extenders' => [],
          ],
        ],
      ],
    ]);
    $view->save();
    return $view;
  };
  $view_display = static function (string $view_id) use ($view_storage): array {
    $view_storage->resetCache([$view_id]);
    return $view_storage->load($view_id)->get('display')['page_1'];
  };

  // Replace an enabled Views page with a content page.
  $view_source_path = $live_alias . '-view-source';
  $view_source_id = 'moody_page_launch_source_' . $suffix;
  $create_view($view_source_id, 'Page launch source View', $view_source_path, TRUE);
  $view_replacement_alias = $live_alias . '-view-replacement';
  $view_replacement = Node::create([
    'type' => 'article',
    'title' => 'Page launch View replacement',
    'status' => FALSE,
    'path' => ['alias' => $view_replacement_alias, 'pathauto' => 0],
  ]);
  $view_replacement->save();
  $view_source_target = [
    'type' => 'view',
    'view_id' => $view_source_id,
    'display_id' => 'page_1',
  ];
  $view_replacement_target = ['type' => 'node', 'id' => (int) $view_replacement->id()];
  $view_source_plan = $launcher->buildPlan($view_source_target, $view_replacement_target);
  $launcher->launch(
    $view_source_target,
    $view_replacement_target,
    $launcher->fingerprint($view_source_plan),
  );
  $assert($view_display($view_source_id)['display_options']['enabled'] === FALSE, 'The source Views page display was not disabled.');
  $source_view = $view_storage->load($view_source_id);
  $source_displays = $source_view->get('display');
  $assert($source_displays['block_1']['display_options']['enabled'] === TRUE, 'Disabling the source page also disabled another View display.');
  $assert($load_alias((int) $view_replacement->id())->getAlias() === $view_source_path, 'The replacement node did not receive the Views page path.');
  $assert($get_redirect($view_replacement_alias)->getRedirect()['uri'] === 'internal:/node/' . $view_replacement->id(), 'The replacement node legacy path was not redirected.');
  $view_source_request = Request::create($view_source_path);
  $route_provider->getRouteCollectionForRequest($view_source_request);
  $assert($current_path->getPath($view_source_request) === '/node/' . $view_replacement->id(), 'The former Views path did not resolve to the replacement node.');

  // Replace a content page with a disabled Views page display.
  $node_source_alias = $live_alias . '-node-source';
  $node_source = Node::create([
    'type' => 'article',
    'title' => 'Page launch node source',
    'status' => TRUE,
    'path' => ['alias' => $node_source_alias, 'pathauto' => 0],
  ]);
  $node_source->save();
  $view_destination_path = $live_alias . '-view-destination';
  $view_destination_id = 'moody_page_launch_destination_' . $suffix;
  $create_view($view_destination_id, 'Page launch destination View', $view_destination_path, FALSE);
  $node_source_target = ['type' => 'node', 'id' => (int) $node_source->id()];
  $view_destination_target = [
    'type' => 'view',
    'view_id' => $view_destination_id,
    'display_id' => 'page_1',
  ];
  $view_destination_plan = $launcher->buildPlan($node_source_target, $view_destination_target);
  $launcher->launch(
    $node_source_target,
    $view_destination_target,
    $launcher->fingerprint($view_destination_plan),
  );
  $destination_display = $view_display($view_destination_id);
  $assert($destination_display['display_options']['enabled'] === TRUE, 'The destination Views page display was not enabled.');
  $assert($destination_display['display_options']['path'] === ltrim($node_source_alias, '/'), 'The destination Views page path was not updated.');
  $assert($load_alias((int) $node_source->id())->getAlias() === $node_source_alias . '-old-v1', 'The source node did not receive its archive alias.');
  $assert($get_redirect($view_destination_path)->getRedirect()['uri'] === 'internal:' . $node_source_alias, 'The destination View legacy path was not redirected.');
  $assert($get_redirect('/node/' . $node_source->id())->getRedirect()['uri'] === 'internal:' . $node_source_alias, 'The source node URL was not redirected to the destination View.');
  $destination_routes = $route_provider->getRouteCollectionForRequest(Request::create($node_source_alias));
  $destination_route_found = FALSE;
  foreach ($destination_routes as $route) {
    if ($route->getDefault('view_id') === $view_destination_id && $route->getDefault('display_id') === 'page_1') {
      $destination_route_found = TRUE;
      break;
    }
  }
  $assert($destination_route_found, 'The new Views path was not rebuilt into the router.');

  print "Moody Page Launch smoke test passed.\n";
}
finally {
  $transaction->rollBack();
  $account_switcher->switchBack();
  $node_storage->resetCache();
  $alias_storage->resetCache();
  $redirect_storage->resetCache();
  $view_storage->resetCache();
  \Drupal::service('cache_tags.invalidator')->invalidateTags(['route_match']);
  \Drupal::service('router.builder')->rebuild();
}
