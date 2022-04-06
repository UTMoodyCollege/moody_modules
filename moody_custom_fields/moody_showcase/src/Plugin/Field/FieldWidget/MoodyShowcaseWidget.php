<?php

namespace Drupal\moody_showcase\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_showcase_widget' widget.
 *
 * @FieldWidget(
 *   id = "moody_showcase_widget",
 *   module = "moody_showcase",
 *   label = @Translation("Moody showcase widget"),
 *   field_types = {
 *     "moody_showcase"
 *   }
 * )
 */
class MoodyShowcaseWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element['image'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['utexas_image'],
      '#cardinality' => 1,
      '#title' => $this->t('Image'),
      '#description' => t('Upload an image of 900 x 970 pixels to maintain resolution & avoid cropping. If using the Moody Showcase Marketing Style formatter, opt for an image with dimensions of 1000 x 666 pixels to maintain resolution & avoid cropping.'),
      '#default_value' => isset($items[$delta]->image) ? $items[$delta]->image : NULL,
    ];
    $element['headline'] = [
      '#title' => $this->t('Headline'),
      '#type' => 'textfield',
      '#default_value' => $items[$delta]->headline ?? NULL,
      '#size' => '60',
      '#placeholder' => '',
      '#maxlength' => 255,
    ];
    $element['copy'] = [
      '#title' => 'Copy',
      '#type' => 'text_format',
      '#default_value' => isset($items[$delta]->copy_value) ? $items[$delta]->copy_value : NULL,
      '#format' => isset($items[$delta]->copy_format) ? $items[$delta]->copy_format : 'restricted_html',
    ];
    $element['cta'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Call to Action'),
    ];
    $element['cta']['link'] = [
      '#type' => 'utexas_link_options_element',
      '#default_value' => [
        'uri' => $items[$delta]->link_uri ?? '',
        'title' => $items[$delta]->link_title ?? '',
        'options' => isset($items[$delta]->link_options) ? $items[$delta]->link_options : [],
      ],
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      if (empty($value['image'])) {
        // A null media value should be saved as 0.
        $value['image'] = 0;
      }
      // Massage erroneous value.
      else if (strpos($value['image'], '0,') !== false) {
        $value['image'] = str_replace('0,', '', $value['image']);
      }
      $value['copy_value'] = $value['copy']['value'];
      $value['copy_format'] = $value['copy']['format'];
      if (isset($value['cta']['link']['uri'])) {
        $value['link_uri'] = $value['cta']['link']['uri'] ?? '';
        $value['link_title'] = $value['cta']['link']['title'] ?? '';
        $value['link_options'] = $value['cta']['link']['options'] ?? [];
      }
    }
    return $values;
  }

}
