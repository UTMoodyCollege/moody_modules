<?php

namespace Drupal\moody_accordion\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_accordion_widget' widget.
 *
 * @FieldWidget(
 *   id = "moody_accordion_widget",
 *   module = "moody_accordion",
 *   label = @Translation("Moody accordion widget"),
 *   field_types = {
 *     "moody_accordion"
 *   }
 * )
 */
class MoodyAccordionWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Panel title'),
      '#default_value' => isset($items[$delta]->title) ? $items[$delta]->title : NULL,
    ];
    $element['copy'] = [
      '#title' => $this->t('Panel Contents'),
      '#type' => 'text_format',
      '#wysiwyg' => TRUE,
      '#default_value' => isset($items[$delta]->copy_value) ? $items[$delta]->copy_value : NULL,
      '#format' => isset($items[$delta]->copy_format) ? $items[$delta]->copy_format : 'flex_html',
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
    }
    return $values;
  }

}
