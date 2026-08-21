<?php

namespace Drupal\moody_subsite\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

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
    $menu_items = [];

    foreach ($items as $item) {
      $menu_items[] = [
        'title' => $item->title,
        'link' => $item->link,
        'is_child' => (bool) $item->is_child,
      ];
    }

    foreach (static::buildMenuTree($menu_items) as $delta => $item) {
      $elements[$delta] = [
        '#theme' => 'moody_subsite_menu',
        '#title' => $item['title'],
        '#link' => $item['link'],
        '#children' => $item['children'],
      ];
    }

    return $elements;
  }

  /**
   * Groups child items beneath the nearest preceding top-level item.
   */
  protected static function buildMenuTree(array $items) {
    $tree = [];

    foreach ($items as $item) {
      if (!empty($item['is_child']) && $tree) {
        $tree[count($tree) - 1]['children'][] = $item;
        continue;
      }

      $item['children'] = [];
      $tree[] = $item;
    }

    return $tree;
  }

}
