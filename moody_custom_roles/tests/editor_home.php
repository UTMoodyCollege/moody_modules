<?php

/**
 * Run with drush php:script; all fixture writes are rolled back.
 */
use Drupal\moody_custom_roles\EditorHome;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use Drupal\node\Entity\Node;
use Drupal\Core\Entity\Entity\EntityViewDisplay;

$check = static function ($condition, $message) {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$transaction = \Drupal::database()->startTransaction();
$switcher = \Drupal::service('account_switcher');
$switched = FALSE;
try {
  $suffix = bin2hex(random_bytes(4));
  $role = Role::create(['id' => 'workspace_test_' . $suffix, 'label' => 'Workspace test', 'permissions' => ['access content', 'administer nodes', 'bypass node access']]);
  $role->save();
  $editor = User::create(['name' => 'workspace_editor_' . $suffix, 'status' => 1, 'roles' => [$role->id()]]);
  $editor->save();
  $limited = User::create(['name' => 'workspace_limited_' . $suffix, 'status' => 1]);
  $limited->save();
  $bundle = array_key_first(\Drupal::entityTypeManager()->getStorage('node_type')->loadMultiple());
  $node = Node::create(['type' => $bundle, 'title' => 'Workspace revision fixture ' . $suffix, 'uid' => 1, 'status' => 0]);
  $node->setRevisionUserId($editor->id());
  $node->setRevisionCreationTime(time());
  $node->save();

  $switcher->switchTo($editor);
  $switched = TRUE;
  $build = EditorHome::build();
  $check($build['#cache']['max-age'] === 0, 'Personal dashboard must not share render caches.');
  $check(isset($build['activity']), 'Site-wide editor should see recent activity.');
  $check(!isset($build['media']), 'Workspace must not include recently uploaded media.');
  $check(in_array($node->label(), array_column($build['recent']['content']['#items'], '#title'), TRUE), 'Edits must use revision author, not node owner.');
  $display = EntityViewDisplay::collectRenderDisplay($editor, 'full');
  $profile = [];
  moody_custom_roles_user_view($profile, $editor, $display, 'full');
  $check(isset($profile['editor_home']), 'Own full profile should include workspace.');
  $profile = [];
  moody_custom_roles_user_view($profile, $limited, $display, 'full');
  $check(!isset($profile['editor_home']), 'Other profiles must not show personal workspace.');
  $switcher->switchBack();
  $switched = FALSE;

  $switcher->switchTo($limited);
  $switched = TRUE;
  $build = EditorHome::build();
  $check(!isset($build['activity']) && !isset($build['media']), 'Limited accounts must not see site-wide activity.');
  $check(!in_array($node->label(), array_column($build['recent']['content']['#items'], '#title'), TRUE), 'Inaccessible unpublished content must not appear.');
  foreach ($build['actions']['content']['#items'] as $action) {
    $check($action['#url']->access($limited), 'Every shortcut must be accessible.');
  }
  print "Editor workspace checks passed (fixtures rolled back).\n";
}
finally {
  if ($switched) {
    $switcher->switchBack();
  }
  $transaction->rollBack();
  \Drupal::entityTypeManager()->getStorage('node')->resetCache();
  \Drupal::entityTypeManager()->getStorage('user')->resetCache();
  \Drupal::entityTypeManager()->getStorage('user_role')->resetCache();
}
