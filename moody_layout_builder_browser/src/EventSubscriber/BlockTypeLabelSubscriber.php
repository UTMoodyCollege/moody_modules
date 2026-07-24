<?php

namespace Drupal\moody_layout_builder_browser\EventSubscriber;

use Drupal\Component\Utility\Html;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\layout_builder\Event\SectionComponentBuildRenderArrayEvent;
use Drupal\layout_builder\LayoutBuilderEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds block type labels to Layout Builder previews.
 */
class BlockTypeLabelSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      LayoutBuilderEvents::SECTION_COMPONENT_BUILD_RENDER_ARRAY => [
        'addBlockTypeLabel',
        0,
      ],
    ];
  }

  /**
   * Adds the block plugin's administrative label above its preview.
   */
  public function addBlockTypeLabel(SectionComponentBuildRenderArrayEvent $event): void {
    $plugin = $event->getPlugin();
    if (!$event->inPreview() || !$plugin instanceof BlockPluginInterface) {
      return;
    }

    $definition = $plugin->getPluginDefinition();
    if (empty($definition['admin_label'])) {
      return;
    }

    $label = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['moody-layout-builder-block-type'],
      ],
      '#weight' => -1000,
      'prefix' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => new TranslatableMarkup('Block type:'),
        '#attributes' => [
          'class' => ['moody-layout-builder-block-type__prefix'],
        ],
      ],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => Html::escape((string) $definition['admin_label']),
        '#attributes' => [
          'class' => ['moody-layout-builder-block-type__label'],
        ],
      ],
    ];

    $build = $event->getBuild();
    if (isset($build['content'])) {
      $build['content'] = [
        'moody_block_type_label' => $label,
        'block_content' => $build['content'],
      ];
    }
    else {
      $build['moody_block_type_label'] = $label;
    }
    $build['#attached']['library'][] = 'moody_layout_builder_browser/block_type_labels';
    $event->setBuild($build);
  }

}
