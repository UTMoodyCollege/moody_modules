<?php

use Drupal\node\Entity\Node;

$account = \Drupal::entityTypeManager()->getStorage('user')->load(1);
if (!$account) {
  throw new RuntimeException('User 1 is required for the request diagnostics smoke test.');
}
\Drupal::currentUser()->setAccount($account);

$node = NULL;
$thread = NULL;

try {
  $node = Node::create([
    'type' => 'moody_standard_page',
    'title' => 'Moody AI request diagnostics smoke test',
    'status' => 0,
    'uid' => 1,
  ]);
  $node->save();

  $manager = \Drupal::service('moody_ai_assistant.chat_manager');
  $thread = $manager->getThread($node, $account, TRUE);
  $thread->addMessage('user', str_repeat('A', 3000) . 'RECENT_MESSAGE_SECRET', [
    'private_metadata' => 'MESSAGE_METADATA_SECRET',
  ]);

  $context = [
    'entity_type' => 'node',
    'entity_id' => (int) $node->id(),
    'bundle' => $node->bundle(),
    'label' => $node->label(),
    'is_layout_builder_context' => TRUE,
    'prefer_ai_images' => TRUE,
    'existing_components' => [[
      'uuid' => 'component-one',
      'label' => 'Useful component context',
      'block_type' => 'basic',
    ]],
    'selected_block_references' => [],
    'selected_existing_block_references' => [],
    'user_access' => [
      'authority' => str_repeat('X', 250000) . 'USER_ACCESS_SECRET',
    ],
  ];

  $prompt_method = new ReflectionMethod($manager, 'buildPrompt');
  $prompt_method->setAccessible(TRUE);
  $prompt = $prompt_method->invoke($manager, 'Build one useful block.', $context, $thread);

  if (strlen($prompt) > 25000) {
    throw new RuntimeException('The compact block-generation prompt exceeded its smoke-test bound.');
  }
  foreach (['USER_ACCESS_SECRET', 'MESSAGE_METADATA_SECRET', 'RECENT_MESSAGE_SECRET'] as $secret) {
    if (str_contains($prompt, $secret)) {
      throw new RuntimeException(sprintf('The compact prompt leaked excluded data: %s.', $secret));
    }
  }
  if (!str_contains($prompt, 'Useful component context')) {
    throw new RuntimeException('The compact prompt dropped useful page component context.');
  }

  $context_method = new ReflectionMethod($manager, 'collectPageContext');
  $context_method->setAccessible(TRUE);
  $collected_context = $context_method->invoke($manager, $node, $account, ['is_layout_builder_context' => TRUE]);
  $collected_context_characters = strlen((string) json_encode($collected_context));
  $collected_prompt = $prompt_method->invoke($manager, 'Build one useful block.', $collected_context, $thread);
  if (strlen($collected_prompt) > 25000) {
    throw new RuntimeException('The locally collected page context was not compacted before block generation.');
  }

  $details_method = new ReflectionMethod($manager, 'buildTechnicalDetails');
  $details_method->setAccessible(TRUE);
  $details = $details_method->invoke(
    $manager,
    new InvalidArgumentException('The structured request is empty or too large. SECRET_EXCEPTION_BODY'),
    $node,
    $thread,
    [
      'site_host' => '2moody-core.ddev.site',
      'provider' => 'openai',
      'model' => 'gpt-5.6-luna',
      'is_layout_builder_context' => TRUE,
      'prefer_ai_images' => TRUE,
      'existing_media_ids' => [10, 11],
      'selected_block_references' => [['reference_id' => 'basic']],
    ],
    3,
    'Choosing a block and generating instructions...',
    'SECRET_PROMPT_BODY',
  );

  if (!preg_match('/^AI-[A-F0-9]{8}$/', $details['support_code'] ?? '')) {
    throw new RuntimeException('The diagnostic receipt did not contain a valid support code.');
  }
  if (($details['error_code'] ?? '') !== 'request_context_too_large' || ($details['stage'] ?? '') !== 'Choosing a block and generating instructions...') {
    throw new RuntimeException('The diagnostic receipt did not classify the request failure and stage.');
  }
  if (($details['attachment_count'] ?? 0) !== 3 || ($details['existing_media_count'] ?? 0) !== 2 || ($details['selected_component_count'] ?? 0) !== 1) {
    throw new RuntimeException('The diagnostic receipt did not retain safe request counts.');
  }
  foreach (['SECRET_PROMPT_BODY', 'SECRET_EXCEPTION_BODY', 'USER_ACCESS_SECRET'] as $secret) {
    if (str_contains($details['report'] ?? '', $secret)) {
      throw new RuntimeException(sprintf('The diagnostic receipt exposed sensitive request data: %s.', $secret));
    }
  }

  print json_encode([
    'compact_prompt_characters' => strlen($prompt),
    'collected_context_characters' => $collected_context_characters,
    'collected_prompt_characters' => strlen($collected_prompt),
    'oversized_access_context_excluded' => TRUE,
    'recent_metadata_excluded' => TRUE,
    'support_code_format' => TRUE,
    'safe_failure_receipt' => TRUE,
  ], JSON_PRETTY_PRINT) . PHP_EOL;
}
finally {
  if ($thread) {
    $thread->delete();
  }
  if ($node) {
    if (\Drupal::hasService('trash.manager')) {
      \Drupal::service('trash.manager')->executeInTrashContext('inactive', static function () use ($node): void {
        $node->delete();
      });
    }
    else {
      $node->delete();
    }
  }
}
