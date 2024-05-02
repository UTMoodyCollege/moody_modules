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
    $output = '<div class="custom-term-links-container">';

    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vocabulary);

    if (!empty($terms)) {
      foreach ($terms as $term_info) {
        $term = Term::load($term_info->tid);
        if ($term) {
          $url = "/grad-profile/" . $term->label();
          $output .= '<a href="' . $url . '">' . $term->label() . '</a><br>';
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
