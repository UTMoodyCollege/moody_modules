<?php

namespace Drupal\moody_flex_grid\Element;

use Drupal\Core\Render\Element\FormElement;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines an element for a single link, title, image and subtitle field.
 *
 * @FormElement("moody_flex_grid")
 */
class MoodyFlexGridElement extends FormElement {

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
    $element['image'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['utexas_image'],
      '#cardinality' => 1,
      '#name' => 'image',
      '#title' => t('Image'),
      '#default_value' => isset($element['#default_value']['image']) ? $element['#default_value']['image'] : NULL,
      '#description' => t('Image will be scaled and cropped to a 1:1 ratio. Ideally, upload an image of 500 x 500 pixels to maintain resolution & avoid cropping. If using the Flex Grid Rectangular Style, opt for an image with a 3:2 aspect ratio.'),
      '#upload_location' => 'public://moody_flex_grid_items/',
    ];
    $element['headline'] = [
      '#type' => 'textfield',
      '#title' => t('Item Headline'),
      '#default_value' => isset($element['#default_value']['headline']) ? $element['#default_value']['headline'] : '',
    ];
    $element['copy'] = [
      '#title' => 'Copy',
      '#type' => 'textarea',
      '#default_value' => isset($element['#default_value']['copy']) ? $element['#default_value']['copy'] : NULL,
    ];
    $element['link'] = [
      '#type' => 'utexas_link_options_element',
      '#default_value' => [
        'uri' => $element['#default_value']['link']['uri'] ?? NULL,
        'title' => $element['#default_value']['link']['title'] ?? NULL,
        'options' => $element['#default_value']['link']['options'] ?? [],
      ],
      '#suppress_title_display' => TRUE,
    ];
    $element['link']['#description'] = t('A valid URL for this item. If present, the item headline and image will become links. Start typing the title of a piece of content to select it. You can also enter an internal path such as %internal or an external URL such as %external. Enter %front to link to the front page.', [
      '%internal' => '/node/add',
      '%external' => 'https://example.com',
      '%front' => '<front>',
    ]);
    return $element;
  }

}
