<?php

// php tests/utility-context.php (standalone, no Drupal bootstrap).
require __DIR__ . '/../../moody_ai/modules/moody_ai_base/src/HtmlSanitizer.php';
require __DIR__ . '/../../moody_ai/modules/moody_ai_base/src/PromptContext.php';
$sanitizer = new \Drupal\moody_ai_base\HtmlSanitizer();
$classes = json_decode(file_get_contents(__DIR__ . '/../utility-classes.json'), TRUE, 512, JSON_THROW_ON_ERROR);
foreach ($classes as $class) {
  $html = '<div class="' . $class . '">Text</div>';
  if ($sanitizer->sanitize($html) !== $html) {
    throw new \RuntimeException('Catalog class removed: ' . $class);
  }
}
if ($sanitizer->sanitize('<div class="ut-cols-999 md:ut-position-fixed ut-border-radius-lg mhb-scene" onclick="bad()">Text</div>') !== '<div>Text</div>') {
  throw new \RuntimeException('Unknown/retired classes or unsafe attributes survived.');
}
$context = (new \Drupal\moody_ai_base\PromptContext())->builtInSections()['design_system']['content'];
if ($context !== file_get_contents(__DIR__ . '/../utility-context.txt')) {
  throw new \RuntimeException('AI context differs from generated catalog.');
}
echo "AI exact allowlist and shared context passed.\n";
