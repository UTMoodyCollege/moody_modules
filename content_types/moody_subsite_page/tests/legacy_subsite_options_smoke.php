<?php

declare(strict_types=1);

use Drupal\Core\Form\FormState;
use Drupal\node\Entity\Node;

$node = Node::create(['type' => 'moody_subsite_page']);
$form_object = \Drupal::entityTypeManager()->getFormObject('node', 'default');
$form_object->setEntity($node);
$form = \Drupal::formBuilder()->buildForm($form_object, new FormState());

if (($form['legacy_subsite_options']['#open'] ?? TRUE) !== FALSE) {
  throw new RuntimeException('Legacy Subsite Options must be collapsed by default.');
}

foreach (['field_primary_subsite_hero', 'field_hide_default_hero', 'field_hide_default_infobar'] as $field_name) {
  if (($form[$field_name]['#group'] ?? '') !== 'legacy_subsite_options') {
    throw new RuntimeException("$field_name is not grouped under Legacy Subsite Options.");
  }
}

foreach (['field_hide_default_hero', 'field_hide_default_infobar'] as $field_name) {
  if ((int) $node->get($field_name)->value !== 1) {
    throw new RuntimeException("$field_name does not default to enabled.");
  }
}

$standard_page = Node::create(['type' => 'moody_standard_page']);
$standard_form_object = \Drupal::entityTypeManager()->getFormObject('node', 'default');
$standard_form_object->setEntity($standard_page);
$standard_form = \Drupal::formBuilder()->buildForm($standard_form_object, new FormState());

if (isset($standard_form['legacy_subsite_options'])) {
  throw new RuntimeException('Legacy Subsite Options must not appear on other content types.');
}

print "Legacy subsite options are grouped and new-page defaults are enabled.\n";
