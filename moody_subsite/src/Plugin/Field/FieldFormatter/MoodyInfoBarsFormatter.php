<?php

namespace Drupal\moody_subsite\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_info_bars_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_info_bars_formatter",
 *   label = @Translation("Moody info bars formatter"),
 *   field_types = {
 *     "moody_info_bars"
 *   }
 * )
 */
class MoodyInfoBarsFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      $elements[$delta] = [
        '#theme' => 'moody_info_bars',
        '#title' => $item->title,
        '#link' => $item->link,
      ];
    }

    return $elements;
  }

}
