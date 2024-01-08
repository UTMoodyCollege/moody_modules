<?php

namespace Drupal\moody_subsite\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'subsite_custom_logo' field type.
 *
 * @FieldType(
 *   id = "subsite_custom_logo",
 *   label = @Translation("Subsite custom logo"),
 *   description = @Translation("Moody subsite custom logo for centers and institutes"),
 *   default_widget = "subsite_custom_logo_widget",
 *   default_formatter = "subsite_custom_logo_formatter"
 * )
 */
class SubsiteCustomLogo extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    // Prevent early t() calls by using the TranslatableMarkup.
    $properties['media'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Media'))
      ->setRequired(FALSE);
    $properties['size'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Logo size'))
      ->setRequired(FALSE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = [
      'columns' => [
        'media' => [
          'type' => 'varchar',
          'length' => 512,
          'binary' => FALSE,
        ],
        'size' => [
          'type' => 'varchar',
          'length' => 512,
          'binary' => FALSE,
        ],
      ],
    ];

    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    // $random = new Random();
    // $values['value'] = $random->word(mt_rand(1, $field_definition->getSetting('max_length')));
    // return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $media = $this->get('media')->getValue();
    return $media === NULL || $media === '';
  }

}
