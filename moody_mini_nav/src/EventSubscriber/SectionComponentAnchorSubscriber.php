<?php

declare(strict_types=1);

namespace Drupal\moody_mini_nav\EventSubscriber;

use Drupal\Component\Utility\Html;
use Drupal\layout_builder\Event\SectionComponentBuildRenderArrayEvent;
use Drupal\layout_builder\LayoutBuilderEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds stable anchor attributes to Layout Builder components.
 */
final class SectionComponentAnchorSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      LayoutBuilderEvents::SECTION_COMPONENT_BUILD_RENDER_ARRAY => ['onBuildRender', -100],
    ];
  }

  /**
   * Adds anchor metadata to every rendered section component.
   */
  public function onBuildRender(SectionComponentBuildRenderArrayEvent $event): void {
    $build = $event->getBuild();
    if ($build === []) {
      return;
    }

    $component_uuid = $event->getComponent()->getUuid();
    $build['#attributes']['class'][] = 'moody-mini-nav-anchor-target';
    $build['#attributes']['data-moody-mini-nav-component-uuid'] = $component_uuid;
    $build['#attributes']['data-moody-mini-nav-anchor-id'] = 'moody-mini-nav-target-' . Html::getId($component_uuid);
    $build['#attributes']['id'] ??= 'moody-mini-nav-target-' . Html::getId($component_uuid);

    $event->setBuild($build);
  }

}
