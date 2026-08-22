<?php

namespace Drupal\moody_ai_assistant\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides targeted page block inspection helpers for AI edit planning.
 */
class AIBlockInspector {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The layout context collector.
   *
   * @var \Drupal\moody_ai_assistant\Service\LayoutContextCollector
   */
  protected $layoutContextCollector;

  /**
   * The block parser.
   *
   * @var \Drupal\moody_ai_assistant\Service\BlockParser
   */
  protected $blockParser;

  /**
   * Constructs the block inspector.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LayoutContextCollector $layout_context_collector, BlockParser $block_parser) {
    $this->entityTypeManager = $entity_type_manager;
    $this->layoutContextCollector = $layout_context_collector;
    $this->blockParser = $block_parser;
  }

  /**
   * Lists editable inline block identifiers for a page.
   */
  public function listEditableBlocks(ContentEntityInterface $entity, array $runtime_context = []) {
    $context = $this->layoutContextCollector->collectEntityContext($entity, $runtime_context);
    $blocks = [];

    foreach ($context['existing_components'] ?? [] as $component) {
      if (empty($component['uuid']) || empty($component['block_type']) || empty($component['block_revision_id'])) {
        continue;
      }

      $blocks[] = [
        'component_uuid' => (string) $component['uuid'],
        'section' => (int) ($component['section'] ?? 0),
        'region' => (string) ($component['region'] ?? ''),
        'plugin_id' => (string) ($component['plugin_id'] ?? ''),
        'block_type' => (string) ($component['block_type'] ?? ''),
        'block_label' => (string) ($component['block_label'] ?? $component['label'] ?? ''),
        'block_id' => (int) ($component['block_id'] ?? 0),
        'block_revision_id' => (int) ($component['block_revision_id'] ?? 0),
      ];
    }

    return $blocks;
  }

  /**
   * Gets exported block contents for selected component UUIDs.
   */
  public function getBlockContents(ContentEntityInterface $entity, array $component_uuids, array $runtime_context = []) {
    $component_uuids = array_values(array_unique(array_filter(array_map('strval', $component_uuids))));
    if (!$component_uuids) {
      return [];
    }

    $uuid_lookup = array_fill_keys($component_uuids, TRUE);
    $block_index = $this->listEditableBlocks($entity, $runtime_context);
    $contents = [];

    foreach ($block_index as $component) {
      if (empty($uuid_lookup[$component['component_uuid']])) {
        continue;
      }

      $block = $this->entityTypeManager->getStorage('block_content')->loadRevision($component['block_revision_id']);
      if (!$block) {
        continue;
      }

      $contents[] = $component + [
        'content' => $this->blockParser->exportBlockToInstruction($block),
      ];
    }

    return $contents;
  }

}
