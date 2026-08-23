<?php

namespace Drupal\moody_ai_assistant\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\moody_ai_base\UsageTracker;
use Drupal\moody_ai_assistant\Exception\InsufficientUsageTokensException;

/**
 * Tracks per-user AI usage and token budgets.
 */
class AIUsageTracker extends UsageTracker {

  /**
   * Gets a user's token budget summary.
   */
  public function getUserBudgetSummary($uid) {
    $uid = (int) $uid;
    if (!$this->tableExists('moody_ai_assistant_user_budget')) {
      return [
        'has_budget' => FALSE,
        'token_budget' => NULL,
        'tokens_used' => 0,
        'remaining' => NULL,
        'is_exhausted' => FALSE,
      ];
    }

    $record = $this->database->select('moody_ai_assistant_user_budget', 'b')
      ->fields('b', ['token_budget', 'tokens_used'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();

    if (!$record) {
      return [
        'has_budget' => FALSE,
        'token_budget' => NULL,
        'tokens_used' => 0,
        'remaining' => NULL,
        'is_exhausted' => FALSE,
      ];
    }

    $budget = max(0, (int) $record['token_budget']);
    $used = max(0, (int) $record['tokens_used']);

    return [
      'has_budget' => TRUE,
      'token_budget' => $budget,
      'tokens_used' => $used,
      'remaining' => max(0, $budget - $used),
      'is_exhausted' => $used >= $budget,
    ];
  }

  /**
   * Throws when the user cannot start another AI request.
   */
  public function assertUserHasBudget(AccountInterface $account) {
    $summary = $this->getUserBudgetSummary($account->id());
    if (!empty($summary['is_exhausted'])) {
      throw new InsufficientUsageTokensException('You have insufficient usage tokens.');
    }
  }

  /**
   * Creates or updates a user token budget.
   */
  public function setUserBudget($uid, $token_budget, $reset_used = FALSE) {
    if (!$this->tableExists('moody_ai_assistant_user_budget')) {
      throw new \RuntimeException('Moody AI Assistant usage budget table is not installed. Run database updates.');
    }

    $uid = (int) $uid;
    $now = time();
    $fields = [
      'uid' => $uid,
      'token_budget' => max(0, (int) $token_budget),
      'changed' => $now,
    ];

    if ($reset_used) {
      $fields['tokens_used'] = 0;
    }

    $this->database->merge('moody_ai_assistant_user_budget')
      ->key('uid', $uid)
      ->fields($fields)
      ->insertFields($fields + [
        'tokens_used' => 0,
        'created' => $now,
      ])
      ->execute();
  }

  /**
   * Resets a user's used token count.
   */
  public function resetUserUsage($uid) {
    if (!$this->tableExists('moody_ai_assistant_user_budget')) {
      return;
    }

    $uid = (int) $uid;
    $this->database->update('moody_ai_assistant_user_budget')
      ->fields([
        'tokens_used' => 0,
        'changed' => time(),
      ])
      ->condition('uid', $uid)
      ->execute();
  }

  /**
   * Records shared usage and applies any Assistant-specific user budget.
   */
  public function recordUsage(AccountInterface $account, ?ContentEntityInterface $entity, $prompt, $tokens_used, $status = 'success', $thread_id = NULL, $source = 'assistant', $operation = '', $items_affected = 0) {
    parent::recordUsage($account, $entity, $prompt, $tokens_used, $status, $thread_id, $source, $operation, $items_affected);
    $uid = (int) $account->id();
    $tokens_used = max(0, (int) $tokens_used);
    $now = time();
    if ($tokens_used > 0 && $this->userHasBudget($uid)) {
      $this->database->update('moody_ai_assistant_user_budget')
        ->expression('tokens_used', 'tokens_used + :tokens_used', [':tokens_used' => $tokens_used])
        ->fields(['changed' => $now])
        ->condition('uid', $uid)
        ->execute();
    }
  }

  /**
   * Checks whether a user has an explicit budget row.
   */
  protected function userHasBudget($uid) {
    if (!$this->tableExists('moody_ai_assistant_user_budget')) {
      return FALSE;
    }

    return (bool) $this->database->select('moody_ai_assistant_user_budget', 'b')
      ->condition('uid', (int) $uid)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Checks whether a usage table exists.
   */
  protected function tableExists($table) {
    return $this->database->schema()->tableExists($table);
  }

}
