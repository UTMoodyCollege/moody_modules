<?php

namespace Drupal\faculty_bio_content_type\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'faculty_bio_subordinate_default' formatter.
 *
 * @FieldFormatter(
 *   id = "faculty_bio_subordinate_default",
 *   label = @Translation("Faculty Bio Subordinate default"),
 *   field_types = {
 *     "faculty_bio_subordinate"
 *   }
 * )
 */
class FacultyBioSubordinateDefaultFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      // Render each item as a theme.
      $elements[$delta] = [
        '#theme' => 'faculty_bio_content_type_subordinate_info',
        '#name' => $item->name,
        '#title' => $item->title,
        '#email' => $item->email,
      ];
    }

    return $elements;
  }
}
