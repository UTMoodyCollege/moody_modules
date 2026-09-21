<?php

require __DIR__ . '/../src/TextLayout.php';
use Drupal\moody_scroll_reveal_media\TextLayout;

function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$legacy = TextLayout::normalize([]);
check(!$legacy['enabled'], 'Legacy layouts must remain opt-in.');
$layout = TextLayout::normalize(['enabled' => TRUE, 'mobile' => ['title' => ['x' => 90, 'width' => 100, 'size' => 'bad', 'align' => 'url(bad)']], 'desktop' => ['body' => ['x' => -20, 'size' => 200]]]);
check($layout['mobile']['title']['width'] == 10, 'Text must stay within horizontal bounds.');
check($layout['mobile']['title']['size'] == 32 && $layout['mobile']['title']['align'] === 'left', 'Unsafe inputs need defaults.');
check($layout['desktop']['body']['x'] == 0 && $layout['desktop']['body']['size'] == 120, 'Values must be clamped.');
check($layout['tablet']['body']['size'] == 20, 'Devices and elements must remain independent.');
check(TextLayout::normalize($layout) === $layout, 'Saved settings must roundtrip.');
check(str_contains(TextLayout::style($layout, 'title'), '--reveal-mobile-size:2rem'), 'Use scalable font units.');
echo "PASS: defaults, bounds, independence, safe styles and roundtrip.\n";
