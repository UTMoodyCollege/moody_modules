<?php

namespace Drupal\moody_block_publishing\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\layout_builder\Event\SectionComponentBuildRenderArrayEvent;
use Drupal\layout_builder\LayoutBuilderEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hides unpublished Layout Builder blocks from normal page views.
 */
final class SectionComponentRenderSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      LayoutBuilderEvents::SECTION_COMPONENT_BUILD_RENDER_ARRAY => 'onBuildRenderArray',
    ];
  }

  /**
   * Removes unpublished components for users without the bypass permission.
   */
  public function onBuildRenderArray(SectionComponentBuildRenderArrayEvent $event): void {
    $component = $event->getComponent();
    $is_unpublished = (bool) $component->get('moody_block_publishing_unpublished');

    if (!$is_unpublished) {
      return;
    }

    $event->getCacheableMetadata()->addCacheContexts(['user.permissions']);

    if ($event->inPreview() || $this->currentUser->hasPermission('view unpublished layout builder blocks')) {
      return;
    }

    $event->setBuild([]);
  }

}