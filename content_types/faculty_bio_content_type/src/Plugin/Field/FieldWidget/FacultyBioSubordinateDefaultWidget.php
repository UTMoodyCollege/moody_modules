<?php

namespace Drupal\faculty_bio_content_type\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'faculty_bio_subordinate_default' widget.
 *
 * @FieldWidget(
 *   id = "faculty_bio_subordinate_default",
 *   label = @Translation("Faculty Bio Subordinate default"),
 *   field_types = {
 *     "faculty_bio_subordinate"
 *   }
 * )
 */
class FacultyBioSubordinateDefaultWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => isset($items[$delta]->name) ? $items[$delta]->name : '',
      '#size' => 25,
      '#maxlength' => 255,
      '#required' => $this->fieldDefinition->isRequired(),
    ];

    $element['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => isset($items[$delta]->title) ? $items[$delta]->title : '',
      '#size' => 25,
      '#maxlength' => 255,
    ];

    $element['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#default_value' => isset($items[$delta]->email) ? $items[$delta]->email : '',
      '#size' => 25,
      '#maxlength' => 255,
    ];

    return $element;
  }
}
