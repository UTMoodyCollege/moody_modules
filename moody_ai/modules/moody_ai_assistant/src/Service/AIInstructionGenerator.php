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

    $payload = $this->planner->createBlockPayload($prompt, $current_plan, $blockData, [
      'revision_prompt' => $revision_prompt,
      'current_instructions' => $current_instructions,
      'uploaded_assets' => $context['uploaded_assets'] ?? [],
      'prefer_ai_images' => !empty($context['prefer_ai_images']),
    ], $stream_callback);
    return $this->messageSuppressesMedia($prompt)
      ? $this->suppressMediaInstructions($payload, $blockData)
      : $payload;
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

    $payload = $this->planner->createBlockPayload($prompt, $plan, $blockData, [
      'current_instructions' => [
        'instructions' => [$existing_instruction],
      ],
      'edit_mode' => TRUE,
      'uploaded_assets' => $context['uploaded_assets'] ?? [],
      'prefer_ai_images' => !empty($context['prefer_ai_images']),
      'block_tools' => $context['block_tools'] ?? [],
    ], $stream_callback);
    return $this->messageSuppressesMedia($prompt)
      ? $this->suppressMediaInstructions($payload, $blockData)
      : $payload;
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

    $payload = $this->planner->createBlockPayload($prompt, $current_plan, $blockData, $context, $stream_callback);
    $instructions = array_values(array_filter($payload['instructions'] ?? [], 'is_array'));
    $payload['instructions'] = $instructions ? [reset($instructions)] : [];
    if ($payload['instructions'] && $selected_type) {
      $payload['instructions'][0]['block_type'] = $selected_type;
    }

    return $this->messageSuppressesMedia($prompt)
      ? $this->suppressMediaInstructions($payload, $blockData)
      : $payload;
  }

  /**
   * Detects an explicit instruction to avoid all imagery and Media.
   */
  protected function messageSuppressesMedia($prompt) {
    return (bool) preg_match('/\b(?:text[- ]only|no (?:images?|imagery|photos?|media)|without (?:any )?(?:images?|imagery|photos?|media))\b/i', (string) $prompt);
  }

  /**
   * Removes model-supplied media data when the editor explicitly forbids it.
   */
  protected function suppressMediaInstructions(array $payload, array $blockData) {
    $payload['plan']['asset_requirements'] = [];
    if (empty($payload['instructions']) || !is_array($payload['instructions'])) {
      return $payload;
    }

    foreach ($payload['instructions'] as &$instruction) {
      $block_type = (string) ($instruction['block_type'] ?? '');
      $field_definitions = $blockData['content_blocks'][$block_type]['fields'] ?? [];
      if (empty($instruction['field_info']) || !is_array($instruction['field_info'])) {
        continue;
      }
      foreach ($instruction['field_info'] as $field_name => &$field_data) {
        $definition = $field_definitions[$field_name] ?? [];
        if (($definition['type'] ?? '') === 'entity_reference' && ($definition['target_type'] ?? '') === 'media') {
          unset($instruction['field_info'][$field_name]);
          continue;
        }
        if (isset($definition['properties']['image']) || isset($definition['properties']['media'])) {
          $field_data = $this->removeMediaKeys($field_data);
        }
      }
      unset($field_data);
    }
    unset($instruction);

    return $payload;
  }

  /**
   * Recursively removes media-specific keys from compound field payloads.
   */
  protected function removeMediaKeys($value) {
    if (!is_array($value)) {
      return $value;
    }

    foreach ($value as $key => $child) {
      if (in_array((string) $key, ['image', 'media', 'image_url', 'image_prompt', 'asset_type'], TRUE)) {
        unset($value[$key]);
        continue;
      }
      $value[$key] = $this->removeMediaKeys($child);
    }
    return $value;
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
