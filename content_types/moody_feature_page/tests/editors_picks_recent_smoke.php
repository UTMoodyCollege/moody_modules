<?php

// Read-only: run with drush php:script in a bootstrapped Drupal site.
use Drupal\Core\Form\FormState;

$plugin = \Drupal::service('plugin.manager.block')->createInstance(
  'moody_feature_page_feature_pages_editors_picks',
  ['items' => [['type' => 'node', 'node' => '']]],
);
$form = $plugin->blockForm([], new FormState());
$selector = $form['items']['rows'][0]['details']['node'];
if (!array_key_exists('#maxlength', $selector) || $selector['#maxlength'] !== NULL) {
  throw new RuntimeException('Feature Page labels must override the inherited 128-character limit.');
}
$label = str_repeat('Long story title ', 20) . '(2237)';
if (\Drupal\Core\Entity\Element\EntityAutocomplete::extractEntityIdFromAutocompleteInput($label) !== '2237') {
  throw new RuntimeException('Long labels must still resolve to the original entity ID.');
}
$options = $form['items']['rows'][0]['details']['recent']['#options'];
if (count($options) > 20 || !isset($form['items']['rows'][0]['details']['node'])) {
  throw new RuntimeException('Recent choices exceeded 20 or removed autocomplete.');
}
$previous = [PHP_INT_MAX, PHP_INT_MAX];
foreach (\Drupal::entityTypeManager()->getStorage('node')->loadMultiple(array_keys($options)) as $node) {
  $order = [$node->getCreatedTime(), (int) $node->id()];
  if (!$node->isPublished() || $node->bundle() !== 'moody_feature_page' || !$node->access('view') || $order > $previous) {
    throw new RuntimeException('Recent choice is unpublished, inaccessible, wrong type, or out of order.');
  }
  $previous = $order;
}
echo 'PASS: ' . count($options) . " recent published Feature Pages, newest first, with autocomplete retained.\n";
