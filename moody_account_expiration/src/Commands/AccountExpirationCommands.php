<?php

namespace Drupal\moody_account_expiration\Commands;

use Drupal\moody_account_expiration\AccountExpirationProcessor;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Command\Command;

/**
 * Drush commands for account expiration enforcement.
 */
final class AccountExpirationCommands extends DrushCommands {

  public function __construct(
    private readonly AccountExpirationProcessor $processor,
  ) {
    parent::__construct();
  }

  /**
   * Blocks active users whose account expiration date has arrived.
   *
   * @command moody-account-expiration:enforce
   * @aliases mae:enforce
   * @usage moody-account-expiration:enforce
   *   Enforce account expiration using the site's current date.
   */
  public function enforce(): int {
    $blocked = $this->processor->process();
    $this->output()->writeln(sprintf(
      'Blocked %d expired account%s.',
      count($blocked),
      count($blocked) === 1 ? '' : 's',
    ));
    return Command::SUCCESS;
  }

}
