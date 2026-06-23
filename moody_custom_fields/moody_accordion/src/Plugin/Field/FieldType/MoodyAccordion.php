<?php

namespace Drupal\moody_accordion\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'moody_accordion' field type.
 *
 * @FieldType(
 *   id = "moody_accordion",
 *   label = @Translation("Moody Accordion"),
 *   description = @Translation("Bootstrap powered accordion"),
 *   default_widget = "moody_accordion_widget",
 *   default_formatter = "moody_accordion_formatter"
 * )
 */
class MoodyAccordion extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    // Prevent early t() calls by using the TranslatableMarkup.
    $properties['title'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title value'))
      ->setRequired(TRUE);
    $properties['copy_value'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Copy value'))
      ->setRequired(FALSE);
    $properties['copy_format'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Copy format'))
      ->setRequired(FALSE);
    $properties['block_id'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Block plugin ID'))
      ->setRequired(FALSE);
    $properties['block_config'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Block configuration'))
      ->setRequired(FALSE);
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = [
      'columns' => [
        'title' => [
          'type' => 'varchar',
          'length' => 255,
          'binary' => FALSE,
        ],
        'copy_value' => [
          'type' => 'text',
          'size' => 'normal',
          'binary' => FALSE,
        ],
        'copy_format' => [
          'type' => 'varchar',
          'length' => 255,
          'binary' => FALSE,
        ],
        'block_id' => [
          'type' => 'varchar',
          'length' => 255,
          'binary' => FALSE,
        ],
        'block_config' => [
          'type' => 'text',
          'size' => 'normal',
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
    $random = new Random();
    $values['title'] = $random->word(mt_rand(1, $field_definition->getSetting('max_length')));
    $values['copy_value'] = $random->word(mt_rand(1, $field_definition->getSetting('max_length')));
    $values['copy_format'] = 'flex_html';
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $title = $this->get('title')->getValue();
    $copy_value = $this->get('copy_value')->getValue();
    $block_id = $this->get('block_id')->getValue();
    return ($title === NULL || $title === '') && ($copy_value === NULL || $copy_value === '') && ($block_id === NULL || $block_id === '');
  }

}
