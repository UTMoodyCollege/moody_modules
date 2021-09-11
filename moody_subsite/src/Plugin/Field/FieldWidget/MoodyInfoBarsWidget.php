<?php

namespace Drupal\moody_subsite\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_info_bars_widget' widget.
 *
 * @FieldWidget(
 *   id = "moody_info_bars_widget",
 *   module = "moody_subsite",
 *   label = @Translation("Moody info bars widget"),
 *   field_types = {
 *     "moody_info_bars"
 *   }
 * )
 */
class MoodyInfoBarsWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {

    $element['link'] = [
      '#type' => 'textfield',
      '#title' => t('URL'),
      '#default_value' => isset($items[$delta]->link) ? $items[$delta]->link : NULL,
      '#maxlength' => 256,
    ];
    $element['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => isset($items[$delta]->title) ? $items[$delta]->title : NULL,
    ];
    return $element;
  }

}
