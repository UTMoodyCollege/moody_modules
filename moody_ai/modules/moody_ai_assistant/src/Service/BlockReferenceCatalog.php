<?php

namespace Drupal\moody_ai_assistant\Service;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Builds promptable block reference metadata for the Layout Builder library.
 */
class BlockReferenceCatalog {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The block plugin manager.
   *
   * @var \Drupal\Core\Block\BlockManagerInterface
   */
  protected $blockManager;

  /**
   * The layout context collector.
   *
   * @var \Drupal\moody_ai_assistant\Service\LayoutContextCollector
   */
  protected $layoutContextCollector;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs the block reference catalog.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, BlockManagerInterface $block_manager, LayoutContextCollector $layout_context_collector, ModuleHandlerInterface $module_handler) {
    $this->entityTypeManager = $entity_type_manager;
    $this->blockManager = $block_manager;
    $this->layoutContextCollector = $layout_context_collector;
    $this->moduleHandler = $module_handler;
  }

  /**
   * Returns grouped promptable block references for available Layout Builder blocks.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The current page entity.
   * @param array $runtime_context
   *   Runtime layout context.
   *
   * @return array<int, array<string, mixed>>
   *   Grouped block references.
   */
  public function getGroupedReferences(ContentEntityInterface $entity, array $runtime_context = []) {
    return $this->groupReferences($this->getAvailableReferences($entity, $runtime_context));
  }

  /**
   * Returns the flat, authoritative list of blocks offered by the browser.
   *
   * The assistant uses the same list as the picker so it cannot recommend an
   * installed block that editors cannot actually add on the current site.
   */
  public function getAvailableReferences(ContentEntityInterface $entity, array $runtime_context = []) {
    $context = $this->layoutContextCollector->collectEntityContext($entity, $runtime_context);
    $browser_metadata = $this->loadLayoutBuilderBrowserMetadata();
    $usage = $this->buildExistingUsageLookup($context['existing_components'] ?? []);
    $references = [];

    if (!empty($browser_metadata['items'])) {
      foreach ($browser_metadata['items'] as $browser_item) {
        $references[] = $this->buildAvailableReference($browser_item, $browser_metadata['categories'], $usage);
      }
    }
    else {
      foreach ($context['existing_components'] ?? [] as $component) {
        $references[] = $this->buildReference($component, $browser_metadata);
      }
    }

    return array_values($references);
  }

  /**
   * Returns grouped promptable references for existing blocks on the page.
   */
  public function getGroupedExistingReferences(ContentEntityInterface $entity, array $runtime_context = []) {
    $context = $this->layoutContextCollector->collectEntityContext($entity, $runtime_context);
    $browser_metadata = $this->loadLayoutBuilderBrowserMetadata();
    $references = [];

    foreach ($context['existing_components'] ?? [] as $component) {
      $reference = $this->buildExistingReference($component, $browser_metadata);
      if (empty($reference['can_edit'])) {
        continue;
      }

      $references[] = $reference;
    }

    return $this->groupReferences($references);
  }

  /**
   * Groups and sorts block references for picker rendering.
   */
  protected function groupReferences(array $references) {
    $groups = [];

    foreach ($references as $reference) {
      $group_id = $reference['group_id'];
      if (!isset($groups[$group_id])) {
        $groups[$group_id] = [
          'id' => $group_id,
          'label' => $reference['group_label'],
          'weight' => $reference['group_weight'],
          'opened' => !empty($reference['group_opened']),
          'items' => [],
        ];
      }

      $groups[$group_id]['items'][] = $reference;
    }

    foreach ($groups as &$group) {
      usort($group['items'], function (array $left, array $right) {
        $label_compare = strnatcasecmp($left['sort_label'], $right['sort_label']);
        if ($label_compare !== 0) {
          return $label_compare;
        }
        return strnatcasecmp($left['type_label'], $right['type_label']);
      });
    }
    unset($group);

    uasort($groups, function (array $left, array $right) {
      $left_weight = $left['weight'] ?? 999;
      $right_weight = $right['weight'] ?? 999;
      if ($left_weight !== $right_weight) {
        return $left_weight <=> $right_weight;
      }
      return strnatcasecmp($left['label'], $right['label']);
    });

    return array_values($groups);
  }

  /**
   * Builds a normalized promptable reference from a browser item.
   */
  protected function buildAvailableReference(array $browser_item, array $browser_categories, array $usage) {
    $plugin_id = (string) ($browser_item['block_id'] ?? '');
    $browser_category = NULL;
    if (!empty($browser_item['category']) && isset($browser_categories[$browser_item['category']])) {
      $browser_category = $browser_categories[$browser_item['category']];
    }

    $plugin_definition = $plugin_id !== '' ? $this->blockManager->getDefinition($plugin_id, FALSE) : NULL;
    $block_type = str_starts_with($plugin_id, 'inline_block:') ? substr($plugin_id, strlen('inline_block:')) : '';
    $label = trim((string) ($browser_item['label'] ?? ($plugin_definition['admin_label'] ?? $plugin_definition['label'] ?? $plugin_id)));
    if ($label === '') {
      $label = 'Untitled block';
    }

    $category_label = trim((string) ($browser_category['label'] ?? ($plugin_definition['category'] ?? 'Other blocks')));
    if ($category_label === '') {
      $category_label = 'Other blocks';
    }

    $usage_key = $block_type !== '' ? 'inline_block:' . $block_type : $plugin_id;
    $existing_count = isset($usage[$usage_key]) ? count($usage[$usage_key]) : 0;

    return [
      'id' => $plugin_id !== '' ? $plugin_id : (string) ($browser_item['id'] ?? ''),
      'reference_id' => $plugin_id !== '' ? $plugin_id : (string) ($browser_item['id'] ?? ''),
      'uuid' => '',
      'plugin_id' => $plugin_id,
      'block_id' => NULL,
      'block_revision_id' => NULL,
      'block_type' => $block_type,
      'label' => $label,
      'sort_label' => $label,
      'type_label' => $block_type !== '' ? str_replace('_', ' ', $block_type) : 'Plugin block',
      'region' => '',
      'section' => 0,
      'can_edit' => FALSE,
      'group_id' => (string) ($browser_category['id'] ?? $this->normalizeGroupId($category_label)),
      'group_label' => $category_label,
      'group_weight' => isset($browser_category['weight']) && $browser_category['weight'] !== NULL ? (int) $browser_category['weight'] : 999,
      'group_opened' => !empty($browser_category['opened']),
      'image_path' => (string) ($browser_item['image_path'] ?? ''),
      'image_alt' => (string) ($browser_item['image_alt'] ?? $label),
      'browser_label' => (string) ($browser_item['label'] ?? ''),
      'is_available_block' => TRUE,
      'existing_count' => $existing_count,
      'selection_mode' => 'new',
    ];
  }

  /**
   * Builds a normalized promptable reference for an existing component.
   */
  protected function buildExistingReference(array $component, array $browser_metadata) {
    $reference = $this->buildReference($component, $browser_metadata);
    $reference['selection_mode'] = 'edit';
    return $reference;
  }

  /**
   * Builds a normalized promptable reference.
   */
  protected function buildReference(array $component, array $browser_metadata) {
    $plugin_id = (string) ($component['plugin_id'] ?? '');
    $browser_item = $this->matchBrowserItem($component, $browser_metadata['items']);
    $browser_category = NULL;
    if ($browser_item && !empty($browser_item['category']) && isset($browser_metadata['categories'][$browser_item['category']])) {
      $browser_category = $browser_metadata['categories'][$browser_item['category']];
    }

    $plugin_definition = $plugin_id !== '' ? $this->blockManager->getDefinition($plugin_id, FALSE) : NULL;
    $instance_label = trim((string) ($component['block_label'] ?? $component['label'] ?? ''));
    $type_label = trim((string) ($browser_item['label'] ?? ($component['block_type'] ?? ($plugin_definition['admin_label'] ?? $plugin_definition['label'] ?? $plugin_id))));
    $display_label = $instance_label !== '' ? $instance_label : $type_label;
    if ($display_label === '') {
      $display_label = 'Untitled block';
    }

    $category_label = trim((string) ($browser_category['label'] ?? ($plugin_definition['category'] ?? 'Other blocks')));
    if ($category_label === '') {
      $category_label = 'Other blocks';
    }

    return [
      'id' => (string) ($component['uuid'] ?? ''),
      'reference_id' => (string) ($component['uuid'] ?? ''),
      'uuid' => (string) ($component['uuid'] ?? ''),
      'plugin_id' => $plugin_id,
      'block_id' => !empty($component['block_id']) ? (int) $component['block_id'] : NULL,
      'block_revision_id' => !empty($component['block_revision_id']) ? (int) $component['block_revision_id'] : NULL,
      'block_type' => (string) ($component['block_type'] ?? ''),
      'label' => $display_label,
      'sort_label' => $display_label,
      'type_label' => $type_label !== '' ? $type_label : $display_label,
      'region' => (string) ($component['region'] ?? ''),
      'section' => isset($component['section']) ? (int) $component['section'] : 0,
      'can_edit' => !empty($component['block_revision_id']) && !empty($component['block_type']),
      'group_id' => (string) ($browser_category['id'] ?? $this->normalizeGroupId($category_label)),
      'group_label' => $category_label,
      'group_weight' => isset($browser_category['weight']) && $browser_category['weight'] !== NULL ? (int) $browser_category['weight'] : 999,
      'group_opened' => !empty($browser_category['opened']),
      'image_path' => (string) ($browser_item['image_path'] ?? ''),
      'image_alt' => (string) ($browser_item['image_alt'] ?? $type_label),
      'browser_label' => (string) ($browser_item['label'] ?? ''),
      'is_available_block' => FALSE,
      'existing_count' => 1,
      'selection_mode' => 'edit',
    ];
  }

  /**
   * Builds a lookup of current page components by library-style block key.
   */
  protected function buildExistingUsageLookup(array $components) {
    $usage = [];

    foreach ($components as $component) {
      if (!is_array($component)) {
        continue;
      }

      $plugin_id = (string) ($component['plugin_id'] ?? '');
      $block_type = (string) ($component['block_type'] ?? '');
      $key = $block_type !== '' ? 'inline_block:' . $block_type : $plugin_id;
      if ($key === '') {
        continue;
      }

      $usage[$key][] = $component;
    }

    return $usage;
  }

  /**
   * Loads Layout Builder Browser block and category metadata when available.
   */
  protected function loadLayoutBuilderBrowserMetadata() {
    if (!$this->moduleHandler->moduleExists('layout_builder_browser')) {
      return [
        'items' => [],
        'categories' => [],
      ];
    }

    $items = [];
    $categories = [];

    $category_entities = $this->entityTypeManager
      ->getStorage('layout_builder_browser_blockcat')
      ->loadByProperties(['status' => TRUE]);
    /** @var \Drupal\layout_builder_browser\Entity\LayoutBuilderBrowserBlockCategory $category_entity */
    foreach ($category_entities as $category_entity) {
      $categories[$category_entity->id()] = [
        'id' => (string) $category_entity->id(),
        'label' => (string) $category_entity->label(),
        'weight' => $category_entity->getWeight(),
        'opened' => (bool) $category_entity->getOpened(),
      ];
    }

    $item_entities = $this->entityTypeManager
      ->getStorage('layout_builder_browser_block')
      ->loadByProperties(['status' => TRUE]);
    /** @var \Drupal\layout_builder_browser\Entity\LayoutBuilderBrowserBlock $item_entity */
    foreach ($item_entities as $item_entity) {
      $items[(string) $item_entity->block_id] = [
        'id' => (string) $item_entity->id(),
        'block_id' => (string) $item_entity->block_id,
        'category' => (string) $item_entity->category,
        'label' => (string) $item_entity->label(),
        'image_path' => (string) ($item_entity->image_path ?? ''),
        'image_alt' => (string) ($item_entity->image_alt ?? ''),
      ];
    }

    return [
      'items' => $items,
      'categories' => $categories,
    ];
  }

  /**
   * Matches a page component to Layout Builder Browser metadata.
   */
  protected function matchBrowserItem(array $component, array $browser_items) {
    $candidates = array_filter([
      (string) ($component['plugin_id'] ?? ''),
      !empty($component['block_type']) ? 'inline_block:' . $component['block_type'] : '',
    ]);

    foreach ($candidates as $candidate) {
      if (isset($browser_items[$candidate])) {
        return $browser_items[$candidate];
      }
    }

    return NULL;
  }

  /**
   * Normalizes a fallback group ID.
   */
  protected function normalizeGroupId($value) {
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: 'other_blocks';
    return trim($value, '_') ?: 'other_blocks';
  }

}
