<?php

namespace Drupal\moody_flex_tabs\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_flex_tabs_widget' widget.
 *
 * @FieldWidget(
 *   id = "moody_flex_tabs_widget",
 *   module = "moody_flex_tabs",
 *   label = @Translation("Moody Flex Tabs Widget"),
 *   field_types = {
 *     "moody_flex_tabs"
 *   }
 * )
 */
class MoodyFlexTabsWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element['set_active'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Set this tab to active'),
      '#default_value' => isset($items[$delta]->set_active) ? $items[$delta]->set_active : NULL,
    ];
    $element['tab_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Panel title'),
      '#default_value' => isset($items[$delta]->tab_title) ? $items[$delta]->tab_title : NULL,
    ];
    $element['copy'] = [
      '#title' => $this->t('Panel Contents'),
      '#type' => 'text_format',
      '#default_value' => isset($items[$delta]->copy_value) ? $items[$delta]->copy_value : NULL,
      '#format' => isset($items[$delta]->copy_format) ? $items[$delta]->copy_format : 'restricted_html',
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
