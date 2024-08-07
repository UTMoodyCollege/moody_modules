<?php

namespace Drupal\moody_grad_profile\Plugin\views\area;

use Drupal\Core\Url;
use Drupal\taxonomy\Entity\Term;
use Drupal\views\Plugin\views\area\AreaPluginBase;

/**
 * Defines a header plugin for displaying taxonomy terms as links.
 *
 * @ViewsArea("grad_profile_header")
 * 
 */
class GradProfileHeader extends AreaPluginBase
{

  public function render($empty = FALSE)
  {
    $vocabulary = 'moody_grad_profile_group';
    $output = '<div class="custom-term-links-container row p-3">';
    // Lets add an "All" link
    $url = "/graduate-profiles";
    $output .= '<a class="ut-btn" href="' . $url . '">All</a><br>';

    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vocabulary);

    $first = TRUE; // Initialize a variable to check if the term is the first in the list
    if (!empty($terms)) {
      foreach ($terms as $term_info) {
        $term = Term::load($term_info->tid);
        if ($term) {
          $url = "/graduate-profiles/" . $term->id();
          $linkClass = 'ut-btn ml-2';
          $output .= '<a class="' . $linkClass . '" href="' . $url . '">' . $term->label() . '</a><br>';
          $first = FALSE; // Set first to FALSE after the first term is processed
        }
      }
    } else {
      $output .= 'No terms available.';
    }

    $output .= '</div>';
    return [
      '#markup' => $output,
    ];
  }
}
