<?php

/**
 * @file
 * Read-only local regression: drush php:script <this-file>.
 */

$entity = \Drupal::entityTypeManager()->getStorage('moody_subsite')->create([]);
foreach ([NULL, '', 0, '0'] as $media) {
  $entity->set('custom_logo', ['media' => $media, 'size' => 'medium_logo']);
  if (!$entity->get('custom_logo')->isEmpty()) {
    throw new RuntimeException('An unset logo bypassed required-field validation.');
  }
}
foreach ([['media' => '123'], ['media' => '0', 'svg_logo' => 123]] as $value) {
  $entity->set('custom_logo', $value);
  if ($entity->get('custom_logo')->isEmpty()) {
    throw new RuntimeException('A selected image/SVG was treated as empty.');
  }
}

$storage = \Drupal::entityTypeManager()->getStorage('node');
$nodes = $storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)
  ->condition('type', 'moody_subsite_page')->range(0, 100)->execute());
$checked = FALSE;
foreach ($nodes as $node) {
  $subsites = \Drupal::entityTypeManager()->getStorage('moody_subsite')->loadByProperties([
    'directory_structure' => $node->get('field_moody_url_generator')->getString(),
  ]);
  if (!$subsites) {
    continue;
  }
  $subsite = reset($subsites);
  $original = $subsite->get('custom_logo')->getValue();
  try {
    foreach ([['media' => '0'], ['media' => '2147483647'], ['media' => '0', 'svg_logo' => 2147483647]] as $value) {
      // In-memory only; never save the fixture entities.
      $subsite->set('custom_logo', $value);
      $variables = ['node' => $node];
      moody_subsite_page_preprocess_page($variables);
      $theme = \Drupal::config('system.theme')->get('default');
      if ($variables['custom_logo'] !== FALSE || !$variables['default_logo_url']
        || $variables['default_logo_url'] !== theme_get_setting('logo.url', $theme)) {
        throw new RuntimeException('Missing/deleted logos did not use the normal site logo.');
      }
    }
    $checked = TRUE;
  }
  finally {
    $subsite->set('custom_logo', $original);
  }
  break;
}
if (!$checked) {
  throw new RuntimeException('An existing subsite page is required for this check.');
}
echo "Required-logo validation and missing/deleted-logo fallback passed. No entities saved.\n";
