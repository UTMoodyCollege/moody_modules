<?php

declare(strict_types=1);

namespace Drupal\moody_media_remediation;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;

/**
 * Scans managed files and applies reversible reference consolidations.
 */
final class MediaRemediationManager {

  private const SCAN_TABLE = 'moody_media_remediation_scan';
  private const ITEM_TABLE = 'moody_media_remediation_item';
  private const OPERATION_TABLE = 'moody_media_remediation_operation';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUsageInterface $fileUsage,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Creates a scan and returns the IDs for its metadata and hash passes.
   */
  public function prepareScan(int $uid): array {
    $latest = $this->getLatestScan();
    if ($latest && $latest['status'] === 'running') {
      if ((int) $latest['started'] > time() - 21600) {
        throw new \RuntimeException('A media scan is already running.');
      }
      $this->markScanFailed((int) $latest['scan_id']);
    }

    $file_ids = array_map('intval', $this->database->select('file_managed', 'f')
      ->fields('f', ['fid'])
      ->orderBy('fid')
      ->execute()
      ->fetchCol());

    $duplicate_sizes = $this->database->select('file_managed', 's');
    $duplicate_sizes->addField('s', 'filesize');
    $duplicate_sizes
      ->condition('s.filesize', 0, '>')
      ->groupBy('s.filesize')
      ->having('COUNT(*) > 1');

    $hash_query = $this->database->select('file_managed', 'f');
    $hash_query->addField('f', 'fid');
    $hash_query
      ->condition('f.filesize', $duplicate_sizes, 'IN')
      ->orderBy('f.fid');
    $hash_file_ids = array_map('intval', $hash_query->execute()->fetchCol());

    $transaction = $this->database->startTransaction();
    try {
      $this->database->delete(self::ITEM_TABLE)->execute();
      $this->database->delete(self::SCAN_TABLE)->execute();
      $scan_id = $this->createScan($uid, count($file_ids));
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }

    return [
      'scan_id' => $scan_id,
      'file_ids' => $file_ids,
      'hash_file_ids' => $hash_file_ids,
    ];
  }

  /**
   * Creates a scan row. Public to support small isolated smoke tests.
   */
  public function createScan(int $uid, int $total_files): int {
    return (int) $this->database->insert(self::SCAN_TABLE)
      ->fields([
        'uid' => $uid,
        'status' => 'running',
        'started' => time(),
        'total_files' => $total_files,
      ])
      ->execute();
  }

  /**
   * Collects metadata and usage for one batch of file IDs.
   */
  public function scanMetadata(int $scan_id, array $file_ids, array &$context): void {
    $file_ids = array_values(array_unique(array_map('intval', $file_ids)));
    if (!$file_ids) {
      return;
    }

    $files = [];
    $file_query = $this->database->select('file_managed', 'f');
    $file_query->fields('f', [
      'fid',
      'uid',
      'filename',
      'uri',
      'filemime',
      'filesize',
      'status',
      'created',
      'changed',
    ]);
    $file_query->condition('f.fid', $file_ids, 'IN');
    foreach ($file_query->execute() as $file) {
      $files[(int) $file->fid] = $file;
    }

    $usage = [];
    foreach ($file_ids as $fid) {
      $usage[$fid] = [
        'core' => [],
        'tracked' => [],
        'core_count' => 0,
        'tracked_count' => 0,
      ];
    }

    $core_query = $this->database->select('file_usage', 'u');
    $core_query->fields('u', ['fid', 'module', 'type', 'id', 'count']);
    $core_query
      ->condition('u.fid', $file_ids, 'IN')
      ->condition('u.count', 0, '>');
    foreach ($core_query->execute() as $row) {
      $fid = (int) $row->fid;
      $usage[$fid]['core'][] = [
        'module' => (string) $row->module,
        'source_type' => (string) $row->type,
        'source_id' => (string) $row->id,
        'count' => (int) $row->count,
      ];
      $usage[$fid]['core_count'] += (int) $row->count;
    }

    $tracked_query = $this->database->select('entity_usage', 'u');
    $tracked_query->fields('u', [
      'target_id',
      'source_type',
      'source_id',
      'source_id_string',
      'source_langcode',
      'source_vid',
      'method',
      'field_name',
      'count',
    ]);
    $tracked_query
      ->condition('u.target_type', 'file')
      ->condition('u.target_id', $file_ids, 'IN')
      ->condition('u.count', 0, '>');
    foreach ($tracked_query->execute() as $row) {
      $fid = (int) $row->target_id;
      $source_id = $row->source_id ?: $row->source_id_string;
      $usage[$fid]['tracked'][] = [
        'source_type' => (string) $row->source_type,
        'source_id' => (string) $source_id,
        'source_langcode' => (string) $row->source_langcode,
        'source_vid' => (string) $row->source_vid,
        'method' => (string) $row->method,
        'field_name' => (string) $row->field_name,
        'count' => (int) $row->count,
      ];
      $usage[$fid]['tracked_count'] += (int) $row->count;
    }

    $existing = 0;
    foreach ($files as $fid => $file) {
      $exists = file_exists((string) $file->uri);
      $existing += (int) $exists;
      $this->database->merge(self::ITEM_TABLE)
        ->keys([
          'scan_id' => $scan_id,
          'fid' => $fid,
        ])
        ->fields([
          'uid' => (int) $file->uid,
          'filename' => mb_substr((string) $file->filename, 0, 255),
          'uri' => (string) $file->uri,
          'mime_type' => mb_substr((string) $file->filemime, 0, 255),
          'filesize' => max(0, (int) $file->filesize),
          'file_status' => (int) $file->status,
          'created' => (int) $file->created,
          'changed' => (int) $file->changed,
          'file_exists' => (int) $exists,
          'core_usage' => $usage[$fid]['core_count'],
          'tracked_usage' => $usage[$fid]['tracked_count'],
          'usage_data' => Json::encode([
            'core' => $usage[$fid]['core'],
            'tracked' => $usage[$fid]['tracked'],
          ]),
        ])
        ->execute();
    }

    $update = $this->database->update(self::SCAN_TABLE)
      ->condition('scan_id', $scan_id);
    $update->expression('processed_files', 'processed_files + :processed', [
      ':processed' => count($files),
    ]);
    $update->expression('existing_files', 'existing_files + :existing', [
      ':existing' => $existing,
    ]);
    $update->execute();

    $context['results']['scan_id'] = $scan_id;
    $context['results']['metadata_files'] = ($context['results']['metadata_files'] ?? 0) + count($files);
    $context['message'] = t('Collected metadata for @count files.', [
      '@count' => $context['results']['metadata_files'],
    ]);
  }

