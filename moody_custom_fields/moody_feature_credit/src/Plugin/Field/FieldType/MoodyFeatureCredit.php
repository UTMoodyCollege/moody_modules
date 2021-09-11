<?php

namespace Drupal\moody_feature_credit\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'moody_feature_credit' field type.
 *
 * @FieldType(
 *   id = "moody_feature_credit",
 *   label = @Translation("Moody Feature Credit"),
 *   description = @Translation("Moody Feature Credit"),
 *   category = @Translation("Moody"),
 *   default_widget = "moody_feature_credit",
 *   default_formatter = "moody_feature_credit"
 * )
 */
class MoodyFeatureCredit extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    // Prevent early t() calls by using the TranslatableMarkup.
    $properties['first_name'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('First Namee'))
      ->setRequired(FALSE);
    $properties['last_name'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Last Namee'))
      ->setRequired(FALSE);
    $properties['title'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title'))
      ->setRequired(FALSE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = [
      'columns' => [
        'first_name' => [
          'type' => 'varchar',
          'length' => 255,
          'binary' => FALSE,
        ],
        'last_name' => [
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
    $values['first_name'] = $random->word(mt_rand(1, $field_definition->getSetting('max_length')));
    $values['last_name'] = $random->word(mt_rand(1, $field_definition->getSetting('max_length')));
    $values['title'] = $random->word(mt_rand(1, $field_definition->getSetting('max_length')));
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $first_name = $this->get('first_name')->getValue();
    $last_name = $this->get('last_name')->getValue();
    $title = $this->get('title')->getValue();
    return ($first_name === NULL || $first_name === '') &&
      ($last_name === NULL || $last_name === '') &&
      ($title === NULL || $title === '');
  }

}
