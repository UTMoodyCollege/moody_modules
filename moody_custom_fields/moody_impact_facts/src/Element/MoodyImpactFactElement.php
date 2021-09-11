<?php

namespace Drupal\moody_impact_facts\Element;

use Drupal\Core\Render\Element\FormElement;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines an element for a single title, subtitle and link field.
 *
 * @FormElement("moody_impact_facts")
 */
class MoodyImpactFactElement extends FormElement {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = get_class($this);
    return [
      '#input' => TRUE,
      '#tree' => TRUE,
      '#process' => [
        [$class, 'processLinkElement'],
      ],
    ];
  }

  /**
   * Process handler for the link form element.
   */
  public static function processLinkElement(&$element, FormStateInterface $form_state, &$form) {
    $element['headline'] = [
      '#title' => t('Headline'),
      '#type' => 'textfield',
      '#default_value' => isset($element['#default_value']['headline']) ? $element['#default_value']['headline'] : '',
    ];
    $element['subheadline'] = [
      '#title' => 'Subheadline',
      '#type' => 'textfield',
      '#default_value' => isset($element['#default_value']['subheadline']) ? $element['#default_value']['subheadline'] : '',
    ];
    return $element;
  }

}
