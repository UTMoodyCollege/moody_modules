<?php

// Run with drush php:script after enabling moody_image_gallery.
use Drupal\Core\Form\FormState;

$check = static function ($condition, $message) {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$manager = \Drupal::service('plugin.manager.block');
$ids = \Drupal::entityQuery('media')->accessCheck(TRUE)->condition('bundle', 'utexas_image')->range(0, 1)->execute();
$check($ids !== [], 'An accessible image is required.');
$item = ['media' => reset($ids), 'caption' => '<b>Caption</b>', 'focus_x' => '25%', 'focus_y' => '75%'];
$block = $manager->createInstance('moody_image_gallery_block', ['items' => [$item]]);
$check($block->build()['#layout'] === 'legacy', 'Existing galleries must stay legacy.');
$state = new FormState();
$state->setUserInput([]);
$form = $block->blockForm([], $state);
$check($form['items']['#tree'] && count($form['items'][0]['size']['#options']) === 3, 'Nested size controls missing.');
$items = array_map(static fn ($size) => $item + ['size' => $size], ['large', 'medium', 'small']);
$state->setValues(['headline' => 'Mosaic', 'layout' => 'mosaic', 'gutter' => '0', 'items' => $items]);
$block->blockSubmit($form, $state);
$rebuilt = $manager->createInstance('moody_image_gallery_block', $block->getConfiguration());
$build = $rebuilt->build();
$check($build['#layout'] === 'mosaic' && $build['#gutter'] === '0', 'Layout/spacing did not persist.');
$check(array_column($build['#items'], 'size') === ['large', 'medium', 'small'], 'Sizes did not persist.');
$html = (string) \Drupal::service('renderer')->renderRoot($build);
$check(substr_count($html, 'data-gallery-trigger') === 3, 'Lightbox triggers lost.');
$check(str_contains($html, 'moody-image-gallery--mosaic') && str_contains($html, '25% 75%'), 'Mosaic/focal position missing.');
$check(str_contains($html, '&lt;b&gt;Caption&lt;/b&gt;'), 'Caption must remain escaped.');
$bad = $manager->createInstance('moody_image_gallery_block', ['layout' => 'bad', 'gutter' => 'bad', 'items' => [$item + ['size' => 'bad']]])->build();
$check($bad['#layout'] === 'legacy' && $bad['#gutter'] === '1.25' && $bad['#items'][0]['size'] === 'small', 'Unsafe options need safe fallbacks.');
echo "Gallery mosaic, legacy fallback, form roundtrip, focal point and lightbox markup passed.\n";
