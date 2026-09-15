<?php

namespace Drupal\moody_layout_restrictions\Commands;

use Drush\Commands\DrushCommands;

final class RestrictionCommands extends DrushCommands {

  /**
   * Verify migration, optionally rehearse the upstream uninstall on Test only.
   *
   * @command moody-layout-restrictions:verify
   * @option uninstall-legacy Uninstall only Layout Builder Restrictions on Test.
   */
  public function verify(array $options = ['uninstall-legacy' => FALSE]): int {
    if ($options['uninstall-legacy'] && getenv('PANTHEON_ENVIRONMENT') !== 'test') {
      throw new \RuntimeException('Uninstall rehearsal is restricted to Pantheon Test.');
    }
    $storage = \Drupal::entityTypeManager()->getStorage('entity_view_display');
    $before = [];
    $migrated = 0;
    foreach ($storage->loadMultiple() as $id => $display) {
      if ($display->getThirdPartySettings('layout_builder_restrictions')) {
        throw new \RuntimeException('Unmigrated display: ' . $id);
      }
      $migrated += (bool) $display->getThirdPartySettings('moody_layout_restrictions');
      $before[$id] = $display->toArray();
    }
    $legacy = 'layout_builder_restrictions';
    if ($options['uninstall-legacy'] && \Drupal::moduleHandler()->moduleExists($legacy)) {
      $modules = \Drupal::service('extension.list.module')->getList();
      $installed = \Drupal::config('core.extension')->get('module');
      if ($dependent = array_intersect_key($modules[$legacy]->required_by, $installed)) {
        throw new \RuntimeException('Installed modules still depend on Restrictions: ' . implode(', ', array_keys($dependent)));
      }
      if (!\Drupal::service('module_installer')->uninstall([$legacy], FALSE)) {
        throw new \RuntimeException('Legacy uninstall did not succeed.');
      }
      $storage = \Drupal::entityTypeManager()->getStorage('entity_view_display');
      $storage->resetCache();
      foreach ($before as $id => $data) {
        // Section objects are reloaded instances; compare their values, not identity.
        if ($storage->load($id)?->toArray() != $data) {
          throw new \RuntimeException('Display changed during uninstall: ' . $id);
        }
      }
    }
    $this->output()->writeln(json_encode([
      'status' => 'success',
      'displays' => count($before),
      'migrated_displays' => $migrated,
      'legacy_enabled' => \Drupal::moduleHandler()->moduleExists($legacy),
    ], JSON_THROW_ON_ERROR));
    return 0;
  }
}
