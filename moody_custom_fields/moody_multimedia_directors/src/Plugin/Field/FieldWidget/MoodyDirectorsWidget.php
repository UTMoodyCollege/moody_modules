<?php

namespace Drupal\moody_multimedia_directors\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_directors_widget' widget.
 *
 * @FieldWidget(
 *   id = "moody_directors_widget",
 *   module = "moody_multimedia_directors",
 *   label = @Translation("Moody directors widget"),
 *   field_types = {
 *     "moody_directors"
 *   }
 * )
 */
class MoodyDirectorsWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First Name'),
      '#default_value' => isset($items[$delta]->first_name) ? $items[$delta]->first_name : NULL,
      '#size' => $this->getSetting('size'),
      '#maxlength' => $this->getFieldSetting('max_length'),
    ];

    $element['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last Name'),
      '#default_value' => isset($items[$delta]->last_name) ? $items[$delta]->last_name : NULL,
      '#size' => $this->getSetting('size'),
      '#maxlength' => $this->getFieldSetting('max_length'),
    ];

    $element['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Credit'),
      '#default_value' => isset($items[$delta]->title) ? $items[$delta]->title : NULL,
      '#size' => $this->getSetting('size'),
      '#maxlength' => $this->getFieldSetting('max_length'),
    ];
    return $element;
  }

}
