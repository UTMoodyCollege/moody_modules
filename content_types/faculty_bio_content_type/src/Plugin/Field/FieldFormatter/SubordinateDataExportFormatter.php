<?php declare(strict_types = 1);

namespace Drupal\faculty_bio_content_type\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

/**
 * Plugin implementation of the 'Subordinate Data Export' formatter.
 *
 * @FieldFormatter(
 *   id = "faculty_bio_content_type_subordinate_data_export",
 *   label = @Translation("Subordinate Data Export"),
 *   field_types = {"faculty_bio_subordinate"},
 * )
 */
final class SubordinateDataExportFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $element = [];
    foreach ($items as $delta => $item) {
      // Each item has values of name, title and email. Let's simply display those in a comma separated way
      $element[$delta] = [
        '#markup' => '[name=' . $item->name . '&title=' . $item->title . '&email=' . $item->email . ']',
      ];
      
    }
    return $element;
  }

}
