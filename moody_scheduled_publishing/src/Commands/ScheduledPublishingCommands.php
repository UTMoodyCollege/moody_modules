<?php

namespace Drupal\moody_scheduled_publishing\Commands;

use Drupal\moody_scheduled_publishing\Scheduler;
use Drush\Commands\DrushCommands;

final class ScheduledPublishingCommands extends DrushCommands {

  public function __construct(private readonly Scheduler $scheduler) {
    parent::__construct();
  }

  /**
   * Process a bounded batch of due content and print a JSON receipt.
   *
   * @command moody-scheduled-publishing:process
   * @option prototype Allow an explicit local DDEV prototype run.
   * @option target Explicit Pantheon target: test or live. Must match runtime.
   * @usage moody-scheduled-publishing:process --prototype
   *   Process local DDEV schedules only. Never enables remote processing.
   */
  public function process(array $options = ['prototype' => FALSE, 'target' => 'live']): int {
    $result = $this->scheduler->run((bool) $options['prototype'], (string) $options['target']);
    $this->output()->writeln(json_encode($result, JSON_THROW_ON_ERROR));
    return $result['failed'] || $result['blocked'] ? 1 : 0;
  }

}
