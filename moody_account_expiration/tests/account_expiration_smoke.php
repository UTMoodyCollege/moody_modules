<?php

/**
 * @file
 * Local integration smoke test. Run with: drush php:script tests/account_expiration_smoke.php
 */

use Drupal\moody_account_expiration\AccountExpirationProcessor;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

$processor = \Drupal::service('moody_account_expiration.processor');
$storage = \Drupal::entityTypeManager()->getStorage('user');
$suffix = bin2hex(random_bytes(4));
$created = [];

try {
  $assigned = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('roles', 'moody_account_expiration_administrator')
    ->execute();
  if ($assigned) {
    throw new RuntimeException('The expiration administrator role must be unassigned before this smoke test.');
  }

  $target = User::create([
    'name' => "expiration-target-$suffix",
    'mail' => "expiration-target-$suffix@example.invalid",
    'status' => 1,
    AccountExpirationProcessor::FIELD_NAME => '2000-01-01',
  ]);
  $target->save();
  $created[] = $target;

  $viewer = User::create([
    'name' => "expiration-viewer-$suffix",
    'mail' => "expiration-viewer-$suffix@example.invalid",
    'status' => 1,
  ]);
  $viewer->save();
  $created[] = $viewer;

  $administrator = User::create([
    'name' => "expiration-admin-$suffix",
    'mail' => "expiration-admin-$suffix@example.invalid",
    'status' => 1,
    'roles' => ['moody_account_expiration_administrator'],
  ]);
  $administrator->save();
  $created[] = $administrator;

  $field = $target->get(AccountExpirationProcessor::FIELD_NAME);
  if (!$field->access('view', $target) || $field->access('edit', $target)) {
    throw new RuntimeException('A user must be able to view, but not edit, their own expiration date.');
  }
  if ($field->access('view', $viewer) || $field->access('edit', $viewer)) {
    throw new RuntimeException('An unrelated user must not access another account expiration date.');
  }
  if (!$field->access('view', $administrator) || !$field->access('edit', $administrator)) {
    throw new RuntimeException('The expiration administrator role must be able to view and edit expiration dates.');
  }
  $user_one = $storage->load(1);
  if (!$user_one instanceof UserInterface || !$field->access('view', $user_one) || !$field->access('edit', $user_one)) {
    throw new RuntimeException('User 1 must be able to view and edit expiration dates.');
  }

  $viewer->block();
  $viewer->set(AccountExpirationProcessor::FIELD_NAME, '2000-01-01');
  $viewer->save();
  $viewer->set(AccountExpirationProcessor::FIELD_NAME, '2999-01-01');
  $viewer->save();
  $viewer = $storage->loadUnchanged($viewer->id());
  if (!$viewer instanceof UserInterface || !$viewer->isBlocked() || $processor->wasBlocked($viewer)) {
    throw new RuntimeException('Changing a date must not reactivate an account blocked outside this module.');
  }

  $blocked = $processor->process('2000-01-01');
  if ($blocked !== [(int) $target->id()]) {
    throw new RuntimeException('The processor did not block exactly the expected expired account.');
  }

  $target = $storage->loadUnchanged($target->id());
  if (!$target instanceof UserInterface || !$target->isBlocked() || !$processor->wasBlocked($target)) {
    throw new RuntimeException('The expired account was not blocked and marked correctly.');
  }

  $target->set(AccountExpirationProcessor::FIELD_NAME, '2999-01-01');
  $target->save();
  $target = $storage->loadUnchanged($target->id());
  if (!$target instanceof UserInterface || !$target->isActive() || $processor->wasBlocked($target)) {
    throw new RuntimeException('Extending the date did not reactivate the module-blocked account.');
  }

  print "Moody Account Expiration smoke test passed.\n";
}
finally {
  foreach (array_reverse($created) as $account) {
    $processor->forget($account);
    $account->delete();
  }
}
