<?php

use Drupal\block_content\Entity\BlockContent;
use Drupal\moody_ai_assistant\Controller\AIChatStreamController;
use Drupal\node\Entity\Node;
use Drupal\layout_builder\Section;
use Symfony\Component\HttpFoundation\Request;

$account = \Drupal::entityTypeManager()->getStorage('user')->load(1);
if (!$account) {
  throw new RuntimeException('User 1 is required for the Layout Builder draft smoke test.');
}
\Drupal::currentUser()->setAccount($account);

$node = NULL;
$block = NULL;
$tempstore = \Drupal::service('layout_builder.tempstore_repository');
$collector = \Drupal::service('moody_ai_assistant.layout_context_collector');
$placement_manager = \Drupal::service('moody_ai_assistant.layout_placement_manager');
$runtime_context = ['is_layout_builder_context' => TRUE];

try {
  $node = Node::create([
    'type' => 'moody_standard_page',
    'title' => 'Moody AI streaming draft smoke test',
    'status' => 0,
    'uid' => 1,
  ]);
  $node->save();

  $base_storage = $collector->getPreferredSectionStorage($node, $runtime_context);
  if (!$base_storage) {
    throw new RuntimeException('Could not resolve Layout Builder storage for the disposable page.');
  }
  $tempstore->delete($base_storage);
  $editable_method = new ReflectionMethod($placement_manager, 'getEditableSectionStorage');
  $editable_method->setAccessible(TRUE);
  [, $uses_draft_without_existing_tempstore] = $editable_method->invoke($placement_manager, $node, $runtime_context);
  $saved_count_before = $node->get('layout_builder__layout')->count();
  while ($base_storage->count() < 2) {
    $base_storage->appendSection(new Section('layout_onecol'));
  }
  $tempstore->set($base_storage);

  $block = BlockContent::create([
    'type' => 'basic',
    'info' => 'Moody AI streaming draft smoke block',
    'body' => [
      'value' => '<p>Initial streaming draft content.</p>',
      'format' => 'flex_html',
    ],
  ]);
  $block->setReusable(FALSE);
  $block->save();

  $placement = $placement_manager->placeBlock($node, $block, $runtime_context, [
    'section_delta' => 1,
    'region' => 'content',
  ]);

  $section_method = new ReflectionMethod($placement_manager, 'resolveSectionDelta');
  $section_method->setAccessible(TRUE);
  $region_method = new ReflectionMethod($placement_manager, 'resolveRegion');
  $region_method->setAccessible(TRUE);
  $invalid_section_fallback = $section_method->invoke($placement_manager, $base_storage, ['section_delta' => 99]);
  $invalid_region_fallback = $region_method->invoke($placement_manager, $base_storage->getSection(1), ['region' => 'not-a-region']);

  $reloaded = Node::load($node->id());
  $saved_count_after_create = $reloaded->get('layout_builder__layout')->count();
  $working_storage = $collector->getResolvedSectionStorage($reloaded, $runtime_context);
  $working_component = $working_storage->getSection($placement['section_delta'])->getComponent($placement['component_uuid']);

  $block = \Drupal::service('moody_ai_assistant.block_parser')->updateBlockFromInstructions($block, [
    'instructions' => [[
      'block_type' => 'basic',
      'field_info' => [
        'body' => [
          'type' => 'text_with_summary',
          'value' => '<p>Updated streaming draft content.</p>',
          'format' => 'flex_html',
        ],
      ],
    ]],
  ], $node);
  $updated_placement = $placement_manager->updateInlineBlockComponent($reloaded, $placement['component_uuid'], $block, $runtime_context);

  $working_storage = $collector->getResolvedSectionStorage($reloaded, $runtime_context);
  $updated_component = $working_storage->getSection($updated_placement['section_delta'])->getComponent($updated_placement['component_uuid']);
  $saved_count_after_edit = Node::load($node->id())->get('layout_builder__layout')->count();

  $request_stack = \Drupal::service('request_stack');
  $pushed_request = FALSE;
  if (!$request_stack->getCurrentRequest()) {
    $request_stack->push(Request::create('/moody-ai/assistant/stream', 'POST'));
    $pushed_request = TRUE;
  }
  try {
    $controller = AIChatStreamController::create(\Drupal::getContainer());
    $render_method = new ReflectionMethod($controller, 'buildLayoutCommands');
    $render_method->setAccessible(TRUE);
    $layout_commands = $render_method->invoke($controller, $reloaded, $runtime_context);
  }
  finally {
    if ($pushed_request) {
      $request_stack->pop();
    }
  }
  $replace_command = array_values(array_filter($layout_commands, static fn (array $command): bool => ($command['command'] ?? '') === 'insert' && ($command['selector'] ?? '') === '#layout-builder'))[0] ?? [];

  if (!$tempstore->has($base_storage)) {
    throw new RuntimeException('AI placement did not create a Layout Builder tempstore draft.');
  }
  if (!$uses_draft_without_existing_tempstore) {
    throw new RuntimeException('A fresh Layout Builder assistant request would bypass the draft workspace.');
  }
  if ($saved_count_before !== $saved_count_after_create || $saved_count_before !== $saved_count_after_edit) {
    throw new RuntimeException('AI draft work changed the saved page layout before Save layout.');
  }
  if ($placement['section_delta'] !== 1 || $placement['region'] !== 'content') {
    throw new RuntimeException('AI placement did not honor a valid existing section and region.');
  }
  if ($invalid_section_fallback !== 0 || $invalid_region_fallback !== 'content') {
    throw new RuntimeException('Invalid AI placement targets did not fall back safely.');
  }
  if ((int) $working_component->get('configuration')['block_revision_id'] < 1) {
    throw new RuntimeException('The created draft component did not reference a block revision.');
  }
  if ((int) $updated_component->get('configuration')['block_revision_id'] !== (int) $block->getRevisionId()) {
    throw new RuntimeException('The streamed edit did not update the draft component revision.');
  }
  if (empty($replace_command['data']) || !str_contains((string) $replace_command['data'], $placement['component_uuid'])) {
    throw new RuntimeException('The streamed Layout Builder AJAX replacement did not contain the draft component.');
  }

  print json_encode([
    'draft_only_create' => TRUE,
    'draft_only_edit' => TRUE,
    'fresh_layout_uses_draft' => TRUE,
    'requested_section_target' => $placement['section_delta'] . ':' . $placement['region'],
    'invalid_target_fallback' => $invalid_section_fallback . ':' . $invalid_region_fallback,
    'component_uuid_preserved' => $placement['component_uuid'] === $updated_placement['component_uuid'],
    'layout_ajax_replace' => TRUE,
  ], JSON_PRETTY_PRINT) . PHP_EOL;
}
finally {
  if ($node) {
    $base_storage = $collector->getPreferredSectionStorage($node, $runtime_context);
    if ($base_storage) {
      $tempstore->delete($base_storage);
    }
    if (\Drupal::hasService('trash.manager')) {
      \Drupal::service('trash.manager')->executeInTrashContext('inactive', static function () use ($node): void {
        $node->delete();
      });
    }
    else {
      $node->delete();
    }
  }
  if ($block) {
    $block->delete();
  }
}
