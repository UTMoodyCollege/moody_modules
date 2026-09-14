<?php

namespace Drupal\moody_scheduled_publishing;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\node\NodeInterface;

/**
 * Shared scheduling and bounded publishing operation for forms and Drush.
 */
final class Scheduler {

  private bool $publishing = FALSE;

  public function canSchedule(NodeInterface $node, AccountInterface $account): bool {
    if ($account->isAnonymous() || !$node->isDefaultTranslation()) {
      return FALSE;
    }
    // Never bypass Content Moderation transitions or publish a pending revision.
    if (\Drupal::hasService('content_moderation.moderation_information') &&
        \Drupal::service('content_moderation.moderation_information')->isModeratedEntity($node)) {
      return FALSE;
    }
    return ($node->isNew() ? \Drupal::entityTypeManager()->getAccessControlHandler('node')->createAccess($node->bundle(), $account) : $node->access('update', $account))
      && $node->get('status')->access('edit', $account)
      && $node->isDefaultRevision();
  }

  public function get(int $nid): ?object {
    return \Drupal::database()->select('moody_publish_schedule', 's')->fields('s')
      ->condition('nid', $nid)->execute()->fetchObject() ?: NULL;
  }

  public function token(int $nid): string {
    return hash('sha256', json_encode($this->get($nid), JSON_THROW_ON_ERROR));
  }

  public function event(int $nid, int $uid, string $event, int $at = 0, int $revision = 0): void {
    \Drupal::database()->insert('moody_publish_event')->fields([
      'nid' => $nid, 'uid' => $uid, 'event' => $event, 'publish_at' => $at,
      'revision_id' => $revision, 'created' => \Drupal::time()->getCurrentTime(),
    ])->execute();
  }

  /**
   * Serialize scheduler writes; node saving itself keeps Drupal's normal flow.
   */
  private function locked(callable $callback): mixed {
    // ponytail: one site-wide lock; bounded batches keep editor waits short.
    $lock = \Drupal::lock();
    if (!$lock->acquire('moody_scheduled_publishing', 180)) {
      throw new \RuntimeException('Publishing is running. Please try again shortly.');
    }
    try {
      return $callback();
    }
    finally {
      $lock->release('moody_scheduled_publishing');
    }
  }

