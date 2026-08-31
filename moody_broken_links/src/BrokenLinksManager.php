<?php

declare(strict_types=1);

namespace Drupal\moody_broken_links;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * Scans content links and applies revision-safe remediations.
 */
final class BrokenLinksManager {

  private const SCAN_TABLE = 'moody_broken_links_scan';
  private const RESULT_TABLE = 'moody_broken_links_result';
  private const HTML_FIELD_TYPES = ['text', 'text_long', 'text_with_summary'];

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly ClientInterface $httpClient,
  ) {}

  /**
   * Returns all node bundle labels, keyed by machine name.
   */
  public function getBundleOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $type) {
      $options[$type->id()] = $type->label();
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  /**
   * Creates a scan and returns the selected node IDs.
   */
  public function prepareScan(array $bundles, int $uid, ?int $node_id = NULL): array {
    $node_storage = $this->entityTypeManager->getStorage('node');
    if ($node_id) {
      $node = $node_storage->load($node_id);
      if (!$node instanceof NodeInterface) {
        throw new \InvalidArgumentException('Select a valid page.');
      }
      $bundles = [$node->bundle()];
      $node_ids = [$node_id];
    }
    else {
      $bundles = array_values(array_unique(array_filter(array_map('strval', $bundles))));
      $available = $this->getBundleOptions();
      $unknown = array_diff($bundles, array_keys($available));
      if (!$bundles || $unknown) {
        throw new \InvalidArgumentException('Select at least one valid content type.');
      }
      $node_ids = array_map('intval', $node_storage
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $bundles, 'IN')
        ->sort('nid')
        ->execute());
    }

    $latest = $this->getLatestScan();
    if ($latest && $latest['status'] === 'running') {
      if ((int) $latest['started'] > time() - 21600) {
        throw new \RuntimeException('A broken-link scan is already running.');
      }
      $this->markScanFailed((int) $latest['scan_id']);
    }

    $transaction = $this->database->startTransaction();
    try {
      $this->database->delete(self::RESULT_TABLE)->execute();
      $this->database->delete(self::SCAN_TABLE)->execute();
      $scan_id = $this->createScan($uid, $bundles, count($node_ids));
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }

    return [
      'scan_id' => $scan_id,
      'node_ids' => $node_ids,
    ];
  }

  /**
   * Creates one scan row. Public for isolated smoke tests.
   */
  public function createScan(int $uid, array $bundles, int $total_nodes): int {
    return (int) $this->database->insert(self::SCAN_TABLE)
      ->fields([
        'uid' => $uid,
        'status' => 'running',
        'started' => time(),
        'bundles' => Json::encode(array_values($bundles)),
        'total_nodes' => $total_nodes,
      ])
      ->execute();
  }

  /**
   * Scans one batch of current nodes and Layout Builder inline blocks.
   */
  public function scanNodes(int $scan_id, array $node_ids, string $site_base_url, array &$context): void {
    $node_ids = array_values(array_unique(array_map('intval', $node_ids)));
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($node_ids);
    $found = 0;

    foreach ($nodes as $node) {
      foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
        $translation = $node->getTranslation($langcode);
        $page_url = $this->pageUrl($translation, $site_base_url);
        $found += $this->scanEntityFields($scan_id, $translation, [
          'nid' => (int) $node->id(),
          'bundle' => $node->bundle(),
          'title' => (string) $translation->label(),
          'langcode' => $langcode,
          'source_type' => 'node_field',
        ], $page_url);
        $found += $this->scanInlineBlocks($scan_id, $translation, $page_url);
      }
    }

    $this->database->update(self::SCAN_TABLE)
      ->expression('processed_nodes', 'processed_nodes + :processed', [':processed' => count($nodes)])
      ->condition('scan_id', $scan_id)
      ->execute();

    $context['results']['scan_id'] = $scan_id;
    $context['results']['processed_nodes'] = ($context['results']['processed_nodes'] ?? 0) + count($nodes);
    $context['results']['found_links'] = ($context['results']['found_links'] ?? 0) + $found;
    $context['message'] = t('Scanned @nodes pages and found @links checkable links.', [
      '@nodes' => $context['results']['processed_nodes'],
      '@links' => $context['results']['found_links'],
    ]);
  }

  /**
   * Marks a completed scan and returns its totals.
   */
  public function finishScan(int $scan_id): array {
    $total = (int) $this->database->select(self::RESULT_TABLE, 'r')
      ->condition('scan_id', $scan_id)
      ->countQuery()
      ->execute()
      ->fetchField();
    $broken = $this->countResults($scan_id, 'broken');
    $warning = $this->countResults($scan_id, 'warning');

    $this->database->update(self::SCAN_TABLE)
      ->fields([
        'status' => 'complete',
        'completed' => time(),
        'total_links' => $total,
        'broken_links' => $broken,
        'warning_links' => $warning,
      ])
      ->condition('scan_id', $scan_id)
      ->execute();

    return [
      'total_links' => $total,
      'broken_links' => $broken,
      'warning_links' => $warning,
    ];
  }

  /**
   * Marks an interrupted scan as failed.
   */
  public function markScanFailed(int $scan_id): void {
    $this->database->update(self::SCAN_TABLE)
      ->fields(['status' => 'failed', 'completed' => time()])
      ->condition('scan_id', $scan_id)
      ->execute();
  }

  /**
   * Returns the latest scan, if any.
   */
  public function getLatestScan(): ?array {
    $row = $this->database->select(self::SCAN_TABLE, 's')
      ->fields('s')
      ->orderBy('scan_id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    $row['bundles'] = Json::decode((string) $row['bundles']) ?: [];
    return $row;
  }

  /**
   * Builds a dashboard result query.
   */
  public function resultQuery(int $scan_id, string $status, string $search = ''): SelectInterface {
    $query = $this->database->select(self::RESULT_TABLE, 'r');
    $query->fields('r');
    $query->condition('r.scan_id', $scan_id);
    if (in_array($status, ['broken', 'warning', 'ok'], TRUE)) {
      $query->condition('r.result_status', $status);
    }
    elseif ($status === 'needs_attention') {
      $query->condition('r.result_status', ['broken', 'warning'], 'IN');
    }
    if ($search !== '') {
      $group = $query->orConditionGroup()
        ->condition('r.title', '%' . $this->database->escapeLike($search) . '%', 'LIKE')
        ->condition('r.href', '%' . $this->database->escapeLike($search) . '%', 'LIKE')
        ->condition('r.source_label', '%' . $this->database->escapeLike($search) . '%', 'LIKE');
      $query->condition($group);
    }
    $query->orderBy('r.remediated');
    $query->orderBy('r.result_status');
    $query->orderBy('r.nid');
    $query->orderBy('r.result_id');
    return $query;
  }

  /**
   * Returns one result row.
   */
  public function getResult(int $result_id): ?array {
    $row = $this->database->select(self::RESULT_TABLE, 'r')
      ->fields('r')
      ->condition('result_id', $result_id)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    $row['source_data'] = Json::decode((string) $row['source_data']) ?: [];
    return $row;
  }

  /**
   * Returns the active results for one page in a scan.
   */
  public function getPageResults(int $scan_id, int $nid): array {
    $query = $this->database->select(self::RESULT_TABLE, 'r');
    $query->fields('r');
    $query->condition('scan_id', $scan_id);
    $query->condition('nid', $nid);
    $query->condition('remediated', 0);
    $query->orderBy('source_label');
    $query->orderBy('result_id');

    $results = [];
    foreach ($query->execute() as $row) {
      $result = (array) $row;
      $result['source_data'] = Json::decode((string) $result['source_data']) ?: [];
      $results[(int) $result['result_id']] = $result;
    }
    return $results;
  }

  /**
   * Validates a replacement entered by an administrator.
   */
  public function validateReplacement(string $replacement): string {
    $replacement = trim($replacement);
    if ($replacement === '' || strlen($replacement) > 2048 || preg_match('/[\s\x00-\x1F\x7F]/', $replacement)) {
      throw new \InvalidArgumentException('Enter a valid URL without control characters.');
    }
    $site_relative = str_starts_with($replacement, '/') && !str_starts_with($replacement, '//');
    $internal = str_starts_with($replacement, 'internal:/') && !str_starts_with($replacement, 'internal://');
    if ($site_relative || $internal || preg_match('@^entity:node/\d+$@', $replacement)) {
      return $replacement;
    }
    $parts = parse_url($replacement);
    if (!$parts || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], TRUE)) {
      throw new \InvalidArgumentException('Use an absolute HTTP(S) URL or a site-relative path beginning with /.');
    }
    return $replacement;
  }

  /**
   * Revises or removes one exact scanned link occurrence.
   */
  public function remediate(int $result_id, string $action, ?string $replacement = NULL): array {
    $result = $this->getResult($result_id);
    if (!$result || (int) $result['remediated']) {
      throw new \InvalidArgumentException('This result is no longer available for remediation.');
    }
    $changed = $this->remediatePage((int) $result['scan_id'], (int) $result['nid'], [
      $result_id => [
        'action' => $action,
        'replacement' => $replacement,
      ],
    ]);
    $changed['action'] = $action;
    return $changed;
  }

  /**
   * Applies several scanned link changes to one page in one revision.
   */
  public function remediatePage(int $scan_id, int $nid, array $changes): array {
    if (!$this->currentUser->hasPermission('administer moody broken links reports')) {
      throw new \RuntimeException('You do not have permission to remediate links.');
    }
    if (!$changes) {
      throw new \InvalidArgumentException('Queue at least one link change.');
    }

    $available = $this->getPageResults($scan_id, $nid);
    $entries = [];
    foreach ($changes as $result_id => $change) {
      $result_id = (int) $result_id;
      if (!$result_id || !isset($available[$result_id]) || !is_array($change)) {
        throw new \InvalidArgumentException('One or more queued results are no longer available.');
      }
      $action = (string) ($change['action'] ?? '');
      if (!in_array($action, ['revise', 'remove'], TRUE)) {
        throw new \InvalidArgumentException('Unsupported remediation action.');
      }
      $replacement = NULL;
      if ($action === 'revise') {
        $replacement = $this->validateReplacement((string) ($change['replacement'] ?? ''));
      }
      $entries[] = [
        'result' => $available[$result_id],
        'action' => $action,
        'replacement' => $replacement,
      ];
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $storage->resetCache([$nid]);
    $node = $storage->load($nid);
    $first = $entries[0]['result'];
    if (!$node instanceof NodeInterface || $node->bundle() !== $first['bundle']) {
      throw new \RuntimeException('The source page no longer exists. Run a new scan.');
    }

    $node_fields = [];
    $inline_blocks = [];
    foreach ($entries as $entry) {
      $result = $entry['result'];
      if ($result['bundle'] !== $node->bundle()) {
        throw new \RuntimeException('The queued results do not belong to one page.');
      }
      $langcode = (string) $result['langcode'];
      if (!$node->hasTranslation($langcode)) {
        throw new \RuntimeException('A scanned page translation no longer exists. Run a new scan.');
      }
      $source = $result['source_data'];
      $field_name = (string) ($source['field_name'] ?? '');
      if ($result['source_type'] === 'node_field') {
        $node_fields[$langcode][$field_name][] = $entry;
      }
      elseif ($result['source_type'] === 'inline_block_field') {
        $block_key = Json::encode([
          $langcode,
          (int) ($source['section_delta'] ?? -1),
          (string) ($source['component_uuid'] ?? ''),
          (int) ($source['block_id'] ?? 0),
        ]);
        $inline_blocks[$block_key]['source'] = $source;
        $inline_blocks[$block_key]['langcode'] = $langcode;
        $inline_blocks[$block_key]['fields'][$field_name][] = $entry;
      }
      else {
        throw new \RuntimeException('This source type cannot be remediated safely.');
      }
    }

    $transaction = NULL;
    try {
      foreach ($node_fields as $langcode => $fields) {
        $translation = $node->getTranslation($langcode);
        if (!$translation->access('update', $this->currentUser)) {
          throw new \RuntimeException('You do not have update access to this page.');
        }
        foreach ($fields as $field_entries) {
          $this->mutateFieldBatch($translation, $field_entries);
        }
      }

      foreach ($inline_blocks as $block_group) {
        $translation = $node->getTranslation($block_group['langcode']);
        if (!$translation->access('update', $this->currentUser)) {
          throw new \RuntimeException('You do not have update access to this page.');
        }
        [$layout, $section_delta, $section, $component, $configuration, $block] = $this->loadInlineBlock($translation, $block_group['source']);
        foreach ($block_group['fields'] as $field_entries) {
          $this->mutateFieldBatch($block, $field_entries);
        }
        $this->setRevisionMetadata($block, sprintf('Moody Broken Links: applied %d queued link changes', count($entries)));
        $configuration['block_serialized'] = serialize($block);
        $component->setConfiguration($configuration);
        $layout->setSection($section_delta, $section);
      }

      $transaction = $this->database->startTransaction();
      $node->setNewRevision(TRUE);
      $this->setRevisionMetadata($node, sprintf('Moody Broken Links: applied %d queued link changes', count($entries)));
      $node->save();

      $changed_at = time();
      foreach (['revise', 'remove'] as $action) {
        $result_ids = [];
        foreach ($entries as $entry) {
          if ($entry['action'] === $action) {
            $result_ids[] = (int) $entry['result']['result_id'];
          }
        }
        if (!$result_ids) {
          continue;
        }
        $updated = $this->database->update(self::RESULT_TABLE)
          ->fields([
            'remediated' => 1,
            'action' => $action,
            'changed' => $changed_at,
          ])
          ->condition('scan_id', $scan_id)
          ->condition('nid', $nid)
          ->condition('remediated', 0)
          ->condition('result_id', $result_ids, 'IN')
          ->execute();
        if ((int) $updated !== count($result_ids)) {
          throw new \RuntimeException('The queued results changed before they could be saved. Run a new scan.');
        }
      }
    }
    catch (\Throwable $exception) {
      if ($transaction) {
        $transaction->rollBack();
      }
      $storage->resetCache([$nid]);
      throw $exception;
    }

    return [
      'nid' => (int) $node->id(),
      'revision_id' => (int) $node->getRevisionId(),
      'changed' => count($entries),
    ];
  }

  /**
   * Scans supported fields on one entity.
   */
  private function scanEntityFields(int $scan_id, FieldableEntityInterface $entity, array $source, string $page_url): int {
    $count = 0;
    foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
      if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
        continue;
      }
      $type = $definition->getType();
      $field_label = (string) $definition->getLabel();
      foreach ($entity->get($field_name) as $delta => $item) {
        if ($type === 'link') {
          $href = trim((string) $item->uri);
          if ($href === '') {
            continue;
          }
          $data = $source + [
            'field_name' => $field_name,
            'field_delta' => (int) $delta,
            'property' => 'uri',
            'value_kind' => 'uri',
            'remove_item' => TRUE,
          ];
          $count += $this->recordLink($scan_id, $data, $field_label, $href, (string) ($item->title ?? ''), $href, 0, $page_url);
          continue;
        }

        if (in_array($type, self::HTML_FIELD_TYPES, TRUE)) {
          $html = (string) $item->value;
          foreach ($this->extractAnchors($html) as $anchor) {
            $data = $source + [
              'field_name' => $field_name,
              'field_delta' => (int) $delta,
              'property' => 'value',
              'value_kind' => 'markup',
            ];
            $count += $this->recordLink($scan_id, $data, $field_label, $anchor['href'], $anchor['text'], $html, $anchor['occurrence'], $page_url);
          }
          continue;
        }

        $values = $item->getValue();
        foreach ($values as $property => $value) {
          if (!is_string($value) || $value === '') {
            continue;
          }
          $property_label = $field_label . ' — ' . str_replace('_', ' ', (string) $property);
          $data = $source + [
            'field_name' => $field_name,
            'field_delta' => (int) $delta,
            'property' => (string) $property,
          ];

          if ($this->isUriProperty((string) $property)) {
            $uri_data = $data + ['value_kind' => 'uri'];
            $count += $this->recordLink(
              $scan_id,
              $uri_data,
              $property_label,
              $value,
              $this->linkTextFromValues($values),
              $value,
              0,
              $page_url,
            );
          }

          $structured = $this->decodeStructured($value);
          if ($structured !== NULL) {
            foreach ($this->findStructuredLinks($structured) as $link) {
              $structured_data = $data + [
                'value_kind' => $link['kind'],
                'structured_path' => $link['path'],
              ];
              $structured_label = $property_label . ' › ' . implode(' › ', array_map('strval', $link['path']));
              $count += $this->recordLink(
                $scan_id,
                $structured_data,
                $structured_label,
                $link['href'],
                $link['text'],
                $value,
                $link['occurrence'],
                $page_url,
              );
            }
          }
          elseif (str_contains(strtolower($value), '<a')) {
            foreach ($this->extractAnchors($value) as $anchor) {
              $markup_data = $data + ['value_kind' => 'markup'];
              $count += $this->recordLink($scan_id, $markup_data, $property_label, $anchor['href'], $anchor['text'], $value, $anchor['occurrence'], $page_url);
            }
          }
        }
      }
    }
    return $count;
  }

  /**
   * Finds URL and formatted-markup values inside serialized custom fields.
   */
  private function findStructuredLinks(array $values, array $path = []): array {
    $links = [];
    foreach ($values as $key => $value) {
      $current_path = [...$path, $key];
      if (is_array($value)) {
        $links = array_merge($links, $this->findStructuredLinks($value, $current_path));
        continue;
      }
      if (!is_string($value) || $value === '') {
        continue;
      }
      if ($this->isUriProperty((string) $key)) {
        $links[] = [
          'kind' => 'uri',
          'path' => $current_path,
          'href' => $value,
          'text' => $this->linkTextFromValues($values),
          'occurrence' => 0,
        ];
      }
      if (str_contains(strtolower($value), '<a')) {
        foreach ($this->extractAnchors($value) as $anchor) {
          $links[] = [
            'kind' => 'markup',
            'path' => $current_path,
            'href' => $anchor['href'],
            'text' => $anchor['text'],
            'occurrence' => $anchor['occurrence'],
          ];
        }
      }
    }
    return $links;
  }

  /**
   * Decodes Drupal custom-field storage without instantiating PHP objects.
   */
  private function decodeStructured(string $value): ?array {
    if (!preg_match('/^a:\d+:/', $value)) {
      return NULL;
    }
    $decoded = @unserialize($value, ['allowed_classes' => FALSE]);
    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * Identifies conventional URL property names used by core and Moody fields.
   */
  private function isUriProperty(string $property): bool {
    return (bool) preg_match('/(?:^|_)(?:uri|url|link)$/i', $property);
  }

  /**
   * Finds a nearby human-facing link label.
   */
  private function linkTextFromValues(array $values): string {
    foreach (['link_title', 'link_text', 'title', 'label', 'headline'] as $key) {
      if (isset($values[$key]) && is_scalar($values[$key])) {
        return (string) $values[$key];
      }
    }
    return '';
  }

  /**
   * Scans revision-pinned inline blocks in a per-node layout override.
   */
  private function scanInlineBlocks(int $scan_id, NodeInterface $node, string $page_url): int {
    if (!$node->hasField('layout_builder__layout') || $node->get('layout_builder__layout')->isEmpty()) {
      return 0;
    }

    $count = 0;
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    foreach ($node->get('layout_builder__layout')->getSections() as $section_delta => $section) {
      foreach ($section->getComponents() as $component_uuid => $component) {
        $configuration = $component->toArray()['configuration'] ?? [];
        if (!str_starts_with((string) ($configuration['id'] ?? ''), 'inline_block:') || empty($configuration['block_revision_id'])) {
          continue;
        }
        $block = $block_storage->loadRevision((int) $configuration['block_revision_id']);
        if (!$block instanceof FieldableEntityInterface) {
          continue;
        }
        if ($block->hasTranslation($node->language()->getId())) {
          $block = $block->getTranslation($node->language()->getId());
        }
        $count += $this->scanEntityFields($scan_id, $block, [
          'nid' => (int) $node->id(),
          'bundle' => $node->bundle(),
          'title' => (string) $node->label(),
          'langcode' => $node->language()->getId(),
          'source_type' => 'inline_block_field',
          'section_delta' => (int) $section_delta,
          'component_uuid' => (string) $component_uuid,
          'block_revision_id' => (int) $configuration['block_revision_id'],
          'block_id' => (int) $block->id(),
          'block_label' => (string) $block->label(),
        ], $page_url);
      }
    }
    return $count;
  }

  /**
   * Checks and stores one link, returning one when it was checkable.
   */
  private function recordLink(int $scan_id, array $source, string $field_label, string $href, string $link_text, string $source_value, int $occurrence, string $page_url): int {
    $absolute_url = $this->resolveHref($href, $page_url);
    if ($absolute_url === NULL) {
      return 0;
    }
    $cached = $this->cachedCheck($scan_id, $absolute_url);
    $check = $cached ?: $this->checkUrl($absolute_url, parse_url($page_url, PHP_URL_HOST) ?: '');
    $source_label = $source['source_type'] === 'inline_block_field'
      ? sprintf('Layout: %s — %s', $source['block_label'] ?: 'Inline block', $field_label)
      : $field_label;

    $this->database->insert(self::RESULT_TABLE)
      ->fields([
        'scan_id' => $scan_id,
        'nid' => $source['nid'],
        'bundle' => $source['bundle'],
        'title' => mb_substr($source['title'], 0, 255),
        'langcode' => $source['langcode'],
        'source_type' => $source['source_type'],
        'source_data' => Json::encode($source),
        'source_label' => mb_substr($source_label, 0, 255),
        'source_hash' => hash('sha256', $source_value),
        'occurrence' => $occurrence,
        'href' => $href,
        'link_text' => mb_substr(trim($link_text), 0, 2000),
        'absolute_url' => $absolute_url,
        'url_hash' => hash('sha256', $absolute_url),
        'result_status' => $check['status'],
        'http_code' => $check['code'],
        'message' => $check['message'],
      ])
      ->execute();
    return 1;
  }

  /**
   * Returns an earlier result for the same URL in this scan.
   */
  private function cachedCheck(int $scan_id, string $absolute_url): ?array {
    $query = $this->database->select(self::RESULT_TABLE, 'r');
    $query->fields('r', ['result_status', 'http_code', 'message']);
    $query->condition('scan_id', $scan_id);
    $query->condition('url_hash', hash('sha256', $absolute_url));
    $query->condition('absolute_url', $absolute_url);
    $query->range(0, 1);
    $row = $query->execute()->fetchAssoc();
    return $row ? [
      'status' => (string) $row['result_status'],
      'code' => (int) $row['http_code'],
      'message' => (string) $row['message'],
    ] : NULL;
  }

  /**
   * Resolves a stored href against its source page.
   */
  private function resolveHref(string $href, string $page_url): ?string {
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
    if ($href === '' || str_starts_with($href, '#') || preg_match('@^(?:mailto|tel|sms|javascript|data):@i', $href)) {
      return NULL;
    }
    if (preg_match('@^entity:node/(\d+)$@', $href, $matches)) {
      $node = $this->entityTypeManager->getStorage('node')->load((int) $matches[1]);
      return $node instanceof NodeInterface ? $this->resolveHref($node->toUrl()->toString(), $page_url) : NULL;
    }
    if (str_starts_with($href, 'internal:')) {
      $href = substr($href, 9);
    }
    try {
      $resolved = UriResolver::resolve(new Uri($page_url), new Uri($href));
    }
    catch (\Throwable) {
      return NULL;
    }
    if (!in_array(strtolower($resolved->getScheme()), ['http', 'https'], TRUE) || $resolved->getHost() === '') {
      return NULL;
    }
    return (string) $resolved->withFragment('');
  }

  /**
   * Performs a bounded HTTP check with redirect and private-network guards.
   */
  private function checkUrl(string $url, string $site_host): array {
    $current = $url;
    for ($redirects = 0; $redirects <= 5; $redirects++) {
      $safety = $this->validateDestination($current, $site_host);
      if ($safety !== NULL) {
        return ['status' => $safety['status'], 'code' => 0, 'message' => $safety['message']];
      }
      try {
        $response = $this->httpClient->request('HEAD', $current, $this->requestOptions());
        $code = $response->getStatusCode();
        if ($code >= 400) {
          $options = $this->requestOptions();
          $options['headers']['Range'] = 'bytes=0-0';
          $response = $this->httpClient->request('GET', $current, $options);
          $code = $response->getStatusCode();
        }
      }
      catch (\Throwable $exception) {
        return [
          'status' => 'warning',
          'code' => 0,
          'message' => 'Request failed: ' . mb_substr($exception->getMessage(), 0, 500),
        ];
      }

      if ($code >= 300 && $code < 400) {
        $location = $response->getHeaderLine('Location');
        if ($location === '') {
          return ['status' => 'warning', 'code' => $code, 'message' => 'Redirect response did not include a destination.'];
        }
        $current = $this->resolveHref($location, $current) ?? '';
        if ($current === '') {
          return ['status' => 'broken', 'code' => $code, 'message' => 'Redirect destination is invalid.'];
        }
        continue;
      }
      if ($code >= 200 && $code < 300) {
        return ['status' => 'ok', 'code' => $code, 'message' => 'OK'];
      }
      if (in_array($code, [401, 403, 408, 425, 429], TRUE) || $code >= 500) {
        return ['status' => 'warning', 'code' => $code, 'message' => 'The destination could not be confirmed.'];
      }
      return ['status' => 'broken', 'code' => $code, 'message' => 'The destination returned an error response.'];
    }
    return ['status' => 'broken', 'code' => 0, 'message' => 'Too many redirects.'];
  }

  /**
   * Returns request options that avoid downloading response bodies.
   */
  private function requestOptions(): array {
    return [
      'allow_redirects' => FALSE,
      'connect_timeout' => 4,
      'http_errors' => FALSE,
      'stream' => TRUE,
      'timeout' => 8,
      'headers' => ['User-Agent' => 'MoodyBrokenLinks/1.0'],
    ];
  }

  /**
   * Rejects external hosts that resolve to local or reserved addresses.
   */
  private function validateDestination(string $url, string $site_host): ?array {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
      return ['status' => 'broken', 'message' => 'The URL has no host.'];
    }
    if ($host === strtolower($site_host)) {
      return NULL;
    }
    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
      return ['status' => 'warning', 'message' => 'Skipped a private destination.'];
    }

    $addresses = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
      $addresses[] = $host;
    }
    else {
      $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
      foreach ($records as $record) {
        if (!empty($record['ip'])) {
          $addresses[] = $record['ip'];
        }
        if (!empty($record['ipv6'])) {
          $addresses[] = $record['ipv6'];
        }
      }
      if (!$addresses) {
        return ['status' => 'broken', 'message' => 'The host could not be resolved.'];
      }
    }

    foreach ($addresses as $address) {
      if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return ['status' => 'warning', 'message' => 'Skipped a private or reserved destination.'];
      }
    }
    return NULL;
  }

  /**
   * Extracts ordered anchor occurrences from a formatted-text value.
   */
  private function extractAnchors(string $html): array {
    [$document, $root] = $this->loadFragment($html);
    if (!$document || !$root) {
      return [];
    }
    $anchors = [];
    $occurrence = 0;
    foreach ($root->getElementsByTagName('a') as $anchor) {
      if (!$anchor->hasAttribute('href')) {
        continue;
      }
      $text = preg_replace('/\s+/u', ' ', trim((string) $anchor->textContent)) ?: '';
      $anchors[] = [
        'occurrence' => $occurrence++,
        'href' => $anchor->getAttribute('href'),
        'text' => $text,
      ];
    }
    return $anchors;
  }

  /**
   * Mutates all queued results for one entity field from its scanned value.
   */
  private function mutateFieldBatch(FieldableEntityInterface $entity, array $entries): void {
    $first_source = $entries[0]['result']['source_data'];
    $field_name = (string) ($first_source['field_name'] ?? '');
    if ($field_name === '' || !$entity->hasField($field_name)) {
      throw new \RuntimeException('The source field changed after the scan. Run a new scan.');
    }
    $field = $entity->get($field_name);
    $raw_groups = [];
    foreach ($entries as $entry) {
      $source = $entry['result']['source_data'];
      if (($source['field_name'] ?? '') !== $field_name) {
        throw new \RuntimeException('The queued field results are inconsistent. Run a new scan.');
      }
      $delta = (int) ($source['field_delta'] ?? -1);
      $property = (string) ($source['property'] ?? '');
      $key = $delta . "\0" . $property;
      $raw_groups[$key]['delta'] = $delta;
      $raw_groups[$key]['property'] = $property;
      $raw_groups[$key]['entries'][] = $entry;
    }

    $plans = [];
    foreach ($raw_groups as $group) {
      $delta = $group['delta'];
      $property = $group['property'];
      if ($delta < 0 || $property === '' || !$field->offsetExists($delta)) {
        throw new \RuntimeException('The source field changed after the scan. Run a new scan.');
      }
      $item = $field->get($delta);
      $value = (string) ($item->{$property} ?? '');
      foreach ($group['entries'] as $entry) {
        if (!hash_equals((string) $entry['result']['source_hash'], hash('sha256', $value))) {
          throw new \RuntimeException('The source field changed after the scan. Run a new scan.');
        }
      }
      $mutation = $this->mutateRawValue($value, $group['entries']);
      $plans[] = [
        'delta' => $delta,
        'property' => $property,
        'item' => $item,
        'value' => $mutation['value'],
        'remove_item' => $mutation['remove_item'],
      ];
    }

    $removals = [];
    foreach ($plans as $plan) {
      if ($plan['remove_item']) {
        if (isset($removals[$plan['delta']])) {
          throw new \RuntimeException('The same field item was queued for removal more than once.');
        }
        $removals[$plan['delta']] = TRUE;
      }
    }
    foreach ($plans as $plan) {
      if (isset($removals[$plan['delta']]) && !$plan['remove_item']) {
        throw new \RuntimeException('A removed field item cannot also contain another queued change.');
      }
      if (!$plan['remove_item']) {
        $plan['item']->{$plan['property']} = $plan['value'];
      }
    }
    $deltas = array_keys($removals);
    rsort($deltas, SORT_NUMERIC);
    foreach ($deltas as $delta) {
      $field->removeItem((int) $delta);
    }
  }

  /**
   * Computes one raw property value after all of its queued changes.
   */
  private function mutateRawValue(string $value, array $entries): array {
    $structured_entries = [];
    $markup_entries = [];
    $uri_entries = [];
    foreach ($entries as $entry) {
      $source = $entry['result']['source_data'];
      if (!empty($source['structured_path'])) {
        $structured_entries[] = $entry;
      }
      elseif (($source['value_kind'] ?? '') === 'markup') {
        $markup_entries[] = $entry;
      }
      elseif (($source['value_kind'] ?? '') === 'uri') {
        $uri_entries[] = $entry;
      }
      else {
        throw new \RuntimeException('This field value cannot be remediated safely.');
      }
    }
    $kinds = (int) (bool) $structured_entries + (int) (bool) $markup_entries + (int) (bool) $uri_entries;
    if ($kinds !== 1) {
      throw new \RuntimeException('The queued field results cannot be combined safely. Run a new scan.');
    }

    if ($markup_entries) {
      return [
        'value' => $this->mutateMarkup($value, $markup_entries),
        'remove_item' => FALSE,
      ];
    }

    if ($uri_entries) {
      if (count($uri_entries) !== 1) {
        throw new \RuntimeException('The same URL field was queued more than once.');
      }
      $entry = $uri_entries[0];
      if ($value !== $entry['result']['href']) {
        throw new \RuntimeException('The link changed after the scan. Run a new scan.');
      }
      $source = $entry['result']['source_data'];
      return [
        'value' => $entry['action'] === 'remove' ? '' : $this->linkFieldUri((string) $entry['replacement']),
        'remove_item' => $entry['action'] === 'remove' && !empty($source['remove_item']),
      ];
    }

    $structured = $this->decodeStructured($value);
    if ($structured === NULL) {
      throw new \RuntimeException('The structured field changed after the scan. Run a new scan.');
    }
    $paths = [];
    foreach ($structured_entries as $entry) {
      $path = $entry['result']['source_data']['structured_path'];
      $key = Json::encode($path);
      $paths[$key]['path'] = $path;
      $paths[$key]['entries'][] = $entry;
    }
    foreach ($paths as $path_group) {
      $current = $this->getStructuredValue($structured, $path_group['path']);
      if (!is_string($current)) {
        throw new \RuntimeException('The structured field changed after the scan. Run a new scan.');
      }
      $kind = (string) $path_group['entries'][0]['result']['source_data']['value_kind'];
      foreach ($path_group['entries'] as $entry) {
        if (($entry['result']['source_data']['value_kind'] ?? '') !== $kind) {
          throw new \RuntimeException('The structured field results cannot be combined safely.');
        }
      }
      if ($kind === 'markup') {
        $changed = $this->mutateMarkup($current, $path_group['entries']);
      }
      elseif ($kind === 'uri' && count($path_group['entries']) === 1) {
        $entry = $path_group['entries'][0];
        if ($current !== $entry['result']['href']) {
          throw new \RuntimeException('The link changed after the scan. Run a new scan.');
        }
        $changed = $entry['action'] === 'remove' ? '' : $this->linkFieldUri((string) $entry['replacement']);
      }
      else {
        throw new \RuntimeException('The structured field results cannot be combined safely.');
      }
      $this->setStructuredValue($structured, $path_group['path'], $changed);
    }
    return ['value' => serialize($structured), 'remove_item' => FALSE];
  }

  /**
   * Returns a nested structured-field value, or NULL when the path is stale.
   */
  private function getStructuredValue(array $values, array $path): mixed {
    $current = $values;
    foreach ($path as $key) {
      if (!is_array($current) || !array_key_exists($key, $current)) {
        return NULL;
      }
      $current = $current[$key];
    }
    return $current;
  }

  /**
   * Replaces a nested structured-field value.
   */
  private function setStructuredValue(array &$values, array $path, mixed $replacement): void {
    $current = &$values;
    foreach ($path as $key) {
      if (!is_array($current) || !array_key_exists($key, $current)) {
        throw new \RuntimeException('The structured field changed after the scan. Run a new scan.');
      }
      $current = &$current[$key];
    }
    $current = $replacement;
  }

  /**
   * Loads one revision-pinned inline block and its mutable layout component.
   */
  private function loadInlineBlock(NodeInterface $node, array $source): array {
    if (!$node->hasField('layout_builder__layout') || $node->get('layout_builder__layout')->isEmpty()) {
      throw new \RuntimeException('The page layout changed after the scan. Run a new scan.');
    }
    $layout = $node->get('layout_builder__layout');
    $section_delta = (int) $source['section_delta'];
    $sections = $layout->getSections();
    if (!isset($sections[$section_delta])) {
      throw new \RuntimeException('The page layout changed after the scan. Run a new scan.');
    }
    $section = $sections[$section_delta];
    try {
      $component = $section->getComponent((string) $source['component_uuid']);
    }
    catch (\InvalidArgumentException) {
      throw new \RuntimeException('The page layout changed after the scan. Run a new scan.');
    }
    $configuration = $component->toArray()['configuration'] ?? [];
    if (empty($configuration['block_revision_id'])) {
      throw new \RuntimeException('The inline block changed after the scan. Run a new scan.');
    }

    $block = $this->entityTypeManager->getStorage('block_content')->loadRevision((int) $configuration['block_revision_id']);
    if (!$block instanceof FieldableEntityInterface || (int) $block->id() !== (int) $source['block_id']) {
      throw new \RuntimeException('The inline block changed after the scan. Run a new scan.');
    }
    if ($block->hasTranslation($node->language()->getId())) {
      $block = $block->getTranslation($node->language()->getId());
    }
    return [$layout, $section_delta, $section, $component, $configuration, $block];
  }

  /**
   * Revises queued hrefs or unwraps anchors while retaining child markup.
   */
  private function mutateMarkup(string $html, array $entries): string {
    [$document, $root] = $this->loadFragment($html);
    if (!$document || !$root) {
      throw new \RuntimeException('The formatted markup could not be parsed safely.');
    }
    $matches = [];
    foreach ($root->getElementsByTagName('a') as $anchor) {
      if ($anchor->hasAttribute('href')) {
        $matches[] = $anchor;
      }
    }
    $selected = [];
    foreach ($entries as $entry) {
      $occurrence = (int) $entry['result']['occurrence'];
      $anchor = $matches[$occurrence] ?? NULL;
      if (isset($selected[$occurrence])) {
        throw new \RuntimeException('The same link occurrence was queued more than once.');
      }
      if (!$anchor || $anchor->getAttribute('href') !== $entry['result']['href']) {
        throw new \RuntimeException('The link changed after the scan. Run a new scan.');
      }
      $selected[$occurrence] = [$anchor, $entry];
    }
    foreach ($selected as [$anchor, $entry]) {
      if ($entry['action'] === 'revise') {
        $anchor->setAttribute('href', (string) $entry['replacement']);
      }
      else {
        $parent = $anchor->parentNode;
        if (!$parent) {
          throw new \RuntimeException('The formatted markup could not be changed safely.');
        }
        while ($anchor->firstChild) {
          $parent->insertBefore($anchor->firstChild, $anchor);
        }
        $parent->removeChild($anchor);
      }
    }

    $output = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
      $output .= $document->saveHTML($child);
    }
    return $output;
  }

  /**
   * Parses an HTML fragment inside a stable wrapper.
   */
  private function loadFragment(string $html): array {
    $document = new \DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(TRUE);
    try {
      $loaded = $document->loadHTML(
        '<?xml encoding="UTF-8"><div id="moody-broken-links-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
      );
    }
    finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }
    return $loaded ? [$document, $document->getElementById('moody-broken-links-root')] : [NULL, NULL];
  }

  /**
   * Converts site-relative paths to Drupal link field URIs.
   */
  private function linkFieldUri(string $replacement): string {
    return str_starts_with($replacement, '/') ? 'internal:' . $replacement : $replacement;
  }

  /**
   * Builds the absolute canonical URL used to resolve relative links.
   */
  private function pageUrl(NodeInterface $node, string $site_base_url): string {
    $base = new Uri(rtrim($site_base_url, '/') . '/');
    return (string) UriResolver::resolve($base, new Uri(ltrim($node->toUrl()->toString(), '/')));
  }

  /**
   * Adds revision audit metadata when supported by the entity type.
   */
  private function setRevisionMetadata(object $entity, string $message): void {
    if (method_exists($entity, 'setNewRevision')) {
      $entity->setNewRevision(TRUE);
    }
    if (method_exists($entity, 'setRevisionUserId')) {
      $entity->setRevisionUserId((int) $this->currentUser->id());
    }
    if (method_exists($entity, 'setRevisionCreationTime')) {
      $entity->setRevisionCreationTime(time());
    }
    if (method_exists($entity, 'setRevisionLogMessage')) {
      $entity->setRevisionLogMessage(mb_substr($message, 0, 255));
    }
  }

  /**
   * Counts results by status.
   */
  private function countResults(int $scan_id, string $status): int {
    return (int) $this->database->select(self::RESULT_TABLE, 'r')
      ->condition('scan_id', $scan_id)
      ->condition('result_status', $status)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
