<?php

/** Read-only local Drupal regression: no content or configuration saves. */
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\moody_layout_builder_browser\RetiredAddToAny;

if (!str_ends_with(\Drupal::request()->getHost(), '.ddev.site')) {
  throw new RuntimeException('Run on local DDEV only.');
}
function retired_check($condition, $message) {
  if (!$condition) { throw new RuntimeException($message); }
}
$section = new Section('layout_onecol', ['label' => 'Keep this section']);
foreach ([
  'retired' => 'extra_field_block:node:moody_feature_page:addtoany',
  'other_bundle' => 'extra_field_block:node:page:addtoany',
  'body' => 'field_block:node:moody_feature_page:body',
  'other_entity' => 'extra_field_block:block_content:basic:addtoany',
] as $uuid => $id) {
  $section->appendComponent(new SectionComponent($uuid, 'content', ['id' => $id], ['retained_setting' => TRUE]));
}
$before = $section->toArray();
retired_check(RetiredAddToAny::prune([$section]) === 2, 'Wrong removal count');
$expected = $before;
unset($expected['components']['retired'], $expected['components']['other_bundle']);
retired_check($section->toArray() === $expected, 'Unrelated layout settings changed');
retired_check(RetiredAddToAny::prune([$section]) === 0, 'Cleanup not idempotent');
$node = \Drupal::entityTypeManager()->getStorage('node')->create([
  'type' => 'moody_feature_page', 'title' => 'Unsaved test only', 'status' => 0,
  'layout_builder__layout' => [['section' => Section::fromArray($before)]],
]);
\Drupal::moduleHandler()->invoke('moody_layout_builder_browser', 'node_load', [[$node]]);
retired_check(count($node->get('layout_builder__layout')->getSections()[0]->getComponents()) === 2, 'Override cleanup failed');
retired_check(!$node->id() && !$node->isPublished(), 'Node state changed');
$display = \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay::create([
  'targetEntityType' => 'node', 'bundle' => 'moody_feature_page', 'mode' => 'test',
  'third_party_settings' => ['layout_builder' => ['sections' => [Section::fromArray($before)]]],
]);
\Drupal::moduleHandler()->invoke('moody_layout_builder_browser', 'entity_view_display_load', [[$display]]);
retired_check($display->getSections()[0]->toArray() === $expected, 'Display load hook failed');
$node->set('layout_builder__layout', [['section' => Section::fromArray($before)]]);
\Drupal::moduleHandler()->invoke('moody_layout_builder_browser', 'node_presave', [$node]);
retired_check($node->get('layout_builder__layout')->getSections()[0]->toArray() === $expected, 'Node presave hook failed');
echo "Retired-component pruning, override handling, preservation and idempotence passed.\n";
