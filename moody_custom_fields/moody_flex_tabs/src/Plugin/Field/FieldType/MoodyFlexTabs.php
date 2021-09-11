<?php

namespace Drupal\moody_flex_tabs\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'moody_flex_tabs' field type.
 *
 * @FieldType(
 *   id = "moody_flex_tabs",
 *   label = @Translation("Moody Flex Tabs"),
 *   description = @Translation("Configurable horizontal tabs"),
 *   default_widget = "moody_flex_tabs_widget",
 *   default_formatter = "moody_flex_tabs_formatter",
 *   category = "Moody"
 * )
 */
class MoodyFlexTabs extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    // Prevent early t() calls by using the TranslatableMarkup.
    $properties['set_active'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Set Active'))
      ->setRequired(FALSE);
    $properties['tab_title'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Tab Title'))
      ->setRequired(FALSE);
    $properties['copy_value'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Copy value'))
      ->setRequired(FALSE);
    $properties['copy_format'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Copy format'))
      ->setRequired(FALSE);
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = [
      'columns' => [
        'set_active' => [
          'type' => 'int',
        ],
        'tab_title' => [
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
      ],
    ];

    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints() {
    $constraints = parent::getConstraints();

    if ($max_length = $this->getSetting('max_length')) {
      $constraint_manager = \Drupal::typedDataManager()->getValidationConstraintManager();
      $constraints[] = $constraint_manager->create('ComplexData', [
        'value' => [
          'Length' => [
            'max' => $max_length,
            'maxMessage' => t('%name: may not be longer than @max characters.', [
              '%name' => $this->getFieldDefinition()->getLabel(),
              '@max' => $max_length,
            ]),
          ],
        ],
      ]);
    }

    return $constraints;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $random = new Random();
    $values['set_active'] = mt_rand(0, 1);
    $values['tab_title'] = $random->word(10);
    $values['copy_value'] = $random->sentences(8);
    $values['copy_format'] = 'restricted_html';
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $set_active = $this->get('set_active')->getValue();
    $tab_title = $this->get('tab_title')->getValue();
    $tab_content = $this->get('copy_value')->getValue();
    return empty($set_active) && empty($tab_title) && empty($tab_content);
  }

}
