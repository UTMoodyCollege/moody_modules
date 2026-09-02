<?php

declare(strict_types=1);

use Drupal\Core\Form\FormState;

$subsite_id = (int) getenv('MOODY_SUBSITE_TEST_ID');
if (!$subsite_id) {
  throw new RuntimeException('Set MOODY_SUBSITE_TEST_ID before running this smoke test.');
}

$subsite = \Drupal::entityTypeManager()->getStorage('moody_subsite')->load($subsite_id);
if (!$subsite) {
  throw new RuntimeException('The requested subsite could not be loaded.');
}

$form_object = \Drupal::entityTypeManager()->getFormObject('moody_subsite', 'edit');
$form_object->setEntity($subsite);
$form = \Drupal::formBuilder()->buildForm($form_object, new FormState());
$pages = $form['#attached']['drupalSettings']['moodySubsite']['relatedPages'] ?? [];
$button = $form['navigation']['subsite_nav']['related_pages']['button'] ?? NULL;

if (!$pages || (string) ($button['#value'] ?? '') !== 'Add related pages') {
  throw new RuntimeException('The related-pages form control is incomplete.');
}
foreach ($pages as $page) {
  if (empty($page['title']) || !str_starts_with($page['url'] ?? '', '/')) {
    throw new RuntimeException('A related page is missing its title or relative URL.');
  }
}

print json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
