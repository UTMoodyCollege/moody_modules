<?php

declare(strict_types=1);

use Drupal\Core\File\FileExists;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * Small local smoke check for scan, consolidate, and undo behavior.
 */
function remediation_check(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$database = \Drupal::database();
$manager = \Drupal::service('moody_media_remediation.manager');
$file_repository = \Drupal::service('file.repository');
$media_storage = \Drupal::entityTypeManager()->getStorage('media');
$file_storage = \Drupal::entityTypeManager()->getStorage('file');
$account_switcher = \Drupal::service('account_switcher');
$admin = \Drupal::entityTypeManager()->getStorage('user')->load(1);
$scan_id = NULL;
$operation_id = NULL;
$delete_operation_id = NULL;
$media_id = NULL;
$file_ids = [];
$switched_account = FALSE;

try {
  remediation_check($admin !== NULL, 'User 1 is required for this local smoke check.');
  $account_switcher->switchTo($admin);
  $switched_account = TRUE;
  $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', TRUE);
  remediation_check($png !== FALSE, 'Could not decode the test image.');
  $suffix = bin2hex(random_bytes(5));
  $canonical = $file_repository->writeData(
    $png,
    "temporary://remediation-canonical-$suffix.png",
    FileExists::Error,
  );
  $duplicate = $file_repository->writeData(
    $png,
    "temporary://remediation-duplicate-$suffix.png",
    FileExists::Error,
  );
  remediation_check($canonical instanceof FileInterface, 'Could not create the canonical test file.');
  remediation_check($duplicate instanceof FileInterface, 'Could not create the duplicate test file.');
  $canonical->setPermanent();
  $canonical->save();
  $duplicate->setPermanent();
  $duplicate->save();
  $file_ids = [(int) $canonical->id(), (int) $duplicate->id()];

  $media = $media_storage->create([
    'bundle' => 'utexas_image',
    'name' => "Remediation smoke $suffix",
    'status' => 0,
    'field_utexas_media_image' => [[
      'target_id' => $duplicate->id(),
      'alt' => 'Remediation smoke test',
      'title' => '',
    ]],
  ]);
  $media->save();
  remediation_check($media instanceof MediaInterface, 'Could not create test media.');
  $media_id = (int) $media->id();

  $scan_id = $manager->createScan(1, 2);
  $context = [];
  $manager->scanMetadata($scan_id, $file_ids, $context);
  $manager->scanHashes($scan_id, $file_ids, $context);
  $summary = $manager->finishScan($scan_id);
  remediation_check($summary['duplicate_groups'] === 1, 'The exact duplicate group was not detected.');
  remediation_check($summary['duplicate_files'] === 2, 'The exact duplicate file count was incorrect.');

  file_put_contents($duplicate->getFileUri(), $png . 'changed');
  try {
    $manager->consolidateGroup(
      $scan_id,
      hash('sha256', $png),
      (int) $canonical->id(),
      [(int) $duplicate->id()],
    );
    throw new RuntimeException('Hash drift did not stop consolidation.');
  }
  catch (RuntimeException $exception) {
    remediation_check(
      str_contains($exception->getMessage(), 'changed since the scan'),
      'Hash drift returned an unexpected error.',
    );
  }
  file_put_contents($duplicate->getFileUri(), $png);

  $result = $manager->consolidateGroup(
    $scan_id,
    hash('sha256', $png),
    (int) $canonical->id(),
    [(int) $duplicate->id()],
  );
  $operation_id = (int) $result['operation_id'];
  remediation_check($operation_id > 0, 'The consolidation did not create an operation.');
  remediation_check($result['changed_entities'] === 1, 'The consolidation did not update the test media.');

  $media_storage->resetCache([$media_id]);
  $updated_media = $media_storage->load($media_id);
  remediation_check(
    (int) $updated_media->get('field_utexas_media_image')->target_id === (int) $canonical->id(),
    'The media reference did not move to the canonical file.',
  );

  $updated_media->get('field_utexas_media_image')->alt = 'Changed after remediation';
  $updated_media->save();
  try {
    $manager->undoOperation($operation_id);
    throw new RuntimeException('Field drift did not stop undo.');
  }
  catch (RuntimeException $exception) {
    remediation_check(
      str_contains($exception->getMessage(), 'changed after remediation'),
      'Field drift returned an unexpected error.',
    );
  }
  $updated_media->get('field_utexas_media_image')->alt = 'Remediation smoke test';
  $updated_media->save();

  $undo = $manager->undoOperation($operation_id);
  remediation_check($undo['changed_entities'] === 1, 'Undo did not update the test media.');
  $media_storage->resetCache([$media_id]);
  $restored_media = $media_storage->load($media_id);
  remediation_check(
    (int) $restored_media->get('field_utexas_media_image')->target_id === (int) $duplicate->id(),
    'Undo did not restore the original file reference.',
  );

  $duplicate_uri = $duplicate->getFileUri();
  $delete_result = $manager->consolidateGroup(
    $scan_id,
    hash('sha256', $png),
    (int) $canonical->id(),
    [(int) $duplicate->id()],
    TRUE,
  );
  $delete_operation_id = (int) $delete_result['operation_id'];
  remediation_check($delete_operation_id > 0, 'Deletion did not create an operation.');
  remediation_check($delete_result['deleted_files'] === 1, 'The duplicate file was not reported as deleted.');
  $file_storage->resetCache([(int) $duplicate->id()]);
  remediation_check($file_storage->load($duplicate->id()) === NULL, 'The duplicate file entity was not deleted.');
  remediation_check(!file_exists($duplicate_uri), 'The duplicate binary was not deleted.');
  $media_storage->resetCache([$media_id]);
  $deleted_media = $media_storage->load($media_id);
  remediation_check(
    (int) $deleted_media->get('field_utexas_media_image')->target_id === (int) $canonical->id(),
    'The media reference was not left on the canonical file after deletion.',
  );
  try {
    $manager->undoOperation($delete_operation_id);
    throw new RuntimeException('A deletion operation was incorrectly available to undo.');
  }
  catch (InvalidArgumentException $exception) {
    remediation_check(
      str_contains($exception->getMessage(), 'not available to undo'),
      'Deletion undo returned an unexpected error.',
    );
  }

  print "Media remediation smoke check passed.\n";
}
finally {
  if ($media_id) {
    $media_storage->resetCache([$media_id]);
    if ($media = $media_storage->load($media_id)) {
      $media->delete();
    }
  }
  if ($file_ids) {
    $file_storage->resetCache($file_ids);
    foreach ($file_storage->loadMultiple($file_ids) as $file) {
      $file->delete();
    }
  }
  if ($scan_id) {
    $database->delete('moody_media_remediation_operation')->condition('scan_id', $scan_id)->execute();
    $database->delete('moody_media_remediation_item')->condition('scan_id', $scan_id)->execute();
    $database->delete('moody_media_remediation_scan')->condition('scan_id', $scan_id)->execute();
  }
  if ($switched_account) {
    $account_switcher->switchBack();
  }
}
