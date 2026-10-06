<?php

/**
 * Read-only fleet check: drush php:script tests/catalog-report.php.
 */

$definitions = \Drupal::service('plugin.manager.block')->getDefinitions();
$entries = \Drupal::entityTypeManager()->getStorage('layout_builder_browser_block')->loadMultiple();
$invalid = [];
$enabled = [];
foreach ($entries as $entry) {
  $config = $entry->toArray();
  if (empty($config['status'])) {
    continue;
  }
  $enabled[] = $config['block_id'];
  if (!isset($definitions[$config['block_id']])) {
    $invalid[] = $config['block_id'];
  }
}
$quotation = $entries['moody_quotation'] ?? NULL;
$thumbnail = $quotation ? $quotation->toArray()['image_path'] : '';
$valid_thumbnail = $thumbnail && is_file(DRUPAL_ROOT . $thumbnail);
$missing = [];
$module_path = \Drupal::service('extension.list.module')->getPath('moody_layout_builder_browser');
foreach (array_merge(glob(DRUPAL_ROOT . '/' . $module_path . '/config/install/layout_builder_browser.layout_builder_browser_block.*.yml'), glob(DRUPAL_ROOT . '/' . $module_path . '/config/optional/layout_builder_browser.layout_builder_browser_block.*.yml')) as $file) {
  $default = \Symfony\Component\Yaml\Yaml::parseFile($file);
  $id = $default['block_id'];
  // Views and reusable content are discovered dynamically, not copied between sites.
  if (str_starts_with($id, 'views_block:') || str_starts_with($id, 'block_content:')) {
    continue;
  }
  if (isset($definitions[$id]) && !in_array($id, $enabled, TRUE)) {
    $missing[] = $id;
  }
}
echo json_encode([
  'enabled' => $enabled,
  'invalid' => $invalid,
  'missing_available_defaults' => $missing,
  'views_and_reusable_discovery' => \Drupal::moduleHandler()->moduleExists('moody_layout_builder_browser'),
  'quotation_thumbnail' => (bool) $valid_thumbnail,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if ($missing || !$valid_thumbnail || !in_array('inline_block:moody_quotation', $enabled, TRUE)) {
  throw new RuntimeException('Catalog is missing available shared blocks or Moody Quotation and its thumbnail.');
}
