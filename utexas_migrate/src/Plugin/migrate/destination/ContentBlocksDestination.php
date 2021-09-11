<?php

namespace Drupal\utexas_migrate\Plugin\migrate\destination;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\block\Entity\Block;
use Drupal\migrate\Plugin\migrate\destination\Entity;
use Drupal\migrate\Plugin\MigrateDestinationInterface;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Row;

/**
 * Provides a 'utexas_content_blocks_destination' destination plugin.
 *
 * @MigrateDestination(
 *   id = "utexas_content_blocks_destination"
 * )
 */
class ContentBlocksDestination extends Entity implements MigrateDestinationInterface {

  /**
   * Import function that runs on each row.
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    try {
      $migrated_format = $row->getSourceProperty('format');
      $block = BlockContent::create([
        'type' => 'basic',
        'info' => $row->getSourceProperty('info'),
        'body' => [
          'value' => $row->getSourceProperty('body'),
          'format' => isset($migrated_format) ? $migrated_format : 'flex_html',
          // @todo: replace with format that allows <iframe> & <script>
          // @todo: add minimal text_format mapping for blocks that may be set
          // to other text formats?
        ],
      ]);
      $block->save();
      $region = $row->getSourceProperty('region');
      if ($region) {
        $config = \Drupal::config('system.theme');
        $placed_block = Block::create([
          'id' => $block->id(),
          'weight' => 0,
          'theme' => $config->get('default'),
          'status' => TRUE,
          'region' => 'footer_left',
          'plugin' => 'block_content:' . $block->uuid(),
          'settings' => [],
        ]);
        $placed_block->save();
      }
      return [$block->id()];
    }
    catch (EntityStorageException $e) {
      \Drupal::logger('utexas_migrate')->warning("Import of block failed: :error - Code: :code", [
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
      'entity_id' => [
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
    return 'block';
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
    try {
      $block = BlockContent::load($destination_identifier['entity_id']);
      if ($block != NULL) {
        $block->delete();
      }
    }
    catch (EntityStorageException $e) {
      \Drupal::logger('utexas_migrate')->warning("Rollback of block with id of :bid failed: :error - Code: :code", [
        ':bid' => $destination_identifier['id'],
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
