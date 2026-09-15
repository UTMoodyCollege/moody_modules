<?php

/**
 * Local-only integration check. Migrates config and uninstalls the old module.
 * Back up the local database first. Run with drush php:script.
 */

use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\moody_layout_restrictions\Restrictions;

if (getenv('PANTHEON_ENVIRONMENT') || !getenv('IS_DDEV_PROJECT')) {
  throw new RuntimeException('This mutating integration check is DDEV-only.');
}
$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$check(\Drupal::moduleHandler()->moduleExists('layout_builder_restrictions'), 'Start with the old module installed.');
$manager = \Drupal::service('plugin.manager.layout_builder_restriction');
$legacy = $manager->createInstance('entity_view_mode_restriction');
$blocks = \Drupal::service('plugin.manager.block')->getDefinitions();
$layouts = \Drupal::service('plugin.manager.core.layout')->getDefinitions();
$storage_manager = \Drupal::service('plugin.manager.layout_builder.section_storage');
$before = [];
foreach (\Drupal::entityTypeManager()->getStorage('entity_view_display')->loadMultiple() as $id => $display) {
  if (!$display->getThirdPartySettings('layout_builder_restrictions')) {
    continue;
  }
  $storage = $storage_manager->load('defaults', ['display' => EntityContext::fromEntity($display)]);
  if (!$storage) {
    continue;
  }
  $extra = ['section_storage' => $storage, 'delta' => 0, 'region' => 'content'];
  $before[$id] = [
    array_keys($legacy->alterBlockDefinitions($blocks, $extra)),
    array_keys($legacy->alterSectionDefinitions($layouts, $extra)),
    array_map(static fn($section) => $section->toArray(), $display->getSections()),
  ];
}
$check(count($before) > 0, 'No legacy display rules found to compare.');
\Drupal::service('module_installer')->install(['moody_layout_restrictions']);
Restrictions::migrate();
Restrictions::migrate();
$enabled = array_keys(\Drupal::moduleHandler()->getModuleList());
\Drupal::service('module_installer')->uninstall(['layout_builder_restrictions']);
$after_enabled = array_keys(\Drupal::moduleHandler()->getModuleList());
$check(array_values(array_diff($enabled, $after_enabled)) === ['layout_builder_restrictions'], 'Uninstall removed another module.');
foreach ($before as $id => [$expected_blocks, $expected_layouts, $sections]) {
  $display = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load($id);
  $check((bool) $display, 'Display lost during uninstall: ' . $id);
  $check(array_map(static fn($section) => $section->toArray(), $display->getSections()) === $sections, 'Existing layout changed: ' . $id);
  $storage = \Drupal::service('plugin.manager.layout_builder.section_storage')->load('defaults', ['display' => EntityContext::fromEntity($display)]);
  $extra = ['section_storage' => $storage, 'delta' => 0, 'region' => 'content'];
  $actual_blocks = $blocks;
  $actual_layouts = $layouts;
  moody_layout_restrictions_plugin_filter_block__layout_builder_alter($actual_blocks, $extra);
  moody_layout_restrictions_plugin_filter_layout__layout_builder_alter($actual_layouts, $extra);
  $check(array_keys($actual_blocks) === $expected_blocks, 'Block rules changed: ' . $id . '; removed=' . implode(',', array_diff($expected_blocks, array_keys($actual_blocks))) . '; added=' . implode(',', array_diff(array_keys($actual_blocks), $expected_blocks)));
  $check(array_keys($actual_layouts) === $expected_layouts, 'Layout rules changed: ' . $id);
}
echo 'PASS: ' . count($before) . " displays retain block/layout rules and existing layouts after migration and uninstall; migration is idempotent.\n";