  /**
   * Hashes one batch of same-size candidates.
   */
  public function scanHashes(int $scan_id, array $file_ids, array &$context): void {
    $file_ids = array_values(array_unique(array_map('intval', $file_ids)));
    if (!$file_ids) {
      return;
    }

    $query = $this->database->select(self::ITEM_TABLE, 'i');
    $query->fields('i', ['fid', 'uri', 'file_exists']);
    $query
      ->condition('i.scan_id', $scan_id)
      ->condition('i.fid', $file_ids, 'IN');

    $hashed = 0;
    foreach ($query->execute() as $row) {
      if (!(int) $row->file_exists || !file_exists((string) $row->uri)) {
        continue;
      }
      $hash = hash_file('sha256', (string) $row->uri);
      if ($hash === FALSE) {
        continue;
      }
      $this->database->update(self::ITEM_TABLE)
        ->fields(['sha256' => $hash])
        ->condition('scan_id', $scan_id)
        ->condition('fid', (int) $row->fid)
        ->execute();
      $hashed++;
    }

    $context['results']['scan_id'] = $scan_id;
    $context['results']['hashed_files'] = ($context['results']['hashed_files'] ?? 0) + $hashed;
    $context['message'] = t('Hashed @count same-size files.', [
      '@count' => $context['results']['hashed_files'],
    ]);
  }

  /**
   * Finalizes scan totals.
   */
  public function finishScan(int $scan_id): array {
    $groups = 0;
    $duplicate_files = 0;
    $group_query = $this->database->select(self::ITEM_TABLE, 'i');
    $group_query->addField('i', 'sha256');
    $group_query->addExpression('COUNT(*)', 'group_size');
    $group_query
      ->condition('i.scan_id', $scan_id)
      ->condition('i.sha256', '', '<>')
      ->groupBy('i.sha256')
      ->having('COUNT(*) > 1');
    foreach ($group_query->execute() as $group) {
      $groups++;
      $duplicate_files += (int) $group->group_size;
    }

    $unused_query = $this->database->select(self::ITEM_TABLE, 'i');
    $unused_query
      ->condition('i.scan_id', $scan_id)
      ->condition('i.core_usage', 0)
      ->condition('i.tracked_usage', 0);
    $unused = (int) $unused_query->countQuery()->execute()->fetchField();

    $missing_query = $this->database->select(self::ITEM_TABLE, 'i');
    $missing_query
      ->condition('i.scan_id', $scan_id)
      ->condition('i.file_exists', 0);
    $missing = (int) $missing_query->countQuery()->execute()->fetchField();

    $this->database->update(self::SCAN_TABLE)
      ->fields([
        'status' => 'complete',
        'completed' => time(),
        'duplicate_groups' => $groups,
        'duplicate_files' => $duplicate_files,
        'unused_files' => $unused,
        'missing_files' => $missing,
      ])
      ->condition('scan_id', $scan_id)
      ->execute();

    return [
      'duplicate_groups' => $groups,
      'duplicate_files' => $duplicate_files,
      'unused_files' => $unused,
      'missing_files' => $missing,
    ];
  }

