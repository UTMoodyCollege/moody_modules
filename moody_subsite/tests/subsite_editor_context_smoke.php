<?php

declare(strict_types=1);

use Drupal\Core\Session\AccountInterface;

$uid = (int) getenv('MOODY_SUBSITE_TEST_UID');
if (!$uid) {
  throw new RuntimeException('Set MOODY_SUBSITE_TEST_UID to a Subsite Editor account before running this smoke test.');
}

$account = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
if (!$account instanceof AccountInterface) {
  throw new RuntimeException('The requested test account could not be loaded.');
}

$context = \Drupal::service('moody_subsite.editor_context')->collect($account);
$expected_active = in_array('moody_subsite_editor', $account->getRoles(), TRUE);
if (($context['active'] ?? NULL) !== $expected_active) {
  throw new RuntimeException('Subsite Editor role detection does not match Drupal roles.');
}

foreach ($context['assigned_subsites'] ?? [] as $subsite) {
  if (empty($subsite['id']) || empty($subsite['label']) || empty($subsite['dashboard_url']) || !isset($subsite['pages'], $subsite['page_count'])) {
    throw new RuntimeException('An assigned subsite context entry is incomplete.');
  }
  if ($subsite['page_count'] !== count($subsite['pages'])) {
    throw new RuntimeException('The subsite page count is inconsistent.');
  }
  foreach ($subsite['pages'] as $page) {
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($page['id']);
    if (!$node || $node->bundle() !== 'moody_subsite_page' || !$node->access('view', $account)) {
      throw new RuntimeException('The context exposed an inaccessible or non-subsite page.');
    }
  }
}

print json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
