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
 * Plugin implementation of the 'moody_info_bars' field type.
 *
 * @FieldType(
 *   id = "moody_info_bars",
 *   label = @Translation("Moody Info Bars"),
 *   category = "Moody",
 *   description = @Translation("Repeatable list of links with optional URLs"),
 *   default_widget = "moody_info_bars_widget",
 *   default_formatter = "moody_info_bars_formatter"
 * )
 */
class MoodyInfoBars extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    // Prevent early t() calls by using the TranslatableMarkup.
    $properties['link'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Link'))
      ->setRequired(TRUE);
    $properties['title'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Parent'))
      ->setRequired(TRUE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = [
      'columns' => [
        'link' => [
          'type' => 'varchar',
          'length' => 255,
          'binary' => FALSE,
        ],
        'title' => [
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
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $random = new Random();
    $values['link'] = 'https://utexas.edu';
    $values['title'] = $random->word(mt_rand(1, $field_definition->getSetting('max_length')));
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $link = $this->get('link')->getValue();
    $title = $this->get('title')->getValue();
    return ($link === NULL || $link === '') && ($title === NULL || $title === '');
  }

}
