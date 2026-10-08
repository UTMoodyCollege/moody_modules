<?php

namespace Drupal\moody_broken_links;

/** Persistent, revision-independent scan work shared by cron and kickoff. */
final class BackgroundScan {

  public const QUEUE = 'moody_broken_links_scan';

  public function enqueue(array $scan, string $uri): void {
    $queue = \Drupal::queue(self::QUEUE);
    $transaction = \Drupal::database()->startTransaction();
    try {
      foreach (array_values($scan['node_ids']) as $offset => $nid) {
        $queue->createItem(['scan_id' => (int) $scan['scan_id'], 'nid' => (int) $nid, 'offset' => $offset, 'uri' => $uri]);
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  public function process(array $data): void {
    if (!isset($data['scan_id'], $data['nid'], $data['offset'], $data['uri'])
      || !is_int($data['scan_id']) || !is_int($data['nid']) || !is_int($data['offset'])
      || $data['scan_id'] < 1 || $data['nid'] < 1 || $data['offset'] < 0
      || !is_string($data['uri']) || !preg_match('@^https?://[a-zA-Z0-9.-]+(?::[0-9]+)?$@D', $data['uri'])) {
      throw new \InvalidArgumentException('Invalid background scan item.');
    }
    $lock = \Drupal::lock();
    $key = 'moody_broken_links.scan.' . $data['scan_id'];
    if (!$lock->acquire($key, 900)) {
      throw new \Drupal\Core\Queue\RequeueException('Scan worker already active.');
    }
    try {
      $manager = \Drupal::service('moody_broken_links.manager');
      $scan = $manager->getLatestScan();
      if (!$scan || (int) $scan['scan_id'] !== $data['scan_id'] || $scan['status'] !== 'running') {
        return;
      }
      $processed = (int) $scan['processed_nodes'];
      if ($processed > $data['offset']) {
        return; // A committed item whose queue acknowledgement was interrupted.
      }
      if ($processed !== $data['offset']) {
        throw new \Drupal\Core\Queue\RequeueException('Waiting for preceding page.');
      }
      $transaction = \Drupal::database()->startTransaction();
      try {
        if (!\Drupal::entityTypeManager()->getStorage('node')->load($data['nid'])) {
          // A page deleted after kickoff still consumes its inventory position.
          \Drupal::database()->update('moody_broken_links_scan')->expression('processed_nodes', 'processed_nodes + 1')->condition('scan_id', $data['scan_id'])->execute();
        }
        else {
          $context = [];
          $manager->scanNodes($data['scan_id'], [$data['nid']], $data['uri'], $context);
        }
        if ($processed + 1 === (int) $scan['total_nodes']) {
          $manager->finishScan($data['scan_id']);
        }
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
    }
    finally {
      $lock->release($key);
    }
  }

  /** Starts one item after the response; cron handles remaining persistent work. */
  public function kickoff(): void {
    $queue = \Drupal::queue(self::QUEUE);
    if (!$item = $queue->claimItem(900)) {
      return;
    }
    try {
      $this->process($item->data);
      $queue->deleteItem($item);
    }
    catch (\Throwable $e) {
      $queue->releaseItem($item);
      \Drupal::logger('moody_broken_links')->error('Background scan kickoff paused: @error', ['@error' => $e->getMessage()]);
    }
  }
}
