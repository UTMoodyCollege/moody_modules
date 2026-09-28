<?php

/**
 * Read-only regression check: run with drush php:script on a bootstrapped site.
 */
$definition = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions('moody_subsite')['subsite_nav'];
if ($definition->getCardinality() !== -1) {
  throw new RuntimeException('Subsite menu cardinality must be integer -1 for Drupal 11.4.');
}
$storage = \Drupal::entityTypeManager()->getStorage('moody_subsite');
$checked = 0;
foreach ($storage->loadMultiple() as $subsite) {
  $count = (int) \Drupal::database()->select('moody_subsite__subsite_nav', 'n')
    ->condition('entity_id', $subsite->id())
    ->condition('deleted', 0)
    ->condition('langcode', $subsite->language()->getId())
    ->countQuery()->execute()->fetchField();
  if (count($subsite->get('subsite_nav')) !== $count) {
    throw new RuntimeException('Stored menu items did not load for subsite ' . $subsite->id());
  }
  $checked += $count;
}
print "Verified $checked stored subsite menu items load through Drupal.\n";
