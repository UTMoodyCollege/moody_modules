<?php

namespace Drupal\moody_hero\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_hero' widget.
 *
 * @FieldWidget(
 *   id = "moody_hero",
 *   label = @Translation("Hero"),
 *   field_types = {
 *     "moody_hero"
 *   }
 * )
 */
class MoodyHeroWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    // Get the form item that this widget is being applied to.
    /** @var \Drupal\link\LinkItemInterface $item */
    $item = $items[$delta];
    $element['media'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['utexas_image'],
      '#delta' => $delta,
      '#cardinality' => 1,
      '#title' => $this->t('Image'),
      // '#default_value' => isset($item->media) ? $item->media : 0,
      '#default_value' => !empty($item->media) ? $item->media : NULL,
      '#description' => $this->t('Image will be scaled and cropped to a 87:47 ratio. Upload an image with a minimum resolution of 2280x1232 pixels to maintain quality and avoid cropping.'),
    ];
    $element['disable_image_styles'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable image size optimization.'),
      '#description' => $this->t('Check this if you need to display an animated GIF or have specific image dimensions requirements.'),
      '#default_value' => $item->disable_image_styles ?? 0,
      '#states' => [
        'invisible' => [
          ':input[name="' . $field_name . '[' . $delta . '][media][media_library_selection]"]' => ['value' => "0"],
        ],
      ],
    ];
    $element['heading'] = [
      '#title' => $this->t('Heading'),
      '#type' => 'textfield',
      '#default_value' => isset($item->heading) ? $item->heading : NULL,
      '#size' => '60',
      '#description' => $this->t('Optional, but recommended to provide alternative textual explanation of the media.'),
      '#maxlength' => 255,
    ];
    $element['subheading'] = [
      '#title' => $this->t('Subheading'),
      '#type' => 'textfield',
      '#default_value' => isset($item->subheading) ? $item->subheading : NULL,
      '#size' => '60',
      '#description' => $this->t('Optional. Displays directly beneath the heading. For best appearance, use no more than 140 characters. Note: this field is not visible in the default display or in hero style 2.'),
      '#maxlength' => 255,
    ];
    $element['caption'] = [
      '#title' => $this->t('Caption'),
      '#type' => 'textfield',
      '#default_value' => isset($item->caption) ? $item->caption : NULL,
      '#size' => '60',
      '#description' => $this->t('Optional text to display directly beneath the media.'),
      '#maxlength' => 255,
    ];
    $element['credit'] = [
      '#title' => $this->t('Credit'),
      '#type' => 'textfield',
      '#default_value' => isset($item->credit) ? $item->credit : NULL,
      '#size' => '60',
      '#description' => $this->t('Optional way to provide attribution, displayed directly beneath the media.'),
      '#maxlength' => 255,
    ];
    $element['text_color'] = [
      '#title' => $this->t('Text Color (Hero Style 6, 7 and 8)'),
      '#type' => 'radios',
      '#options' => [
        'white-text' => $this->t('White text'),
        'orange-text' => $this->t('Orange text'),
        'charcoal-text' => $this->t('Charcoal text'),
      ],
      '#default_value' => isset($item->text_color) ? $item->text_color : 'white-text',
      '#description' => $this->t('Select the color of the text fields. Note: this option only has an effect with Hero Style 6, 7 and 8.'),
    ];
    $element['overlay'] = [
      '#title' => $this->t('Image Overlay (Hero Style 6, 7 and 8)'),
      '#type' => 'radios',
      '#options' => [
        'no-overlay' => $this->t('No overlay'),
        'orange-overlay' => $this->t('Orange overlay'),
        'charcoal-overlay' => $this->t('Charcoal overlay'),
        'heavy-orange-overlay' => $this->t('Darker orange overlay'),
        'heavy-charcoal-overlay' => $this->t('Darker charcoal overlay'),
      ],
      '#default_value' => isset($item->overlay) ? $item->overlay : 'no-overlay',
      '#description' => $this->t('Optionally add a color overlay to the image. Note: this option only has an effect with Hero Style 6, 7 and 8.'),
    ];
    $element['text_position'] = [
      '#title' => $this->t('Text Position (Hero Styles 7 and 8 only)'),
      '#type' => 'radios',
      '#options' => [
        'centered' => $this->t('Centered'),
        'top-left' => $this->t('Top left'),
        'top-right' => $this->t('Top right'),
        'bottom-left' => $this->t('Bottom left'),
        'bottom-right' => $this->t('Bottom right'),
      ],
      '#default_value' => isset($item->text_position) ? $item->text_position : 'centered',
      '#description' => $this->t('Select the positioning of the text fields. Note: this option only has an effect with Hero Styles 7 and 8.'),
    ];
    $element['cta'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Call to Action'),
    ];
    $element['cta']['link'] = [
      '#type' => 'utexas_link_options_element',
      '#default_value' => [
        'uri' => $item->link_uri ?? '',
        'title' => $item->link_title ?? '',
        'options' => isset($item->link_options) ? $item->link_options : [],
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
      if (empty($value['media'])) {
        // A null media value should be saved as 0.
        $value['media'] = 0;
      }
      if (isset($value['cta']['link']['uri'])) {
        $value['link_uri'] = $value['cta']['link']['uri'] ?? '';
        $value['link_title'] = $value['cta']['link']['title'] ?? '';
        $value['link_options'] = $value['cta']['link']['options'] ?? [];
      }
    }
    return $values;
  }

}
