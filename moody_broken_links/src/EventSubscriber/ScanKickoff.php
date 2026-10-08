<?php

namespace Drupal\moody_broken_links\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ScanKickoff implements EventSubscriberInterface {
  public function terminate(TerminateEvent $event): void {
    if ($event->getRequest()->attributes->get('_moody_broken_links_kickoff')) {
      \Drupal::service('moody_broken_links.background_scan')->kickoff();
    }
  }
  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => ['terminate', 0]];
  }
}
