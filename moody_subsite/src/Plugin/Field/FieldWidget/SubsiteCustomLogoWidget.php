<?php

namespace Drupal\moody_subsite\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;

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
    $element['custom_logo']['svg_logo'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('SVG Logo'),
      '#upload_location' => 'public://subsite-logos/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'svg'],
      ],
      '#element_validate' => [[static::class, 'validateSvgLogo']],
      '#default_value' => !empty($item->svg_logo) ? [$item->svg_logo] : [],
      '#description' => $this->t('Optional SVG logo. If set, this is used instead of the image logo.'),
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
      $custom_logo = $value['custom_logo'] ?? $value;
      // A null media value should be saved as 0.
      $value['media'] = !empty($custom_logo['media']) ? $custom_logo['media'] : 0;
      $svg_logo = $custom_logo['svg_logo'] ?? [];
      $value['svg_logo'] = is_array($svg_logo) ? ($svg_logo['fids'][0] ?? $svg_logo[0] ?? 0) : $svg_logo;
      if ($value['svg_logo'] && $file = File::load($value['svg_logo'])) {
        $file->setPermanent();
        $file->save();
      }
      if (!empty($custom_logo['size'])) {
        $value['size'] = $custom_logo['size'];
      }
    }
    return $values;
  }

  /**
   * Rejects SVG uploads with common executable payloads.
   */
  public static function validateSvgLogo(array &$element, FormStateInterface $form_state, array &$complete_form) {
    foreach ((array) ($element['#value']['fids'] ?? []) as $fid) {
      if ($file = File::load($fid)) {
        $svg = file_get_contents($file->getFileUri());
        if ($svg !== FALSE && preg_match('/<script\b|on[a-z]+\s*=|javascript:/i', $svg)) {
          $form_state->setError($element, t('The SVG logo contains scripting and cannot be uploaded.'));
        }
      }
    }
  }

}
