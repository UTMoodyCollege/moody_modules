<?php

namespace Drupal\moody_subsite\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Component\Utility\UrlHelper;

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
    $cache = (new CacheableMetadata())
      ->addCacheContexts(['user', 'url.site', 'languages:language_url'])
      ->addCacheTags(['node_list', 'path_alias_list']);

    foreach ($items as $item) {
      $menu_items[] = [
        'title' => $item->title,
        'link' => $item->link,
        'is_child' => (bool) $item->is_child,
      ];
    }

    foreach (static::buildMenuTree($menu_items) as $delta => $item) {
      // Filter after grouping so a hidden parent cannot reparent its children.
      if (!$this->canViewLink($item['link'], $cache)) {
        continue;
      }
      $children = array_filter($item['children'], fn(array $child) => $this->canViewLink($child['link'], $cache));
      $elements[$delta] = [
        '#theme' => 'moody_subsite_menu',
        '#title' => $item['title'],
        '#link' => $item['link'],
        '#children' => $children,
      ];
    }

    // Retain dependencies even when every link is hidden. Publishing a target
    // must invalidate the anonymous menu as well as the editor's cached menu.
    $cache->applyTo($elements);
    return $elements;
  }

  /**
   * Uses the destination's normal Drupal view access, including node grants.
   */
  protected function canViewLink(string $link, CacheableMetadata $cache): bool {
    if (UrlHelper::isExternal($link)) {
      if (strcasecmp((string) parse_url($link, PHP_URL_HOST), \Drupal::request()->getHost()) !== 0) {
        return TRUE;
      }
      $link = parse_url($link, PHP_URL_PATH) ?: '/';
    }
    $url = \Drupal::service('path.validator')->getUrlIfValidWithoutAccessCheck($link ?: '/');
    if (!$url) {
      return FALSE;
    }
    $access = $url->access(\Drupal::currentUser(), TRUE);
    $cache->addCacheableDependency($access);
    return $access->isAllowed();
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
