<?php

/**
 * @file
 * Contains moody_subsite.page.inc.
 *
 * Page callback for Moody subsite entities.
 */

use Drupal\Core\Render\Element;

/**
 * Prepares variables for Moody subsite templates.
 *
 * Default template: moody_subsite.html.twig.
 *
 * @param array $variables
 *   An associative array containing:
 *   - elements: An associative array containing the user information and any
 *   - attributes: HTML attributes for the containing element.
 */
function template_preprocess_moody_subsite(array &$variables) {
  // Fetch MoodySubsite Entity Object.
  $moody_subsite = $variables['elements']['#moody_subsite'];

  // Helpful $content variable for templates.
  foreach (Element::children($variables['elements']) as $key) {
    $variables['content'][$key] = $variables['elements'][$key];
  }
}
