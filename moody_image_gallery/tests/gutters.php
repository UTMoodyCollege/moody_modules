<?php

// Run with drush php:script; reads an existing image, never saves content.
use Drupal\Core\Form\FormState;

$ids = \Drupal::entityQuery('media')->accessCheck(TRUE)->condition('bundle', 'utexas_image')->exists('field_utexas_media_image.target_id')->range(0, 1)->execute();
if (!$ids) { throw new RuntimeException('An existing image is required.'); }
$manager = \Drupal::service('plugin.manager.block');
$items = [['media' => reset($ids)]];
foreach (['legacy', 'mosaic'] as $layout) {
  $block = $manager->createInstance('moody_image_gallery_block', ['layout' => $layout, 'items' => $items]);
  $legacy = $block->build();
  if ($legacy['#gutter_color'] !== 'inherit' || $legacy['#gutter'] !== '1.25') { throw new RuntimeException('Legacy defaults changed.'); }
  $state = new FormState();
  $state->setUserInput([]);
  $form = $block->blockForm([], $state);
  if (!isset($form['gutter']['#options']['0.25'], $form['gutter_color']['#options']['white'])) { throw new RuntimeException('Missing controls.'); }
  $state->setValues(['headline' => '', 'layout' => $layout, 'gutter' => '0.25', 'gutter_color' => 'white', 'items' => $items]);
  $block->blockSubmit($form, $state);
  $reloaded = $manager->createInstance('moody_image_gallery_block', $block->getConfiguration());
  $build = $reloaded->build();
  $html = (string) \Drupal::service('renderer')->renderRoot($build);
  if (!str_contains($html, 'moody-image-gallery__grid--white-gutters') || !str_contains($html, '--moody-image-gallery-gap: 0.25rem')) { throw new RuntimeException('Saved gutter settings did not render.'); }
  $bad = $manager->createInstance('moody_image_gallery_block', ['items' => $items, 'gutter_color' => 'red;bad', 'gutter' => 'bad'])->build();
  if ($bad['#gutter_color'] !== 'inherit' || $bad['#gutter'] !== '1.25') { throw new RuntimeException('Unsafe gutter settings accepted.'); }
}
echo "PASS: legacy defaults, form/save/reload, both layouts, rendering and allowlists.\n";
