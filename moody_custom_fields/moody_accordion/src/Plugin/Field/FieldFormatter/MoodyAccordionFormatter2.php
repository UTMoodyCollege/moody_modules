<?php

namespace Drupal\moody_accordion\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'moody_accordion_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_accordion_formatter2",
 *   label = @Translation("Moody Accordion Small"),
 *   field_types = {
 *     "moody_accordion"
 *   }
 * )
 */
class MoodyAccordionFormatter2 extends MoodyAccordionDefaultFormatter {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);
    $elements['#attributes']['class'][] = 'condensed';
    return $elements;
  }

}
