<?php

namespace Drupal\moody_ai_assistant\Service;

use Drupal\Core\State\StateInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;

class AIInstructionGenerator {
  protected $state;
  protected $layoutManager;
  protected $blockDataCollector;
  protected $planner;

  public function __construct(
    StateInterface $state,
    LayoutPluginManagerInterface $layout_manager,
    BlockDataCollectorService $block_data_collector,
    AssistantPlanner $planner
  ) {
    $this->state = $state;
    $this->layoutManager = $layout_manager;
    $this->blockDataCollector = $block_data_collector;
    $this->planner = $planner;
  }

  public function generate($prompt, $context = [], ?callable $stream_callback = NULL) {
    $blockData = $this->blockDataCollector->getStoredData();
    if (empty($blockData['content_blocks'])) {
      $blockData = $this->blockDataCollector->collectBlockData();
    }

    $current_plan = $context['current_plan'] ?? [];
    $revision_prompt = trim((string) ($context['revision_prompt'] ?? ''));
    $current_instructions = $context['current_instructions'] ?? [];

    if (empty($current_plan)) {
      $current_plan = $this->planner->identifyBlockPlan($prompt, $blockData, $stream_callback);
    }

    $selected_type = $current_plan['selected_block_type'] ?? NULL;
    if ($selected_type && !$this->hasCompoundPropertyMetadata($blockData['content_blocks'][$selected_type] ?? [])) {
      $blockData = $this->blockDataCollector->collectBlockData();
    }

    if ($selected_type && !empty($blockData['content_blocks'][$selected_type])) {
      $blockData['selected_block'] = [
        'machine_name' => $selected_type,
        'definition' => $blockData['content_blocks'][$selected_type],
      ];
    }

    return $this->planner->createBlockPayload($prompt, $current_plan, $blockData, [
      'revision_prompt' => $revision_prompt,
      'current_instructions' => $current_instructions,
      'uploaded_assets' => $context['uploaded_assets'] ?? [],
      'prefer_ai_images' => !empty($context['prefer_ai_images']),
    ], $stream_callback);
  }

  /**
   * Generates an updated payload for an existing block type.
   *
   * @param string $prompt
   *   The user's revision request.
   * @param string $block_type
   *   The target block bundle.
   * @param array $existing_instruction
   *   Serialized current block data.
   *
   * @return array
   *   The generated payload.
   */
  public function generateForExistingBlock($prompt, $block_type, array $existing_instruction, array $context = [], ?callable $stream_callback = NULL) {
    $blockData = $this->blockDataCollector->getStoredData();
    if (empty($blockData['content_blocks'])) {
      $blockData = $this->blockDataCollector->collectBlockData();
    }

    $plan = [
      'selected_block_type' => $block_type,
      'confidence' => 'high',
      'reasoning' => 'Editing the existing block already placed on the page.',
      'asset_requirements' => [],
      'notes' => ['This payload updates an existing block instead of creating a new one.'],
    ];

    return $this->planner->createBlockPayload($prompt, $plan, $blockData, [
      'revision_prompt' => $prompt,
      'current_instructions' => [
        'instructions' => [$existing_instruction],
      ],
      'uploaded_assets' => $context['uploaded_assets'] ?? [],
      'prefer_ai_images' => !empty($context['prefer_ai_images']),
      'block_tools' => $context['block_tools'] ?? [],
    ], $stream_callback);
  }

  /**
   * Plans a structured multi-block build or page blueprint.
   */
  public function planStructuredBuild($prompt, array $page_context, array $recent_messages = [], array $page_options = [], ?callable $stream_callback = NULL) {
    $blockData = $this->blockDataCollector->getStoredData();
    if (empty($blockData['content_blocks'])) {
      $blockData = $this->blockDataCollector->collectBlockData();
    }

    return $this->planner->planStructuredBuild($prompt, $page_context, $recent_messages, $page_options, $blockData, $stream_callback);
  }

  /**
   * Generates one component from a structured multi-block plan.
   */
  public function generateFromStructuredPlanItem($prompt, array $plan_item, array $context = [], ?callable $stream_callback = NULL) {
    $blockData = $this->blockDataCollector->getStoredData();
    if (empty($blockData['content_blocks'])) {
      $blockData = $this->blockDataCollector->collectBlockData();
    }

    $current_plan = [
      'selected_block_type' => (string) ($plan_item['selected_block_type'] ?? ''),
      'confidence' => 'high',
      'reasoning' => (string) ($plan_item['reasoning'] ?? 'Structured page plan item.'),
      'asset_requirements' => [],
      'notes' => [
        'This payload belongs to a structured page plan.',
        'Component label: ' . (string) ($plan_item['label'] ?? 'Component'),
        'Component goal: ' . (string) ($plan_item['goal'] ?? ''),
      ],
    ];

    if (empty($current_plan['selected_block_type'])) {
      $current_plan = $this->planner->identifyBlockPlan($prompt, $blockData, $stream_callback);
    }

    $selected_type = $current_plan['selected_block_type'] ?? NULL;
    if ($selected_type && !empty($blockData['content_blocks'][$selected_type])) {
      $blockData['selected_block'] = [
        'machine_name' => $selected_type,
        'definition' => $blockData['content_blocks'][$selected_type],
      ];
    }

    return $this->planner->createBlockPayload($prompt, $current_plan, $blockData, $context, $stream_callback);
  }

  /**
   * Determines whether a block definition already includes compound property metadata.
   *
   * @param array $block_definition
   *   The selected block definition.
   *
   * @return bool
   *   TRUE when any field has nested property metadata.
   */
  protected function hasCompoundPropertyMetadata(array $block_definition) {
    foreach ($block_definition['fields'] ?? [] as $field_definition) {
      if (!empty($field_definition['properties'])) {
        return TRUE;
      }
    }

    return FALSE;
  }
}
