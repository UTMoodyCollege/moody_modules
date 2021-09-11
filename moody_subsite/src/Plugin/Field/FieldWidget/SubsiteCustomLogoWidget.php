<?php

namespace Drupal\moody_subsite\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'subsite_custom_logo_widget' widget.
 *
 * @FieldWidget(
 *   id = "subsite_custom_logo_widget",
 *   module = "moody_subsite",
 *   label = @Translation("Subsite custom logo widget"),
 *   field_types = {
 *     "subsite_custom_logo"
 *   }
 * )
 */
class SubsiteCustomLogoWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $item = $items[$delta];
    $element['custom_logo'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Subsite Custom Logo'),
    ];
    $element['custom_logo']['media'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['utexas_image'],
      '#delta' => $delta,
      '#cardinality' => 1,
      '#title' => $this->t('Image'),
      '#default_value' => isset($item->media) ? $item->media : 0,
      '#description' => $this->t('Image will be scaled to a width of 1000px.'),
    ];
    $element['custom_logo']['size'] = [
      '#type' => 'radios',
      '#title' => $this->t('Logo Height'),
      '#options' => [
        'short_logo' => 'Short (max-height of 60px on desktop). Ideal for logo spanning one line.',
        'medium_logo' => 'Medium (max-height of 80px on desktop). Ideal for logo spanning two lines.',
        'tall_logo' => 'Tall (max-height of 100px on desktop). Ideal for logo spanning three lines.',
      ],
      '#default_value' => isset($item->size) ? $item->size : 'medium_logo',
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    // This loop is through (potential) field instances.
    foreach ($values as &$value) {
      // A null media value should be saved as 0.
      $value['media'] = !empty($value['custom_logo']['media']) ? $value['custom_logo']['media'] : 0;
      if (!empty($value['custom_logo']['size'])) {
        $value['size'] = $value['custom_logo']['size'];
      }
    }
    return $values;
  }

}
