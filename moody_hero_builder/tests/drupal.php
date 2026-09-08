<?php

// Run on a local Drupal site with the module enabled: drush php:script this-file.
// This test only builds plugin/form/render objects; it saves no content/config.
use Drupal\Core\Form\FormState;
use Drupal\moody_hero_builder\HeroConfiguration;

$manager = \Drupal::service('plugin.manager.block');
$hero = HeroConfiguration::defaults();
$hero['elements'][0]['text'] = '<script>alert("escaped")</script>';
$block = $manager->createInstance('moody_hero_builder', ['hero' => $hero]);
for ($i = 0; $i < 3; $i++) {
  $block = $manager->createInstance('moody_hero_builder', $block->getConfiguration());
  if ($block->getConfiguration()['hero'] !== $hero) {
    throw new RuntimeException('Reopening changed or duplicated the composition.');
  }
}
$state = new FormState();
$state->setValues(['hero_builder' => ['source' => json_encode($hero), 'image' => 0]]);
$form = $block->blockForm([], $state);
$block->blockValidate($form, $state);
if ($state->hasAnyErrors()) {
  throw new RuntimeException('Valid form rejected.');
}
$block->blockSubmit($form, $state);
if ($block->getConfiguration()['hero'] != HeroConfiguration::validate($hero)) {
  throw new RuntimeException('Submission lost composition.');
}
$render = $block->build();
$html = (string) \Drupal::service('renderer')->renderInIsolation($render);
if (str_contains($html, '<script>') || !str_contains($html, '&lt;script&gt;') || substr_count($html, '<h2>') !== 1) {
  throw new RuntimeException('Unsafe or invalid heading markup.');
}
$invalid = $manager->createInstance('moody_hero_builder', ['hero' => 'invalid']);
if (isset($invalid->build()['#theme'])) {
  throw new RuntimeException('Invalid imported configuration was rendered.');
}
if (!\Drupal::service('config.typed')->hasConfigSchema('block.settings.moody_hero_builder')) {
  throw new RuntimeException('Missing block schema.');
}
print "Hero Drupal plugin/form/render/schema checks passed.\n";
