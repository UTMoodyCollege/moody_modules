<?php

namespace Drupal\moody_block_reports;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;

/**
 * Builds Layout Builder block usage audit data.
 */
class BlockUsageAudit {

  use StringTranslationTrait;

  /**
   * The node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $nodeStorage;

  /**
   * The block content storage.
   *
   * @var \Drupal\Core\Entity\RevisionableStorageInterface
   */
  protected $blockContentStorage;

  /**
   * Constructs the audit service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    protected BlockManagerInterface $blockManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    $string_translation
  ) {
    $this->nodeStorage = $entity_type_manager->getStorage('node');
    $this->blockContentStorage = $entity_type_manager->getStorage('block_content');
    $this->stringTranslation = $string_translation;
  }

  /**
   * Returns node IDs to audit.
   *
   * @param string[] $bundles
   *   The node bundles to include.
   * @param bool $include_unpublished
   *   TRUE to include unpublished content.
   *
   * @return int[]
   *   Matching node IDs.
   */
  public function getNodeIds(array $bundles, $include_unpublished = TRUE) {
    $query = $this->nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundles, 'IN');

    if (!$include_unpublished) {
      $query->condition('status', NodeInterface::PUBLISHED);
    }

    return array_values($query->sort('nid')->execute());
  }

  /**
   * Audits a node and updates aggregate results.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being audited.
   * @param array $results
   *   The aggregate results array.
   */
  public function auditNode(NodeInterface $node, array &$results) {
    if (!$node->hasField('layout_builder__layout') || $node->get('layout_builder__layout')->isEmpty()) {
      return;
    }

    foreach ($node->get('layout_builder__layout')->getSections() as $section) {
      foreach ($section->getComponents() as $component) {
        $configuration = (array) $component->get('configuration');
        $plugin_id = (string) ($configuration['id'] ?? '');

        if ($plugin_id === '') {
          continue;
        }

        $usage_key = $this->buildUsageKey($plugin_id, $configuration);
        $usage = &$results['blocks'][$usage_key];

        if (!isset($usage)) {
          $usage = [
            'plugin_id' => $plugin_id,
            'label' => $this->resolveBlockLabel($plugin_id, $configuration),
            'source' => str_starts_with($plugin_id, 'inline_block:') ? 'Inline block' : 'Plugin block',
            'machine_name' => $this->resolveMachineName($plugin_id),
            'view_modes' => [],
            'pages' => [],
            'usage_items' => [],
            'placements' => 0,
          ];
        }

        $view_mode = $this->resolveViewMode($plugin_id, $configuration);
        if ($view_mode !== '') {
          $usage['view_modes'][$view_mode] = $view_mode;
        }

        $usage['placements']++;
        $usage['pages'][$node->id()] = [
          'nid' => (int) $node->id(),
          'title' => $node->label(),
          'bundle' => $this->getBundleLabel($node->bundle()),
        ];
        $usage['usage_items'][] = [
          'instance_label' => $this->resolveInstanceLabel($plugin_id, $configuration),
          'view_mode' => $view_mode,
          'nid' => (int) $node->id(),
          'title' => $node->label(),
          'bundle' => $this->getBundleLabel($node->bundle()),
        ];
      }
    }
  }

  /**
   * Normalizes aggregate results for display.
   *
   * @param array $results
   *   Raw aggregate results.
   *
   * @return array
   *   Normalized results.
   */
  public function finalizeResults(array $results) {
    $rows = [];

    foreach ($results['blocks'] ?? [] as $block) {
      $pages = array_values($block['pages']);
      $usage_items = array_values($block['usage_items'] ?? []);
      usort($pages, static function (array $a, array $b) {
        return strcasecmp($a['title'], $b['title']);
      });
      usort($usage_items, static function (array $a, array $b) {
        return [strcasecmp($a['instance_label'], $b['instance_label']), strcasecmp($a['title'], $b['title'])];
      });

      $rows[] = [
        'plugin_id' => $block['plugin_id'],
        'label' => $block['label'],
        'source' => $block['source'],
        'machine_name' => $block['machine_name'],
        'view_modes' => array_values($block['view_modes'] ?? []),
        'pages_count' => count($pages),
        'placements' => $block['placements'],
        'pages' => $pages,
        'usage_items' => $usage_items,
      ];
    }

    usort($rows, static function (array $a, array $b) {
      return [$b['pages_count'], $b['placements'], $a['label']] <=> [$a['pages_count'], $a['placements'], $b['label']];
    });

    return [
      'generated' => \Drupal::time()->getRequestTime(),
      'bundles' => $results['bundles'] ?? [],
      'include_unpublished' => !empty($results['include_unpublished']),
      'audited_nodes' => (int) ($results['audited_nodes'] ?? 0),
      'blocks' => $rows,
    ];
  }

  /**
   * Builds a stable aggregate key for a block usage row.
   *
   * @param string $plugin_id
   *   The block plugin ID.
   * @param array $configuration
   *   The block configuration.
   *
   * @return string
   *   The aggregate key.
   */
  protected function buildUsageKey($plugin_id, array $configuration) {
    return $plugin_id;
  }

  /**
   * Resolves a human-readable block label.
   *
   * @param string $plugin_id
   *   The block plugin ID.
   * @param array $configuration
   *   The block configuration.
   *
   * @return string
   *   The resolved label.
   */
  protected function resolveBlockLabel($plugin_id, array $configuration) {
    if (str_starts_with($plugin_id, 'inline_block:')) {
      $label = $this->resolveInlineBlockLabel($plugin_id, $configuration);
      if ($label !== '') {
        return $label;
      }
    }

    try {
      $block = $this->blockManager->createInstance($plugin_id, $configuration);
      $label = trim((string) $block->label());
      if ($label !== '') {
        return $label;
      }

      $definition = $block->getPluginDefinition();
      if (!empty($definition['admin_label'])) {
        return (string) $definition['admin_label'];
      }
    }
    catch (\Throwable) {
      // Fall back to a predictable label when a plugin cannot be instantiated.
    }

    return $plugin_id;
  }

  /**
   * Resolves an inline block label.
   *
   * @param string $plugin_id
   *   The block plugin ID.
   * @param array $configuration
   *   The block configuration.
   *
   * @return string
   *   The resolved label.
   */
  protected function resolveInlineBlockLabel($plugin_id, array $configuration) {
    [, $bundle] = explode(':', $plugin_id, 2) + [NULL, NULL];
    if ($bundle) {
      $bundle_info = $this->bundleInfo->getBundleInfo('block_content');
      if (!empty($bundle_info[$bundle]['label'])) {
        return (string) $bundle_info[$bundle]['label'];
      }
    }

    if (!empty($configuration['label'])) {
      return (string) $configuration['label'];
    }

    if (!empty($configuration['block_serialized'])) {
      $entity = @unserialize($configuration['block_serialized']);
      if ($entity && method_exists($entity, 'label')) {
        return (string) $entity->label();
      }
    }

    if (!empty($configuration['block_revision_id'])) {
      $entity = $this->blockContentStorage->loadRevision($configuration['block_revision_id']);
      if ($entity) {
        return (string) $entity->label();
      }
    }

    return $plugin_id;
  }

  /**
   * Resolves a per-placement label for usage listings.
   *
   * @param string $plugin_id
   *   The block plugin ID.
   * @param array $configuration
   *   The block configuration.
   *
   * @return string
   *   The placement label.
   */
  protected function resolveInstanceLabel($plugin_id, array $configuration) {
    if (!empty($configuration['label'])) {
      return (string) $configuration['label'];
    }

    if (str_starts_with($plugin_id, 'inline_block:')) {
      if (!empty($configuration['block_serialized'])) {
        $entity = @unserialize($configuration['block_serialized']);
        if ($entity && method_exists($entity, 'label')) {
          $label = trim((string) $entity->label());
          if ($label !== '') {
            return $label;
          }
        }
      }

      if (!empty($configuration['block_revision_id'])) {
        $entity = $this->blockContentStorage->loadRevision($configuration['block_revision_id']);
        if ($entity) {
          $label = trim((string) $entity->label());
          if ($label !== '') {
            return $label;
          }
        }
      }
    }

    return $this->resolveBlockLabel($plugin_id, $configuration);
  }

  /**
   * Resolves the machine name behind a block row.
   *
   * @param string $plugin_id
   *   The block plugin ID.
   *
   * @return string
   *   The machine-readable plugin or bundle ID.
   */
  protected function resolveMachineName($plugin_id) {
    if (str_starts_with($plugin_id, 'inline_block:')) {
      [, $bundle] = explode(':', $plugin_id, 2) + [NULL, NULL];
      return $bundle ?: $plugin_id;
    }

    return $plugin_id;
  }

  /**
   * Resolves the configured formatter or view mode for a placement.
   *
   * @param string $plugin_id
   *   The block plugin ID.
   * @param array $configuration
   *   The block configuration.
   *
   * @return string
   *   The resolved view mode, or an empty string when not available.
   */
  protected function resolveViewMode($plugin_id, array $configuration) {
    $candidates = [
      $configuration['view_mode'] ?? NULL,
      $configuration['settings']['view_mode'] ?? NULL,
      $configuration['settings']['formatter']['type'] ?? NULL,
    ];

    foreach ($candidates as $candidate) {
      if (is_string($candidate)) {
        $candidate = trim($candidate);
        if ($candidate !== '') {
          return $candidate;
        }
      }
    }

    return '';
  }

  /**
   * Gets a human-readable node bundle label.
   *
   * @param string $bundle
   *   The node bundle machine name.
   *
   * @return string
   *   The bundle label.
   */
  protected function getBundleLabel($bundle) {
    $bundle_info = $this->bundleInfo->getBundleInfo('node');
    return $bundle_info[$bundle]['label'] ?? $bundle;
  }

}
