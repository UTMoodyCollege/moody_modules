<?php

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\moody_scheduled_publishing\Controller\Dashboard;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

if (!getenv('DDEV_SITENAME') || getenv('PANTHEON_ENVIRONMENT')) {
  throw new RuntimeException('Fixture tests are restricted to local DDEV.');
}
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
  if (!$ok) {
    throw new RuntimeException($message);
  }
  $checks++;
};
$scheduler = \Drupal::service('moody_scheduled_publishing.scheduler');
$db = \Drupal::database();
$storage = \Drupal::entityTypeManager()->getStorage('node');
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(User::load(1));
$nodes = [];
$type = $role = $editor = NULL;
$last_run = \Drupal::state()->get('moody_scheduled_publishing.last_run');
try {
  $suffix = substr(bin2hex(random_bytes(6)), 0, 10);
  $bundle = 'schedule_qa_' . $suffix;
  $type = NodeType::create(['type' => $bundle, 'name' => 'Scheduling QA']);
  $type->save();
  $role = Role::create(['id' => $bundle, 'label' => 'Scheduling QA', 'permissions' => [
    'access content', 'access content overview', "create $bundle content", "edit own $bundle content", 'administer node published status',
  ]]);
  $role->save();
  $editor = User::create(['name' => $bundle, 'status' => 1, 'roles' => [$bundle]]);
  $editor->save();
  $make = static function () use (&$nodes, $bundle, $editor): Node {
    $node = Node::create(['type' => $bundle, 'title' => 'Disposable scheduled publishing QA', 'uid' => $editor->id(), 'status' => 0]);
    $node->save();
    $nodes[] = (int) $node->id();
    return $node;
  };
  $due = static function ($node) use ($scheduler, $editor, $db): void {
    $scheduler->schedule((int) $node->id(), time() + 3600, $editor);
    $db->update('moody_publish_schedule')->fields(['publish_at' => time() - 5])->condition('nid', $node->id())->execute();
  };
  $node = $make();
  $check($scheduler->canSchedule($node, $editor), 'Normal own-content editor can schedule.');
  $check(!$scheduler->canSchedule($node, new AnonymousUserSession()), 'Anonymous denied.');
  $other = $make();
  $other->setOwnerId(1)->save();
  $check(!$scheduler->canSchedule($other, $editor), 'Other authors content denied.');
  try {
    $scheduler->schedule((int) $other->id(), time() + 3600, $editor);
    throw new LogicException('Unauthorized schedule was accepted.');
  }
  catch (RuntimeException $e) {
    $check($scheduler->get((int) $other->id()) === NULL, 'Access denial writes nothing.');
  }
  $scheduler->schedule((int) $node->id(), time() + 3600, $editor);
  $check($scheduler->get((int) $node->id())->state === 'pending', 'Schedule persisted.');
  $result = $scheduler->run(TRUE);
  $check(!in_array((int) $node->id(), $result['published'], TRUE), 'Future content stays unpublished.');
  $scheduler->schedule((int) $node->id(), time() + 7200, $editor);
  $stale = $scheduler->token((int) $node->id());
  $scheduler->schedule((int) $node->id(), NULL, $editor);
  try {
    $scheduler->schedule((int) $node->id(), time() + 10800, $editor, $stale);
    throw new LogicException('Stale form accepted.');
  }
  catch (RuntimeException $e) {
    $check($scheduler->get((int) $node->id())->state === 'cancelled', 'Stale form cannot overwrite cancellation.');
  }
  $check($scheduler->get((int) $node->id())->state === 'cancelled', 'Cancel persisted.');
  $events = $db->select('moody_publish_event', 'e')->fields('e', ['event'])->condition('nid', $node->id())->orderBy('id')->execute()->fetchCol();
  $check($events === ['scheduled', 'rescheduled', 'cancelled'], 'Lifecycle history recorded.');
  $due($node);
  $revision = $node->getRevisionId();
  $result = $scheduler->run(TRUE);
  $node = $storage->loadUnchanged($node->id());
  $check(in_array((int) $node->id(), $result['published'], TRUE) && $node->isPublished(), 'Due node published.');
  $check($revision !== $node->getRevisionId() && (int) $node->getRevisionUserId() === (int) $editor->id(), 'New revision attributes scheduling actor.');
  $result = $scheduler->run(TRUE);
  $check(!in_array((int) $node->id(), $result['published'], TRUE), 'Rerun does not publish twice.');
  $blocked = $make();
  $due($blocked);
  $editor->block()->save();
  $result = $scheduler->run(TRUE);
  $check(in_array((int) $blocked->id(), $result['blocked'], TRUE) && !$storage->loadUnchanged($blocked->id())->isPublished(), 'Blocked account cannot publish.');
  $editor->activate()->save();
  $manual = $make();
  $due($manual);
  $manual->setPublished()->save();
  $check($scheduler->get((int) $manual->id())->state === 'published_manually', 'Manual publish reconciles schedule.');
  $deleted = $make();
  $due($deleted);
  $deleted->delete();
  $check($scheduler->get((int) $deleted->id())->state === 'deleted', 'Deletion recorded.');
  $pending = $make();
  $due($pending);
  $pending->setNewRevision(TRUE);
  $pending->isDefaultRevision(FALSE);
  $pending->save();
  $result = $scheduler->run(TRUE);
  $check(in_array((int) $pending->id(), $result['blocked'], TRUE), 'Pending newer revision blocks stale publication.');
  $invalid = $make();
  $due($invalid);
  $db->update('node_field_data')->fields(['title' => ''])->condition('nid', $invalid->id())->execute();
  $db->update('node_field_revision')->fields(['title' => ''])->condition('nid', $invalid->id())->execute();
  $storage->resetCache([$invalid->id()]);
  $result = $scheduler->run(TRUE);
  $check(in_array((int) $invalid->id(), $result['failed'], TRUE), 'Invalid content fails safely.');
  $check(!$storage->loadUnchanged($invalid->id())->isPublished() && $scheduler->get((int) $invalid->id())->state === 'blocked', 'Failed content remains unpublished and needs attention.');
  $db->update('node_field_data')->fields(['title' => 'Disposable scheduled publishing QA'])->condition('nid', $invalid->id())->execute();
  $db->update('node_field_revision')->fields(['title' => 'Disposable scheduled publishing QA'])->condition('nid', $invalid->id())->execute();
  $storage->resetCache([$invalid->id()]);
  // Database lock ownership is per-request; acquiring an existing own lock renews
  // it, so concurrency is exercised separately with independent CLI processes.
  $check((int) \Drupal::currentUser()->id() === 1, 'Publishing restores current user.');
  try {
    $scheduler->run();
    throw new LogicException('Implicit local processing was accepted.');
  }
  catch (RuntimeException $e) {
    $check(str_contains($e->getMessage(), 'disabled'), 'Local execution requires prototype flag.');
  }
  $form_node = $make();
  $form = \Drupal::service('entity.form_builder')->getForm($form_node);
  $check(isset($form['moody_schedule']['at']), 'Node edit form exposes scheduling.');
  $check(in_array('::validateForm', $form['actions']['submit']['#validate'], TRUE), 'Core entity validation preserved.');
  $check(in_array('moody_scheduled_publishing_submit', $form['actions']['submit']['#submit'], TRUE), 'Schedule saves after entity.');
  $dashboard = Dashboard::create(\Drupal::getContainer())->build();
  $check($dashboard['#cache']['max-age'] === 0, 'Dashboard never shares cached content.');
  echo "Passed $checks scheduling integration checks.\n";
}
finally {
  $cleanup = static function () use ($storage, $nodes): void {
    foreach ($storage->loadMultiple($nodes) as $node) {
      $node->delete();
    }
  };
  if (\Drupal::hasService('trash.manager')) {
    \Drupal::service('trash.manager')->executeInTrashContext('ignore', $cleanup);
  }
  else {
    $cleanup();
  }
  if ($nodes) {
    $db->delete('moody_publish_event')->condition('nid', $nodes, 'IN')->execute();
    $db->delete('moody_publish_schedule')->condition('nid', $nodes, 'IN')->execute();
  }
  $editor?->delete();
  $role?->delete();
  $type?->delete();
  if ($last_run === NULL) {
    \Drupal::state()->delete('moody_scheduled_publishing.last_run');
  }
  else {
    \Drupal::state()->set('moody_scheduled_publishing.last_run', $last_run);
  }
  $switcher->switchBack();
}
