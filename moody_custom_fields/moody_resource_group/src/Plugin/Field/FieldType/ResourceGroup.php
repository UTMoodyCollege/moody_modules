<?php

namespace Drupal\moody_resource_group\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'resource_group' field type.
 *
 * @FieldType(
 *   id = "resource_group",
 *   label = @Translation("Resource group"),
 *   description = @Translation("List of links with stylized headline"),
 *   default_widget = "resource_group_widget",
 *   default_formatter = "resource_group_formatter"
 * )
 */
class ResourceGroup extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    // Prevent early t() calls by using the TranslatableMarkup.
    $properties['headline'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Headline value'))
      ->setRequired(FALSE);
    $properties['style'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Style value'))
      ->setRequired(FALSE);
    $properties['links'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Links'))
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
          'length' => 512,
          'binary' => FALSE,
        ],
        'style' => [
          'type' => 'varchar',
          'length' => 512,
          'binary' => FALSE,
        ],
        'links' => [
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
    $values['value'] = $random->word(mt_rand(1, $field_definition->getSetting('max_length')));
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $headline = $this->get('headline')->getValue();
    $links = $this->get('links')->getValue();
    return $headline === NULL || $headline === '' && empty($links);
  }

}
