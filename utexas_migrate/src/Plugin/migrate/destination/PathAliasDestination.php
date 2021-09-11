<?php

namespace Drupal\utexas_migrate\Plugin\migrate\destination;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\migrate\Plugin\migrate\destination\Entity;
use Drupal\migrate\Plugin\MigrateDestinationInterface;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Row;
use Drupal\node\Entity\Node;
use Drupal\pathauto\PathautoState;

/**
 * Update any nodes which have pathauto unchecked.
 *
 * @MigrateDestination(
 *   id = "utexas_path_alias_destination"
 * )
 */
class PathAliasDestination extends Entity implements MigrateDestinationInterface {

  /**
   * Import function that runs on each row.
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    // This gets the NID we requested in the "process" declaration's
    // migration_lookup in utexas_path_aliases.yml.
    $destination = $row->getDestinationProperty('temp_nid');
    try {
      if ($node = Node::load($destination)) {
        // The "pathauto_state" table in D7 records nodes which have pathauto
        // settings that *differ* from the node type default. So, this table
        // will either have a value of "0" or "1" or will not have a row for
        // the node (if it doesn't) differ from the node_type default. A value
        // of "0" means "SKIP PATHAUTO". A value of "1" means "USE PATHAUTO".
        // So, in this case, we are changing the value to "SKIP" from the
        // Flex Page default of "USE" *only* if that's specified in the
        // Drupal 7 node.
        if ($row->getSourceProperty('pathauto') === '0') {
          $alias = $row->getSourceProperty('alias');
          $node->set("path", ["alias" => $alias, "pathauto" => PathautoState::SKIP]);
          // Save the node with the pathauto & pathalias settings.
          $node->save();
        }
        // Else, leave the pathauto setting alone & report this as processed.
        return [$node->id()];
      }
      // The destination node couldn't be found.
      return FALSE;
    }
    catch (EntityStorageException $e) {
      \Drupal::logger('utexas_migrate')->warning("Path alias import to node :nid failed: :error - Code: :code", [
        ':nid' => $destination,
        ':error' => $e->getMessage(),
        ':code' => $e->getCode(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'id' => [
        'type' => 'integer',
        'unsigned' => FALSE,
        'size' => 'big',
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
    return 'node';
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    // TODO: Implement calculateDependencies() method.
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {

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
