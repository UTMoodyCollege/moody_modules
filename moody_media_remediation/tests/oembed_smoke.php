<?php

/** Run with drush php:script; no network requests or entity writes. */

use Drupal\Core\Cache\MemoryBackend;
use Drupal\media\Entity\Media;
use Drupal\media\OEmbed\Resource;
use Drupal\media\OEmbed\ResourceException;
use Drupal\media\OEmbed\ResourceFetcherInterface;
use Drupal\media\OEmbed\UrlResolverInterface;
use Drupal\moody_media_remediation\Plugin\Field\FieldFormatter\ResilientOEmbedFormatter;

require_once dirname(__DIR__) . '/src/Plugin/Field/FieldFormatter/ResilientOEmbedFormatter.php';
require_once dirname(__DIR__) . '/moody_media_remediation.module';

$check = static function (bool $ok, string $message): void {
  if (!$ok) {
    throw new RuntimeException($message);
  }
};
$clock = new class extends \Drupal\Component\Datetime\Time {
  public int $now = 0;
  public function getCurrentTime() { return $this->now; }
  public function getRequestTime() { return $this->now; }
};
$clock->now = time();
$cache = new MemoryBackend($clock);
$fetcher = new class implements ResourceFetcherInterface {
  public int $calls = 0;
  public bool $fail = TRUE;
  public function fetchResource($url) {
    $this->calls++;
    if ($this->fail) {
      throw new ResourceException('Simulated provider failure', $url);
    }
    return Resource::video('<iframe></iframe>', 640, 360, title: 'Recovered video');
  }
};
$resolver = new class implements UrlResolverInterface {
  public function getProviderByUrl($url) { throw new LogicException('Not used'); }
  public function getResourceUrl($url, $max_width = NULL, $max_height = NULL) { return $url; }
};
$logger = new class extends \Psr\Log\AbstractLogger {
  public int $errors = 0;
  public function log($level, string|Stringable $message, array $context = []): void { $this->errors++; }
};
$media = Media::create([
  'bundle' => 'utexas_video_external', 'name' => '<script>Example</script>',
  'field_media_oembed_video' => 'https://youtu.be/MuV-X43QMKo',
]);
$items = $media->get('field_media_oembed_video');
$before = $media->toArray();
$formatter = ResilientOEmbedFormatter::create(Drupal::getContainer(), [
  'field_definition' => $items->getFieldDefinition(),
  'settings' => ResilientOEmbedFormatter::defaultSettings(),
  'label' => 'hidden', 'view_mode' => 'default', 'third_party_settings' => [],
], 'oembed', []);
foreach (['failureCache' => $cache, 'clock' => $clock, 'resourceFetcher' => $fetcher, 'urlResolver' => $resolver, 'logger' => $logger] as $property => $value) {
  (new ReflectionProperty($formatter, $property))->setValue($formatter, $value);
}
$first = $formatter->viewElements($items, 'en');
$check(isset($first[0]['link']), 'Provider failure needs a visible fallback');
$check($first[0]['link']['#url']->toString() === $items->value, 'Keep original URL');
$check(!str_contains((string) $first[0]['link']['#title'], '<script>'), 'Escape media labels');
$clock->now += 60;
$second = $formatter->viewElements($items, 'en');
$check($fetcher->calls === 1 && $logger->errors === 1, 'Do not repeatedly fetch or log cached failures');
$check($second['#cache']['max-age'] === 240, 'Do not extend the failure lifetime');
$cache->invalidateTags(['config:media.settings']);
$formatter->viewElements($items, 'en');
$check($fetcher->calls === 2, 'Settings changes must invalidate failures');
$media->setName('Changed title');
$formatter->viewElements($items, 'en');
$check($fetcher->calls === 3, 'Do not reuse a different revision label');
$formatter->viewElements($items, 'es');
$check($fetcher->calls === 4, 'Keep languages separate');
$clock->now += 301;
$fetcher->fail = FALSE;
$recovered = $formatter->viewElements($items, 'en');
$check(($recovered[0]['#tag'] ?? '') === 'iframe', 'Retry and recover the normal core video output');
$formatter->viewElements($items, 'en');
$check($fetcher->calls === 6, 'Do not negative-cache successful output');
$media->setName('<script>Example</script>');
$check($media->toArray() === $before, 'Rendering must not mutate media');
foreach (['https://youtu.be/example', 'https://vimeo.com/123'] as $url) {
  $check(ResilientOEmbedFormatter::supportsFallback($url), 'Known HTTPS provider should be supported');
}
foreach (['javascript:alert(1)', 'https://youtube.com.evil.test/1', 'https://user@youtube.com/1', 'http://youtu.be/1', 'https://localhost/1', 'https://youtube.com:443/1'] as $url) {
  $check(!ResilientOEmbedFormatter::supportsFallback($url), 'Unsafe or unknown provider must not get a fallback');
}
$definitions = ['oembed' => ['class' => 'original'], 'other' => ['class' => 'unchanged']];
moody_media_remediation_field_formatter_info_alter($definitions);
$check($definitions['oembed']['class'] === ResilientOEmbedFormatter::class && $definitions['other']['class'] === 'unchanged', 'Only replace the oEmbed formatter');
print "PASS: fallback, escaping, bounded caching, invalidation, recovery, URL guards, and no entity writes\n";
