<?php

namespace Drupal\moody_quotation\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_quotation_widget' widget.
 *
 * @FieldWidget(
 *   id = "moody_quotation_widget",
 *   module = "moody_quotation",
 *   label = @Translation("Moody quotation widget"),
 *   field_types = {
 *     "moody_quotation"
 *   }
 * )
 */
class MoodyQuotationWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element['quote'] = [
      '#title' => $this->t('Quote'),
      '#type' => 'textarea',
      '#default_value' => $items[$delta]->quote ?? NULL,
    ];
    $element['author'] = [
      '#title' => $this->t('Author'),
      '#type' => 'textfield',
      '#default_value' => $items[$delta]->author ?? NULL,
    ];
    $element['media'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['utexas_image'],
      '#delta' => $delta,
      '#cardinality' => 1,
      '#title' => $this->t('Image'),
      '#default_value' => !empty($items[$delta]->media) ? $items[$delta]->media : NULL,
      '#description' => $this->t('Upload an image of 500 x 500 pixels to maintain resolution & avoid cropping.'),
    ];
    $element['style'] = [
      '#title' => $this->t('Style'),
      '#type' => 'radios',
      '#options' => [
        'default' => $this->t('Dark text with no background'),
        'orange' => $this->t('Orange background with white text'),
        'grey' => $this->t('Gray background with white text'),
      ],
      '#default_value' => $items[$delta]->style ?? 'default',
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    // This loop is through (potential) field instances.
    foreach ($values as &$value) {
      if (empty($value['media'])) {
        // A null media value should be saved as 0.
        $value['media'] = 0;
      }
    }
    return $values;
  }

}
