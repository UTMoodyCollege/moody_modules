<?php

if (getenv('DDEV_SITENAME') !== '2moody-core' || getenv('PANTHEON_ENVIRONMENT')) {
  throw new RuntimeException('Run this rollback check only in local 2moody-core.');
}
require_once __DIR__ . '/../moody_flex_color_blocks.install';
$transaction = \Drupal::database()->startTransaction();
try {
  $storage = \Drupal\field\Entity\FieldStorageConfig::load('block_content.field_block_flex_color_blocks');
  if (!$storage) { throw new RuntimeException('Missing Flex Color Blocks storage.'); }
  $storage->setCardinality(5)->save();
  moody_flex_color_blocks_update_10001();
  \Drupal::entityTypeManager()->getStorage('field_storage_config')->resetCache();
  if (\Drupal\field\Entity\FieldStorageConfig::load($storage->id())->getCardinality() !== 4) { throw new RuntimeException('Limit did not update to four.'); }
  moody_flex_color_blocks_update_10001();
  echo "PASS: existing storage updates to four; rerun is safe (database rolled back).\n";
}
finally {
  $transaction->rollBack();
  \Drupal::entityTypeManager()->getStorage('field_storage_config')->resetCache();
  \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
}
