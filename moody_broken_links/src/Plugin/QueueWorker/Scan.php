<?php

namespace Drupal\moody_broken_links\Plugin\QueueWorker;

use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[QueueWorker(id: 'moody_broken_links_scan', title: new TranslatableMarkup('Moody background broken-link scan'), cron: ['time' => 30])]
final class Scan extends QueueWorkerBase {
  public function processItem($data): void {
    \Drupal::service('moody_broken_links.background_scan')->process($data);
  }
}
