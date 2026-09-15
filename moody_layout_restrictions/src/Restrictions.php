<?php

namespace Drupal\moody_layout_restrictions;

use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\layout_builder\OverridesSectionStorageInterface;

/**
 * Per-display rules, independent of the retired contributed module.
 */
final class Restrictions {

  public static function settings(array $extra): array {
    $storage = $extra['section_storage'] ?? NULL;
    if ($storage instanceof OverridesSectionStorageInterface) {
      $storage = $storage->getDefaultSectionStorage();
    }
    return $storage instanceof ThirdPartySettingsInterface
      ? $storage->getThirdPartySettings('moody_layout_restrictions') : [];
  }

  public static function blocks(array $definitions, array $settings): array {
    $rules = $settings['entity_view_mode_restriction'] ?? [];
    if (!$rules) {
      return $definitions;
    }
    // Match Restrictions 3.x: obsolete whitelist/blacklist keys are inert.
    $allow = $rules['allowlisted_blocks'] ?? [];
    $deny = $rules['denylisted_blocks'] ?? [];
    $restricted = $rules['restricted_categories'] ?? [];
    $categories = $settings['allowed_block_categories'] ?? [];
    $types = [];
    if (isset($allow['Custom block types']) || isset($deny['Custom block types'])) {
      // One lookup per filter, only when reusable block type rules need it.
      $types = \Drupal::database()->select('block_content', 'b')->fields('b', ['uuid', 'type'])->execute()->fetchAllKeyed();
    }
    foreach ($definitions as $id => $definition) {
      $category = $definition['category'] ?? '';
      $category = $category instanceof TranslatableMarkup ? $category->getUntranslatedString() : (string) $category;
      $category = match ($category) {
        '@entity fields' => 'Content fields',
        'Custom', 'Content block' => 'Custom blocks',
        default => $category,
      };
      $key = $id;
      if (($definition['provider'] ?? '') === 'block_content') {
        if (isset($allow['Custom blocks']) || isset($deny['Custom blocks'])) {
          $category = 'Custom blocks';
        }
        else {
          $category = 'Custom block types';
          $key = $types[substr($id, strlen('block_content:'))] ?? '';
        }
      }
      if (in_array($category, $restricted, TRUE)
        || (isset($allow[$category]) ? !in_array($key, $allow[$category], TRUE)
          : (isset($deny[$category]) ? in_array($key, $deny[$category], TRUE)
            : ($categories && !in_array($category, $categories, TRUE))))) {
        unset($definitions[$id]);
      }
    }
    return $definitions;
  }

  /**
   * Transfer active rules before upstream uninstall removes its settings.
   */
  public static function migrate(): void {
    $plugins = \Drupal::config('layout_builder_restrictions.plugins')->get('plugin_config') ?? [];
    foreach ($plugins as $id => $plugin) {
      if (($id !== 'entity_view_mode_restriction' && !empty($plugin['enabled']))
        || ($id === 'entity_view_mode_restriction' && empty($plugin['enabled']))) {
        throw new \RuntimeException('Nonstandard Layout Builder restriction plugin configuration needs review: ' . $id);
      }
    }
    if (\Drupal::moduleHandler()->moduleExists('layout_builder_restrictions_by_region')) {
      throw new \RuntimeException('Region-specific restrictions require review before migration.');
    }
    $displays = \Drupal::entityTypeManager()->getStorage('entity_view_display')->loadMultiple();
    // Validate the complete migration before saving any displays.
    foreach ($displays as $display) {
      $legacy = $display->getThirdPartySettings('layout_builder_restrictions');
      if ($legacy && $display->getThirdPartySettings('moody_layout_restrictions')) {
        throw new \RuntimeException('Both restriction providers exist on ' . $display->id() . '; reconcile before migration.');
      }
      if (array_diff(array_keys($legacy), ['allowed_block_categories', 'entity_view_mode_restriction'])) {
        throw new \RuntimeException('Unknown restriction settings on ' . $display->id());
      }
    }
    foreach ($displays as $display) {
      $legacy = $display->getThirdPartySettings('layout_builder_restrictions');
      if (!$legacy) {
        continue;
      }
      foreach ($legacy as $key => $value) {
        $display->setThirdPartySetting('moody_layout_restrictions', $key, $value);
        $display->unsetThirdPartySetting('layout_builder_restrictions', $key);
      }
      $display->save();
    }
  }
}
