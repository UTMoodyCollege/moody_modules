<?php

namespace Drupal\faculty_bio_content_type\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'faculty_bio_subordinate' field type.
 *
 * @FieldType(
 *   id = "faculty_bio_subordinate",
 *   label = @Translation("Faculty Bio Subordinate"),
 *   description = @Translation("Stores a subordinate's name, title, and email."),
 *   category = @Translation("Custom"),
 *   default_widget = "faculty_bio_subordinate_default",
 *   default_formatter = "faculty_bio_subordinate_default"
 * )
 */
class FacultyBioSubordinate extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['name'] = DataDefinition::create('string')
      ->setLabel(t('Name'));

    $properties['title'] = DataDefinition::create('string')
      ->setLabel(t('Title'));

    $properties['email'] = DataDefinition::create('string')
      ->setLabel(t('Email'));

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'name' => [
          'type' => 'varchar',
          'length' => 255,
        ],
        'title' => [
          'type' => 'varchar',
          'length' => 255,
        ],
        'email' => [
          'type' => 'varchar',
          'length' => 255,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $name = $this->get('name')->getValue();
    $title = $this->get('title')->getValue();
    $email = $this->get('email')->getValue();
    return empty($name) && empty($title) && empty($email);
  }
}
