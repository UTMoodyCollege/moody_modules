<?php

namespace Drupal\moody_quotation\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'moody_quotation' field type.
 *
 * @FieldType(
 *   id = "moody_quotation",
 *   label = @Translation("Moody quotation"),
 *   description = @Translation("A configurable quotation with author and style options"),
 *   category = @Translation("Moody"),
 *   default_widget = "moody_quotation_widget",
 *   default_formatter = "moody_quotation_formatter"
 * )
 */
class MoodyQuotation extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    // Prevent early t() calls by using the TranslatableMarkup.
    $properties['quote'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Quote value'))
      ->setRequired(FALSE);
    $properties['author'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Author value'))
      ->setRequired(FALSE);
    $properties['style'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Style value'))
      ->setRequired(FALSE);
    $properties['media'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Media'))
      ->setRequired(FALSE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = [
      'columns' => [
        'quote' => [
          'type' => 'text',
          'size' => 'normal',
          'binary' => FALSE,
        ],
        'author' => [
          'type' => 'varchar',
          'length' => 255,
          'binary' => FALSE,
        ],
        'style' => [
          'type' => 'varchar',
          'length' => 255,
          'binary' => FALSE,
        ],
        'media' => [
          'type' => 'int',
        ],
      ],
    ];

    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $random = new Random();
    $values['quote'] = $random->word(mt_rand(10));
    $values['author'] = $random->word(mt_rand(10));
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $quote = $this->get('quote')->getValue();
    return $quote === NULL || $quote === '';
  }

}
