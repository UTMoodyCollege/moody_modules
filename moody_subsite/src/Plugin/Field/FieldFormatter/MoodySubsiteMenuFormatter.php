<?php

namespace Drupal\moody_subsite\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_subsite_menu_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_subsite_menu_formatter",
 *   label = @Translation("Moody subsite menu formatter"),
 *   field_types = {
 *     "moody_subsite_menu"
 *   }
 * )
 */
class MoodySubsiteMenuFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      $elements[$delta] = [
        '#theme' => 'moody_subsite_menu',
        '#title' => $item->title,
        '#link' => $item->link,
      ];
    }

    return $elements;
  }

}
