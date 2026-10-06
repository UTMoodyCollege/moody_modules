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
echo json_encode([
  'enabled' => $enabled,
  'invalid' => $invalid,
  'views_and_reusable_discovery' => \Drupal::moduleHandler()->moduleExists('moody_layout_builder_browser'),
  'quotation_thumbnail' => (bool) $valid_thumbnail,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if ($invalid || !$valid_thumbnail || !in_array('inline_block:moody_quotation', $enabled, TRUE)) {
  throw new RuntimeException('Catalog contains unavailable blocks or is missing Moody Quotation and its thumbnail.');
}
