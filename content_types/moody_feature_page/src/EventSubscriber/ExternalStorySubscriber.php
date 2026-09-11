<?php

namespace Drupal\moody_feature_page\EventSubscriber;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\moody_feature_page\ExternalStory;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Shows the external story notice only on the accessible canonical HTML page.
 */
class ExternalStorySubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [KernelEvents::CONTROLLER => ['onController', 10]];
  }

  public function onController(ControllerEvent $event): void {
    $request = $event->getRequest();
    $node = $request->attributes->get('node');
    if (!$event->isMainRequest() || $request->attributes->get('_route') !== 'entity.node.canonical'
      || $request->getRequestFormat() !== 'html' || !$node instanceof NodeInterface
      || $node->bundle() !== 'moody_feature_page') {
      return;
    }
    $node = \Drupal::service('entity.repository')->getTranslationFromContext($node);
    if (!$node->hasField('field_feature_external') || !$node->get('field_feature_external')->value
      || !$node->hasField('field_feature_external_url')) {
      return;
    }

    $url = (string) $node->get('field_feature_external_url')->value;
    if (!ExternalStory::validUrl($url) || strcasecmp((string) parse_url($url, PHP_URL_HOST), $request->getHost()) === 0) {
      return;
    }

    // The legacy Redirect-record subscriber must not replace this opt-in notice.
    $request->attributes->set('_moody_external_story', TRUE);
    $event->setController(static function () use ($node, $url): array {
      $edit_access = $node->access('update', NULL, TRUE);
      $build = [
        '#theme' => 'moody_feature_external_story',
        '#title' => $node->label(),
        '#destination' => $url,
        '#host' => parse_url($url, PHP_URL_HOST),
        '#automatic' => $node->isPublished() && !$edit_access->isAllowed(),
        '#attached' => ['library' => ['moody_feature_page/external_story']],
        '#cache' => ['contexts' => ['url.site']],
      ];
      CacheableMetadata::createFromRenderArray($build)->addCacheableDependency($node)->addCacheableDependency($edit_access)->applyTo($build);
      return $build;
    });
  }

}
