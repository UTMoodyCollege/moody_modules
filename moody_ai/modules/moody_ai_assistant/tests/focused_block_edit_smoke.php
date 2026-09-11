<?php

/**
 * @file
 * Local-only: drush php:script focused_block_edit_smoke.php. No model calls.
 * Creates disposable draft fixtures and removes them in finally.
 */

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\layout_builder\Section;
use Drupal\node\Entity\Node;

$check = static function ($ok, $message): void {
  if (!$ok) {
    throw new RuntimeException($message);
  }
};
$switcher = \Drupal::service('account_switcher');
$account = \Drupal::entityTypeManager()->getStorage('user')->load(1);
$switcher->switchTo($account);
$collector = \Drupal::service('moody_ai_assistant.layout_context_collector');
$placement = \Drupal::service('moody_ai_assistant.layout_placement_manager');
$manager = \Drupal::service('moody_ai_assistant.chat_manager');
$tempstore = \Drupal::service('layout_builder.tempstore_repository');
$generator = \Drupal::service('moody_ai_assistant.instruction_generator');
$generator_property = new ReflectionProperty($manager, 'instructionGenerator');
$catalog_property = new ReflectionProperty($manager, 'blockReferenceCatalog');
$old_generator = $generator_property->getValue($manager);
$old_catalog = $catalog_property->getValue($manager);
$planner_property = new ReflectionProperty($generator, 'planner');
$old_planner = $planner_property->getValue($generator);
$node = $thread = $storage = NULL;
$blocks = [];

