<?php

namespace Drupal\moody_layout_builder_browser;

use Drupal\layout_builder\Section;
use Drupal\node\NodeInterface;

/** Removes only retired node AddToAny components, without loading plugins. */
final class RetiredAddToAny {

  public static function prune(array $sections): int {
    $removed = 0;
    foreach ($sections as $section) {
      if (!$section instanceof Section) {
        continue;
      }
      foreach ($section->getComponents() as $uuid => $component) {
        $id = $component->toArray()['configuration']['id'] ?? '';
        if (is_string($id) && preg_match('/^extra_field_block:node:[^:]+:addtoany$/D', $id)) {
          $section->removeComponent($uuid);
          $removed++;
        }
      }
    }
    return $removed;
  }

  public static function pruneNode(NodeInterface $node): void {
    if (!$node->hasField('layout_builder__layout')) {
      return;
    }
    foreach ($node->getTranslationLanguages() as $langcode => $language) {
      self::prune($node->getTranslation($langcode)->get('layout_builder__layout')->getSections());
    }
  }

}
