<?php

declare(strict_types=1);

use Drupal\Core\Session\UserSession;
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
foreach (['basic', 'moody_hero', 'moody_flex_grid', 'moody_showcase', 'moody_impact_facts'] as $guided_type) {
  if (empty($identifier_catalog[$guided_type]['description']) || empty($identifier_catalog[$guided_type]['best_for'])) {
    throw new RuntimeException($guided_type . ' is missing block-selection context.');
  }
}
$purpose_catalog_method = new ReflectionMethod($planner, 'buildBlockPurposeCatalog');
$purpose_catalog = $purpose_catalog_method->invoke($planner, ['basic', 'moody_hero'], $block_data);
if (
  array_keys($purpose_catalog) !== ['basic', 'moody_hero']
  || empty($purpose_catalog['basic']['best_for'])
  || empty($purpose_catalog['moody_hero']['best_for'])
) {
  throw new RuntimeException('The compact multi-block purpose catalog is incomplete.');
}
$default_types = $method->invoke($planner, 'Build a faculty and upcoming events section.', [], $block_data);
foreach (['feed_block', 'utprof_profile_listing'] as $dynamic_type) {
  if (in_array($dynamic_type, $default_types, TRUE)) {
    throw new RuntimeException($dynamic_type . ' must not be available to an autonomous structured plan.');
  }
}

$browser_limited_types = $method->invoke($planner, 'Build a page introduction.', [
  'available_block_references' => [
    ['plugin_id' => 'inline_block:basic', 'block_type' => 'basic', 'is_available_block' => TRUE],
    ['plugin_id' => 'inline_block:moody_hero', 'block_type' => 'moody_hero', 'is_available_block' => TRUE],
    ['plugin_id' => 'moody_charts_block', 'block_type' => '', 'is_available_block' => TRUE],
  ],
], $block_data);
if (array_diff($browser_limited_types, ['basic', 'moody_hero']) || !in_array('basic', $browser_limited_types, TRUE)) {
  throw new RuntimeException('Structured planning was not limited to browser-enabled inline block bundles.');
}

$context_method = new ReflectionMethod($planner, 'getConfiguredContextPrompt');
$component_context = $context_method->invoke($planner, TRUE);
if (!str_contains($component_context, '26 enabled components') || !str_contains($component_context, 'Prefer the simplest component')) {
  throw new RuntimeException('The audited Moody component guide is absent from builder planning context.');
}

$limit_method = new ReflectionMethod(\Drupal::service('moody_ai_assistant.instruction_generator'), 'limitContentBlocks');
$limited_data = $limit_method->invoke(\Drupal::service('moody_ai_assistant.instruction_generator'), [
  'content_blocks' => [
    'basic' => ['label' => 'Basic'],
    'unavailable' => ['label' => 'Unavailable'],
  ],
], ['basic']);
if (array_keys($limited_data['content_blocks']) !== ['basic']) {
  throw new RuntimeException('Single-block generation retained an unavailable block bundle.');
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
$page_bundle_method = new ReflectionMethod($chat_manager, 'getRecommendedPageBundleCandidates');
$base_page_bundles = $page_bundle_method->invoke($chat_manager, new UserSession([
  'uid' => 10,
  'roles' => ['authenticated'],
]));
$feature_page_bundles = $page_bundle_method->invoke($chat_manager, new UserSession([
  'uid' => 11,
  'roles' => ['authenticated', 'moody_feature_page_editor'],
]));
$faculty_page_bundles = $page_bundle_method->invoke($chat_manager, new UserSession([
  'uid' => 12,
  'roles' => ['authenticated', 'faculty_bio_editor'],
]));
$admin_page_bundles = $page_bundle_method->invoke($chat_manager, new UserSession([
  'uid' => 1,
  'roles' => ['authenticated'],
]));
if (
  $base_page_bundles !== ['moody_standard_page', 'moody_landing_page', 'moody_subsite_page']
  || !in_array('moody_feature_page', $feature_page_bundles, TRUE)
  || in_array('moody_faculty_bio', $feature_page_bundles, TRUE)
  || !in_array('moody_faculty_bio', $faculty_page_bundles, TRUE)
  || !in_array('moody_faculty_bio', $admin_page_bundles, TRUE)
) {
  throw new RuntimeException('The role-aware page recommendation allowlist is incorrect.');
}

$creation_method = new ReflectionMethod($chat_manager, 'isExplicitBlockCreationRequest');
if (
  !$creation_method->invoke($chat_manager, 'Add two new blocks to this page.', [])
  || $creation_method->invoke($chat_manager, 'Add another item to that same block.', [])
  || $creation_method->invoke($chat_manager, 'Update the existing overview block.', [])
  || $creation_method->invoke($chat_manager, 'Create a block.', ['selected_existing_block_references' => [['uuid' => 'test']]])
) {
  throw new RuntimeException('Creation-request routing could bypass required edit-target analysis.');
}

$plugin_reference_method = new ReflectionMethod($chat_manager, 'getSelectedNewPluginReferences');
$selected_plugins = $plugin_reference_method->invoke($chat_manager, [
  'selected_block_references' => [
    [
      'plugin_id' => 'moody_charts_block',
      'block_type' => '',
      'selection_mode' => 'new',
      'label' => 'Chart',
    ],
    [
      'plugin_id' => 'inline_block:moody_hero',
      'block_type' => 'moody_hero',
      'selection_mode' => 'new',
      'label' => 'Moody Hero',
    ],
  ],
]);
if (array_column($selected_plugins, 'plugin_id') !== ['moody_charts_block']) {
  throw new RuntimeException('An explicitly selected plugin block could be silently substituted by the inline creator.');
}

print json_encode([
  'structured_block_limit' => AssistantPlanner::MAX_STRUCTURED_BLOCKS,
  'identifier_catalog_characters' => strlen(json_encode($identifier_catalog, JSON_PRETTY_PRINT)),
  'guided_block_types' => ['basic', 'moody_hero', 'moody_flex_grid', 'moody_showcase', 'moody_impact_facts'],
  'invalid_block_type_fallback' => 'basic',
  'default_dynamic_types_excluded' => ['feed_block', 'utprof_profile_listing'],
  'browser_enabled_inline_types_enforced' => TRUE,
  'moody_component_guide_loaded' => TRUE,
  'explicit_profile_listing_available' => TRUE,
  'text_only_required_media_types_excluded' => array_values($required_media_types),
  'text_only_media_payload_scrubbed' => TRUE,
  'page_recommendation_bundles' => [
    'base' => $base_page_bundles,
    'feature_editor' => $feature_page_bundles,
    'faculty_editor' => $faculty_page_bundles,
    'uid_1' => $admin_page_bundles,
  ],
  'creation_requests_skip_edit_analysis' => TRUE,
  'selected_plugin_blocks_require_manual_configuration' => TRUE,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
