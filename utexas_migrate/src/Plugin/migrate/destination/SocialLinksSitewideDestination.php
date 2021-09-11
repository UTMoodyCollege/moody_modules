<?php

namespace Drupal\utexas_migrate\Plugin\migrate\destination;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\migrate\Plugin\migrate\destination\Entity;
use Drupal\migrate\Plugin\MigrateDestinationInterface;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Row;

/**
 * Provides a 'utexas_social_links_destination' destination plugin.
 *
 * @MigrateDestination(
 *   id = "utexas_social_links_sitewide_destination"
 * )
 */
class SocialLinksSitewideDestination extends Entity implements MigrateDestinationInterface {

  /**
   * Import function that runs on each row.
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    $links = [];
    foreach ($row->getSourceProperty('links') as $link_item) {
      if ($link_item['social_account_url'] != '') {
        $links[] = [
          'social_account_url' => $link_item['social_account_url'],
          'social_account_name' => $link_item['social_account_name'],
        ];
      }
    }
    if (!empty($links)) {
      try {
        $social_block = BlockContent::create([
          'type' => 'social_links',
          'info' => 'Sitewide Social Links',
          'field_utexas_sl_social_links' => [
            'headline' => $row->getSourceProperty('field_utexas_social_links_headline'),
            'social_account_links' => serialize($links),
          ],
        ]);
        $social_block->save();
        return [$social_block->id()];
      }
      catch (EntityStorageException $e) {
        \Drupal::logger('utexas_migrate')->warning("Import of social link block failed: :error - Code: :code", [
          ':error' => $e->getMessage(),
          ':code' => $e->getCode(),
        ]);
      }
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
      \Drupal::logger('utexas_migrate')->warning("Rollback of block with id of :nid failed: :error - Code: :code", [
        ':nid' => $destination_identifier['id'],
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
