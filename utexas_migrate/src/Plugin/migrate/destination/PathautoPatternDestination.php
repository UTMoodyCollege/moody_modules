<?php

namespace Drupal\utexas_migrate\Plugin\migrate\destination;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\migrate\Plugin\migrate\destination\EntityConfigBase;
use Drupal\migrate\Plugin\MigrateDestinationInterface;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Row;
use Drupal\pathauto\Entity\PathautoPattern;

/**
 * Provides a 'utexas_pathauto_pattern_destination' destination plugin.
 *
 * @MigrateDestination(
 *   id = "utexas_pathauto_pattern_destination"
 * )
 */
class PathautoPatternDestination extends EntityConfigBase implements MigrateDestinationInterface {

  /**
   * Import function that runs on each row.
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    // The pattern from D7 comes as a serialized string.
    $pattern = unserialize($row->getSourceProperty('value'));
    if ($existing_node_pattern = \Drupal::configFactory()->getEditable('pathauto.pattern.pathauto_node')) {
      $existing_node_pattern->set('pattern', $pattern);
      $existing_node_pattern->save();
    }
    else {
      $new_pattern = PathautoPattern::create([
        'id' => 'pathauto_node',
        'label' => 'Pathauto : Node',
        'type' => 'canonical_entities:node',
        'pattern' => $pattern,
        'weight' => 0,
      ]);
      $new_pattern->save();
    }
    return ['pathauto_node'];
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
   * Finds the entity type from configuration or plugin ID.
   *
   * @param string $plugin_id
   *   The plugin ID.
   *
   * @return string
   *   The entity type.
   */
  protected static function getEntityTypeId($plugin_id) {
    return 'pathauto_pattern';
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    try {
      $existing_node_pattern = \Drupal::configFactory()->getEditable('pathauto.pattern.pathauto_node');
      $existing_node_pattern->set('pattern', '[node:title]');
      $existing_node_pattern->save();
    }
    catch (EntityStorageException $e) {
      \Drupal::logger('utexas_migrate')->warning("Rollback of pathauto.pattern.pathauto_node failed. :error - Code: :code", [
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

}
