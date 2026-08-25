<?php

namespace Drupal\moody_account_expiration;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Finds and blocks active accounts whose expiration date has arrived.
 */
final class AccountExpirationProcessor {

  public const FIELD_NAME = 'field_moody_account_expiration';

  private const BLOCKED_ACCOUNTS = 'moody_account_expiration.blocked_accounts';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Blocks all active, expired accounts except user 1.
   *
   * @return int[]
   *   The user IDs blocked during this run.
   */
  public function process(?string $cutoff = NULL): array {
    $cutoff ??= $this->today();
    $this->validateDate($cutoff);

    $storage = $this->entityTypeManager->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 1, '>')
      ->condition('status', 1)
      ->condition(self::FIELD_NAME . '.value', $cutoff, '<=')
      ->sort('uid')
      ->execute();

    $blocked = [];
    foreach ($storage->loadMultiple($ids) as $account) {
      if (!$account instanceof UserInterface || !$account->isActive()) {
        continue;
      }
      $expiration = (string) $account->get(self::FIELD_NAME)->value;
      $account->block();
      $account->save();
      $this->blockedAccounts()->set((string) $account->id(), $expiration);
      $blocked[] = (int) $account->id();
      $this->logger->notice('Blocked expired account @uid (@name) with expiration date @date.', [
        '@uid' => $account->id(),
        '@name' => $account->getAccountName(),
        '@date' => $expiration,
      ]);
    }

    return $blocked;
  }

  /**
   * Reactivates a module-blocked account when its date is cleared or extended.
   */
  public function prepareReactivation(UserInterface $account): void {
    if ($account->isNew() || $account->isActive() || !$this->wasBlocked($account)) {
      return;
    }

    $original = $account->getOriginal();
    if (!$original instanceof UserInterface) {
      return;
    }
    $original_date = (string) $original->get(self::FIELD_NAME)->value;
    $new_date = (string) $account->get(self::FIELD_NAME)->value;
    if ($new_date === $original_date || ($new_date !== '' && $new_date <= $this->today())) {
      return;
    }

    $account->activate();
  }

  /**
   * Removes the module marker after a successful reactivation save.
   */
  public function completeReactivation(UserInterface $account): void {
    if (!$account->isActive() || !$this->wasBlocked($account)) {
      return;
    }

    $date = (string) $account->get(self::FIELD_NAME)->value;
    if ($date === '' || $date > $this->today()) {
      $this->blockedAccounts()->delete((string) $account->id());
      $this->logger->notice('Reactivated account @uid (@name) after its expiration date was cleared or extended.', [
        '@uid' => $account->id(),
        '@name' => $account->getAccountName(),
      ]);
    }
  }

  /**
   * Clears internal state when an account is deleted.
   */
  public function forget(UserInterface $account): void {
    $this->blockedAccounts()->delete((string) $account->id());
  }

  /**
   * Reports whether this module blocked the account.
   */
  public function wasBlocked(UserInterface $account): bool {
    return $this->blockedAccounts()->has((string) $account->id());
  }

  /**
   * Returns today's date in the site's configured timezone.
   */
  public function today(): string {
    $timezone = $this->configFactory->get('system.date')->get('timezone.default') ?: 'UTC';
    return DrupalDateTime::createFromTimestamp($this->time->getRequestTime(), $timezone)->format('Y-m-d');
  }

  /**
   * Returns the module's private account marker store.
   */
  private function blockedAccounts(): KeyValueStoreInterface {
    return $this->keyValueFactory->get(self::BLOCKED_ACCOUNTS);
  }

  /**
   * Rejects invalid programmatic cutoff dates.
   */
  private function validateDate(string $date): void {
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
      throw new \InvalidArgumentException('The cutoff must use a valid YYYY-MM-DD date.');
    }
  }

}
