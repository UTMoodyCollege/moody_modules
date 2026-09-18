<?php

namespace Drupal\moody_quotation\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\moody_quotation\QuotationStyle;

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
    $presentation = QuotationStyle::decode($items[$delta]->style);
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
    $element['attribution'] = [
      '#title' => $this->t('Attribution details'),
      '#type' => 'textarea',
      '#default_value' => $items[$delta]->attribution ?? NULL,
      '#description' => $this->t('Optional supporting text shown after the author.'),
    ];
    $element['media'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['utexas_image'],
      '#delta' => $delta,
      '#cardinality' => 1,
      '#title' => $this->t('Image'),
      '#default_value' => !empty($items[$delta]->media) ? $items[$delta]->media : NULL,
      '#description' => $this->t('For a split quote, choose a large image (at least 1200 pixels wide). The image fills its half of the block and may be cropped.'),
    ];
    $element['style'] = [
      '#title' => $this->t('Style'),
      '#type' => 'radios',
      '#options' => [
        'default' => $this->t('Dark text with no background'),
        'orange' => $this->t('Orange background with white text'),
        'grey' => $this->t('Gray background with white text'),
        'feature' => $this->t('Feature quote with large orange text'),
        'split' => $this->t('Split quote with full-height image'),
      ],
      '#default_value' => $presentation['style'],
    ];
    $element['presentation'] = [
      '#type' => 'details',
      '#title' => $this->t('Split quote appearance'),
      '#open' => TRUE,
      '#description' => $this->t('These options apply to the split quote style. Color pairs maintain readable contrast. The image stacks above the quote on narrow screens.'),
    ];
    foreach (QuotationStyle::OPTIONS as $key => $options) {
      $element['presentation'][$key] = [
        '#type' => 'select',
        '#title' => ['palette' => $this->t('Text and background color'), 'size' => $this->t('Text size'), 'alignment' => $this->t('Text alignment'), 'position' => $this->t('Image position')][$key],
        '#options' => $options,
        '#default_value' => $presentation[$key],
      ];
    }
    $element['cta_wrapper'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Link'),
    ];
    $element['cta_wrapper']['link'] = [
      '#type' => 'utexas_link_options_element',
      '#default_value' => [
        'uri' => $items[$delta]->link_uri ?? NULL,
        'title' => $items[$delta]->link_text ?? NULL,
        'options' => $items[$delta]->link_options ?? [],
      ],
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    // This loop is through (potential) field instances.
    foreach ($values as &$value) {
      $value['style'] = QuotationStyle::encode(['style' => $value['style'] ?? 'default'] + ($value['presentation'] ?? []));
      unset($value['presentation']);
      if (empty($value['media'])) {
        // A null media value should be saved as 0.
        $value['media'] = 0;
      }
      if (isset($value['cta_wrapper']['link']['uri'])) {
        $value['link_uri'] = $value['cta_wrapper']['link']['uri'];
        $value['link_text'] = $value['cta_wrapper']['link']['title'] ?? '';
        $value['link_options'] = $value['cta_wrapper']['link']['options'] ?? [];
      }
    }
    return $values;
  }

}
