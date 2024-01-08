<?php

namespace Drupal\moody_focus_areas\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;

/**
 * Plugin implementation of the 'moody_focus_areas' field type.
 *
 * @FieldType(
 *   id = "moody_focus_areas",
 *   label = @Translation("Moody Focus Areas"),
 *   description = @Translation("Themed configurable focus areas"),
 *   default_widget = "moody_focus_areas_widget",
 *   default_formatter = "moody_focus_areas_formatter"
 * )
 */
class MoodyFocusAreas extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['link_uri'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Link URI'))
      ->setRequired(FALSE);
    $properties['link_title'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Link Title'))
      ->setRequired(FALSE);
    $properties['link_options'] = MapDataDefinition::create()
      ->setLabel(t('Link Options'));
    $properties['items_style'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Items Style'))
      ->setRequired(FALSE);
    $properties['items_title'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Focus Areas Items Title'))
      ->setRequired(FALSE);
    $properties['focus_areas_items'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Focus Areas Items'))
      ->setRequired(FALSE);
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = [
      'columns' => [
        'link_uri' => [
          'type' => 'varchar',
          'length' => 512,
          'binary' => FALSE,
        ],
        'link_title' => [
          'type' => 'varchar',
          'length' => 512,
          'binary' => FALSE,
        ],
        'link_options' => [
          'description' => 'Serialized array of options for the link.',
          'type' => 'blob',
          'size' => 'big',
          'serialize' => TRUE,
        ],
        'items_style' => [
          'type' => 'varchar',
          'length' => 255,
          'binary' => FALSE,
        ],
        'items_title' => [
          'type' => 'varchar',
          'length' => 255,
          'binary' => FALSE,
        ],
        'focus_areas_items' => [
          'type' => 'blob',
          'size' => 'normal',
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
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $items_title = $this->get('items_title')->getValue();
    $items = $this->get('focus_areas_items')->getValue();
    return empty($items_title) && empty($items);
  }

}
