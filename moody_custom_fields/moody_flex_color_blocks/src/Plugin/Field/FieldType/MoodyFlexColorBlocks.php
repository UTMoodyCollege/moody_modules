<?php

namespace Drupal\moody_flex_color_blocks\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'moody_flex_color_blocks' field type.
 *
 * @FieldType(
 *   id = "moody_flex_color_blocks",
 *   label = @Translation("Moody Flex Color Blocks"),
 *   description = @Translation("Styled multiple calls to action"),
 *   default_widget = "moody_flex_color_blocks_widget",
 *   default_formatter = "moody_flex_color_blocks_formatter"
 * )
 */
class MoodyFlexColorBlocks extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    // Prevent early t() calls by using the TranslatableMarkup.
    $properties['headline'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Headline value'))
      ->setRequired(FALSE);
    $properties['subheadline'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Subheadline value'))
      ->setRequired(FALSE);
    $properties['link'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Link value'))
      ->setRequired(FALSE);
    $properties['color_scheme'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Color scheme value'))
      ->setRequired(FALSE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = [
      'columns' => [
        'headline' => [
          'type' => 'varchar',
          'length' => 2048,
          'binary' => FALSE,
        ],
        'subheadline' => [
          'type' => 'varchar',
          'length' => 2048,
          'binary' => FALSE,
        ],
        'link' => [
          'type' => 'varchar',
          'length' => 255,
          'binary' => FALSE,
        ],
        'color_scheme' => [
          'type' => 'varchar',
          'length' => 255,
          'binary' => FALSE,
        ],
      ],
    ];

    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $headline = $this->get('headline')->getValue();
    $subheadline = $this->get('subheadline')->getValue();
    return ($headline === NULL || $headline === '') &&
      ($subheadline === NULL || $subheadline === '');
  }

}
