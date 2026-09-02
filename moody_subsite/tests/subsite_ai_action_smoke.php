<?php

declare(strict_types=1);

use Drupal\Core\Session\AccountInterface;

$subsite_id = (int) getenv('MOODY_SUBSITE_TEST_ID');
if (!$subsite_id) {
  throw new RuntimeException('Set MOODY_SUBSITE_TEST_ID to a disposable local subsite before running this smoke test.');
}

$account = \Drupal::entityTypeManager()->getStorage('user')->load(1);
if (!$account instanceof AccountInterface) {
  throw new RuntimeException('User 1 is required for the subsite AI action smoke test.');
}

$storage = \Drupal::entityTypeManager()->getStorage('moody_subsite');
$subsite = $storage->load($subsite_id);
if (!$subsite) {
  throw new RuntimeException('The requested local test subsite does not exist.');
}

$term_id = (int) ($subsite->get('directory_structure')->first()->target_id ?? 0);
if (!$term_id) {
  throw new RuntimeException('The local test subsite needs a Moody URL Generator term.');
}

$original_menu = $subsite->get('subsite_nav')->getValue();
$original_logo = $subsite->get('custom_logo')->getValue();
$original_home_hero = (int) $subsite->get('subsite_home_hero')->value;
$original_give_link = (string) $subsite->get('give_link')->value;
$test_menu = $original_menu;
$test_menu[] = [
  'title' => 'AI tool smoke link',
  'link' => '/moody-ai-subsite-smoke',
  'is_child' => 0,
];

$media_ids = \Drupal::entityTypeManager()->getStorage('media')->getQuery()
  ->accessCheck(FALSE)
  ->condition('bundle', 'utexas_image')
  ->condition('mid', (int) ($original_logo[0]['media'] ?? 0), '<>')
  ->range(0, 1)
  ->execute();
$logo_media_id = $media_ids ? (int) reset($media_ids) : 0;
$page_title = 'Moody AI subsite action smoke ' . time();
$plan = [
  'subsite_id' => $subsite_id,
  'summary' => 'Smoke-test the reviewed subsite action transaction.',
  'settings' => [
    'subsite_home_hero' => $original_home_hero ? 0 : 1,
  ],
  'replace_menu' => TRUE,
  'menu_items' => $test_menu,
  'new_page' => [
    'title' => $page_title,
    'directory_term_id' => $term_id,
  ],
];
$assets = [];
if ($logo_media_id) {
  $plan += [
    'replace_logo' => TRUE,
    'logo_media_id' => $logo_media_id,
    'logo_size' => 'short_logo',
  ];
  $assets[] = [
    'asset_type' => 'image',
    'media_id' => $logo_media_id,
  ];
}

$manager = \Drupal::service('moody_subsite.ai_action_manager');
$created_page_id = 0;
try {
  foreach ([
    'unsafe link' => [[
      'title' => 'Unsafe link',
      'link' => 'javascript:alert(1)',
      'is_child' => 0,
    ]],
    'orphaned child' => [[
      'title' => 'Orphaned child',
      'link' => '/orphaned-child',
      'is_child' => 1,
    ]],
  ] as $case => $invalid_menu) {
    try {
      $manager->prepareAction([
        'subsite_id' => $subsite_id,
        'replace_menu' => TRUE,
        'menu_items' => $invalid_menu,
      ], $account);
      throw new RuntimeException(sprintf('The %s menu case was accepted.', $case));
    }
    catch (InvalidArgumentException $exception) {
      // Expected: unsafe model output must stop before preview or mutation.
    }
  }

  $preview = $manager->prepareAction($plan, $account, $assets);
  if (($preview['payload']['subsite_id'] ?? 0) !== $subsite_id || count($preview['changes'] ?? []) < 3) {
    throw new RuntimeException('The subsite action preview did not contain the expected reviewed changes.');
  }
  $unchanged = $storage->loadUnchanged($subsite_id);
  if ((int) $unchanged->get('subsite_home_hero')->value !== $original_home_hero || $unchanged->get('subsite_nav')->getValue() !== $original_menu) {
    throw new RuntimeException('Previewing the subsite action mutated the entity.');
  }

  $conflicting = $storage->loadUnchanged($subsite_id);
  $conflicting->set('give_link', '/moody-ai-concurrency-smoke');
  $conflicting->save();
  try {
    $manager->executeAction($preview['payload'], $account);
    throw new RuntimeException('A stale subsite preview was allowed to overwrite newer changes.');
  }
  catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'changed after the preview')) {
      throw $exception;
    }
  }
  $conflicting = $storage->loadUnchanged($subsite_id);
  $conflicting->set('give_link', $original_give_link);
  $conflicting->save();
  $preview = $manager->prepareAction($plan, $account, $assets);

  $result = $manager->executeAction($preview['payload'], $account);
  $created_page_id = (int) ($result['created_page_id'] ?? 0);
  $updated = $storage->loadUnchanged($subsite_id);
  if ((int) $updated->get('subsite_home_hero')->value === $original_home_hero || $updated->get('subsite_nav')->getValue() === $original_menu) {
    throw new RuntimeException('The approved subsite settings or nested menu were not saved.');
  }
  if ($logo_media_id && (int) $updated->get('custom_logo')->first()->media !== $logo_media_id) {
    throw new RuntimeException('The approved attached-image logo was not saved.');
  }

  $page = $created_page_id ? \Drupal::entityTypeManager()->getStorage('node')->load($created_page_id) : NULL;
  if (!$page || $page->bundle() !== 'moody_subsite_page' || $page->isPublished() || $page->label() !== $page_title || (int) $page->get('field_moody_url_generator')->target_id !== $term_id) {
    throw new RuntimeException('The approved unpublished subsite page was not created with the selected URL Generator term.');
  }
}
finally {
  if ($created_page_id && $page = \Drupal::entityTypeManager()->getStorage('node')->load($created_page_id)) {
    $page->delete();
  }
  if ($restore = $storage->loadUnchanged($subsite_id)) {
    $restore->set('subsite_nav', $original_menu);
    $restore->set('custom_logo', $original_logo);
    $restore->set('subsite_home_hero', $original_home_hero);
    $restore->set('give_link', $original_give_link);
    $restore->save();
  }
}

print json_encode([
  'subsite_id' => $subsite_id,
  'preview_change_count' => count($preview['changes']),
  'logo_exercised' => (bool) $logo_media_id,
  'menu_exercised' => TRUE,
  'draft_page_exercised' => TRUE,
  'concurrency_guard_exercised' => TRUE,
  'unsafe_links_rejected' => TRUE,
  'orphaned_children_rejected' => TRUE,
  'restored' => TRUE,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