  /**
   * Marks an interrupted scan as failed.
   */
  public function markScanFailed(int $scan_id): void {
    $this->database->update(self::SCAN_TABLE)
      ->fields([
        'status' => 'failed',
        'completed' => time(),
      ])
      ->condition('scan_id', $scan_id)
      ->execute();
  }

  /**
   * Returns the latest scan, if any.
   */
  public function getLatestScan(): ?array {
    $query = $this->database->select(self::SCAN_TABLE, 's');
    $query->fields('s');
    $query->orderBy('scan_id', 'DESC')->range(0, 1);
    $row = $query->execute()->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Builds a candidate query for the dashboard.
   */
  public function candidateQuery(int $scan_id, string $mode, string $search = ''): SelectInterface {
    $query = $this->database->select(self::ITEM_TABLE, 'i');
    $query->fields('i');
    $query->condition('i.scan_id', $scan_id);

    if ($mode === 'duplicates') {
      $groups = $this->database->select(self::ITEM_TABLE, 'g0');
      $groups->addField('g0', 'sha256');
      $groups->addExpression('COUNT(*)', 'group_size');
      $groups
        ->condition('g0.scan_id', $scan_id)
        ->condition('g0.sha256', '', '<>')
        ->groupBy('g0.sha256')
        ->having('COUNT(*) > 1');
      $query->innerJoin($groups, 'g', 'g.sha256 = i.sha256');
      $query->addField('g', 'group_size');
      $query->orderBy('i.sha256')->orderBy('i.core_usage', 'DESC')->orderBy('i.fid');
    }
    else {
      $query->addExpression('0', 'group_size');
      if ($mode === 'unused') {
        $query->condition('i.core_usage', 0)->condition('i.tracked_usage', 0);
      }
      elseif ($mode === 'missing') {
        $query->condition('i.file_exists', 0);
      }
      $query->orderBy('i.changed', 'ASC')->orderBy('i.fid');
    }

    if ($search !== '') {
      $or = $query->orConditionGroup()
        ->condition('i.filename', '%' . $this->database->escapeLike($search) . '%', 'LIKE')
        ->condition('i.uri', '%' . $this->database->escapeLike($search) . '%', 'LIKE');
      $query->condition($or);
    }

    return $query;
  }

  /**
   * Returns one exact duplicate group.
   */
  public function getGroup(int $scan_id, string $sha256): array {
    if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
      return [];
    }
    $query = $this->database->select(self::ITEM_TABLE, 'i');
    $query->fields('i');
    $query
      ->condition('i.scan_id', $scan_id)
      ->condition('i.sha256', $sha256)
      ->orderBy('i.core_usage', 'DESC')
      ->orderBy('i.tracked_usage', 'DESC')
      ->orderBy('i.fid');
    return $query->execute()->fetchAllAssoc('fid', \PDO::FETCH_ASSOC);
  }

