<?php

use Drupal\moody_hero_builder\HeroConfiguration as Hero;
use Drupal\node\Entity\Node;

function hero_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(\Drupal::entityTypeManager()->getStorage('user')->load(1));
$node = NULL; $storage = NULL;
try {
  $node = Node::create(['type' => 'moody_standard_page', 'title' => 'Disposable Hero AI QA', 'status' => 0, 'uid' => 1]);
  $node->save();
  $runtime = ['is_layout_builder_context' => TRUE];
  $collector = \Drupal::service('moody_ai_assistant.layout_context_collector');
  $storage = $collector->getPreferredSectionStorage($node, $runtime);
  $saved = $node->get('layout_builder__layout')->getValue();
  $manager = \Drupal::service('moody_ai_assistant.layout_placement_manager');
  $configuration = ['hero' => Hero::defaults(), 'image' => 0];
  $configuration['hero']['layout'] = 'text';
  $planner = \Drupal::service('moody_ai_assistant.planner');
  $data = \Drupal::service('moody_ai_assistant.block_data_collector')->collectBlockData();
  $types = (new ReflectionMethod($planner, 'getStructuredPlanBlockTypes'))->invoke($planner, 'Create Hero Builder', ['available_block_references' => [['plugin_id' => 'moody_hero_builder', 'is_available_block' => TRUE]]], $data);
  hero_check(in_array('moody_hero_builder', $types, TRUE), 'Hero Builder absent from automated types');
  if (getenv('MOODY_AI_HERO_PROVIDER_CHECK') === '1') {
    $configuration = $planner->composeHeroBuilder('Create a text-only hero titled Speech, Language, and Hearing Sciences News, dark palette, a large serif heading, and a secondary button labelled Explore news linking to /news. No image or video.', ['allowed_image_ids' => [], 'prefer_ai_images' => FALSE]);
    hero_check($configuration['hero']['layout'] === 'text' && $configuration['hero']['scheme'] === 'dark', 'Provider missed layout or palette');
    hero_check(count(array_filter($configuration['hero']['elements'], fn($e) => $e['type'] === 'button' && $e['url'] === '/news')) === 1, 'Provider missed button');
    echo "PASS: real provider composed Hero Builder using its full contract.\n";
  }
  $placement = $manager->saveHeroBuilder($node, $configuration, $runtime);
  $runtime['edit_component_uuid'] = $placement['component_uuid'];
  $focused = $collector->collectBlockEditContext($node, $runtime, \Drupal::currentUser());
  $original = $focused['existing_components'][0];
  hero_check($original['plugin_id'] === 'moody_hero_builder' && $original['hero_configuration']['hero'] == $configuration['hero'], 'Focused edit lost composition');
  $configuration['hero']['scheme'] = 'orange';
  $manager->saveHeroBuilder($node, $configuration, $runtime, [], $placement['component_uuid'], $original['configuration_hash']);
  try { $manager->saveHeroBuilder($node, $configuration, $runtime, [], $placement['component_uuid'], $original['configuration_hash']); throw new LogicException('Stale edit accepted'); }
  catch (RuntimeException $expected) {}
  $configuration['hero']['background'] = 'video';
  $configuration['hero']['layout'] = 'overlay';
  $configuration['hero']['video_url'] = 'https://vimeo.com/123456789';
  try { $manager->saveHeroBuilder($node, $configuration, $runtime); throw new LogicException('Video without poster accepted'); }
  catch (InvalidArgumentException $expected) {}
  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
  hero_check(Node::load($node->id())->get('layout_builder__layout')->getValue() === $saved, 'Saved page changed');
  echo "PASS: Hero Builder create/edit draft, stale-write rejection, poster requirement, saved page unchanged.\n";
}
finally {
  if ($storage) { \Drupal::service('layout_builder.tempstore_repository')->delete($storage); }
  if ($node) { $node->delete(); }
  $switcher->switchBack();
}
