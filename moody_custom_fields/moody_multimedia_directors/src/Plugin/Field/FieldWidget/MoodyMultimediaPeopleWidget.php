<?php

namespace Drupal\moody_multimedia_directors\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_multimedia_people_widget' widget.
 *
 * @FieldWidget(
 *   id = "moody_multimedia_people_widget",
 *   module = "moody_multimedia_directors",
 *   label = @Translation("Moody multimedia people widget"),
 *   field_types = {
 *     "moody_multimedia_people"
 *   }
 * )
 */
class MoodyMultimediaPeopleWidget extends WidgetBase {

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
      '#title' => $this->t('Title'),
      '#default_value' => isset($items[$delta]->title) ? $items[$delta]->title : NULL,
      '#size' => $this->getSetting('size'),
      '#maxlength' => $this->getFieldSetting('max_length'),
    ];

    $element['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body'),
      '#default_value' => isset($items[$delta]->body) ? $items[$delta]->body : NULL,
      '#size' => $this->getSetting('size'),
      '#maxlength' => 1024,
    ];

    return $element;
  }

}
