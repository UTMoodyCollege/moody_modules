<?php

namespace Drupal\moody_newsletter\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_newsletter_widget' widget.
 *
 * @FieldWidget(
 *   id = "moody_newsletter_widget",
 *   module = "moody_newsletter",
 *   label = @Translation("Moody newsletter widget"),
 *   field_types = {
 *     "moody_newsletter"
 *   }
 * )
 */
class MoodyNewsletterWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $item = $items[$delta];
    $element['headline'] = [
      '#title' => $this->t('Headline'),
      '#type' => 'textfield',
      '#default_value' => isset($item->headline) ? $item->headline : NULL,
      '#size' => '60',
      '#maxlength' => 255,
    ];
    $element['style'] = [
      '#title' => $this->t('Background Color'),
      '#type' => 'radios',
      '#options' => [
        'gray' => $this->t('Gray'),
        'orange' => $this->t('Orange'),
      ],
      '#default_value' => in_array($item->style ?? '', ['gray', 'orange'], TRUE) ? $item->style : 'gray',
      '#description' => in_array($item->style ?? '', ['blue', 'green'], TRUE) ? $this->t('This item uses a retired color. Saving will change it to Gray unless you choose Orange.') : '',
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
      if (isset($value['cta']['link']['uri'])) {
        $value['link_uri'] = $value['cta']['link']['uri'] ?? '';
        $value['link_title'] = $value['cta']['link']['title'] ?? '';
        $value['link_options'] = $value['cta']['link']['options'] ?? [];
      }
    }
    return $values;
  }

}
