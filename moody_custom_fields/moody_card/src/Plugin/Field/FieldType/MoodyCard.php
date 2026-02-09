<?php

namespace Drupal\moody_card\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\Core\File\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\Language;

/**
 * Plugin implementation of the 'moody_card' field type.
 *
 * @FieldType(
 *   id = "moody_card",
 *   label = @Translation("Moody Card"),
 *   description = @Translation("Generic card"),
 *   default_widget = "moody_card_widget",
 *   default_formatter = "moody_card_formatter"
 * )
 */
class MoodyCard extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    // Prevent early t() calls by using the TranslatableMarkup.
    $properties['media'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Media'))
      ->setRequired(FALSE);
    $properties['title'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Caption'))
      ->setRequired(FALSE);
    $properties['subtitle'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Credit'))
      ->setRequired(FALSE);
    $properties['link_uri'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Link URI'))
      ->setRequired(FALSE);
    $properties['link_title'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Link Title'))
      ->setRequired(FALSE);
    $properties['link_options'] = MapDataDefinition::create()
      ->setLabel(t('Link Options'));

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = [
      'columns' => [
        'media' => [
          'type' => 'int',
        ],
        'title' => [
          'type' => 'varchar',
          'length' => 512,
          'binary' => FALSE,
        ],
        'subtitle' => [
          'type' => 'varchar',
          'length' => 512,
          'binary' => FALSE,
        ],
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
      ],
    ];

    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $random = new Random();
    $values['title'] = $random->word(mt_rand(1, 255));
    $values['subtitle'] = $random->sentences(2);
    $values['link_title'] = $random->sentences(1);
    // // Set of possible top-level domains for sample link value.
    $tlds = ['com', 'net', 'gov', 'org', 'edu', 'biz', 'info'];
    // // Set random length for the domain name.
    $domain_length = mt_rand(7, 15);
    $values['link_uri'] = 'https://www.' . $random->word($domain_length) . '.' . $tlds[mt_rand(0, (count($tlds) - 1))];
// Attributes for sample image.
    static $images = [];
    $min_resolution = '2280x1232';
    $max_resolution = '2280x1232';
    $extensions = ['png', 'gif', 'jpg', 'jpeg'];
    $extension = array_rand(array_combine($extensions, $extensions));
    $images[$extension] = $images[$extension] ?? [];
    $images[$extension][$min_resolution] = $images[$extension][$min_resolution] ?? [];
    $images[$extension][$min_resolution][$max_resolution] = $images[$extension][$min_resolution][$max_resolution] ?? [];
    if (count($images[$extension][$min_resolution][$max_resolution]) <= 5) {
      /** @var \Drupal\Core\File\FileSystemInterface $file_system */
      $file_system = \Drupal::service('file_system');
      $tmp_file = $file_system->tempnam('temporary://', 'generateImage_');
      $destination = $tmp_file . '.' . $extension;
      try {
        $file_system->move($tmp_file, $destination);
      }
      catch (FileException $e) {
        // Ignore failed move.
      }
      if ($path = $random->image($file_system->realpath($destination), $min_resolution, $max_resolution)) {
        $image = File::create();
        $image->setFileUri($path);
        $image->setOwnerId(\Drupal::currentUser()->id());
        $image->setMimeType(\Drupal::service('file.mime_type.guesser')->guessMimeType($path));
        $image->setFileName(basename($path));
        $destination_dir = 'public://generated_sample';
        $file_system->prepareDirectory($destination_dir, FileSystemInterface::CREATE_DIRECTORY);
        $destination = $destination_dir . '/' . basename($path);
        $file = \Drupal::service('file.repository')->move($image, $destination);
        $images[$extension][$min_resolution][$max_resolution][$file->id()] = $file;
      }
      else {
        return [];
      }
    }
    else {
      // Select one of the images we've already generated for this field.
      $image_index = array_rand($images[$extension][$min_resolution][$max_resolution]);
      $file = $images[$extension][$min_resolution][$max_resolution][$image_index];
    }
    $image_media = Media::create([
      'name' => 'Image 1',
      'bundle' => 'utexas_image',
      'uid' => '1',
      'langcode' => Language::LANGCODE_NOT_SPECIFIED,
      'status' => '1',
      'field_utexas_media_image' => [
        'target_id' => $file->id(),
        'alt' => t('Test Alt Text'),
        'title' => t('Test Title Text'),
      ],
    ]);
    $image_media->save();
    $values['media'] = $image_media->id();
    // Attributes for sample image.
    $images = [];
    $min_resolution = '2280x1232';
    $max_resolution = '2280x1232';
    $extensions = ['png', 'gif', 'jpg', 'jpeg'];
    $extension = array_rand(array_combine($extensions, $extensions));
    $images[$extension] = $images[$extension] ?? [];
    $images[$extension][$min_resolution] = $images[$extension][$min_resolution] ?? [];
    $images[$extension][$min_resolution][$max_resolution] = $images[$extension][$min_resolution][$max_resolution] ?? [];
    if (count($images[$extension][$min_resolution][$max_resolution]) <= 5) {
      /** @var \Drupal\Core\File\FileSystemInterface $file_system */
      $file_system = \Drupal::service('file_system');
      $tmp_file = $file_system->tempnam('temporary://', 'generateImage_');
      $destination = $tmp_file . '.' . $extension;
      try {
        $file_system->move($tmp_file, $destination);
      }
      catch (FileException $e) {
        // Ignore failed move.
      }
      if ($path = $random->image($file_system->realpath($destination), $min_resolution, $max_resolution)) {
        $image = File::create();
        $image->setFileUri($path);
        $image->setOwnerId(\Drupal::currentUser()->id());
        $image->setMimeType(\Drupal::service('file.mime_type.guesser')->guessMimeType($path));
        $image->setFileName(basename($path));
        $destination_dir = 'public://generated_sample';
        $file_system->prepareDirectory($destination_dir, FileSystemInterface::CREATE_DIRECTORY);
        $destination = $destination_dir . '/' . basename($path);
        $file = \Drupal::service('file.repository')->move($image, $destination);
        $images[$extension][$min_resolution][$max_resolution][$file->id()] = $file;
      }
      else {
        return [];
      }
    }
    else {
      // Select one of the images we've already generated for this field.
      $image_index = array_rand($images[$extension][$min_resolution][$max_resolution]);
      $file = $images[$extension][$min_resolution][$max_resolution][$image_index];
    }
    $image_media = Media::create([
      'name' => 'Image 1',
      'bundle' => 'utexas_image',
      'uid' => '1',
      'langcode' => Language::LANGCODE_NOT_SPECIFIED,
      'status' => '1',
      'field_utexas_media_image' => [
        'target_id' => $file->id(),
        'alt' => t('Test Alt Text'),
        'title' => t('Test Title Text'),
      ],
    ]);
    $image_media->save();
    $values['media'] = $image_media->id();
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $media = $this->get('media')->getValue();
    $title = $this->get('title')->getValue();
    $subtitle = $this->get('subtitle')->getValue();
    return empty($media) && empty($title) && empty($subtitle);
  }

}
