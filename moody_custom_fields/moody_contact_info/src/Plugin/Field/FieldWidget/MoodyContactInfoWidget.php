<?php

namespace Drupal\moody_contact_info\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_contact_info_widget' widget.
 *
 * @FieldWidget(
 *   id = "moody_contact_info_widget",
 *   module = "moody_contact_info",
 *   label = @Translation("Moody contact info widget"),
 *   field_types = {
 *     "moody_contact_info"
 *   }
 * )
 */
class MoodyContactInfoWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element['headline'] = [
      '#title' => $this->t('Headline'),
      '#type' => 'textfield',
      '#default_value' => $items[$delta]->headline ?? NULL,
      '#size' => '60',
      '#placeholder' => '',
      '#maxlength' => 255,
    ];
    $element['subheadline'] = [
      '#title' => $this->t('Subheadline'),
      '#type' => 'textfield',
      '#default_value' => $items[$delta]->subheadline ?? NULL,
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
    $element['link'] = [
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
      $value['copy_value'] = $value['copy']['value'];
      $value['copy_format'] = $value['copy']['format'];
      if (isset($value['link']['uri'])) {
        $value['link_uri'] = $value['link']['uri'] ?? '';
        $value['link_title'] = $value['link']['title'] ?? '';
        $value['link_options'] = $value['link']['options'] ?? [];
      }
    }
    return $values;
  }

}
