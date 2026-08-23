<?php

namespace Drupal\moody_ai_base;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Records privacy-conscious usage and outcome data shared by Moody AI tools.
 */
class UsageTracker {

  /** Token usage at or above this value warrants administrator review. */
  const HIGH_TOKEN_THRESHOLD = 100000;

  /** Prompt length at or above this value warrants administrator review. */
  const LONG_PROMPT_THRESHOLD = 4000;

  /** Requests per user per rolling hour that warrant administrator review. */
  const HIGH_FREQUENCY_THRESHOLD = 20;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs the usage tracker.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Records one AI request without exposing prompts in reports or exports.
   */
  public function recordUsage(AccountInterface $account, ?ContentEntityInterface $entity, $prompt, $tokens_used, $status = 'success', $thread_id = NULL, $source = 'assistant', $operation = '', $items_affected = 0) {
    if (!$this->tableExists('moody_ai_assistant_usage_log')) {
      return;
    }

    $uid = (int) $account->id();
    $tokens_used = max(0, (int) $tokens_used);
    $items_affected = max(0, (int) $items_affected);
    $now = time();
    $status = $this->normalizeMachineName($status, 'error', 32);
    $source = $this->normalizeMachineName($source, 'assistant', 32);
    $operation = $this->normalizeMachineName($operation, '', 48);
    $request_count_hour = 1 + (int) $this->database->select('moody_ai_assistant_usage_log', 'l')
      ->condition('uid', $uid)
      ->condition('created', $now - 3600, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();

    $review_flags = [];
    if (!in_array($status, ['success', 'partial'], TRUE)) {
      $review_flags[] = 'error';
    }
    if ($tokens_used >= static::HIGH_TOKEN_THRESHOLD) {
      $review_flags[] = 'high_tokens';
    }
    if (mb_strlen((string) $prompt) >= static::LONG_PROMPT_THRESHOLD) {
      $review_flags[] = 'long_prompt';
    }
    if ($request_count_hour >= static::HIGH_FREQUENCY_THRESHOLD) {
      $review_flags[] = 'high_frequency';
    }

    $fields = [
      'uid' => $uid,
      'thread_id' => $thread_id ? (int) $thread_id : NULL,
      'target_entity_type' => $entity ? $entity->getEntityTypeId() : '',
      'target_entity_id' => $entity ? (int) $entity->id() : 0,
      'target_entity_label' => $entity ? mb_substr((string) $entity->label(), 0, 255) : '',
      'prompt' => (string) $prompt,
      'tokens_used' => $tokens_used,
      'status' => $status,
      'source' => $source,
      'request_count_hour' => $request_count_hour,
      'needs_review' => $review_flags ? 1 : 0,
      'review_flags' => implode(',', $review_flags),
      'created' => $now,
    ];
    if ($this->database->schema()->fieldExists('moody_ai_assistant_usage_log', 'operation')) {
      $fields['operation'] = $operation;
      $fields['items_affected'] = $items_affected;
    }

    $this->database->insert('moody_ai_assistant_usage_log')
      ->fields($fields)
      ->execute();
  }

  /**
   * Normalizes one untrusted reporting dimension.
   */
  protected function normalizeMachineName($value, $fallback, $length) {
    $value = preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower((string) $value));
    return mb_substr(trim($value, '_') ?: $fallback, 0, $length);
  }

  /**
   * Checks whether the shared usage table exists.
   */
  protected function tableExists($table) {
    return $this->database->schema()->tableExists($table);
  }

}
