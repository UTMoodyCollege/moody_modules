<?php

namespace Drupal\moody_layout_builder_browser;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Element;
use Drupal\Core\Url;
use Drupal\layout_builder_browser\Controller\BrowserController;
use Drupal\views\Plugin\Block\ViewsBlockBase;

/**
 * Supplements the curated directory using the browser's existing link builder.
 */
final class AvailableBlocks extends BrowserController {

  public function append(array &$build, array $context): void {
    $storage = $context['section_storage'];
    $definitions = $this->blockManager->getFilteredDefinitions('layout_builder', $this->getPopulatedContexts($storage), $context + ['list' => 'inline_blocks', 'browse' => TRUE]);
    $existing = self::listedIds($build);
    $groups = [];
    foreach ($definitions as $id => $definition) {
      $group = str_starts_with($id, 'views_block:') ? 'views' : (str_starts_with($id, 'block_content:') ? 'reusable' : NULL);
      if (!$group || isset($existing[$id])) {
        continue;
      }
      // Discovery already applies layout restrictions; retain content access too.
      $plugin = $this->blockManager->createInstance($id);
      if (($plugin instanceof ViewsBlockBase && !$plugin->getViewExecutable()->display_handler->isEnabled()) || !$plugin->access($this->currentUser)) {
        continue;
      }
      $definition['admin_label'] = Html::escape((string) $definition['admin_label']);
      $groups[$group][$id] = $definition;
    }
    foreach ($groups as $group => $blocks) {
      uasort($blocks, static fn(array $a, array $b): int => strnatcasecmp((string) $a['admin_label'], (string) $b['admin_label']));
      $build['block_categories']['moody_available_' . $group] = [
        '#type' => 'details',
        '#title' => $group === 'views' ? $this->t('Views blocks') : $this->t('Reusable blocks'),
        '#open' => FALSE,
        '#attributes' => ['class' => ['js-layout-builder-category']],
        'links' => $this->getBlocks($storage, $context['delta'], $context['region'], $blocks),
      ];
    }
    // This editor-only list depends on live content, Views and per-user access.
    $build['#cache']['max-age'] = 0;
  }

  private static function listedIds(array $element): array {
    $ids = [];
    $url = $element['#url'] ?? NULL;
    if ($url instanceof Url && $url->isRouted() && $url->getRouteName() === 'layout_builder.add_block') {
      $ids[$url->getRouteParameters()['plugin_id']] = TRUE;
    }
    foreach (Element::children($element) as $key) {
      $ids += self::listedIds($element[$key]);
    }
    return $ids;
  }

}
