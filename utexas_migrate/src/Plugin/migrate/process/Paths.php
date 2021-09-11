<?php

namespace Drupal\utexas_migrate\Plugin\migrate\process;

use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Layouts Processor.
 *
 * This plugin takes care of processing a D7 "Page Layout"
 * into something consumable buy D8 "Layout Builder".
 *
 * @MigrateProcessPlugin(
 *   id = "utexas_process_paths"
 * )
 */
class Paths extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $prepared = MigrateHelper::prepareLink($value);
    return $prepared;
  }

}
