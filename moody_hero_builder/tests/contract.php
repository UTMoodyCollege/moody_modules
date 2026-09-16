<?php

declare(strict_types=1);

// Run with PHP 8.3+; no Drupal bootstrap or third-party test dependency needed.
require dirname(__DIR__) . '/src/HeroConfiguration.php';
use Drupal\moody_hero_builder\HeroConfiguration as Hero;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
  if (!$ok) {
    throw new RuntimeException($message);
  }
  $checks++;
};
$reject = static function (array $data, string $message) use ($check): void {
  try {
    Hero::validate($data);
  }
  catch (InvalidArgumentException) {
    $check(TRUE, $message);
    return;
  }
  $check(FALSE, $message);
};
$base = Hero::defaults();
$check(Hero::decode(json_encode($base)) == $base, 'Defaults survive JSON round trip');
foreach (Hero::OPTIONS as $key => $options) {
  foreach ($options as $option) {
    $check(Hero::validate(array_replace($base, [$key => $option, 'video_url' => 'https://vimeo.com/123456789']))[$key] === $option, "Allowed $key");
  }
  $reject(array_replace($base, [$key => 'not-allowed']), "Reject unknown $key");
}
foreach (['/', '/about?a=1&b=2', '#section-2', 'https://moody.utexas.edu/about'] as $url) {
  $check(Hero::validUrl($url), "Allowed URL $url");
}
foreach (['', '//evil.example', '/%2Fevil.example', '/\\evil', "https://example.com\n", 'javascript:alert(1)', 'data:text/html,test', 'http://example.com', 'https://user:pass@example.com', '/%0aheader', '#'] as $url) {
  $check(!Hero::validUrl($url), "Reject unsafe URL $url");
}
foreach ([-1, 101, 'abc', []] as $number) {
  $reject(array_replace($base, ['focal_x' => $number]), 'Bounded focal points');
}
$reject(array_replace($base, ['decorative' => FALSE]), 'Informative image requires alt');
$reject(array_replace($base, ['decorative' => 'false']), 'Boolean cannot be string');
$reject(array_replace($base, ['elements' => []]), 'Empty composition');
$reject(array_replace($base, ['elements' => array_fill(0, 13, $base['elements'][0])]), 'Oversized composition');
$reject(array_replace($base, ['elements' => [$base['elements'][1]]]), 'Heading required');
$duplicate = $base;
$duplicate['elements'][1]['id'] = 'heading';
$reject($duplicate, 'Duplicate IDs');
$long = $base;
$long['elements'][0]['text'] = str_repeat('a', 161);
$reject($long, 'Heading length');
$script = $base;
$script['elements'][0]['text'] = '<script>alert(1)</script>';
$script['css'] = 'background:evil';
$safe = Hero::validate($script);
$check($safe['elements'][0]['text'] === '<script>alert(1)</script>', 'Text is retained for escaping, not treated as HTML');
$check(!isset($safe['css']), 'Unknown configuration is dropped');
foreach (['', '[]', 'null', '{', str_repeat(' ', 32769)] as $json) {
  try {
    Hero::decode($json);
    $check(FALSE, 'Malformed JSON accepted');
  }
  catch (InvalidArgumentException) {
    $check(TRUE, 'Malformed JSON rejected');
  }
}
print "Hero configuration: {$checks} checks passed.\n";
foreach (['https://vimeo.com/123456789', 'https://player.vimeo.com/video/123456789?h=abc123', 'https://vimeo.com/123456789/abc123', 'https://www.youtube.com/watch?v=M7lc1UVf-VE', 'https://youtu.be/M7lc1UVf-VE', 'https://www.youtube-nocookie.com/embed/M7lc1UVf-VE'] as $url) {
  $check(Hero::videoEmbed($url) !== NULL, 'Supported video URL');
}
foreach (['https://evil.test/video/123', 'https://vimeo.com.evil.test/123', 'javascript:alert(1)', 'https://user@vimeo.com/123', 'https://vimeo.com:444/123', 'https://youtube.com/watch?v[]=M7lc1UVf-VE', 'https://vimeo.com/123?h[]=bad', '//vimeo.com/123'] as $url) {
  $check(Hero::videoEmbed($url) === NULL, 'Reject unsafe video URL');
}
$legacy = $base; unset($legacy['background'], $legacy['video_url']);
$check(Hero::validate($legacy)['background'] === 'image', 'Legacy heroes retain image background');
$check(Hero::aiContract()['options'] === Hero::OPTIONS, 'AI uses live option contract');
print "Hero/video configuration: {$checks} checks passed.\n";
