<?php

declare(strict_types=1);

use Drupal\moody_ai_assistant\Service\AssistantPlanner;

$planner = \Drupal::service('moody_ai_assistant.planner');
$block_data = \Drupal::service('moody_ai_assistant.block_data_collector')->getStoredData();
if (empty($block_data['content_blocks'])) {
  $block_data = \Drupal::service('moody_ai_assistant.block_data_collector')->collectBlockData();
}

$method = new ReflectionMethod($planner, 'getStructuredPlanBlockTypes');
$catalog_method = new ReflectionMethod($planner, 'buildIdentifierCatalog');
$identifier_catalog = $catalog_method->invoke($planner, $block_data);
if (strlen(json_encode($identifier_catalog, JSON_PRETTY_PRINT)) >= 100000 || empty($identifier_catalog['basic']['fields']['body'])) {
  throw new RuntimeException('The block identifier catalog is too large or omitted the Basic body field.');
}
$default_types = $method->invoke($planner, 'Build a faculty and upcoming events section.', [], $block_data);
foreach (['feed_block', 'utprof_profile_listing'] as $dynamic_type) {
  if (in_array($dynamic_type, $default_types, TRUE)) {
    throw new RuntimeException($dynamic_type . ' must not be available to an autonomous structured plan.');
  }
}

$resolve_type_method = new ReflectionMethod($planner, 'resolveAllowedBlockType');
if (
  $resolve_type_method->invoke($planner, 'moody_flex_list', ['basic', 'utexas_flex_list']) !== 'utexas_flex_list'
  || $resolve_type_method->invoke($planner, 'invented_component', ['basic', 'moody_quotation']) !== 'basic'
) {
  throw new RuntimeException('Invalid structured block types were not repaired or safely downgraded.');
}

$explicit_types = $method->invoke(
  $planner,
  'Use the selected profile listing.',
  ['selected_block_references' => [[
    'block_type' => 'utprof_profile_listing',
    'selection_mode' => 'new',
  ]]],
  $block_data,
);
if (!in_array('utprof_profile_listing', $explicit_types, TRUE)) {
  throw new RuntimeException('An explicitly selected profile listing should remain available.');
}

$text_only_types = $method->invoke($planner, 'Build a text-only page with no images or media.', [], $block_data);
$required_media_types = [];
foreach ($block_data['content_blocks'] as $type => $definition) {
  foreach ($definition['fields'] ?? [] as $field) {
    if (
      !empty($field['required'])
      && (($field['target_type'] ?? '') === 'media' || isset($field['properties']['image']) || isset($field['properties']['media']))
    ) {
      $required_media_types[] = $type;
      break;
    }
  }
}
foreach ($required_media_types as $type) {
  if (in_array($type, $text_only_types, TRUE)) {
    throw new RuntimeException($type . ' requires media but remained available to a text-only plan.');
  }
}

if (AssistantPlanner::MAX_STRUCTURED_BLOCKS < 10 || AssistantPlanner::MAX_STRUCTURED_BLOCKS > 12) {
  throw new RuntimeException('The structured block limit no longer supports the evaluated 10–12 block range.');
}

$generator = \Drupal::service('moody_ai_assistant.instruction_generator');
$suppress_method = new ReflectionMethod($generator, 'suppressMediaInstructions');
$scrubbed = $suppress_method->invoke($generator, [
  'plan' => ['asset_requirements' => [['field_name' => 'field_test']]],
  'instructions' => [[
    'block_type' => 'test_block',
    'field_info' => [
      'field_media' => ['target_id' => 99],
      'field_compound' => [
        'value' => [
          'headline' => 'Keep this',
          'items' => [['headline' => 'Keep this too', 'image' => ['target_id' => 99]]],
        ],
      ],
    ],
  ]],
], [
  'content_blocks' => [
    'test_block' => [
      'fields' => [
        'field_media' => ['type' => 'entity_reference', 'target_type' => 'media'],
        'field_compound' => ['type' => 'custom', 'properties' => ['image' => []]],
      ],
    ],
  ],
]);
if (
  !empty($scrubbed['plan']['asset_requirements'])
  || isset($scrubbed['instructions'][0]['field_info']['field_media'])
  || isset($scrubbed['instructions'][0]['field_info']['field_compound']['value']['items'][0]['image'])
  || ($scrubbed['instructions'][0]['field_info']['field_compound']['value']['headline'] ?? '') !== 'Keep this'
) {
  throw new RuntimeException('Text-only media suppression did not preserve copy while removing media data.');
}

$chat_manager = \Drupal::service('moody_ai_assistant.chat_manager');
$creation_method = new ReflectionMethod($chat_manager, 'isExplicitBlockCreationRequest');
if (
  !$creation_method->invoke($chat_manager, 'Add two new blocks to this page.', [])
  || $creation_method->invoke($chat_manager, 'Add another item to that same block.', [])
  || $creation_method->invoke($chat_manager, 'Update the existing overview block.', [])
  || $creation_method->invoke($chat_manager, 'Create a block.', ['selected_existing_block_references' => [['uuid' => 'test']]])
) {
  throw new RuntimeException('Creation-request routing could bypass required edit-target analysis.');
}

print json_encode([
  'structured_block_limit' => AssistantPlanner::MAX_STRUCTURED_BLOCKS,
  'identifier_catalog_characters' => strlen(json_encode($identifier_catalog, JSON_PRETTY_PRINT)),
  'invalid_block_type_fallback' => 'basic',
  'default_dynamic_types_excluded' => ['feed_block', 'utprof_profile_listing'],
  'explicit_profile_listing_available' => TRUE,
  'text_only_required_media_types_excluded' => array_values($required_media_types),
  'text_only_media_payload_scrubbed' => TRUE,
  'creation_requests_skip_edit_analysis' => TRUE,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
