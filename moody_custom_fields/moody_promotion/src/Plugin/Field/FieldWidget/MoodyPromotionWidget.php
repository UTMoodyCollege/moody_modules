<?php

namespace Drupal\moody_promotion\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_promotion_widget' widget.
 *
 * @FieldWidget(
 *   id = "moody_promotion_widget",
 *   module = "moody_promotion",
 *   label = @Translation("Moody promotion widget"),
 *   field_types = {
 *     "moody_promotion"
 *   }
 * )
 */
class MoodyPromotionWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $item = $items[$delta];
    $element['media'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['utexas_image'],
      '#delta' => $delta,
      '#description' => '',
      '#cardinality' => 1,
      '#title' => $this->t('Media'),
      '#default_value' => !empty($item->media) ? $item->media : NULL,
    ];
    $element['headline'] = [
      '#title' => 'Headline',
      '#type' => 'textfield',
      '#default_value' => isset($item->headline) ? $item->headline : NULL,
      '#size' => '60',
      '#placeholder' => '',
      '#maxlength' => 255,
    ];
    $element['date'] = [
      '#title' => 'Date',
      '#type' => 'date',
      '#default_value' => isset($item->date) ? $item->date : NULL,
    ];
    $element['copy'] = [
      '#title' => 'Copy',
      '#type' => 'text_format',
      '#default_value' => isset($item->copy_value) ? $item->copy_value : NULL,
      '#format' => $item->copy_format ?? 'restricted_html',
    ];
    $element['cta_wrapper'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Call to Action'),
    ];
    $element['cta_wrapper']['link'] = [
      '#type' => 'utexas_link_options_element',
      '#default_value' => [
        'uri' => isset($item->link_uri) ? $item->link_uri : NULL,
        'title' => isset($item->link_text) ? $item->link_text : NULL,
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
      // A null media value should be saved as 0.
      if (empty($value['media'])) {
        $value['media'] = 0;
      }
      // A null headline value should be removed so that the twig template
      // can easily check for an empty value.
      if (empty($value['headline'])) {
        unset($value['headline']);
      }
      if (empty($value['date'])) {
        unset($value['date']);
      }
      if (isset($value['cta_wrapper']['link']['uri'])) {
        $value['link_uri'] = $value['cta_wrapper']['link']['uri'];
        $value['link_text'] = $value['cta_wrapper']['link']['title'] ?? '';
        $value['link_options'] = $value['cta_wrapper']['link']['options'] ?? [];
      }
      // Split the "text_format" form element data into our field's schema.
      $value['copy_value'] = $value['copy']['value'];
      $value['copy_format'] = $value['copy']['format'];
    }

    return $values;
  }

}
