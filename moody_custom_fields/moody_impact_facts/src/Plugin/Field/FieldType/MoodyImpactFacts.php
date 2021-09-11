<?php

namespace Drupal\moody_impact_facts\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'moody_impact_facts' field type.
 *
 * @FieldType(
 *   id = "moody_impact_facts",
 *   label = @Translation("Moody Impact Facts"),
 *   description = @Translation("Themed configurable display of data"),
 *   category = @Translation("Moody"),
 *   default_widget = "moody_impact_facts_widget",
 *   default_formatter = "moody_impact_facts_default_formatter"
 * )
 */
class MoodyImpactFacts extends FieldItemBase {

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
    $properties['col_number'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Columns value'))
      ->setRequired(FALSE);
    $properties['impact_items'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Items value'))
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
          'length' => 255,
          'binary' => FALSE,
        ],
        'style' => [
          'type' => 'varchar',
          'length' => 255,
          'binary' => FALSE,
        ],
        'col_number' => [
          'type' => 'varchar',
          'length' => 255,
          'binary' => FALSE,
        ],
        'impact_items' => [
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
    // $values['headline'] = $random->word(10);
    // $values['subheadline'] = $random->word(10);
    // $values['style'] = NULL;
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $items = $this->get('impact_items')->getValue();
    return empty($items);
  }

}
