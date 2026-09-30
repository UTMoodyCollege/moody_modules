<?php

// Read-only: run with drush php:script in a bootstrapped Drupal site.
use Drupal\Core\Form\FormState;

$manager = \Drupal::service('plugin.manager.block');
foreach ([
  NULL => 'col-12 col-sm-6',
  1 => 'col-12',
  2 => 'col-12 col-sm-6',
  3 => 'col-12 col-sm-6 col-md-4',
  4 => 'col-12 col-sm-6 col-md-4 col-lg-3',
  99 => 'col-12 col-sm-6',
  'col-md-4 injected' => 'col-12 col-sm-6',
] as $columns => $expected) {
  $configuration = ['items' => [['type' => 'custom', 'title' => 'Smoke test', 'url' => '/']]];
  if ($columns !== '') {
    $configuration['columns'] = $columns;
  }
  $plugin = $manager->createInstance('moody_feature_page_feature_pages_editors_picks', $configuration);
  $build = $plugin->build();
  if (($build['content']['#row_class'] ?? '') !== $expected) {
    throw new RuntimeException('Mixed grid columns did not match: ' . $columns);
  }
  $method = new ReflectionMethod($plugin, 'prepareView');
  $view = $method->invoke($plugin, []);
  if ($view->display_handler->getOption('style')['options']['row_class'] !== $expected) {
    throw new RuntimeException('Legacy View columns did not match: ' . $columns);
  }
}
$plugin = $manager->createInstance('moody_feature_page_feature_pages_editors_picks', []);
$form = $plugin->blockForm([], new FormState());
if ($form['columns']['#default_value'] !== 2 || array_keys($form['columns']['#options']) !== [1, 2, 3, 4]) {
  throw new RuntimeException('Column selector must offer 1–4 and default to 2.');
}
foreach (['3' => 3, '99' => 2, 'col-md-4 injected' => 2] as $input => $expected) {
  $state = (new FormState())->setValue('columns', $input)->setValue('image_style', '');
  $plugin->blockSubmit([], $state);
  if ($plugin->getConfiguration()['columns'] !== $expected) {
    throw new RuntimeException('Submitted columns were not validated.');
  }
}
echo "PASS: default, all column choices, invalid fallback, legacy/mixed parity, and form persistence.\n";
