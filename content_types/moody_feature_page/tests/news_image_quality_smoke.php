<?php

require_once dirname(__DIR__) . '/moody_feature_page.install';

$displays = [
  'legacy' => [
    'display_options' => [
      'fields' => [
        'field_thumbnail_image_for_summar' => [
          'settings' => ['image_style' => 'moody_image_style_363w_x_190h'],
        ],
      ],
    ],
  ],
  'custom' => [
    'display_options' => [
      'fields' => [
        'field_thumbnail_image_for_summar' => [
          'settings' => ['image_style' => 'custom_image_style'],
        ],
      ],
    ],
  ],
  'inherited' => ['display_options' => []],
];

$upgraded = _moody_feature_page_upgrade_news_image_styles($displays);

if ($upgraded['legacy']['display_options']['fields']['field_thumbnail_image_for_summar']['settings']['image_style'] !== 'moody_image_style_960w_x_540h') {
  throw new RuntimeException('The legacy news image style was not upgraded.');
}

if ($upgraded['custom']['display_options']['fields']['field_thumbnail_image_for_summar']['settings']['image_style'] !== 'custom_image_style') {
  throw new RuntimeException('A custom news image style was overwritten.');
}

if ($upgraded['inherited'] !== $displays['inherited']) {
  throw new RuntimeException('An inherited display was changed.');
}

print "News image quality smoke test passed.\n";
