<?php declare(strict_types = 1);

namespace Drupal\moody_styling_tweaks\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'H1 Text Styling' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_styling_tweaks_h1_text_styling",
 *   label = @Translation("H1 Text Styling"),
 *   field_types = {"string"},
 * )
 */
final class H1TextStylingFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    $setting = [
      'extra_classes' => '',
    ];
    return $setting + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $elements['extra_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Extra classes'),
      '#description' => $this->t('Add extra classes to the H1 tag. Provide a comma separated list for multiple.'),
      '#default_value' => $this->getSetting('extra_classes'),
    ];
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    return [
      $this->t('Extra classes: @extra_classes', ['@extra_classes' => $this->getSetting('extra_classes')])
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $element = [];
    foreach ($items as $delta => $item) {
      $element[$delta] = [
        '#markup' => '<h1 class="' . $this->getSetting('extra_classes') . '">' . $item->value . '</h1>',
      ];
    }
    return $element;
  }

}
