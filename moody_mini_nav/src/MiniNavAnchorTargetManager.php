<?php

declare(strict_types=1);

namespace Drupal\moody_mini_nav;

use Drupal\block_content\BlockContentInterface;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * Resolves Layout Builder block targets for Moody Mini Nav.
 */
final class MiniNavAnchorTargetManager {

  /**
   * Constructs the target manager.
   */
  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly BlockManagerInterface $blockManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
  ) {}

  /**
   * Returns anchor target options for the current page context.
   *
   * @return array
   *   Nested select options keyed by component UUID.
   */
  public function getAnchorTargetOptions(): array {
    $node = $this->getCurrentNode();
    if (!$node instanceof NodeInterface || !$node->hasField('layout_builder__layout') || $node->get('layout_builder__layout')->isEmpty()) {
      return [];
    }

    $options = [];
    foreach ($node->get('layout_builder__layout')->getSections() as $section_delta => $section) {
      $section_label = 'Section ' . ($section_delta + 1);
      $components = [];
      foreach ($section->getComponents() as $component_uuid => $component) {
        $configuration = (array) $component->get('configuration');
        $plugin_id = (string) ($configuration['id'] ?? '');
        if ($plugin_id === '') {
          continue;
        }

        $components[$component_uuid] = $this->buildComponentLabel($plugin_id, $configuration, $component->getRegion());
      }

      if ($components !== []) {
        $options[$section_label] = $components;
      }
    }

    return $options;
  }

  /**
   * Resolves the current node from route or section storage context.
   */
  public function getCurrentNode(): ?NodeInterface {
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface) {
      return $node;
    }

    $section_storage = $this->routeMatch->getParameter('section_storage');
    if ($section_storage instanceof SectionStorageInterface) {
      try {
        $entity = $section_storage->getContextValue('entity');
        if ($entity instanceof NodeInterface) {
          return $entity;
        }
      }
      catch (\Throwable) {
        return NULL;
      }
    }

    return NULL;
  }

  /**
   * Builds a human-readable label for a component.
   */
  private function buildComponentLabel(string $plugin_id, array $configuration, string $region): string {
    $label = $this->resolveComponentLabel($plugin_id, $configuration);
    $region_label = str_replace('_', ' ', $region);
    $region_label = ucwords($region_label);

    return sprintf('%s [%s]', $label, $region_label);
  }

  /**
   * Resolves the label for a Layout Builder component.
   */
  private function resolveComponentLabel(string $plugin_id, array $configuration): string {
    if (str_starts_with($plugin_id, 'inline_block:')) {
      $entity = $this->resolveInlineBlockEntity($configuration);
      if ($entity instanceof BlockContentInterface) {
        return trim((string) $entity->label()) ?: $entity->bundle();
      }

      [, $bundle] = explode(':', $plugin_id, 2) + [NULL, NULL];
      if ($bundle !== NULL) {
        $bundle_info = $this->bundleInfo->getBundleInfo('block_content');
        if (!empty($bundle_info[$bundle]['label'])) {
          return (string) $bundle_info[$bundle]['label'];
        }
      }
    }

    if (!empty($configuration['label'])) {
      return (string) $configuration['label'];
    }

    try {
      $block = $this->blockManager->createInstance($plugin_id, $configuration);
      $resolved = trim((string) $block->label());
      if ($resolved !== '') {
        return $resolved;
      }

      $definition = $block->getPluginDefinition();
      if (!empty($definition['admin_label'])) {
        return (string) $definition['admin_label'];
      }
    }
    catch (\Throwable) {
      // Fall through to the plugin ID when instantiation fails.
    }

    return $plugin_id;
  }

  /**
   * Resolves an inline block entity from component configuration.
   */
  private function resolveInlineBlockEntity(array $configuration): ?BlockContentInterface {
    if (!empty($configuration['block_serialized'])) {
      $entity = @unserialize($configuration['block_serialized']);
      return $entity instanceof BlockContentInterface ? $entity : NULL;
    }

    if (!empty($configuration['block_revision_id'])) {
      $storage = $this->entityTypeManager->getStorage('block_content');
      if ($storage instanceof RevisionableStorageInterface) {
        $entity = $storage->loadRevision((int) $configuration['block_revision_id']);
        return $entity instanceof BlockContentInterface ? $entity : NULL;
      }
    }

    return NULL;
  }

}