  /**
   * Returns recent operations.
   */
  public function getOperations(int $limit = 10, ?int $scan_id = NULL, ?string $sha256 = NULL): array {
    $query = $this->database->select(self::OPERATION_TABLE, 'o');
    $query->fields('o');
    if ($scan_id !== NULL) {
      $query->condition('o.scan_id', $scan_id);
    }
    if ($sha256 !== NULL) {
      $query->condition('o.sha256', $sha256);
    }
    $query->orderBy('operation_id', 'DESC')->range(0, $limit);
    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Returns one operation.
   */
  public function getOperation(int $operation_id): ?array {
    $query = $this->database->select(self::OPERATION_TABLE, 'o');
    $query->fields('o')->condition('operation_id', $operation_id);
    $operation = $query->execute()->fetchAssoc();
    return $operation ?: NULL;
  }

  /**
   * Consolidates managed current references and retains every file.
   */
  public function consolidateGroup(
    int $scan_id,
    string $sha256,
    int $canonical_fid,
    array $duplicate_fids,
  ): array {
    $group = $this->getGroup($scan_id, $sha256);
    $duplicate_fids = array_values(array_unique(array_map('intval', $duplicate_fids)));
    if (count($group) < 2 || !isset($group[$canonical_fid]) || !$duplicate_fids) {
      throw new \InvalidArgumentException('Select a canonical file and at least one duplicate.');
    }
    if (in_array($canonical_fid, $duplicate_fids, TRUE)) {
      throw new \InvalidArgumentException('The canonical file cannot also be consolidated.');
    }
    foreach ($duplicate_fids as $fid) {
      if (!isset($group[$fid])) {
        throw new \InvalidArgumentException('Every selected file must belong to this exact duplicate group.');
      }
    }

    $file_storage = $this->entityTypeManager->getStorage('file');
    $files = $file_storage->loadMultiple(array_merge([$canonical_fid], $duplicate_fids));
    foreach (array_merge([$canonical_fid], $duplicate_fids) as $fid) {
      $file = $files[$fid] ?? NULL;
      if (!$file instanceof FileInterface || !file_exists($file->getFileUri())) {
        throw new \RuntimeException(sprintf('File %d is missing; no references were changed.', $fid));
      }
      $current_hash = hash_file('sha256', $file->getFileUri());
      if ($current_hash === FALSE || !hash_equals($sha256, $current_hash)) {
        throw new \RuntimeException(sprintf('File %d changed since the scan; no references were changed.', $fid));
      }
    }

    $source_ids = [];
    foreach ($duplicate_fids as $fid) {
      foreach ($this->fileUsage->listUsage($files[$fid]) as $module_usage) {
        foreach ($module_usage as $entity_type => $ids) {
          foreach (array_keys($ids) as $entity_id) {
            $source_ids[$entity_type][(string) $entity_id] = (string) $entity_id;
          }
        }
      }
    }

    $entities = [];
    $changes = [];
    foreach ($source_ids as $entity_type => $ids) {
      if (!$this->entityTypeManager->hasDefinition($entity_type)) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entity_type);
      foreach ($storage->loadMultiple(array_values($ids)) as $entity) {
        if (!$entity instanceof ContentEntityInterface) {
          continue;
        }
        if (!$entity->access('update', $this->currentUser)) {
          throw new \RuntimeException(sprintf('Update access was denied for %s %s.', $entity_type, $entity->id()));
        }

        $entity_key = $entity_type . ':' . $entity->id();
        $entity_changed = FALSE;
        foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
          $translation = $entity->getTranslation($langcode);
          foreach ($translation->getFields() as $field_name => $field) {
            $definition = $field->getFieldDefinition();
            $storage_definition = $definition->getFieldStorageDefinition();
            $field_type = $definition->getType();
            $target_type = $storage_definition->getSetting('target_type');
            if ($definition->isComputed() ||
              (!in_array($field_type, ['file', 'image'], TRUE) && $target_type !== 'file')) {
              continue;
            }

            $before = $field->getValue();
            $after = $before;
            $field_changed = FALSE;
            foreach ($after as &$item) {
              if (isset($item['target_id']) && in_array((int) $item['target_id'], $duplicate_fids, TRUE)) {
                $item['target_id'] = $canonical_fid;
                $field_changed = TRUE;
              }
            }
            unset($item);
            if (!$field_changed) {
              continue;
            }

            $translation->set($field_name, $after);
            $changes[] = [
              'entity_type' => $entity_type,
              'entity_id' => (string) $entity->id(),
              'langcode' => $langcode,
              'field_name' => $field_name,
              'before' => $before,
              'after' => $after,
            ];
            $entity_changed = TRUE;
          }
        }
        if ($entity_changed) {
          $entities[$entity_key] = $entity;
        }
      }
    }

    if (!$changes) {
      return [
        'operation_id' => NULL,
        'changed_fields' => 0,
        'changed_entities' => 0,
      ];
    }

    foreach ($entities as $entity) {
      $violations = $entity->validate();
      if (count($violations)) {
        throw new \RuntimeException(sprintf(
          '%s %s did not validate: %s',
          $entity->getEntityTypeId(),
          $entity->id(),
          (string) $violations,
        ));
      }
    }

    $transaction = $this->database->startTransaction();
    try {
      foreach ($entities as $entity) {
        $this->prepareRevision($entity, 'Consolidated exact duplicate file references.');
        $entity->save();
      }
      $operation_id = (int) $this->database->insert(self::OPERATION_TABLE)
        ->fields([
          'scan_id' => $scan_id,
          'uid' => (int) $this->currentUser->id(),
          'created' => time(),
          'status' => 'applied',
          'sha256' => $sha256,
          'canonical_fid' => $canonical_fid,
          'duplicate_fids' => Json::encode($duplicate_fids),
          'changes' => Json::encode($changes),
        ])
        ->execute();
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }

