<?php

use Drupal\moody_card_builder\CardConfiguration as Cards;
use Drupal\moody_ai_assistant\Service\AssistantPlanner;
use Drupal\node\Entity\Node;

function card_check($ok, $message) { if (!$ok) { throw new LogicException($message); } }
function card_reject(callable $call, string $exception): void {
  try { $call(); }
  catch (Throwable $error) { if ($error instanceof $exception) { return; } throw $error; }
  throw new LogicException('Expected rejection: ' . $exception);
}

// Exercise the actual parser and validation without a paid provider call.
$stub = new class extends AssistantPlanner {
  public array $payload = [];
  public function __construct() {}
  protected function requestChatCompletion(array $messages, $temperature = 0.7, ?callable $stream_callback = NULL) {
    card_check(str_contains($messages[0]['content'], 'focal_x'), 'Incomplete model contract');
    card_check(!str_contains($messages[1]['content'], '\\/'), 'Escaped URL slashes confuse exact preservation');
    return ['choices' => [['message' => ['content' => json_encode($this->payload)]]]];
  }
};
$stub->payload = ['collection' => Cards::defaults(), 'image_prompts' => []];
$configuration = $stub->composeCardBuilder('Build a card', ['allowed_image_ids' => [], 'prefer_ai_images' => FALSE]);
$configuration['images'] = [];
$stub->composeCardBuilder('Keep links', [], ['collection' => Cards::defaults()]);
$stub->payload['collection']['cards'][0]['image'] = 999999;
card_reject(fn() => $stub->composeCardBuilder('Build', []), InvalidArgumentException::class);
$stub->payload['collection'] = Cards::defaults();
$stub->payload['image_prompts'] = ['card-1' => 'An image'];
card_reject(fn() => $stub->composeCardBuilder('Build', []), InvalidArgumentException::class);
$stub->payload['image_prompts'] = ['missing-card' => 'An image'];
card_reject(fn() => $stub->composeCardBuilder('Build', ['prefer_ai_images' => TRUE]), InvalidArgumentException::class);
$stub->payload['image_prompts'] = [];
$stub->payload['collection']['cards'][0]['rows'][0]['cells'][0]['url'] = 'javascript:alert(1)';
$stub->payload['collection']['cards'][0]['rows'][0]['cells'][0]['type'] = 'button';
card_reject(fn() => $stub->composeCardBuilder('Build', []), InvalidArgumentException::class);

$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(\Drupal::entityTypeManager()->getStorage('user')->load(1));
$node = NULL; $storage = NULL;
try {
  $node = Node::create(['type' => 'moody_standard_page', 'title' => 'Disposable Card AI QA', 'status' => 0, 'uid' => 1]);
  $node->save();
  $runtime = ['is_layout_builder_context' => TRUE];
  $collector = \Drupal::service('moody_ai_assistant.layout_context_collector');
  $storage = $collector->getPreferredSectionStorage($node, $runtime);
  $saved = $node->get('layout_builder__layout')->getValue();
  $manager = \Drupal::service('moody_ai_assistant.layout_placement_manager');
  $planner = \Drupal::service('moody_ai_assistant.planner');
  $data = \Drupal::service('moody_ai_assistant.block_data_collector')->collectBlockData();
  $types = (new ReflectionMethod($planner, 'getStructuredPlanBlockTypes'))->invoke($planner, 'Create cards', ['available_block_references' => [['plugin_id' => 'moody_card_builder', 'is_available_block' => TRUE]]], $data);
  card_check(in_array('moody_card_builder', $types, TRUE), 'Card Builder absent from automated types');
  if (getenv('MOODY_AI_CARD_PROVIDER_CHECK') === '1') {
    $configuration = $planner->composeCardBuilder('Create three text-only cards titled Speech, Language, and Hearing. Three desktop columns, two tablet columns, one mobile column; rounded corners. Each card has one short supporting sentence and an Explore button linking to /news, aligned right beside a left-aligned News label in a 50/50 footer row. No images.', ['allowed_image_ids' => [], 'prefer_ai_images' => FALSE]);
    $configuration['images'] = [];
    card_check(count($configuration['collection']['cards']) === 3, 'Provider missed three cards');
    card_check($configuration['collection']['columns'] === ['mobile' => 1, 'tablet' => 2, 'desktop' => 3], 'Provider missed responsive columns');
    echo "PASS: real provider composed Card Builder.\n";
  }
  $placement = $manager->saveBuilder('moody_card_builder', $node, $configuration, $runtime);
  $runtime['edit_component_uuid'] = $placement['component_uuid'];
  $focused = $collector->collectBlockEditContext($node, $runtime, \Drupal::currentUser());
  $original = $focused['existing_components'][0];
  card_check($original['plugin_id'] === 'moody_card_builder' && $original['card_configuration']['collection'] == $configuration['collection'], 'Focused edit lost composition');
  $inspector = \Drupal::service('moody_ai_assistant.block_inspector');
  $inspected = $inspector->getBlockContents($node, [$placement['component_uuid']], $runtime);
  card_check(($inspected[0]['content']['collection'] ?? NULL) == $configuration['collection'], 'Inspector lost card configuration');
  $configuration['collection']['cards'][0]['scheme'] = 'orange';
  if (getenv('MOODY_AI_CARD_PROVIDER_CHECK') === '1') {
    $chat = \Drupal::service('moody_ai_assistant.chat_manager');
    $configuration = (new ReflectionMethod($chat, 'composeBuilder'))->invoke($chat, 'moody_card_builder', 'Change only the first card palette to orange. Preserve all other content, IDs, ordering, layouts and images exactly. Do not generate images.', [], [], $original['card_configuration']);
    $expected = $original['card_configuration']['collection'];
    $expected['cards'][0]['scheme'] = 'orange';
    card_check($configuration['collection'] == $expected, 'Focused provider edit changed unrelated settings');
    echo "PASS: real provider focused card edit preserved unrelated content and settings.\n";
  }
  $manager->saveBuilder('moody_card_builder', $node, $configuration, $runtime, [], $placement['component_uuid'], $original['configuration_hash']);
  card_reject(fn() => $manager->saveBuilder('moody_card_builder', $node, $configuration, $runtime, [], $placement['component_uuid'], $original['configuration_hash']), RuntimeException::class);
  $invalid = $configuration;
  $invalid['collection']['cards'][0]['image'] = 999999999;
  $invalid['images'] = [999999999];
  card_reject(fn() => $manager->saveBuilder('moody_card_builder', $node, $invalid, $runtime), InvalidArgumentException::class);
  card_reject(fn() => $manager->saveBuilder('system_powered_by_block', $node, $configuration, $runtime), InvalidArgumentException::class);
  $switcher->switchTo(new \Drupal\Core\Session\AnonymousUserSession());
  try {
    card_reject(fn() => $manager->saveBuilder('moody_card_builder', $node, $configuration, $runtime), RuntimeException::class);
    card_reject(fn() => $collector->collectBlockEditContext($node, $runtime, \Drupal::currentUser()), InvalidArgumentException::class);
  }
  finally { $switcher->switchBack(); }
  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
  card_check(Node::load($node->id())->get('layout_builder__layout')->getValue() === $saved, 'Saved page changed');
  echo "PASS: Card AI contract, media/generation validation, create/edit draft, stale-write rejection, permission denial, saved page unchanged.\n";
}
finally {
  if ($storage) { \Drupal::service('layout_builder.tempstore_repository')->delete($storage); }
  if ($node) { $node->delete(); }
  $switcher->switchBack();
}
