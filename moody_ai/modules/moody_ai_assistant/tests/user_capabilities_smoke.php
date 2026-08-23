<?php

declare(strict_types=1);

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;

$uid = (int) getenv('MOODY_AI_TEST_UID');
$entity_type = trim((string) (getenv('MOODY_AI_TEST_ENTITY_TYPE') ?: 'node'));
$entity_id = (int) getenv('MOODY_AI_TEST_ENTITY_ID');

if (!$uid || !$entity_id) {
  throw new RuntimeException('Set MOODY_AI_TEST_UID and MOODY_AI_TEST_ENTITY_ID before running this smoke test.');
}

$account = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
$entity = \Drupal::entityTypeManager()->getStorage($entity_type)->load($entity_id);
if (!$account instanceof AccountInterface || !$entity instanceof ContentEntityInterface) {
  throw new RuntimeException('The requested test account or content entity could not be loaded.');
}

$snapshot = \Drupal::service('moody_ai_assistant.user_capability_collector')->collect($account, $entity);
if (
  empty($snapshot['authority'])
  || empty($snapshot['roles'])
  || !isset($snapshot['content_types'], $snapshot['site_tools'], $snapshot['creatable_media_types'])
  || ($snapshot['current_content']['entity_type'] ?? '') !== $entity_type
  || (string) ($snapshot['current_content']['bundle'] ?? '') !== (string) $entity->bundle()
  || !array_key_exists('update', $snapshot['current_content']['operations'] ?? [])
  || !array_key_exists('can_publish', $snapshot['current_content']['publication'] ?? [])
  || !array_key_exists('can_unpublish', $snapshot['current_content']['publication'] ?? [])
) {
  throw new RuntimeException('The current-user capability snapshot is incomplete.');
}

$expected_redirect_access = FALSE;
if (\Drupal::entityTypeManager()->hasDefinition('redirect')) {
  $expected_redirect_access = $account->hasPermission('administer redirects')
    && \Drupal::entityTypeManager()->getAccessControlHandler('redirect')->createAccess(NULL, $account);
}
if (($snapshot['site_tools']['create_redirect'] ?? NULL) !== $expected_redirect_access) {
  throw new RuntimeException('Redirect creation access does not match Drupal entity access.');
}

foreach ($snapshot['current_content']['publication']['available_transitions'] ?? [] as $transition) {
  if (empty($transition['id']) || !$account->hasPermission('use ' . $snapshot['current_content']['publication']['workflow_id'] . ' transition ' . $transition['id'])) {
    throw new RuntimeException('The snapshot contains a publication transition the account cannot use.');
  }
}

print json_encode([
  'uid' => $uid,
  'roles' => array_column($snapshot['roles'], 'label'),
  'content_types' => array_column($snapshot['content_types'], 'id'),
  'current_content' => $snapshot['current_content'],
  'site_tools' => $snapshot['site_tools'],
  'creatable_media_types' => array_column($snapshot['creatable_media_types'], 'id'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
