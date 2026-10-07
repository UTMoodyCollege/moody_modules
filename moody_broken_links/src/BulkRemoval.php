<?php

declare(strict_types=1);

namespace Drupal\moody_broken_links;

use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Native batch removal, using the existing revision-safe remediation service. */
final class BulkRemoval {

  public const ROOT = 'private://moody-broken-links-audit';

  private function authorize(): void {
    if (!\Drupal::currentUser()->isAuthenticated() || !\Drupal::currentUser()->hasPermission('administer moody broken links reports')) {
      throw new AccessDeniedHttpException();
    }
  }

  public static function codes(array $codes): array {
    foreach ($codes as $code) {
      if ((!is_int($code) && !is_string($code)) || !preg_match('/^(0|[45][0-9]{2})$/D', (string) $code)) {
        throw new \InvalidArgumentException('Invalid HTTP error code.');
      }
    }
    $codes = array_values(array_unique(array_map('intval', $codes)));
    if (!$codes || array_filter($codes, static fn($code) => $code !== 0 && ($code < 400 || $code > 599))) {
      throw new \InvalidArgumentException('Choose HTTP error codes (400–599), or 0 for failed connections.');
    }
    sort($codes);
    return $codes;
  }

  public function codeOptions(): array {
    $this->authorize();
    $scan = \Drupal::service('moody_broken_links.manager')->getLatestScan();
    if (!$scan || $scan['status'] !== 'complete') {
      return [];
    }
    $values = \Drupal::database()->select('moody_broken_links_result', 'r')->fields('r', ['http_code'])
      ->condition('scan_id', $scan['scan_id'])->condition('remediated', 0)
      ->condition('result_status', ['broken', 'warning'], 'IN')->distinct()->execute()->fetchCol();
    $options = [];
    foreach ($values as $code) {
      $code = (int) $code;
      if ($code === 0 || ($code >= 400 && $code <= 599)) {
        $options[$code] = $code === 0 ? '0 — connection/check failure' : (string) $code;
      }
    }
    ksort($options);
    return $options;
  }

  public static function eligible(array $row, bool $include_fields): bool {
    return in_array($row['result_status'], ['broken', 'warning'], TRUE)
      && (empty($row['source_data']['remove_item']) && ($row['source_data']['value_kind'] ?? '') === 'markup'
        || $include_fields && ($row['source_data']['value_kind'] ?? '') === 'uri');
  }

  /** Writes an exact private preview; no content changes. */
  public function preview(array $codes, bool $include_fields): string {
    $this->authorize();
    $codes = self::codes($codes);
    $manager = \Drupal::service('moody_broken_links.manager');
    $scan = $manager->getLatestScan();
    if (!$scan || $scan['status'] !== 'complete') {
      throw new \RuntimeException('Wait for the latest scan to finish before preparing removals.');
    }
    $rows = \Drupal::database()->select('moody_broken_links_result', 'r')->fields('r')
      ->condition('scan_id', $scan['scan_id'])->condition('remediated', 0)
      ->condition('result_status', ['broken', 'warning'], 'IN')->condition('http_code', $codes, 'IN')
      ->orderBy('nid')->orderBy('result_id')->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $pages = [];
    $excluded = [];
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    foreach ($rows as $row) {
      $row['source_data'] = json_decode($row['source_data'], TRUE, 512, JSON_THROW_ON_ERROR);
      $node = $storage->load($row['nid']);
      if (!self::eligible($row, $include_fields) || !$node || !$node->access('update', \Drupal::currentUser())) {
        $excluded[] = ['result_id' => (int) $row['result_id'], 'nid' => (int) $row['nid'], 'reason' => 'Unsupported removal type, link-field opt-in missing, or no page update access.'];
        continue;
      }
      $nid = (int) $row['nid'];
      $pages[$nid]['before_revision_id'] = (int) $node->getRevisionId();
      $pages[$nid]['title'] = (string) $row['title'];
      $pages[$nid]['url'] = $node->toUrl()->setAbsolute()->toString();
      $pages[$nid]['links'][] = [
        'result_id' => (int) $row['result_id'], 'href' => $row['href'], 'http_code' => (int) $row['http_code'],
        'status' => $row['result_status'], 'source' => $row['source_label'], 'langcode' => $row['langcode'],
        'link_text' => $row['link_text'], 'source_hash' => $row['source_hash'],
        'source_kind' => $row['source_data']['value_kind'], 'removes_field_item' => !empty($row['source_data']['remove_item']),
        'action' => 'remove',
      ];
    }
    if (!$pages) {
      throw new \RuntimeException('No eligible unrepaired links match this selection.');
    }
    $id = gmdate('Y-m-d') . '/' . bin2hex(random_bytes(16));
    $directory = self::ROOT . '/' . $id;
    if (!\Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException('Private audit storage is unavailable. No content changed.');
    }
    $this->write($directory . '/preview.json', [
      'audit_id' => $id, 'status' => 'preview', 'created' => time(), 'timezone' => 'UTC',
      'actor_uid' => (int) \Drupal::currentUser()->id(), 'actor_name' => \Drupal::currentUser()->getAccountName(),
      'site' => \Drupal::request()->getSchemeAndHttpHost(), 'scan_id' => (int) $scan['scan_id'],
      'codes' => $codes, 'include_link_fields' => $include_fields, 'pages' => $pages, 'excluded' => $excluded,
    ]);
    return $id;
  }

