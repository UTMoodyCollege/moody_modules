<?php

// Run with local Drush php:script. Uses no AI API; removes its draft fixture.
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\moody_ai_assistant\Service\ContentLookup;
use Drupal\moody_ai_assistant\Service\AssistantPlanner;
use Drupal\node\Entity\Node;

function discovery_check($condition, $message) {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

final class DiscoveryPlanner extends AssistantPlanner {
  public array $answer = [];
  public function __construct() { $this->componentContext = ''; }
  protected function requestChatCompletion(array $messages, $temperature = 0.7, ?callable $stream_callback = NULL) {
    return ['choices' => [['message' => ['content' => json_encode($this->answer)]]]];
  }
}

$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(\Drupal::entityTypeManager()->getStorage('user')->load(1));
$node = NULL;
$storage = NULL;
try {
  $lookup = new ContentLookup(\Drupal::entityTypeManager());
  $query = ['entity_type' => 'node', 'bundle' => 'moody_feature_page', 'text' => '', 'limit' => 6];
  $results = $lookup->lookup([$query], \Drupal::currentUser());
  $ids = array_column($results[0]['items'], 'id');
  $expected = array_values(\Drupal::entityTypeManager()->getStorage('node')->getQuery()->accessCheck(TRUE)->condition('type', 'moody_feature_page')->condition('status', 1)->sort('created', 'DESC')->sort('nid', 'DESC')->range(0, 6)->execute());
  discovery_check($ids && $ids == $expected, 'Latest-six discovery differs from the published query.');
  foreach ($results[0]['items'] as $item) {
    discovery_check(!array_diff(array_keys($item), ['entity_type', 'id', 'bundle', 'label', 'url', 'created']), 'Unexpected content fields exposed.');
  }
  foreach ([['entity_type' => 'file'], array_replace($query, ['limit' => 1000]), $query + ['status' => 0]] as $invalid) {
    try { $lookup->lookup([$invalid], \Drupal::currentUser()); throw new LogicException('Invalid query accepted.'); }
    catch (InvalidArgumentException $expected_error) {}
  }
  $switcher->switchTo(new AnonymousUserSession());
  try {
    try { $lookup->lookup([$query], \Drupal::currentUser()); throw new LogicException('Anonymous lookup accepted.'); }
    catch (RuntimeException $expected_error) {}
  }
  finally { $switcher->switchBack(); }

  $restricted = new class(['uid' => 999999, 'roles' => []]) extends \Drupal\Core\Session\UserSession {
    public function hasPermission(string $permission) { return $permission === 'use moody ai assistant'; }
  };
  $switcher->switchTo($restricted);
  try {
    discovery_check($lookup->lookup([$query], \Drupal::currentUser())[0]['items'] === [], 'Assistant permission bypassed node view access.');
    discovery_check(!empty($lookup->lookup([['entity_type' => 'user', 'bundle' => '', 'text' => '', 'limit' => 6]], \Drupal::currentUser())[0]['unavailable']), 'User directory permission was bypassed.');
  }
  finally { $switcher->switchBack(); }

  $plugin = 'moody_feature_page_feature_pages_editors_picks';
  $planner = new DiscoveryPlanner();
  $planner->answer = ['mode' => 'single', 'blocks' => [['selected_block_type' => $plugin, 'node_ids' => $ids]]];
  $context = ['available_block_references' => [['plugin_id' => $plugin, 'is_available_block' => TRUE]], 'content_lookup_results' => $results];
  $plan = $planner->planStructuredBuild('Add the latest six Feature Pages in Editors Picks.', $context, [], [], ['content_blocks' => ['basic' => []]]);
  discovery_check($plan['mode'] === 'multi' && $plan['blocks'][0]['node_ids'] === $ids, 'Plugin plan lost its verified references or execution mode.');

  if (getenv('MOODY_AI_DISCOVERY_PROVIDER_CHECK') === '1') {
    $real_planner = \Drupal::service('moody_ai_assistant.planner');
    $message = 'Build a hero that says Speech, Language, and Hearing Sciences News, then an Editors Picks block pointing to the last 6 most recent published Feature Pages.';
    $real_results = $lookup->lookup($real_planner->planContentLookups($message, $lookup->catalog()), \Drupal::currentUser());
    discovery_check(($real_results[0]['items'] ?? []) === $results[0]['items'], 'Provider did not discover the latest six Feature Pages.');
    $data = \Drupal::service('moody_ai_assistant.block_data_collector')->collectBlockData();
    $context['content_lookup_results'] = $real_results;
    $context['available_block_references'][] = ['plugin_id' => 'inline_block:moody_hero', 'block_type' => 'moody_hero', 'is_available_block' => TRUE];
    $real_plan = $real_planner->planStructuredBuild($message, $context, [], [], $data);
    discovery_check(count($real_plan['blocks']) === 2 && $real_plan['blocks'][0]['selected_block_type'] === 'moody_hero' && $real_plan['blocks'][1]['selected_block_type'] === $plugin && array_map('intval', $real_plan['blocks'][1]['node_ids']) === $ids, 'Provider did not plan hero plus correctly ordered Editors Picks.');
    echo "PASS: real provider planned hero plus latest-six Editors Picks.\n";
  }

  $node = Node::create(['type' => 'moody_standard_page', 'title' => 'Disposable AI discovery QA', 'status' => 0, 'uid' => 1]);
  $node->save();
  $runtime = ['is_layout_builder_context' => TRUE];
  $collector = \Drupal::service('moody_ai_assistant.layout_context_collector');
  $storage = $collector->getPreferredSectionStorage($node, $runtime);
  $before = $node->get('layout_builder__layout')->getValue();
  $manager = \Drupal::service('moody_ai_assistant.layout_placement_manager');
  $placement = $manager->placeEditorsPicks($node, $ids, $results, $runtime);
  $draft = $collector->getResolvedSectionStorage($node, $runtime);
  $config = $draft->getSection($placement['section_delta'])->getComponent($placement['component_uuid'])->toArray()['configuration'];
  discovery_check($config['id'] === $plugin && $config['selected_nodes'] === $ids, 'Draft plugin configuration is incorrect.');
  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
  discovery_check(Node::load($node->id())->get('layout_builder__layout')->getValue() === $before, 'Placement changed the saved page.');
  try { $manager->placeEditorsPicks($node, [$node->id()], $results, $runtime); throw new LogicException('Undiscovered ID accepted.'); }
  catch (InvalidArgumentException $expected_error) {}
  echo "PASS: bounded, published, permission-checked discovery and draft-only Editors Picks placement.\n";
}
finally {
  if ($storage) { \Drupal::service('layout_builder.tempstore_repository')->delete($storage); }
  if ($node) { $node->delete(); }
  $switcher->switchBack();
}
