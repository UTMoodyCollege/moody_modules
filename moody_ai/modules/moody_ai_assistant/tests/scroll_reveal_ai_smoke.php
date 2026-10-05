<?php

use Drupal\moody_ai_assistant\Service\AssistantPlanner;
use Drupal\moody_scroll_reveal_media\TextLayout;
use Drupal\node\Entity\Node;

function reveal_check($ok, $message) { if (!$ok) { throw new LogicException($message); } }
function reveal_reject(callable $call): void {
  try { $call(); } catch (InvalidArgumentException|RuntimeException $error) { return; }
  throw new LogicException('Expected invalid or forbidden composition to fail.');
}
$stub = new class extends AssistantPlanner {
  public array $payload = [];
  public function __construct() {}
  protected function requestChatCompletion(array $messages, $temperature = 0.7, ?callable $stream_callback = NULL) {
    reveal_check(str_contains($messages[0]['content'], 'mobile/tablet/desktop'), 'Missing responsive contract');
    return ['choices' => [['message' => ['content' => json_encode($this->payload)]]]];
  }
};
$configuration = ['headline' => 'Story', 'animation_style' => 'fade', 'slides' => [[
  'media' => 0, 'video_url' => '', 'video_autoplay' => FALSE, 'eyebrow' => '', 'title' => 'Heading',
  'body' => ['value' => '<p>Subheading</p>', 'format' => 'flex_html'],
  'title_display' => 'overlay', 'title_position' => 'center', 'direction' => 'right',
  'text_layout' => TextLayout::normalize(['enabled' => TRUE]),
]]];
$stub->payload = $configuration;
$stub->composeScrollReveal('Build', ['allowed_formats' => ['flex_html']]);
$stub->payload['slides'][0]['media'] = 999999999;
reveal_reject(fn() => $stub->composeScrollReveal('Build', ['allowed_formats' => ['flex_html']]));
$stub->payload = $configuration;
$stub->payload['slides'][0]['text_layout']['desktop']['body']['width'] = 101;
reveal_reject(fn() => $stub->composeScrollReveal('Build', ['allowed_formats' => ['flex_html']]));
$stub->payload = $configuration;
$stub->payload['slides'][0]['video_url'] = 'https://vimeo.com/123456789';
reveal_reject(fn() => $stub->composeScrollReveal('Build', ['allowed_formats' => ['flex_html']]));
$stub->payload = $configuration;
reveal_reject(fn() => $stub->composeScrollReveal('Build', ['allowed_formats' => []]));

$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(\Drupal::entityTypeManager()->getStorage('user')->load(1));
$node = NULL; $storage = NULL;
try {
  $node = Node::create(['type' => 'moody_standard_page', 'title' => 'Disposable Scroll Reveal AI QA', 'status' => 0, 'uid' => 1]);
  $node->save();
  $runtime = ['is_layout_builder_context' => TRUE];
  $collector = \Drupal::service('moody_ai_assistant.layout_context_collector');
  $storage = $collector->getPreferredSectionStorage($node, $runtime);
  $saved = $node->get('layout_builder__layout')->getValue();
  $manager = \Drupal::service('moody_ai_assistant.layout_placement_manager');
  $planner = \Drupal::service('moody_ai_assistant.planner');
  if (getenv('MOODY_AI_REVEAL_PROVIDER_CHECK') === '1') {
    $configuration = $planner->composeScrollReveal('Create one text-only overlay slide. Heading "Heading", subheading "Subheading". Independent mobile/tablet/desktop layout enabled. Heading width 70%, subheading width 60% on mobile, 50% on tablet, 40% on desktop. Heading at x=10 y=20 size=32; subheading at x=10 y=55 size=20, both left aligned. No media, video or autoplay. Empty section headline; fade animation and right reveal direction.', ['allowed_media_ids' => [], 'allowed_formats' => ['flex_html'], 'supplied_video_urls' => []]);
    foreach (['mobile' => 60, 'tablet' => 50, 'desktop' => 40] as $device => $width) {
      reveal_check($configuration['slides'][0]['text_layout'][$device]['body']['width'] == $width, 'Provider missed independent widths');
    }
    $edited = $planner->composeScrollReveal('Change only the desktop subheading width to 45%. Preserve every other value and slide exactly.', ['allowed_media_ids' => [], 'allowed_formats' => ['flex_html'], 'supplied_video_urls' => []], $configuration);
    $expected = $configuration; $expected['slides'][0]['text_layout']['desktop']['body']['width'] = 45;
    reveal_check($edited == $expected, 'Focused provider edit changed unrelated settings');
    $configuration = $edited;
    echo "PASS: real provider composed responsive Scroll Reveal and preserved unrelated settings on focused edit.\n";
  }
  $data = \Drupal::service('moody_ai_assistant.block_data_collector')->collectBlockData();
  $types = (new ReflectionMethod($planner, 'getStructuredPlanBlockTypes'))->invoke($planner, 'Create scroll reveal', ['available_block_references' => [['plugin_id' => 'moody_scroll_reveal_media_block', 'is_available_block' => TRUE]]], $data);
  reveal_check(in_array('moody_scroll_reveal_media_block', $types, TRUE), 'Scroll Reveal missing from automatic planning');
  $placement = $manager->saveBuilder('moody_scroll_reveal_media_block', $node, $configuration, $runtime);
  $runtime['edit_component_uuid'] = $placement['component_uuid'];
  $original = $collector->collectBlockEditContext($node, $runtime, \Drupal::currentUser())['existing_components'][0];
  reveal_check($original['scroll_reveal_configuration'] == $configuration, 'Focused edit lost configuration');
  $inspected = \Drupal::service('moody_ai_assistant.block_inspector')->getBlockContents($node, [$placement['component_uuid']], $runtime);
  reveal_check($inspected[0]['content'] == $configuration, 'Inspector lost configuration');
  $configuration['slides'][0]['text_layout']['tablet']['body']['width'] = $configuration['slides'][0]['text_layout']['tablet']['body']['width'] == 50 ? 60 : 50;
  $manager->saveBuilder('moody_scroll_reveal_media_block', $node, $configuration, $runtime, [], $placement['component_uuid'], $original['configuration_hash']);
  reveal_reject(fn() => $manager->saveBuilder('moody_scroll_reveal_media_block', $node, $configuration, $runtime, [], $placement['component_uuid'], $original['configuration_hash']));
  $invalid = $configuration; $invalid['slides'][0]['media'] = 999999999;
  reveal_reject(fn() => $manager->saveBuilder('moody_scroll_reveal_media_block', $node, $invalid, $runtime));
  $invalid = $configuration; $invalid['slides'][0]['body']['format'] = 'missing_format';
  reveal_reject(fn() => $manager->saveBuilder('moody_scroll_reveal_media_block', $node, $invalid, $runtime));
  $switcher->switchTo(new \Drupal\Core\Session\AnonymousUserSession());
  try { reveal_reject(fn() => $manager->saveBuilder('moody_scroll_reveal_media_block', $node, $configuration, $runtime)); }
  finally { $switcher->switchBack(); }
  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
  reveal_check(Node::load($node->id())->get('layout_builder__layout')->getValue() === $saved, 'Saved page changed');
  echo "PASS: Scroll Reveal AI contract, lookup restrictions, responsive validation, draft create/edit, inspection, stale edits, media/format access and permission denial.\n";
}
finally {
  if ($storage) { \Drupal::service('layout_builder.tempstore_repository')->delete($storage); }
  if ($node) { $node->delete(); }
  $switcher->switchBack();
}
