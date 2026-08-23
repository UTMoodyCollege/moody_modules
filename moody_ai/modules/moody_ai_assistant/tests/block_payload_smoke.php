<?php

declare(strict_types=1);

use Drupal\block_content\BlockContentInterface;
use Drupal\Core\Session\AccountInterface;

$uid = (int) (getenv('MOODY_AI_TEST_UID') ?: 1);
$account = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
if (!$account instanceof AccountInterface) {
  throw new RuntimeException('The requested test account could not be loaded.');
}
\Drupal::currentUser()->setAccount($account);

$parser = \Drupal::service('moody_ai_assistant.block_parser');
$blocks = [];
try {
  $promo_blocks = $parser->createBlocksFromInstructions([
    'block_title' => 'Moody AI parser smoke — Promo List',
    'reusable' => FALSE,
    'instructions' => [[
      'block_type' => 'utexas_promo_list',
      'field_info' => [
        'field_block_pl' => [
          'type' => 'utexas_promo_list',
          'value' => [
            'headline' => 'Opportunities',
            'promo_list_items' => '<ul><li><a href="/one">Studio one</a> — First opportunity.</li><li><a href="/two">Studio two</a> — Second opportunity.</li></ul>',
          ],
        ],
      ],
    ]],
  ]);
  $blocks = array_merge($blocks, $promo_blocks);
  $promo = $promo_blocks[0] ?? NULL;
  if (!$promo instanceof BlockContentInterface) {
    throw new RuntimeException('The Promo List smoke block was not created.');
  }
  $promo_value = $promo->get('field_block_pl')->first()->getValue();
  $items = @unserialize((string) ($promo_value['promo_list_items'] ?? ''), ['allowed_classes' => FALSE]);
  if (!is_array($items) || count($items) !== 2 || ($items[0]['item']['headline'] ?? '') !== 'Studio one') {
    throw new RuntimeException('The generated Promo List value was not normalized to serialized structured items.');
  }

  $profile_blocks = $parser->createBlocksFromInstructions([
    'block_title' => 'Moody AI parser smoke — Profile Listing',
    'reusable' => FALSE,
    'instructions' => [[
      'block_type' => 'utprof_profile_listing',
      'field_info' => [
        'field_utprof_list_method' => [
          'type' => 'list_string',
          'value' => 'filter',
        ],
        'field_utprof_view_mode' => [
          'type' => 'entity_reference',
          'target_id' => 'default',
        ],
      ],
    ]],
  ]);
  $blocks = array_merge($blocks, $profile_blocks);
  $profile = $profile_blocks[0] ?? NULL;
  if (!$profile instanceof BlockContentInterface || (string) $profile->get('field_utprof_view_mode')->target_id !== 'node.full') {
    throw new RuntimeException('The generated view mode alias did not resolve to node.full.');
  }

  $anchor_blocks = $parser->createBlocksFromInstructions([
    'block_title' => 'Moody AI parser smoke — Anchor Images',
    'reusable' => FALSE,
    'instructions' => [[
      'block_type' => 'moody_anchors_block',
      'field_info' => [
        'field_anchor_image' => [
          'type' => 'moody_card',
          'value' => array_map(static fn(int $number): array => [
            'title' => $number === 1 ? ['unexpected' => 'nested value'] : 'Anchor ' . $number,
            'subtitle' => 'Cardinality test item',
            'link_uri' => '/anchor-' . $number,
            'link_title' => 'View anchor ' . $number,
          ], range(1, 5)),
        ],
      ],
    ]],
  ]);
  $blocks = array_merge($blocks, $anchor_blocks);
  $anchors = $anchor_blocks[0] ?? NULL;
  if (
    !$anchors instanceof BlockContentInterface
    || $anchors->get('field_anchor_image')->count() !== 4
    || (string) $anchors->get('field_anchor_image')->first()->title !== ''
  ) {
    throw new RuntimeException('Compound field values were not normalized safely or capped at field cardinality.');
  }

  $resource_blocks = $parser->createBlocksFromInstructions([
    'block_title' => 'Moody AI parser smoke — Resources',
    'reusable' => FALSE,
    'instructions' => [[
      'block_type' => 'utexas_resources',
      'field_info' => [
        'field_block_resources' => [
          'type' => 'utexas_resources',
          'value' => [
            'headline' => 'Resources',
            'resource_items' => '<ul><li><a href="/studio">Production studio</a></li><li><a href="/editing">Editing suite</a></li></ul>',
          ],
        ],
      ],
    ]],
  ]);
  $blocks = array_merge($blocks, $resource_blocks);
  $resources = $resource_blocks[0] ?? NULL;
  if (!$resources instanceof BlockContentInterface) {
    throw new RuntimeException('The Resources smoke block was not created.');
  }
  $resource_value = $resources->get('field_block_resources')->first()->getValue();
  $resource_items = @unserialize((string) ($resource_value['resource_items'] ?? ''), ['allowed_classes' => FALSE]);
  if (!is_array($resource_items) || count($resource_items) !== 2 || ($resource_items[0]['item']['links'][0]['uri'] ?? '') !== 'internal:/studio') {
    throw new RuntimeException('The generated Resources value was not normalized to serialized structured items.');
  }

  $focus_blocks = $parser->createBlocksFromInstructions([
    'block_title' => 'Moody AI parser smoke — Focus Areas',
    'reusable' => FALSE,
    'instructions' => [[
      'block_type' => 'moody_focus_areas',
      'field_info' => [
        'field_focus_areas' => [
          'type' => 'moody_focus_areas',
          'value' => [
            'items_title' => 'Program pillars',
            'focus_areas_items' => '<div><h3>Craft</h3><p>Build practical skills.</p></div><div><h3>Ethics</h3><p>Tell stories responsibly.</p></div>',
          ],
        ],
      ],
    ]],
  ]);
  $blocks = array_merge($blocks, $focus_blocks);
  $focus = $focus_blocks[0] ?? NULL;
  if (!$focus instanceof BlockContentInterface) {
    throw new RuntimeException('The Focus Areas smoke block was not created.');
  }
  $focus_value = $focus->get('field_focus_areas')->first()->getValue();
  $focus_items = @unserialize((string) ($focus_value['focus_areas_items'] ?? ''), ['allowed_classes' => FALSE]);
  if (!is_array($focus_items) || count($focus_items) !== 2 || ($focus_items[0]['item']['headline'] ?? '') !== 'Craft') {
    throw new RuntimeException('The generated Focus Areas value was not normalized to serialized structured items.');
  }

  $flex_blocks = $parser->createBlocksFromInstructions([
    'block_title' => 'Moody AI parser smoke — Flex Content Area',
    'reusable' => FALSE,
    'instructions' => [[
      'block_type' => 'utexas_flex_content_area',
      'field_info' => [
        'field_block_fca' => [
          'type' => 'utexas_flex_content_area',
          'value' => [
            'headline' => 'Workshop overview',
            'copy_value' => '<p>Practical support information.</p>',
            'copy_format' => 'flex_html',
            'links' => [
              ['uri' => '/details', 'title' => 'Workshop details'],
              ['uri' => '/support', 'title' => 'Request support'],
            ],
          ],
        ],
      ],
    ]],
  ]);
  $blocks = array_merge($blocks, $flex_blocks);
  $flex = $flex_blocks[0] ?? NULL;
  if (!$flex instanceof BlockContentInterface) {
    throw new RuntimeException('The Flex Content Area smoke block was not created.');
  }
  $flex_value = $flex->get('field_block_fca')->first()->getValue();
  $flex_links = @unserialize((string) ($flex_value['links'] ?? ''), ['allowed_classes' => FALSE]);
  if (!is_array($flex_links) || count($flex_links) !== 2 || ($flex_links[1]['uri'] ?? '') !== 'internal:/support') {
    throw new RuntimeException('The generated Flex Content Area links were not normalized to serialized structured items.');
  }
  if ((int) ($flex_value['image'] ?? -1) !== 0) {
    throw new RuntimeException('The image-free Flex Content Area did not retain the formatter-safe zero sentinel.');
  }

  print json_encode([
    'promo_list_items' => count($items),
    'normalized_view_mode' => (string) $profile->get('field_utprof_view_mode')->target_id,
    'anchor_items_capped_at' => $anchors->get('field_anchor_image')->count(),
    'resource_items' => count($resource_items),
    'focus_area_items' => count($focus_items),
    'flex_content_links' => count($flex_links),
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
finally {
  foreach ($blocks as $block) {
    if ($block instanceof BlockContentInterface && !$block->isNew()) {
      $block->delete();
    }
  }
}
