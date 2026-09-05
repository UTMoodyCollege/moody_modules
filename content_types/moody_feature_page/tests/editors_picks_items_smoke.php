<?php

require_once dirname(__DIR__) . '/moody_feature_page.module';

$rows = [
  [
    'weight' => 10,
    'details' => [
      'type' => 'custom',
      'custom' => [
        'image' => '42',
        'subhead_1' => ' Research ',
        'subhead_2' => ' Sept. 2, 2026 ',
        'title' => ' External story ',
        'body' => ' Summary ',
        'url' => ' https://example.com/story ',
      ],
    ],
  ],
  [
    'weight' => -10,
    'details' => [
      'type' => 'node',
      'node' => ['target_id' => '7700'],
    ],
  ],
];

$items = _moody_feature_page_normalize_editors_picks_items($rows);
if ($items !== [
  ['type' => 'node', 'node' => '7700'],
  [
    'type' => 'custom',
    'image' => '42',
    'subhead_1' => 'Research',
    'subhead_2' => 'Sept. 2, 2026',
    'title' => 'External story',
    'body' => 'Summary',
    'url' => 'https://example.com/story',
  ],
]) {
  throw new RuntimeException('Mixed Editor\'s Picks rows were not normalized in weight order.');
}

$empty = _moody_feature_page_normalize_editors_picks_items([
  ['details' => ['type' => 'node', 'node' => '']],
  ['details' => ['type' => 'custom', 'custom' => []]],
]);
if ($empty !== []) {
  throw new RuntimeException('Empty Editor\'s Picks rows were saved.');
}

$rebuild = _moody_feature_page_normalize_editors_picks_items([
  [
    'details' => [
      'type' => 'custom',
      'custom' => ['image' => ['media_library_selection' => '42']],
    ],
  ],
  ['details' => ['type' => 'node', 'node' => 'Example Feature (7700)']],
], TRUE);
if (($rebuild[0]['image'] ?? '') !== '42' || ($rebuild[1]['node'] ?? '') !== '7700') {
  throw new RuntimeException('Raw AJAX media and autocomplete values were not preserved.');
}

print "Editor's Picks item smoke test passed.\n";