  public function schedule(int $nid, ?int $at, AccountInterface $account, ?string $expected = NULL): void {
    $this->locked(function () use ($nid, $at, $account, $expected): void {
      $storage = \Drupal::entityTypeManager()->getStorage('node');
      $node = $storage->loadUnchanged($nid);
      if (!$node || !$this->canSchedule($node, $account)) {
        throw new \RuntimeException('You cannot schedule publication of this content.');
      }
      if ($expected !== NULL && !hash_equals($expected, $this->token($nid))) {
        // A manual publish performed by this same form already reconciled it.
        if ($at === NULL && $node->isPublished() && $this->get($nid)?->state === 'published_manually') {
          return;
        }
        throw new \RuntimeException('The schedule changed since this form was opened. Reload before editing it.');
      }
      if ($at !== NULL && ($at <= \Drupal::time()->getCurrentTime() || $node->isPublished())) {
        throw new \InvalidArgumentException('Choose a future time and leave Published unchecked.');
      }
      $db = \Drupal::database();
      $transaction = $db->startTransaction();
      try {
        $old = $this->get($nid);
        if ($at === NULL) {
          if ($old && in_array($old->state, ['pending', 'blocked'], TRUE)) {
            $db->update('moody_publish_schedule')->fields(['state' => 'cancelled', 'changed' => time()])->condition('nid', $nid)->execute();
            $this->event($nid, (int) $account->id(), 'cancelled', (int) $old->publish_at);
          }
          return;
        }
        if ($old && $old->state === 'pending' && (int) $old->publish_at === $at) {
          return;
        }
        $db->merge('moody_publish_schedule')->key('nid', $nid)->fields([
          'uid' => (int) $account->id(), 'publish_at' => $at,
          'changed' => time(), 'state' => 'pending',
        ])->execute();
        $this->event($nid, (int) $account->id(), $old && in_array($old->state, ['pending', 'blocked'], TRUE) ? 'rescheduled' : 'scheduled', $at, (int) $node->getRevisionId());
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
    });
  }

  /**
   * Record manual publication/deletion without changing ordinary edit behavior.
   */
  public function reconcile(NodeInterface $node, bool $deleted = FALSE): void {
    if ($this->publishing || (!$deleted && (!$node->isPublished() || !$node->isDefaultRevision()))) {
      return;
    }
    $old = $this->get((int) $node->id());
    if (!$old || !in_array($old->state, ['pending', 'blocked'], TRUE)) {
      return;
    }
    $state = $deleted ? 'deleted' : 'published_manually';
    $changed = \Drupal::database()->update('moody_publish_schedule')
      ->fields(['state' => $state, 'changed' => time()])->condition('nid', $node->id())
      ->condition('state', ['pending', 'blocked'], 'IN')->execute();
    if ($changed) {
      $this->event((int) $node->id(), (int) \Drupal::currentUser()->id(), $state, (int) $old->publish_at, (int) $node->getRevisionId());
    }
  }

  /**
   * Publish up to 25 due nodes, with a 45-second budget between nodes.
   *
   * Automatic operation requires a settings.php opt-in (not exported config).
   * Explicit prototype execution is permitted only in DDEV.
   */
  public function run(bool $prototype = FALSE, string $target = 'live'): array {
    $environment = getenv('PANTHEON_ENVIRONMENT');
    $allowed = ($target === 'test' && $environment === 'test') ||
      ($target === 'live' && $environment === 'live' && Settings::get('moody_scheduled_publishing_enabled', FALSE));
    if (!in_array($target, ['test', 'live'], TRUE)) {
      throw new \InvalidArgumentException('Target must be test or live.');
    }
    if (!$allowed && !($prototype && getenv('DDEV_SITENAME') && !$environment)) {
      throw new \RuntimeException('Automatic publishing is disabled for this environment. Local DDEV testing requires --prototype.');
    }
    return $this->locked(function (): array {
      $db = \Drupal::database();
      $started = time();
      $result = ['published' => [], 'blocked' => [], 'failed' => []];
      $ids = $db->select('moody_publish_schedule', 's')->fields('s', ['nid'])
        ->condition('state', 'pending')->condition('publish_at', $started, '<=')
        ->orderBy('publish_at')->range(0, 25)->execute()->fetchCol();
      foreach ($ids as $nid) {
        if (time() - $started >= 45) {
          break;
        }
        $transaction = $db->startTransaction();
        $switched = FALSE;
        try {
          // Lock the node row before loading: concurrent ordinary saves wait.
          $db->select('node', 'n')->fields('n', ['nid'])->condition('nid', $nid)->forUpdate()->execute()->fetchField();
          $schedule = $db->select('moody_publish_schedule', 's')->fields('s')->condition('nid', $nid)->forUpdate()->execute()->fetchObject();
          if (!$schedule || $schedule->state !== 'pending' || (int) $schedule->publish_at > time()) {
            continue;
          }
          $storage = \Drupal::entityTypeManager()->getStorage('node');
          $node = $storage->loadUnchanged($nid);
          $actor = \Drupal::entityTypeManager()->getStorage('user')->load($schedule->uid);
          if (!$node || !$actor || !$actor->isActive() || !$this->canSchedule($node, $actor)) {
            $db->update('moody_publish_schedule')->fields(['state' => 'blocked', 'changed' => time()])->condition('nid', $nid)->execute();
            $this->event((int) $nid, (int) $schedule->uid, 'blocked_access', (int) $schedule->publish_at);
            $result['blocked'][] = (int) $nid;
            continue;
          }
          if ($node->isPublished()) {
            $this->reconcile($node);
            continue;
          }
          // Don't publish older content while a newer non-default revision exists.
          if ((int) $storage->getLatestRevisionId($nid) !== (int) $node->getRevisionId()) {
            $db->update('moody_publish_schedule')->fields(['state' => 'blocked', 'changed' => time()])->condition('nid', $nid)->execute();
            $this->event((int) $nid, (int) $schedule->uid, 'blocked_revision', (int) $schedule->publish_at);
            $result['blocked'][] = (int) $nid;
            continue;
          }
          \Drupal::service('account_switcher')->switchTo($actor);
          $switched = TRUE;
          $this->publishing = TRUE;
          $node->setPublished()->setNewRevision(TRUE);
          $node->setRevisionUserId($actor->id());
          $node->setRevisionCreationTime(time());
          $node->setRevisionLogMessage('Published by Moody Scheduled Publishing.');
          if ($node->validate()->count()) {
            throw new \RuntimeException('Content validation failed.');
          }
          $node->save();
          $db->update('moody_publish_schedule')->fields(['state' => 'published', 'changed' => time()])->condition('nid', $nid)->execute();
          $this->event((int) $nid, (int) $actor->id(), 'published', (int) $schedule->publish_at, (int) $node->getRevisionId());
          $result['published'][] = (int) $nid;
        }
        catch (\Throwable $e) {
          $transaction->rollBack();
          \Drupal::entityTypeManager()->getStorage('node')->resetCache([$nid]);
          // A bad node must not starve the next batch. Editors can reschedule
          // after correcting the problem; failed content stays unpublished.
          $db->update('moody_publish_schedule')->fields(['state' => 'blocked', 'changed' => time()])->condition('nid', $nid)->condition('state', 'pending')->execute();
          $this->event((int) $nid, (int) ($schedule->uid ?? 0), 'failed', (int) ($schedule->publish_at ?? 0));
          \Drupal::logger('moody_scheduled_publishing')->error('Scheduled publication failed for node @nid (@type).', ['@nid' => $nid, '@type' => get_class($e)]);
          $result['failed'][] = (int) $nid;
        }
        finally {
          $this->publishing = FALSE;
          if ($switched) {
            \Drupal::service('account_switcher')->switchBack();
          }
          unset($transaction);
        }
      }
      \Drupal::state()->set('moody_scheduled_publishing.last_run', ['time' => time(), 'result' => array_map('count', $result)]);
      return $result;
    });
  }

}
