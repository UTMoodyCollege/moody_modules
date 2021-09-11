<?php

namespace Drupal\utexas_migrate\Plugin\migrate\destination;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\migrate\Plugin\migrate\destination\DestinationBase;
use Drupal\migrate\Plugin\MigrateDestinationInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Row;
use Drupal\google_tag\Entity\Container;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Provides a 'utexas_site_settings_destination' destination plugin.
 *
 * @MigrateDestination(
 *   id = "utexas_site_settings_destination"
 * )
 */
class SiteSettingsDestination extends DestinationBase implements MigrateDestinationInterface {

  /**
   * Import function that runs on each row.
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    // Additional site settings may be added here as needed.
    $settings = [
      'default_breadcrumb_display' => [
        'key' => 'breadcrumbs_visibility.content_type.utexas_flex_page',
        'value' => 'display_breadcrumbs',
      ],
      'utexas_twitter_widget_key' => [
        'key' => 'twitter_profile_widget.settings',
        'value' => 'twitter_widget_key',
      ],
      'utexas_twitter_widget_secret' => [
        'key' => 'twitter_profile_widget.settings',
        'value' => 'twitter_widget_secret',
      ],
      'site_mail' => [
        'key' => 'system.site',
        'value' => 'mail',
      ],
      'site_name' => [
        'key' => 'system.site',
        'value' => 'name',
      ],
      'site_slogan' => [
        'key' => 'system.site',
        'value' => 'slogan',
      ],
    ];
    foreach ($settings as $source => $destination) {
      $data = $row->getSourceProperty($source);
      $config = \Drupal::configFactory()->getEditable($destination['key']);
      $config->set($destination['value'], $data);
      $config->save();
    }

    // Front page & 403 & 404 pages.
    $config = \Drupal::configFactory()->getEditable('system.site');
    $front = MigrateHelper::getDestinationFromSource($row->getSourceProperty('site_frontpage'));
    $site403 = MigrateHelper::getDestinationFromSource($row->getSourceProperty('site_403'));
    $site404 = MigrateHelper::getDestinationFromSource($row->getSourceProperty('site_404'));
    $config->set('page.front', $front);
    $config->set('page.403', $site403);
    $config->set('page.404', $site404);
    $config->save();

    // Validate if there is a google tag to migrate.
    if ($row->getSourceProperty('utexas_google_tag_manager_gtm_code') !== NULL) {
      // Create container with GTM source settings.
      $container = new Container([], 'google_tag_container');
      $container->enforceIsNew();
      $container->set('id', 'utexas_migrated_gtm');
      $container->set('label', 'Migrated GTM');
      $container->set('container_id', $row->getSourceProperty('utexas_google_tag_manager_gtm_code'));
      $excluded_paths = $container->get('path_list');
      $migrated_paths = $row->getSourceProperty('utexas_google_tag_manager_gtm_exclude_paths');
      // Convert default and incoming paths to arrays.
      $migrated_paths = explode("\n", $migrated_paths);
      $excluded_paths = explode("\n", $excluded_paths);
      // Loop through incoming paths.
      foreach ($migrated_paths as $key => &$path) {
        // Preppend slash for D8 compliance.
        $path = "/" . $path;
        // If path not in default array, add it.
        if (!in_array($path, $excluded_paths)) {
          array_push($excluded_paths, $path);
        }
      }
      // Convert parsed paths back to string.
      $excluded_paths = implode("\n", $excluded_paths);
      // Append paths to list.
      $container->set('path_list', $excluded_paths);
      // Save container.
      $container->save();
    }
    // As an array of 1 item, this will indicate that the migration operation
    // completed its one task (composed of multiple settings).
    return ['site_settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'id' => [
        'type' => 'string',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    try {
      // Config for flex_page defaults to 1 for breadcrumb visibility. Reset.
      $flex_page_breadcrumb_display = \Drupal::configFactory()->getEditable('breadcrumbs_visibility.content_type.utexas_flex_page');
      $flex_page_breadcrumb_display->set('display_breadcrumbs', 1);
      $flex_page_breadcrumb_display->save();
      // Delete GTM container.
      $container = \Drupal::configFactory()->getEditable('google_tag.container.utexas_migrated_gtm');
      $container->delete();
    }
    catch (EntityStorageException $e) {
      \Drupal::logger('utexas_migrate')->warning("Rollback of site_settings failed. :error - Code: :code", [
        ':error' => $e->getMessage(),
        ':code' => $e->getCode(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function supportsRollback() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function rollbackAction() {
    return MigrateIdMapInterface::ROLLBACK_DELETE;
  }

  /**
   * {@inheritdoc}
   */
  public function fields(MigrationInterface $migration = NULL) {
    // Not needed; must be implemented to respect MigrateDestinationInterface.
  }

}
