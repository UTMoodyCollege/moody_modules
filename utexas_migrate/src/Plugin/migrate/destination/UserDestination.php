<?php

namespace Drupal\utexas_migrate\Plugin\migrate\destination;

use Drupal\migrate\Row;
use Drupal\user\Plugin\migrate\destination\EntityUser;

/**
 * Provides a 'user' destination plugin. The id MUST end in the entity name.
 *
 * @MigrateDestination(
 *   id = "utexas:user"
 * )
 */
class UserDestination extends EntityUser {

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\migrate\MigrateException
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    // Do not overwrite the root account password.
    if ($row->getSourceProperty('uid') == 1) {
      $row->removeDestinationProperty('pass');
      $row->setDestinationProperty('uid', '1');
    }
    // print_r($row);
    return parent::import($row, $old_destination_id_values);
  }

}
