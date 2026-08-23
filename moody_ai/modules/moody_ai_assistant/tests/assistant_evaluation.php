<?php

declare(strict_types=1);

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;

$uid = (int) getenv('MOODY_AI_EVAL_UID');
$entity_type = trim((string) (getenv('MOODY_AI_EVAL_ENTITY_TYPE') ?: 'node'));
$entity_id = (int) getenv('MOODY_AI_EVAL_ENTITY_ID');
$prompt = trim((string) getenv('MOODY_AI_EVAL_PROMPT'));
$expected_min = max(0, (int) getenv('MOODY_AI_EVAL_EXPECTED_MIN_BLOCKS'));
$model = trim((string) getenv('MOODY_AI_EVAL_MODEL')) ?: 'gpt-5.6-luna';

if (!$uid || !$entity_id || $prompt === '') {
  throw new RuntimeException('Set MOODY_AI_EVAL_UID, MOODY_AI_EVAL_ENTITY_ID, and MOODY_AI_EVAL_PROMPT.');
}

$account = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
$entity = \Drupal::entityTypeManager()->getStorage($entity_type)->load($entity_id);
if (!$account instanceof AccountInterface || !$entity instanceof ContentEntityInterface || !$entity->hasField('layout_builder__layout')) {
  throw new RuntimeException('The evaluation account or Layout Builder entity is unavailable.');
}
if (!$account->hasPermission('use moody ai assistant') || !$entity->access('update', $account)) {
  throw new RuntimeException('The evaluation account cannot use Moody AI on this entity.');
}

\Drupal::currentUser()->setAccount($account);
$count_components = static function ($section_list): array {
  $all = 0;
  $inline = 0;
  $types = [];
  foreach ($section_list->getSections() as $section) {
    foreach ($section->getComponents() as $component) {
      $all++;
      $configuration = $component->get('configuration');
      $plugin_id = (string) ($configuration['id'] ?? '');
      if (str_starts_with($plugin_id, 'inline_block:')) {
        $inline++;
        $bundle = substr($plugin_id, strlen('inline_block:'));
        $types[$bundle] = ($types[$bundle] ?? 0) + 1;
      }
    }
  }
  ksort($types);
  return ['all' => $all, 'inline' => $inline, 'types' => $types];
};

$saved_sections = $entity->get('layout_builder__layout');
$section_storage = \Drupal::service('moody_ai_assistant.layout_context_collector')->getPreferredSectionStorage($entity, [
  'is_layout_builder_context' => TRUE,
]);
$tempstore = \Drupal::service('layout_builder.tempstore_repository');
$working_sections = $section_storage && $tempstore->has($section_storage)
  ? $tempstore->get($section_storage)
  : ($section_storage ?: $saved_sections);
$before_saved = $count_components($saved_sections);
$before_working = $count_components($working_sections);
$started = microtime(TRUE);
$events = [];
$error = NULL;
$exception_class = NULL;
$diagnostic_trace = NULL;
try {
  \Drupal::service('moody_ai_assistant.chat_manager')->processUserMessageStream(
    $entity,
    $account,
    $prompt,
    static function (string $event, array $payload) use (&$events, $started): void {
      $events[] = [
        'seconds' => round(microtime(TRUE) - $started, 2),
        'event' => $event,
        'message' => (string) ($payload['message'] ?? $payload['status_message'] ?? ''),
      ];
    },
    [
      'provider' => 'openai',
      'model' => $model,
      'is_layout_builder_context' => TRUE,
    ],
  );
}
catch (Throwable $exception) {
  $error = $exception->getMessage();
  $exception_class = get_class($exception);
  if (getenv('MOODY_AI_EVAL_DEBUG')) {
    $diagnostic_trace = $exception->getTraceAsString();
  }
}

\Drupal::entityTypeManager()->getStorage($entity_type)->resetCache([$entity_id]);
$entity = \Drupal::entityTypeManager()->getStorage($entity_type)->load($entity_id);
$after_saved = $count_components($entity->get('layout_builder__layout'));
$section_storage = \Drupal::service('moody_ai_assistant.layout_context_collector')->getPreferredSectionStorage($entity, [
  'is_layout_builder_context' => TRUE,
]);
$working_sections = $section_storage && $tempstore->has($section_storage)
  ? $tempstore->get($section_storage)
  : ($section_storage ?: $entity->get('layout_builder__layout'));
$after_working = $count_components($working_sections);
$thread = \Drupal::service('moody_ai_assistant.chat_manager')->getThread($entity, $account, FALSE);
$messages = $thread ? $thread->getMessages() : [];
$last_assistant = NULL;
foreach (array_reverse($messages) as $message) {
  if (($message['role'] ?? '') === 'assistant') {
    $last_assistant = $message;
    break;
  }
}
$usage = \Drupal::database()->select('moody_ai_assistant_usage_log', 'l')
  ->fields('l', ['tokens_used', 'status', 'created'])
  ->condition('uid', $uid)
  ->condition('target_entity_type', $entity_type)
  ->condition('target_entity_id', $entity_id)
  ->orderBy('id', 'DESC')
  ->range(0, 1)
  ->execute()
  ->fetchAssoc() ?: [];

$report = [
  'entity' => $entity_type . '/' . $entity_id,
  'model' => $model,
  'elapsed_seconds' => round(microtime(TRUE) - $started, 2),
  'before_saved' => $before_saved,
  'before_working' => $before_working,
  'after_saved' => $after_saved,
  'after_working' => $after_working,
  'created_working_inline_blocks' => $after_working['inline'] - $before_working['inline'],
  'working_changes_are_unsaved' => $after_working !== $after_saved,
  'planned_blocks' => count($last_assistant['metadata']['structured_plan']['blocks'] ?? []),
  'placements' => count($last_assistant['metadata']['placements'] ?? []),
  'assistant_message' => (string) ($last_assistant['content'] ?? ''),
  'assistant_metadata_keys' => array_keys($last_assistant['metadata'] ?? []),
  'usage' => $usage,
  'events' => $events,
  'error' => $error,
  'exception_class' => $exception_class,
  'diagnostic_trace' => $diagnostic_trace,
];

print json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if ($error !== NULL || $report['created_working_inline_blocks'] < $expected_min) {
  exit(1);
}
