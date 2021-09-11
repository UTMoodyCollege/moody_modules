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
 * Provides a destination plugin.
 *
 * @MigrateDestination(
 *   id = "twitter_widgets"
 * )
 */
class TwitterWidgets extends Entity implements MigrateDestinationInterface {

  /**
   * {@inheritdoc}
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    try {
      $block = BlockContent::create([
        'type' => 'twitter_widget',
        'info' => $row->getSourceProperty('name'),
        'field_twitter_profile_widget' => [
          'headline' => $row->getSourceProperty('headline'),
          'list_type' => $row->getSourceProperty('type'),
          'search' => $row->getSourceProperty('search'),
          'account' => $row->getSourceProperty('account'),
          'timeline' => $row->getSourceProperty('timeline_list'),
          'count' => $row->getSourceProperty('count') ?? 5,
          'view_all' => $row->getSourceProperty('view_all'),
          'retweets' => $row->getSourceProperty('retweets') ?? 1,
          'replies' => $row->getSourceProperty('replies') ?? 1,
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
          'region' => 'hidden',
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
