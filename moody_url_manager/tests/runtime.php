<?php

use Drupal\moody_url_manager\UrlManager;
use Drupal\node\Entity\Node;
use Drupal\Core\Session\UserSession;

if (getenv('IS_DDEV_PROJECT') !== 'true') { throw new RuntimeException('Local DDEV test only.'); }
$transaction = \Drupal::database()->startTransaction();
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(\Drupal\user\Entity\User::load(1));
$checks = 0;
$check = function ($value, $message) use (&$checks) { if (!$value) { throw new RuntimeException($message); } $checks++; };
$reject = function ($callback, $message) use ($check) { try { $callback(); } catch (\Exception $e) { $check(TRUE, $message); return; } throw new RuntimeException($message); };
try {
  $check(!UrlManager::overallAccess(new UserSession(['uid' => 987654, 'roles' => ['authenticated', 'moody_subsite_editor']]))->isAllowed(), 'Subsite editor cannot access overall');
  $check(UrlManager::overallAccess(new UserSession(['uid' => 987654, 'roles' => ['administrator']]))->isAllowed(), 'Administrator allowed');
  foreach (['https://example.com/x', '//evil.test/x', '/admin/new', '/node/2', '/one?x=1', '/one/', '/a//b'] as $invalid) { $reject(fn() => UrlManager::validateAlias($invalid), 'Unsafe alias rejected'); }
  $node = Node::create(['type' => 'page', 'title' => 'URL manager test', 'status' => 1, 'body' => ['value' => 'Original copy', 'format' => 'plain_text'], 'path' => ['alias' => '/url-manager-qa-old', 'pathauto' => 0]]);
  $node->save();
  $snapshot = UrlManager::snapshot($node);
  $plan = UrlManager::plan((int) $node->id(), '/url-manager-qa-new', NULL);
  $check(UrlManager::snapshot($node) === $snapshot, 'Preview is read-only');
  $reject(fn() => UrlManager::plan((int) $node->id(), '/admin/content', NULL), 'Reserved path blocked');
  $reject(fn() => UrlManager::plan((int) $node->id(), '/url-manager-qa-old', NULL), 'Unchanged alias blocked');
  $other = Node::create(['type' => 'page', 'title' => 'Occupied destination', 'path' => ['alias' => '/url-manager-qa-taken', 'pathauto' => 0]]); $other->save();
  $reject(fn() => UrlManager::plan((int) $node->id(), '/url-manager-qa-taken', NULL), 'Occupied destination blocked');
  $redirects = \Drupal::entityTypeManager()->getStorage('redirect');
  $incoming = $redirects->create(['status_code' => 301, 'language' => 'und']); $incoming->setSource('url-manager-qa-campaign'); $incoming->setRedirect('/url-manager-qa-old'); $incoming->save();
  $reject(function () use ($plan) { $context = []; UrlManager::apply($plan, $context); }, 'Changed redirect graph invalidates preview');
  $plan = UrlManager::plan((int) $node->id(), '/url-manager-qa-new', NULL);
  $context = []; UrlManager::apply($plan, $context);
  \Drupal::entityTypeManager()->getStorage('node')->resetCache();
  $saved = Node::load($node->id());
  $check($saved->get('path')->alias === '/url-manager-qa-new', 'Alias saved');
  $check($saved->get('body')->value === 'Original copy' && $saved->isPublished(), 'Copy and status preserved');
  $check($saved->getRevisionId() !== $snapshot['revision'], 'New node revision');
  $check((bool) $redirects->load($incoming->id()), 'Existing campaign redirect retained');
  $ids = $redirects->getQuery()->accessCheck(FALSE)->condition('redirect_source.path', 'url-manager-qa-old')->execute();
  $check(count($ids) === 1, 'One redirect protects old URL');
  $redirect = $redirects->load(reset($ids));
  $check((int) $redirect->getStatusCode() === 301 && $redirect->get('redirect_redirect')->uri === 'internal:/node/' . $node->id(), 'Permanent redirect follows node');
  $reject(fn() => UrlManager::plan((int) $node->id(), '/url-manager-qa-campaign', NULL), 'Existing campaign source cannot be hijacked');
  $subsites = \Drupal::entityTypeManager()->getStorage('moody_subsite')->loadMultiple();
  if ($subsites) {
    $sid = (int) array_key_first($subsites);
    $check(!UrlManager::subsiteAccess((string) $sid, new UserSession())->isAllowed(), 'Anonymous raw route parameter is denied safely');
    $check(UrlManager::subsiteAccess((string) $sid, \Drupal::currentUser())->isAllowed(), 'Admin raw route parameter is resolved');
    $reject(fn() => UrlManager::plan((int) $node->id(), '/url-manager-qa-next', $sid), 'Out-of-subsite page blocked');
  }
  $check(UrlManager::csvCell('=SUM(A1)') === "\t=SUM(A1)" && UrlManager::csvCell(' ordinary title') === ' ordinary title', 'CSV formula safety');
  $fixture_ids = [];
  for ($i = 0; $i < 27; $i++) {
    $fixture = Node::create(['type' => 'page', 'title' => 'URL CSV QA fixture ' . $i, 'status' => $i === 26 ? 0 : 1]);
    if ($i === 26 && $fixture->hasField('moderation_state')) { $fixture->set('moderation_state', 'draft'); }
    $fixture->save(); $fixture_ids[] = (int) $fixture->id();
  }
  $request = \Symfony\Component\HttpFoundation\Request::create('/admin/content/moody-urls?search=URL%20CSV%20QA%20fixture&page=1&per_page=25');
  $stack = \Drupal::requestStack(); $stack->push($request);
  try {
    $form_object = new \Drupal\moody_url_manager\Form\UrlManagerForm();
    $state = new \Drupal\Core\Form\FormState(); $state->set('scope', NULL); $form = [];
    $form_object->export($form, $state);
    ob_start(); $state->getResponse()->sendContent(); $csv = ob_get_clean();
    $stream = fopen('php://memory', 'r+'); fwrite($stream, $csv); rewind($stream); $rows = [];
    while (($row = fgetcsv($stream, 0, ',', '"', '')) !== FALSE) { $rows[] = $row; } fclose($stream);
    $check(count($rows) === 28, 'CSV exports all 27 matches from pager page 2');
    $check(count(array_filter(array_slice($rows, 1), fn($r) => $r[3] === 'Draft')) === 1, 'CSV includes visible drafts');
    $check(count(UrlManager::filteredQuery(NULL, 'URL CSV QA fixture', 'published')->execute()) === 26, 'Published filter shared with export');
    $check($state->getResponse()->headers->get('Cache-Control') === 'no-store, private', 'CSV is not publicly cached');
  }
  finally { $stack->pop(); }
  $delete_id = $fixture_ids[0];
  $delete_plan = UrlManager::planDelete($delete_id, NULL);
  $stack->push(\Symfony\Component\HttpFoundation\Request::create('/batch?op=do'));
  try {
    \Drupal::entityTypeManager()->getStorage('node')->resetCache([$delete_id]);
    $check(UrlManager::planDelete($delete_id, NULL) === $delete_plan, 'Deletion preview is stable on a different request URL');
  }
  finally { $stack->pop(); }
  $check((bool) Node::load($delete_id), 'Delete preview changes nothing');
  $changed = Node::load($delete_id); $changed->setNewRevision(TRUE); $changed->setTitle('Changed after preview'); $changed->save();
  $reject(function () use ($delete_plan) { $context = []; UrlManager::deletePage($delete_plan, $context); }, 'Stale deletion preview blocked');
  $switcher->switchTo(new UserSession());
  try { $reject(fn() => UrlManager::planDelete($delete_id, NULL), 'Anonymous deletion denied'); }
  finally { $switcher->switchBack(); }
  $delete_context = [];
  foreach (array_slice($fixture_ids, 0, 2) as $id) {
    $delete_plan = UrlManager::planDelete($id, NULL);
    UrlManager::deletePage($delete_plan, $delete_context);
    \Drupal::entityTypeManager()->getStorage('node')->resetCache([$id]);
    $check(!Node::load($id), 'Confirmed page deleted');
  }
  $check(count($delete_context['results']) === 2, 'Bulk deletion records every page');
  $reject(fn() => UrlManager::planDelete((int) $node->id(), $sid ?? 999999), 'Out-of-scope deletion denied');
  $saved->setNewRevision(TRUE); $saved->setUnpublished();
  if ($saved->hasField('moderation_state')) { $saved->set('moderation_state', 'draft'); }
  $saved->isDefaultRevision(FALSE); $saved->save();
  \Drupal::entityTypeManager()->getStorage('node')->resetCache();
  $reject(fn() => UrlManager::plan((int) $node->id(), '/url-manager-qa-next', NULL), 'Pending draft blocks bulk changes');

  $reject(fn() => UrlManager::planDelete((int) $node->id(), NULL), 'Pending draft blocks deletion');

  echo json_encode(['passed' => $checks, 'transaction_rolled_back' => TRUE]) . PHP_EOL;
}
finally { $transaction->rollBack(); $switcher->switchBack(); }