try {
  $node = Node::create(['type' => 'moody_standard_page', 'title' => 'Disposable focused AI edit QA', 'status' => 0, 'uid' => 1]);
  $node->save();
  $runtime = ['is_layout_builder_context' => TRUE];
  $storage = $collector->getPreferredSectionStorage($node, $runtime);
  $storage->appendSection(new Section('layout_onecol'));
  $tempstore->set($storage);
  $placements = [];
  foreach (['Target block', 'Do not touch'] as $label) {
    $block = BlockContent::create(['type' => 'basic', 'info' => $label, 'reusable' => FALSE, 'body' => ['value' => '<p>' . $label . '</p>', 'format' => 'flex_html']]);
    $block->save();
    $blocks[] = $block;
    $placements[] = $placement->placeBlock($node, $block, $runtime);
  }
  $runtime['edit_component_uuid'] = $placements[0]['component_uuid'];
  $runtime['selected_block_references'] = [['uuid' => $placements[1]['component_uuid'], 'block_type' => 'invented', 'label' => 'Untrusted selection', 'selection_mode' => 'edit']];
  $context = $collector->collectBlockEditContext($node, $runtime, $account);
  $check(count($context['existing_components']) === 1, 'Other page blocks leaked into focused context.');
  $check($context['selected_existing_block_references'][0]['uuid'] === $placements[0]['component_uuid'], 'Client selection overrode scope.');
  $check(!isset($context['available_block_references']), 'Full browser catalog leaked into focused context.');
  foreach (['not-a-uuid', \Drupal::service('uuid')->generate()] as $invalid) {
    try {
      $collector->collectBlockEditContext($node, ['edit_component_uuid' => $invalid] + $runtime, $account);
      throw new RuntimeException('Invalid/missing target accepted.');
    }
    catch (InvalidArgumentException $expected) {
    }
  }
  try {
    $collector->collectBlockEditContext($node, $runtime, new AnonymousUserSession());
    throw new RuntimeException('Unauthorized edit accepted.');
  }
  catch (InvalidArgumentException $expected) {
  }

  // Exercise real schema preparation with a capturing, network-free planner.
  $planner = new class {
    public array $data = [];
    public function createBlockPayload($prompt, $plan, $data, $context, $callback = NULL) {
      $this->data = $data;
      return ['instructions' => [['block_type' => 'basic', 'field_info' => []]]];
    }
  };
  $planner_property->setValue($generator, $planner);
  $generator->generateForExistingBlock('Change the heading.', 'basic', ['field_info' => ['body' => ['value' => 'Original']]]);
  $check(array_keys($planner->data) === ['content_blocks'] && array_keys($planner->data['content_blocks']) === ['basic'], 'Editing sent unrelated schemas.');
  $check(!empty($planner->data['content_blocks']['basic']['fields']['body']), 'Body schema missing.');
  $planner_property->setValue($generator, $old_planner);

  $fake = new class {
    public int $calls = 0;
    public array $context = [];
    public $duringGeneration = NULL;
    public function generateForExistingBlock($message, $type, $existing, $context, $callback = NULL) {
      $this->calls++;
      $this->context = $context;
      if ($this->duringGeneration) {
        ($this->duringGeneration)();
      }
      return ['instructions' => [['block_type' => $type, 'field_info' => ['body' => ['value' => '<p>Focused edit complete. ' . $this->calls . '</p>', 'format' => 'flex_html']]]]];
    }
  };
  $generator_property->setValue($manager, $fake);
  $catalog_property->setValue($manager, new class {
    public function getAvailableReferences() {
      throw new RuntimeException('Focused edit loaded the page-wide catalog.');
    }
  });
  $thread = $manager->getThread($node, $account, TRUE);
  $execute = new ReflectionMethod($manager, 'executeUserMessageStream');
  $events = [];
  $emit = static function ($event, $data) use (&$events): void { $events[] = [$event, $data]; };
  $assets = [['type' => 'reference', 'text' => 'Approved reference text']];
  $execute->invoke($manager, $node, $account, $thread, 'Make five pages instead.', $emit, $runtime, $assets);
  $check($fake->calls === 1, 'Directed edit did not use exactly one generator call.');
  $check($fake->context['uploaded_assets'] === $assets, 'Reference attachments were lost.');
  $after = $collector->collectBlockEditContext($node, $runtime, $account);
  $updated = \Drupal::entityTypeManager()->getStorage('block_content')->loadRevision($after['existing_components'][0]['block_revision_id']);
  $check(str_contains($updated->get('body')->value, 'Focused edit complete.'), 'Target was not updated.');
  $all = $collector->collectEntityContext($node, ['is_layout_builder_context' => TRUE]);
  $other = array_values(array_filter($all['existing_components'], static fn ($c) => $c['uuid'] === $placements[1]['component_uuid']))[0];
  $check($other['block_revision_id'] === (int) $blocks[1]->getRevisionId(), 'Another block changed.');
  $saved = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
  $check($saved->get('layout_builder__layout')->isEmpty(), 'AI published the draft layout.');
  $check(end($events)[1]['preserve_page'] === TRUE, 'In-place completion was lost.');

  // Simulate another request editing the selected placement while AI runs.
  $fake->duringGeneration = static function () use ($collector, $node, $runtime, $tempstore): void {
    $fresh = $collector->getResolvedSectionStorage($node, $runtime);
    foreach ($fresh->getSections() as $section) {
      foreach ($section->getComponents() as $uuid => $component) {
        if ($uuid === $runtime['edit_component_uuid']) {
          $config = $component->get('configuration');
          $config['label'] = 'Concurrent editor label';
          $component->setConfiguration($config);
        }
      }
    }
    $tempstore->set($fresh);
  };
  try {
    $execute->invoke($manager, $node, $account, $thread, 'Change again.', $emit, $runtime);
    throw new RuntimeException('Concurrent target update was overwritten.');
  }
  catch (InvalidArgumentException $expected) {
    $check(str_contains($expected->getMessage(), 'changed while AI'), 'Unexpected concurrency error.');
  }
  print "PASS: one-block scope, access, missing target, compact schema, one generator call, attachments, draft-only placement, unrelated block preservation, and stale-target rejection.\n";
}
finally {
  $generator_property->setValue($manager, $old_generator);
  $catalog_property->setValue($manager, $old_catalog);
  $planner_property->setValue($generator, $old_planner);
  if ($storage) { $tempstore->delete($storage); }
  if ($thread) { $thread->delete(); }
  if ($node) { $node->delete(); }
  foreach ($blocks as $block) {
    if ($existing = \Drupal::entityTypeManager()->getStorage('block_content')->load($block->id())) { $existing->delete(); }
  }
  $switcher->switchBack();
}
