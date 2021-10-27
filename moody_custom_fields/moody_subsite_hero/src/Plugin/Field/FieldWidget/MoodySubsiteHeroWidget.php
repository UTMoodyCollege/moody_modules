<?php

namespace Drupal\moody_subsite_hero\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_subsite_hero' widget.
 *
 * @FieldWidget(
 *   id = "moody_subsite_hero",
 *   label = @Translation("Subsite Hero"),
 *   field_types = {
 *     "moody_subsite_hero"
 *   }
 * )
 */
class MoodySubsiteHeroWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    // Get the form item that this widget is being applied to.
    /** @var \Drupal\link\LinkItemInterface $item */
    $item = $items[$delta];
    $element['subsite_hero'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Subsite Hero Image'),
    ];
    $element['subsite_hero']['media'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['utexas_image'],
      '#delta' => $delta,
      '#cardinality' => 1,
      '#title' => $this->t('Image'),
      '#default_value' => isset($item->media) ? $item->media : NULL,
      '#description' => $this->t('Image will be scaled and cropped to 1800 x 575 pixels. Upload an image with a resolution of 1800 x 575 pixels to maintain quality and avoid cropping.'),
    ];
    $element['subsite_hero']['disable_image_styles'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable image size optimization.'),
      '#description' => $this->t('Check this if you need to display an animated GIF or have specific image dimensions requirements.'),
      '#default_value' => isset($item->disable_image_styles) ? $item->disable_image_styles : 0,
    ];
    $element['subsite_hero']['caption'] = [
      '#title' => $this->t('Subsite Hero Caption'),
      '#type' => 'textfield',
      '#default_value' => isset($item->caption) ? $item->caption : NULL,
      '#size' => '60',
      '#description' => $this->t('Optional text to display directly beneath the media.'),
      '#maxlength' => 255,
    ];
    $element['subsite_hero']['credit'] = [
      '#title' => $this->t('Subsite Hero Credit'),
      '#type' => 'textfield',
      '#default_value' => isset($item->credit) ? $item->credit : NULL,
      '#size' => '60',
      '#description' => $this->t('Optional way to provide attribution, displayed directly beneath the media.'),
      '#maxlength' => 255,
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
      $value['media'] = !empty($value['subsite_hero']['media']) ? $value['subsite_hero']['media'] : 0;
      if (!empty($value['subsite_hero']['disable_image_styles'])) {
        $value['disable_image_styles'] = $value['subsite_hero']['disable_image_styles'];
      }
      if (!empty($value['subsite_hero']['caption'])) {
        $value['caption'] = $value['subsite_hero']['caption'];
      }
      if (!empty($value['subsite_hero']['credit'])) {
        $value['credit'] = $value['subsite_hero']['credit'];
      }
    }
    return $values;
  }

}
