<?php

namespace Drupal\moody_card\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_card_widget' widget.
 *
 * @FieldWidget(
 *   id = "moody_card_widget",
 *   module = "moody_card",
 *   label = @Translation("Moody Card Widget"),
 *   field_types = {
 *     "moody_card"
 *   }
 * )
 */
class MoodyCardWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $item = $items[$delta];
    $element['title'] = [
      '#title' => $this->t('Title'),
      '#type' => 'textfield',
      '#default_value' => isset($item->title) ? $item->title : NULL,
      '#size' => '60',
      '#maxlength' => 255,
    ];
    $element['subtitle'] = [
      '#title' => $this->t('Subtitle'),
      '#type' => 'textfield',
      '#default_value' => isset($item->subtitle) ? $item->subtitle : NULL,
      '#size' => '60',
      '#maxlength' => 255,
    ];
    $element['media'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['utexas_image'],
      '#cardinality' => 1,
      '#title' => $this->t('Image'),
      '#default_value' => isset($item->media) ? $item->media : NULL,
    ];
    $element['cta'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Call to Action'),
    ];
    $element['cta']['link'] = [
      '#type' => 'utexas_link_options_element',
      '#default_value' => [
        'uri' => $item->link_uri ?? '',
        'title' => $item->link_title ?? '',
        'options' => isset($item->link_options) ? $item->link_options : [],
      ],
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    // This loop is through (potential) field instances.
    foreach ($values as &$value) {
      if (empty($value['media'])) {
        // A null media value should be saved as 0.
        $value['media'] = 0;
      }
      if (isset($value['cta']['link']['uri'])) {
        $value['link_uri'] = $value['cta']['link']['uri'] ?? '';
        $value['link_title'] = $value['cta']['link']['title'] ?? '';
        $value['link_options'] = $value['cta']['link']['options'] ?? [];
      }
    }
    return $values;
  }

}
