<?php

/**
 * @file
 * Read-only check: drush php:script <path-to-this-file>.
 * Requires existing Subsite Editors with assigned and unassigned pages.
 */

use Drupal\Core\Access\AccessResult;
use Drupal\user\Entity\User;
use Drupal\Core\Session\AnonymousUserSession;

$storage = \Drupal::entityTypeManager()->getStorage('node');
$nodes = $storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)
  ->condition('type', 'moody_subsite_page')->range(0, 200)->execute());
$users = User::loadMultiple(\Drupal::entityTypeManager()->getStorage('user')->getQuery()
  ->accessCheck(FALSE)->condition('roles', 'moody_subsite_editor')->condition('status', 1)->execute());
$checked = ['allowed' => 0, 'denied' => 0];
$form_checked = FALSE;
foreach ($users as $account) {
  // Exercise only accounts that do not already have global alias privileges.
  if ($account->hasPermission('create url aliases') || $account->hasPermission('administer url aliases')) {
    continue;
  }
  foreach ($nodes as $node) {
    $field = $node->get('path');
    $access = $field->access('edit', $account, TRUE);
    $expected = $node->access('update', $account);
    if ($access->isAllowed() !== $expected) {
      throw new RuntimeException('Alias access does not match subsite page update access.');
    }
    if (!in_array('user.roles', $access->getCacheContexts(), TRUE)) {
      throw new RuntimeException('Alias access is missing role cache context.');
    }
    $checked[$expected ? 'allowed' : 'denied']++;

    if (!moody_subsite_entity_field_access('edit', $node->get('title')->getFieldDefinition(), $account, $node->get('title'))->isNeutral()
      || !moody_subsite_entity_field_access('edit', $field->getFieldDefinition(), new AnonymousUserSession(), $field)->isNeutral()) {
      throw new RuntimeException('Alias access changed an unrelated field or account.');
    }
    if ($expected && !$form_checked) {
      $switcher = \Drupal::service('account_switcher');
      $switcher->switchTo($account);
      try {
        $form = \Drupal::service('entity.form_builder')->getForm($node, 'edit');
        if (empty($form['path']['#access']) || !isset($form['path']['widget'][0]['alias'])) {
          throw new RuntimeException('The normal edit form did not expose the alias widget.');
        }
        $form_checked = TRUE;
      }
      finally {
        $switcher->switchBack();
      }
    }

    foreach (['view', 'delete'] as $operation) {
      if (!moody_subsite_entity_field_access($operation, $field->getFieldDefinition(), $account, $field)->isNeutral()) {
        throw new RuntimeException('The alias hook changed an unrelated operation.');
      }
    }
    // A forbidden decision from another access hook must still win.
    if ($access->andIf(AccessResult::forbidden())->isAllowed()) {
      throw new RuntimeException('Alias access overrode another access denial.');
    }
  }
}
if (!$checked['allowed'] || !$checked['denied'] || !$form_checked) {
  throw new RuntimeException('Need assigned and unassigned subsite pages to test both access decisions.');
}
echo 'Subsite alias access passed: ' . json_encode($checked) . PHP_EOL;
