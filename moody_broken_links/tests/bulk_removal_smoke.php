<?php

/** Local DDEV only: creates and deletes its own drafts, findings and audits. */
if (!str_ends_with(\Drupal::request()->getHost(), '.ddev.site')) {
  throw new RuntimeException('Run this test only on local DDEV.');
}
function bulk_check($condition, $message) {
  if (!$condition) { throw new RuntimeException($message); }
}
$bulk = \Drupal::service('moody_broken_links.bulk_removal');
$manager = \Drupal::service('moody_broken_links.manager');
$db = \Drupal::database();
$storage = \Drupal::entityTypeManager()->getStorage('node');
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(\Drupal::entityTypeManager()->getStorage('user')->load(1));
$ids = $audits = [];
$scan_id = 0;
try {
  foreach ([[200], [403], [404], [410], [500], [0]] as $codes) {
    try { \Drupal\moody_broken_links\BulkRemoval::codes($codes); bulk_check($codes !== [200], '200 accepted'); }
    catch (InvalidArgumentException $e) { bulk_check($codes === [200], 'Valid code rejected'); }
  }
  foreach ([['banana'], [TRUE], ['404 OR 1=1'], [301], []] as $bad) {
    try { \Drupal\moody_broken_links\BulkRemoval::codes($bad); throw new LogicException('Invalid codes accepted'); }
    catch (InvalidArgumentException $expected) {}
  }
  $html = '<p><a href="/working">Working</a> <a href="/forbidden"><strong>Forbidden text</strong></a> <a href="/missing">Missing text</a></p>';
  $scan_id = $manager->createScan(1, ['page'], 2);
  $db->update('moody_broken_links_scan')->fields(['status' => 'complete'])->condition('scan_id', $scan_id)->execute();
  foreach (['normal', 'stale'] as $key) {
    $node = $storage->create(['type' => 'page', 'title' => 'Disposable bulk removal ' . $key, 'status' => 0, 'uid' => 1, 'body' => ['value' => $html, 'format' => 'full_html']]);
    if ($node->hasField('moderation_state')) { $node->set('moderation_state', 'draft'); }
    $node->save();
    $ids[$key] = (int) $node->id();
    foreach ([['/working', 200, 'ok'], ['/forbidden', 403, 'warning'], ['/missing', 404, 'broken']] as $occurrence => [$href, $code, $status]) {
      $source = ['nid' => $node->id(), 'bundle' => 'page', 'title' => $node->label(), 'langcode' => $node->language()->getId(), 'source_type' => 'node_field', 'field_name' => 'body', 'field_delta' => 0, 'property' => 'value', 'value_kind' => 'markup'];
      $db->insert('moody_broken_links_result')->fields([
        'scan_id' => $scan_id, 'nid' => $node->id(), 'bundle' => 'page', 'title' => $node->label(), 'langcode' => $node->language()->getId(),
        'source_type' => 'node_field', 'source_data' => json_encode($source), 'source_label' => 'Body', 'source_hash' => hash('sha256', $html),
        'href' => $href, 'absolute_url' => 'https://example.test' . $href, 'url_hash' => hash('sha256', $href), 'link_text' => 'Fixture',
        'occurrence' => $occurrence, 'result_status' => $status, 'http_code' => $code,
      ])->execute();
    }
  }
  $revision = $storage->load($ids['normal'])->getRevisionId();
  $id = $bulk->preview([403, 404], FALSE);
  $audits[] = $id;
  $plan = $bulk->read($id);
  bulk_check(count($plan['pages']) === 2 && count($plan['pages'][$ids['normal']]['links']) === 2, 'Preview selection incorrect');
  bulk_check($storage->load($ids['normal'])->getRevisionId() === $revision, 'Preview mutated content');
  $stale = $storage->load($ids['stale']);
  $stale->setNewRevision(TRUE);
  $stale->setTitle('Changed after preview');
  $stale->save();
  $bulk->start($id);
  try { $bulk->start($id); throw new LogicException('Audit started twice'); } catch (RuntimeException $expected) {}
  $result = $bulk->applyPage($id, $ids['normal']);
  bulk_check($result['status'] === 'changed' && $result['changed'] === 2, 'Removal failed: ' . json_encode($result));
  $storage->resetCache([$ids['normal']]);
  $changed = $storage->load($ids['normal']);
  bulk_check(!$changed->isPublished(), 'Draft was published');
  $body = $changed->get('body')->value;
  bulk_check(str_contains($body, 'href="/working"') && !str_contains($body, 'href="/missing"') && !str_contains($body, 'href="/forbidden"') && str_contains($body, '<strong>Forbidden text</strong>') && str_contains($body, 'Missing text'), 'Text preservation or 200 protection failed');
  $saved_revision = $changed->getRevisionId();
  bulk_check($bulk->applyPage($id, $ids['normal'])['after_revision_id'] === (int) $saved_revision, 'Retry changed revision');
  $failed = $bulk->applyPage($id, $ids['stale']);
  bulk_check($failed['status'] === 'failed', 'Stale page was accepted');
  $storage->resetCache([$ids['stale']]);
  bulk_check($storage->load($ids['stale'])->get('body')->value === $html, 'Stale content changed');
  $bulk->finish($id, TRUE);
  bulk_check(count($bulk->report($id)['outcomes']) === 2, 'Missing audit outcomes');
  bulk_check(isset($bulk->audits()[$id]), 'Audit not discoverable');
  $response = (new \Drupal\moody_broken_links\Controller\AuditController())->download(...explode('/', $id));
  bulk_check(str_contains($response->headers->get('Cache-Control'), 'no-store') && $response->headers->get('X-Content-Type-Options') === 'nosniff', 'Audit response not private');
  bulk_check(moody_broken_links_file_download('private://moody-broken-links-audit/' . $id . '/preview.json') === -1, 'Direct private download allowed');
  try { $bulk->read('../escape'); throw new LogicException('Traversal accepted'); } catch (InvalidArgumentException $expected) {}
  $switcher->switchTo(\Drupal::entityTypeManager()->getStorage('user')->load(0));
  try {
    try { $bulk->read($id); throw new LogicException('Anonymous audit access allowed'); } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $expected) {}
    try { $bulk->preview([404], FALSE); throw new LogicException('Anonymous preview allowed'); } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $expected) {}
  }
  finally { $switcher->switchBack(); }
  $row = ['result_status' => 'broken', 'source_data' => ['value_kind' => 'uri', 'remove_item' => TRUE]];
  bulk_check(!\Drupal\moody_broken_links\BulkRemoval::eligible($row, FALSE) && \Drupal\moody_broken_links\BulkRemoval::eligible($row, TRUE), 'Link-field opt-in ignored');
  echo "Bulk preview, removal, stale revision, idempotence, audit privacy and permission checks passed.\n";
}
finally {
  foreach ($audits as $id) { \Drupal::service('file_system')->deleteRecursive(\Drupal\moody_broken_links\BulkRemoval::ROOT . '/' . $id); }
  if ($ids) {
    $delete = static function () use ($storage, $ids) { $storage->delete($storage->loadMultiple(array_values($ids))); };
    if (\Drupal::hasService('trash.manager')) { \Drupal::service('trash.manager')->executeInTrashContext('ignore', $delete); } else { $delete(); }
  }
  if ($scan_id) {
    $db->delete('moody_broken_links_result')->condition('scan_id', $scan_id)->execute();
    $db->delete('moody_broken_links_scan')->condition('scan_id', $scan_id)->execute();
  }
  $switcher->switchBack();
}
