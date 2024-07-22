<?php

namespace Drupal\moody_flex_color_blocks\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_flex_color_blocks_widget' widget.
 *
 * @FieldWidget(
 *   id = "moody_flex_color_blocks_widget",
 *   module = "moody_flex_color_blocks",
 *   label = @Translation("Moody flex color blocks widget"),
 *   field_types = {
 *     "moody_flex_color_blocks"
 *   }
 * )
 */
class MoodyFlexColorBlocksWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element['headline'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Headline'),
      '#default_value' => isset($items[$delta]->headline) ? $items[$delta]->headline : NULL,
      '#size' => $this->getSetting('size'),
      '#maxlength' => $this->getFieldSetting('max_length'),
    ];

    $element['subheadline'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subheadline'),
      '#default_value' => isset($items[$delta]->subheadline) ? $items[$delta]->subheadline : NULL,
      '#size' => $this->getSetting('size'),
      '#maxlength' => $this->getFieldSetting('max_length'),
    ];

    $element['link'] = [
      '#type' => 'utexas_link_options_element',
      '#title' => $this->t('Link'),
      '#default_value' => [
        'uri' => $items[$delta]->link ?? NULL,
        'title' => $items[$delta]->link_text ?? NULL,
        'options' => $items[$delta]->link_options ?? [],
      ],
      '#suppress_title_display' => TRUE,
    ];

    $element['color_scheme'] = [
      '#type' => 'select',
      '#title' => $this->t('Color Scheme'),
      '#options' => [
        'blue' => $this->t('Blue'),
        'gray' => $this->t('Gray'),
        'green' => $this->t('Green'),
        'orange' => $this->t('Orange'),
      ],
      '#default_value' => isset($items[$delta]->color_scheme) ? $items[$delta]->color_scheme : 'blue',
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    // This loop is through (potential) field instances.
    foreach ($values as &$value) {
      // We only want the 'uri' part of the link for image link, but for
      // consistency we leave the code here to store all link values.
      $value['link_text'] = $value['link']['title'] ?? '';
      $value['link_options'] = $value['link']['options'] ?? NULL;
      // Since the storage value is 'link', we must assign its value last.
      $value['link'] = $value['link']['uri'] ?? '';
    }

    return $values;
  }

}