  private function directory(string $id): string {
    if (!preg_match('~^\d{4}-\d{2}-\d{2}/[a-f0-9]{32}$~D', $id)) {
      throw new \InvalidArgumentException('Invalid audit identifier.');
    }
    return self::ROOT . '/' . $id;
  }

  public function read(string $id): array {
    $this->authorize();
    $raw = file_get_contents($this->directory($id) . '/preview.json');
    if ($raw === FALSE) {
      throw new \RuntimeException('Audit report unavailable.');
    }
    return json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /** Replace via a temporary sibling; never truncate the previous evidence. */
  private function write(string $uri, array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $temp = $uri . '.' . bin2hex(random_bytes(8)) . '.tmp';
    // Drupal stream wrappers do not support LOCK_EX; unique siblings + rename
    // avoid truncation, and the batch takes a Drupal lock before page writes.
    if (file_put_contents($temp, $json) !== strlen($json) || !rename($temp, $uri)) {
      throw new \RuntimeException('Could not persist private audit evidence.');
    }
  }

  public function start(string $id): array {
    $lock = \Drupal::lock();
    $key = 'moody_broken_links.bulk.' . str_replace('/', '.', $id);
    if (!$lock->acquire($key, 120)) {
      throw new \RuntimeException('Another request is processing this audit.');
    }
    try {
    $plan = $this->read($id);
    if ($plan['actor_uid'] !== (int) \Drupal::currentUser()->id() || file_exists($this->directory($id) . '/started.json')) {
      throw new \RuntimeException('This preview is owned by another user or has already been started.');
    }
    $scan = \Drupal::service('moody_broken_links.manager')->getLatestScan();
    if ((int) ($scan['scan_id'] ?? 0) !== $plan['scan_id'] || $scan['status'] !== 'complete') {
      throw new \RuntimeException('The scan changed. Prepare a new preview.');
    }
    $this->write($this->directory($id) . '/started.json', ['confirmed_by' => $plan['actor_uid'], 'started' => time()]);
    return array_keys($plan['pages']);
    }
    finally {
      $lock->release($key);
    }
  }

  /** One page per native batch request, with durable intent and outcome. */
  public function applyPage(string $id, int $nid): array {
    $plan = $this->read($id);
    $directory = $this->directory($id);
    if ($plan['actor_uid'] !== (int) \Drupal::currentUser()->id() || !file_exists($directory . '/started.json') || !isset($plan['pages'][$nid])) {
      throw new AccessDeniedHttpException();
    }
    $lock = \Drupal::lock();
    $key = 'moody_broken_links.bulk.' . str_replace('/', '.', $id);
    if (!$lock->acquire($key, 120)) {
      throw new \RuntimeException('Another request is processing this audit.');
    }
    $uri = $directory . '/page-' . $nid . '.json';
    $transaction = NULL;
    $committed = FALSE;
    try {
      if (file_exists($uri)) {
        $outcome = json_decode(file_get_contents($uri), TRUE, 512, JSON_THROW_ON_ERROR);
        if (in_array($outcome['status'], ['intent', 'pending_commit'], TRUE)) {
          throw new \RuntimeException('Interrupted page attempt: review its audit and source revision before retrying.');
        }
        return $outcome;
      }
      $page = $plan['pages'][$nid];
      $record = $page + ['nid' => $nid, 'actor_uid' => $plan['actor_uid'], 'scan_id' => $plan['scan_id'], 'started' => time(), 'status' => 'intent'];
      $this->write($uri, $record);
      try {
        $manager = \Drupal::service('moody_broken_links.manager');
        $scan = $manager->getLatestScan();
        $storage = \Drupal::entityTypeManager()->getStorage('node');
        $storage->resetCache([$nid]);
        $node = $storage->load($nid);
        if ((int) ($scan['scan_id'] ?? 0) !== $plan['scan_id'] || $scan['status'] !== 'complete'
          || !$node || (int) $node->getRevisionId() !== $page['before_revision_id']) {
          throw new \RuntimeException('Scan or source revision changed; nothing on this page was removed.');
        }
        $changes = [];
        foreach ($page['links'] as $link) {
          $result = $manager->getResult($link['result_id']);
          if (!$result || (int) $result['remediated'] || $result['href'] !== $link['href'] || $result['source_hash'] !== $link['source_hash']) {
            throw new \RuntimeException('A finding changed since preview.');
          }
          $changes[$link['result_id']] = ['action' => 'remove', 'replacement' => NULL];
        }
        $transaction = \Drupal::database()->startTransaction();
        $outcome = $manager->remediatePage($plan['scan_id'], $nid, $changes);
        $record['status'] = 'pending_commit';
        $record['after_revision_id'] = $outcome['revision_id'];
        $record['changed'] = $outcome['changed'];
        $record['completed'] = time();
        $this->write($uri, $record);
        unset($transaction);
        $committed = TRUE;
        $record['status'] = 'changed';
        $this->write($uri, $record);
      }
      catch (\Throwable $e) {
        if ($committed) {
          throw new \RuntimeException('Content saved but audit finalization failed. Review the pending_commit record and revisions before continuing.', 0, $e);
        }
        if (isset($transaction)) {
          $transaction->rollBack();
          $transaction = NULL;
        }
        $record['status'] = 'failed';
        unset($record['after_revision_id']);
        $record['changed'] = 0;
        $record['message'] = $e->getMessage();
        $record['completed'] = time();
        $this->write($uri, $record);
      }
      return $record;
    }
    finally {
      $lock->release($key);
    }
  }

  public function report(string $id): array {
    $plan = $this->read($id);
    $plan['outcomes'] = [];
    foreach (array_keys($plan['pages']) as $nid) {
      $uri = $this->directory($id) . '/page-' . (int) $nid . '.json';
      if (file_exists($uri)) {
        $plan['outcomes'][] = json_decode(file_get_contents($uri), TRUE, 512, JSON_THROW_ON_ERROR);
      }
    }
    $plan['status'] = file_exists($this->directory($id) . '/finished.json') ? 'finished' : (file_exists($this->directory($id) . '/started.json') ? 'started' : 'preview');
    if ($plan['status'] === 'finished') {
      $plan['completion'] = json_decode(file_get_contents($this->directory($id) . '/finished.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    return $plan;
  }

  public function finish(string $id, bool $success): void {
    $plan = $this->read($id);
    if ($plan['actor_uid'] !== (int) \Drupal::currentUser()->id()) {
      throw new AccessDeniedHttpException();
    }
    $this->write($this->directory($id) . '/finished.json', ['batch_completed' => $success, 'finished' => time()]);
  }

  public function audits(): array {
    $this->authorize();
    if (!is_dir(self::ROOT)) {
      return [];
    }
    // ponytail: scan audit metadata on demand; index only if report volume warrants it.
    $files = \Drupal::service('file_system')->scanDirectory(self::ROOT, '/^preview\.json$/');
    $audits = [];
    foreach ($files as $file) {
      $id = substr(dirname($file->uri), strlen(self::ROOT) + 1);
      $audits[$id] = $this->read($id);
    }
    uasort($audits, static fn($a, $b) => $b['created'] <=> $a['created']);
    return $audits;
  }

}
