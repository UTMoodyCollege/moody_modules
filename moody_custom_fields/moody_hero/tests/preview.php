<?php

// Run with drush php:script. Uses an unsaved block; changes no site content.
use Drupal\block_content\Entity\BlockContent;

$ids = \Drupal::entityQuery('media')->accessCheck(FALSE)->condition('bundle', 'utexas_image')->range(0, 1)->execute();
if (!$ids) { throw new RuntimeException('An existing image media item is required.'); }
$block = BlockContent::create(['type' => 'moody_hero', 'info' => 'Unsaved Hero preview QA']);
$block->set('field_block_moody_hero', ['media' => reset($ids), 'heading' => 'Unsaved preview']);
foreach (['moody_hero_1', 'moody_hero_2', 'moody_hero_3', 'moody_hero_5', 'moody_hero_6', 'moody_hero_7', 'moody_hero_8'] as $formatter) {
  $build = $block->get('field_block_moody_hero')->view(['type' => $formatter, 'label' => 'hidden']);
  $html = (string) \Drupal::service('renderer')->renderInIsolation($build);
  if (!str_contains($html, '<style') || !str_contains($html, 'background-image: url(')) {
    throw new RuntimeException("$formatter image CSS is missing from the unsaved/AJAX fragment");
  }
  if (!empty($build['#attached']['html_head'])) { throw new RuntimeException('Hero CSS still depends on page-head attachments'); }
}
$options = array_fill_keys(['default', 'moody_hero_1', 'moody_hero_5', 'moody_hero_5_left', 'moody_hero_5_right'], 'label');
$new = ['#options' => $options, '#default_value' => 'default'];
_moody_hero_hide_retired_style($new);
if (array_intersect_key($new['#options'], array_flip(['moody_hero_5', 'moody_hero_5_left', 'moody_hero_5_right']))) {
  throw new RuntimeException('New Hero widgets offer retired Style 5');
}
$existing = ['#options' => $options, '#default_value' => 'moody_hero_5_left'];
_moody_hero_hide_retired_style($existing);
if (!isset($existing['#options']['moody_hero_5_left']) || isset($existing['#options']['moody_hero_5']) || isset($existing['#options']['moody_hero_5_right'])) {
  throw new RuntimeException('Existing Style 5 must be preserved without offering new variants');
}
echo "PASS: unsaved Hero fragments include image CSS for every background formatter; new Style 5 choices hidden, existing variant preserved.\n";
