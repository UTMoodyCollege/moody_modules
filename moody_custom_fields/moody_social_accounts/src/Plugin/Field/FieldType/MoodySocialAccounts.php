<?php

namespace Drupal\moody_social_accounts\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'moody_social_accounts' field type.
 *
 * @FieldType(
 *   id = "moody_social_accounts",
 *   label = @Translation("Moody Social Accounts"),
 *   description = @Translation("Moody subsite social accounts"),
 *   default_widget = "moody_social_accounts_widget",
 *   default_formatter = "moody_social_accounts_formatter"
 * )
 */
class MoodySocialAccounts extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    // Prevent early t() calls by using the TranslatableMarkup.
    $properties['links'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Link'))
      ->setRequired(TRUE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = [
      'columns' => [
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
    // $values['links'] = $random->word(mt_rand(1, $field_definition->getSetting('max_length')));
    // return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $links = $this->get('links')->getValue();
    return $links === NULL || $links === '';
  }

}
