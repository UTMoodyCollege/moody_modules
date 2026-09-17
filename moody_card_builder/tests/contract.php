<?php
require __DIR__ . '/../../moody_hero_builder/src/HeroConfiguration.php';
require __DIR__ . '/../src/CardConfiguration.php';
use Drupal\moody_card_builder\CardConfiguration as Cards;
function check($value, $message) { if (!$value) { throw new RuntimeException($message); } }
$default = Cards::defaults();
check(Cards::decode(json_encode($default)) == $default, 'Default round trip');
foreach (Cards::DEVICES as $device) {
  foreach (['top', 'left', 'right', 'none'] as $layout) {
    $data = $default; $data['cards'][0]['responsive'][$device]['layout'] = $layout;
    $data['cards'][0]['responsive'][$device]['share'] = 75;
    check(Cards::validate($data)['cards'][0]['responsive'][$device]['share'] === 75, 'Responsive image layout');
  }
}
foreach (Cards::SPLITS as $split => $weights) {
  $data = $default; $data['cards'][0]['rows'][1]['split'] = $split;
  $data['cards'][0]['rows'][1]['cells'] = array_fill(0, count($weights), Cards::cell());
  check(count(Cards::validate($data)['cards'][0]['rows'][1]['cells']) === count($weights), 'Row split');
}
$bad = [];
$data = $default; $data['cards'][] = $data['cards'][0]; $bad[] = $data;
$data = $default; $data['cards'][0]['rows'][2]['cells'][1]['url'] = 'javascript:alert(1)'; $bad[] = $data;
$data = $default; $data['cards'][0]['rows'][2]['cells'][1]['url'] = '//evil.test'; $bad[] = $data;
$data = $default; $data['cards'][0]['rows'][2]['cells'][1]['url'] = 'https://user:pass@example.com'; $bad[] = $data;
$data = $default; $data['cards'][0]['rows'][0]['cells'][0]['type'] = 'text'; $bad[] = $data;
$data = $default; $data['cards'][0]['rows'][1]['cells'][0]['type'] = 'heading'; $bad[] = $data;
$data = $default; $data['cards'][0]['rows'][1]['split'] = 'halves'; $bad[] = $data;
$data = $default; $data['columns']['mobile'] = 3; $bad[] = $data;
$data = $default; $data['cards'][0]['scheme'] = 'red'; $bad[] = $data;
$data = $default; $data['cards'][0]['responsive']['desktop']['focal_x'] = '50; color:red'; $bad[] = $data;
$data = $default; $data['cards'][0]['decorative'] = FALSE; $data['cards'][0]['image'] = 1; $bad[] = $data;
$data = $default; $data['cards'][0]['rows'][1]['cells'][0]['text'] = str_repeat('a', 1201); $bad[] = $data;
foreach ($bad as $data) {
  try { Cards::validate($data); throw new RuntimeException('Invalid input accepted'); }
  catch (InvalidArgumentException $expected) {}
}
foreach (['', 'null', '[]', '{', str_repeat(' ', 131073)] as $json) {
  try { Cards::decode($json); throw new RuntimeException('Malformed JSON accepted'); }
  catch (InvalidArgumentException $expected) {}
}
echo "PASS: default round trip, all responsive layouts/splits, heading/accessibility rules, URL and input bounds.\n";
