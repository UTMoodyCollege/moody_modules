<?php

/**
 * Run with drush php:script after enabling moody_image_grid locally.
 */
use Drupal\moody_image_grid\Plugin\Block\ImageGridBlock;

function check_grid($condition, $message) {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}
foreach (['', '/news', 'https://example.com/story?a=b', 'http://example.com'] as $url) {
  check_grid(ImageGridBlock::safeLink($url), 'Valid link rejected');
}
foreach (['javascript:alert(1)', '//example.com', '/\\example.com', "https://example.com\n", 'data:text/html,test'] as $url) {
  check_grid(!ImageGridBlock::safeLink($url), 'Unsafe link accepted');
}
$manager = \Drupal::service('plugin.manager.block');
$legacy = $manager->createInstance('moody_image_grid_image_grid', []);
check_grid($legacy->build()['#layout'] === 'legacy', 'Legacy layout changed');
$state = new \Drupal\Core\Form\FormState();
$state->setValues(['headline' => 'Test', 'layout' => 'mosaic', 'gap' => 'none', 'image_style' => 'original', 'items' => [['image' => NULL, 'size' => 'medium', 'link_url' => '']]]);
$form = $legacy->blockForm([], $state);
check_grid($form['items']['#tree'] === TRUE && isset($form['items'][0]['size']), 'Nested size controls missing');
$legacy->blockSubmit($form, $state);
$roundtrip = $manager->createInstance('moody_image_grid_image_grid', $legacy->getConfiguration());
check_grid($roundtrip->getConfiguration()['items'][0]['size'] === 'medium', 'Photo size did not survive saving');
check_grid($roundtrip->getConfiguration()['image_style'] === 'original', 'Image style did not survive saving');
$grid = $manager->createInstance('moody_image_grid_image_grid', ['layout' => 'mosaic', 'gap' => 'none', 'image_style' => 'original', 'items' => [['image' => 2147483647], ['image' => NULL]]]);
$build = $grid->build();
check_grid($build['#items'] === [], 'Deleted/empty media must not render empty tiles');
$build['#items'] = [['image_url' => '/example.jpg', 'alt' => 'Example photograph', 'headline' => '<script>bad()</script>', 'link_url' => '', 'size' => 'large']];
$html = (string) \Drupal::service('renderer')->renderInIsolation($build);
check_grid(str_contains($html, 'moody-photo-mosaic gap-none'), 'Mosaic template missing');
check_grid(str_contains($html, 'size-large'), 'Photo size missing');
check_grid(str_contains($html, 'alt="Example photograph"'), 'Alternative text missing');
check_grid(!str_contains($html, '<script>'), 'Headline not escaped');
check_grid(!str_contains($html, '<a '), 'Unlinked photos must not create empty links');
echo "Image grid: legacy fallback, missing media, links, sizes and escaping passed.\n";
