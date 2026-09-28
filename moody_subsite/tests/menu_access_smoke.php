<?php

/**
 * Local/Test only: creates temporary fixtures and removes them in finally.
 * Run via drush php:script; never run this fixture test on Live.
 */
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\moody_subsite\Entity\MoodySubsite;
use Drupal\moody_subsite\Plugin\Field\FieldFormatter\MoodySubsiteMenuFormatter;

$suffix = strtolower(bin2hex(random_bytes(4)));
$role = Role::create(['id' => 'menu_test_' . $suffix, 'label' => 'Temporary menu test']);
$role->grantPermission('access content')->grantPermission('view own unpublished content')->save();
$owner = User::create(['name' => 'menu_test_' . $suffix, 'status' => 1, 'roles' => [$role->id()]]);
$owner->save();
$other = User::create(['name' => 'menu_other_' . $suffix, 'status' => 1, 'roles' => [$role->id()]]);
$other->save();
$node = NULL;
$alias = NULL;
$switcher = \Drupal::service('account_switcher');
$switched = FALSE;
$check = static function ($condition, $message) {
  if (!$condition) { throw new RuntimeException($message); }
};
try {
  $node = Node::create(['type' => 'moody_standard_page', 'title' => 'Temporary menu access test', 'uid' => $owner->id(), 'status' => 0]);
  $node->save();
  $alias = \Drupal\path_alias\Entity\PathAlias::create(['path' => '/node/' . $node->id(), 'alias' => '/menu-test-' . $suffix]);
  $alias->save();
  $subsite = MoodySubsite::create(['subsite_nav' => [
    ['title' => 'Public parent', 'link' => 'https://example.com', 'is_child' => 0],
    ['title' => 'Draft child', 'link' => '/menu-test-' . $suffix, 'is_child' => 1],
    ['title' => 'Draft parent', 'link' => '/node/' . $node->id(), 'is_child' => 0],
    ['title' => 'Hidden branch child', 'link' => 'https://example.com', 'is_child' => 1],
  ]]);
  $formatter = (new ReflectionClass(MoodySubsiteMenuFormatter::class))->newInstanceWithoutConstructor();
  foreach ([new AnonymousUserSession(), $other, $owner] as $account) {
    $switcher->switchTo($account);
    $switched = TRUE;
    $build = $formatter->viewElements($subsite->get('subsite_nav'), 'en');
    $allowed = $account->id() === $owner->id();
    $check(isset($build[1]) === $allowed, 'Draft parent access mismatch');
    $check((count($build[0]['#children']) === 1) === $allowed, 'Draft child access mismatch');
    $check(in_array('user', $build['#cache']['contexts']), 'Missing per-user cache context');
    $check(in_array('node_list', $build['#cache']['tags']), 'Missing publication invalidation tag');
    $switcher->switchBack();
    $switched = FALSE;
  }
  $node->setPublished()->save();
  \Drupal::entityTypeManager()->getAccessControlHandler('node')->resetCache();
  $switcher->switchTo(new AnonymousUserSession());
  $switched = TRUE;
  $build = $formatter->viewElements($subsite->get('subsite_nav'), 'en');
  $check(isset($build[1]) && count($build[0]['#children']) === 1, 'Published links must be visible to anonymous visitors');
  print "Menu draft access, branch filtering, publication, and cache metadata passed.\n";
}
finally {
  if ($switched) { $switcher->switchBack(); }
  if ($node) { $node->delete(); }
  if ($alias) { $alias->delete(); }
  $other->delete();
  $owner->delete();
  $role->delete();
}
