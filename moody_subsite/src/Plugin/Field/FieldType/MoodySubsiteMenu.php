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
 * Plugin implementation of the 'moody_subsite_menu' field type.
 *
 * @FieldType(
 *   id = "moody_subsite_menu",
 *   label = @Translation("Moody Subsite Menu"),
 *   category = "Moody",
 *   description = @Translation("Configurable navigation for Moody subsites"),
 *   default_widget = "moody_subsite_menu_widget",
 *   default_formatter = "moody_subsite_menu_formatter"
 * )
 */
class MoodySubsiteMenu extends FieldItemBase {

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
    // $properties['parent'] = DataDefinition::create('string')
    //   ->setLabel(new TranslatableMarkup('Weight'))
    //   ->setRequired(TRUE);
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