    foreach (array_merge([$canonical_fid], $duplicate_fids) as $fid) {
      $this->refreshUsage($scan_id, $fid);
    }

    return [
      'operation_id' => $operation_id,
      'changed_fields' => count($changes),
      'changed_entities' => count($entities),
    ];
  }

  /**
   * Reverts one operation if every affected field still has its after value.
   */
  public function undoOperation(int $operation_id): array {
    $operation = $this->getOperation($operation_id);
    if (!$operation || $operation['status'] !== 'applied') {
      throw new \InvalidArgumentException('This operation is not available to undo.');
    }

    $changes = Json::decode((string) $operation['changes']);
    $entities = [];
    foreach ($changes as $change) {
      $entity_type = (string) $change['entity_type'];
      $entity_id = (string) $change['entity_id'];
      $entity_key = $entity_type . ':' . $entity_id;
      if (!isset($entities[$entity_key])) {
        $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
        if (!$entity instanceof ContentEntityInterface || !$entity->access('update', $this->currentUser)) {
          throw new \RuntimeException(sprintf('The affected %s %s is unavailable or cannot be updated.', $entity_type, $entity_id));
        }
        $entities[$entity_key] = $entity;
      }

      $entity = $entities[$entity_key];
      $langcode = (string) $change['langcode'];
      if (!$entity->hasTranslation($langcode)) {
        throw new \RuntimeException(sprintf('%s %s no longer has translation %s.', $entity_type, $entity_id, $langcode));
      }
      $translation = $entity->getTranslation($langcode);
      $field_name = (string) $change['field_name'];
      if (!$translation->hasField($field_name) || $translation->get($field_name)->getValue() != $change['after']) {
        throw new \RuntimeException(sprintf('%s %s field %s changed after remediation; undo was stopped.', $entity_type, $entity_id, $field_name));
      }
      $translation->set($field_name, $change['before']);
    }

    foreach ($entities as $entity) {
      $violations = $entity->validate();
      if (count($violations)) {
        throw new \RuntimeException(sprintf(
          '%s %s did not validate: %s',
          $entity->getEntityTypeId(),
          $entity->id(),
          (string) $violations,
        ));
      }
    }

    $transaction = $this->database->startTransaction();
    try {
      foreach ($entities as $entity) {
        $this->prepareRevision($entity, 'Undid exact duplicate file reference consolidation.');
        $entity->save();
      }
      $this->database->update(self::OPERATION_TABLE)
        ->fields([
          'status' => 'reverted',
          'reverted' => time(),
        ])
        ->condition('operation_id', $operation_id)
        ->execute();
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }

    $fids = array_merge(
      [(int) $operation['canonical_fid']],
      array_map('intval', Json::decode((string) $operation['duplicate_fids'])),
    );
    foreach ($fids as $fid) {
      $this->refreshUsage((int) $operation['scan_id'], $fid);
    }

    return [
      'changed_fields' => count($changes),
      'changed_entities' => count($entities),
    ];
  }

  /**
   * Adds revision metadata when the entity supports it.
   */
  private function prepareRevision(ContentEntityInterface $entity, string $message): void {
    if (!$entity instanceof RevisionableInterface) {
      return;
    }
    $entity->setNewRevision(TRUE);
    if (method_exists($entity, 'setRevisionUserId')) {
      $entity->setRevisionUserId((int) $this->currentUser->id());
    }
    if (method_exists($entity, 'setRevisionLogMessage')) {
      $entity->setRevisionLogMessage($message);
    }
  }

  /**
   * Refreshes usage counts in a scan after an operation.
   */
  private function refreshUsage(int $scan_id, int $fid): void {
    $core_query = $this->database->select('file_usage', 'u');
    $core_query->addExpression('COALESCE(SUM(u.count), 0)', 'usage_count');
    $core_query->condition('u.fid', $fid)->condition('u.count', 0, '>');
    $core_usage = (int) $core_query->execute()->fetchField();

    $tracked_query = $this->database->select('entity_usage', 'u');
    $tracked_query->addExpression('COALESCE(SUM(u.count), 0)', 'usage_count');
    $tracked_query
      ->condition('u.target_type', 'file')
      ->condition('u.target_id', $fid)
      ->condition('u.count', 0, '>');
    $tracked_usage = (int) $tracked_query->execute()->fetchField();

    $this->database->update(self::ITEM_TABLE)
      ->fields([
        'core_usage' => $core_usage,
        'tracked_usage' => $tracked_usage,
      ])
      ->condition('scan_id', $scan_id)
      ->condition('fid', $fid)
      ->execute();
  }

}
