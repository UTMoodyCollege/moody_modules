<?php

/** Native local queue/checkpoint regression, without external HTTP calls. */
if (!str_ends_with(\Drupal::request()->getHost(), '.ddev.site')) {
  throw new RuntimeException('Local DDEV only.');
}
$db = \Drupal::database();
$nodes = \Drupal::entityTypeManager()->getStorage('node');
$manager = \Drupal::service('moody_broken_links.manager');
$worker = \Drupal::service('moody_broken_links.background_scan');
$ids = [];
$scan_id = 0;
try {
  for ($i = 0; $i < 2; $i++) {
    $node = $nodes->create(['type' => 'article', 'title' => 'Background scan smoke ' . bin2hex(random_bytes(5)), 'status' => 0,
      'body' => ['value' => '<p><a href="http://localhost/check">Local guarded link</a></p>', 'format' => 'flex_html']]);
    $node->save();
    $ids[] = (int) $node->id();
  }
  $scan_id = $manager->createScan(1, ['article'], 2);
  $worker->enqueue(['scan_id' => $scan_id, 'node_ids' => $ids], 'https://2moody-core.ddev.site');
  $first = ['scan_id' => $scan_id, 'nid' => $ids[0], 'offset' => 0, 'uri' => 'https://2moody-core.ddev.site'];
  $worker->process($first);
  $worker->process($first);
  if ((int) $manager->getLatestScan()['processed_nodes'] !== 1) {
    throw new RuntimeException('Replayed item duplicated page progress.');
  }
  $worker->process(['scan_id' => $scan_id, 'nid' => $ids[1], 'offset' => 1, 'uri' => 'https://2moody-core.ddev.site']);
  $scan = $manager->getLatestScan();
  if ($scan['status'] !== 'complete' || (int) $scan['processed_nodes'] !== 2 || (int) $scan['total_links'] !== 2) {
    throw new RuntimeException('Queue completion or atomic scan totals failed.');
  }
  $worker->process($first);
  echo "Background queue, replay safety and completion passed.\n";
}
finally {
  foreach ($db->select('queue', 'q')->fields('q', ['item_id', 'data'])->condition('name', \Drupal\moody_broken_links\BackgroundScan::QUEUE)->execute() as $item) {
    $data = unserialize($item->data, ['allowed_classes' => FALSE]);
    if (($data['scan_id'] ?? 0) === $scan_id) {
      $db->delete('queue')->condition('item_id', $item->item_id)->execute();
    }
  }
  if ($scan_id) {
    $db->delete('moody_broken_links_result')->condition('scan_id', $scan_id)->execute();
    $db->delete('moody_broken_links_scan')->condition('scan_id', $scan_id)->execute();
  }
  $delete = static function () use ($nodes, $ids): void { $nodes->delete($nodes->loadMultiple($ids)); };
  if (\Drupal::hasService('trash.manager')) {
    \Drupal::service('trash.manager')->executeInTrashContext('ignore', $delete);
  }
  else {
    $delete();
  }
}
