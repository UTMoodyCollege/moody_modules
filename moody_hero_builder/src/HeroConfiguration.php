<?php

declare(strict_types=1);

namespace Drupal\moody_hero_builder;

/**
 * The shared, bounded configuration contract (no arbitrary HTML or CSS).
 */
final class HeroConfiguration {

  public const ELEMENT_OPTIONS = ['type' => ['heading', 'text', 'eyebrow', 'button'], 'size' => ['small', 'medium', 'large'], 'font' => ['sans', 'serif'], 'style' => ['primary', 'secondary']];

  public static function aiContract(): array {
    return ['options' => self::OPTIONS, 'element_options' => self::ELEMENT_OPTIONS, 'defaults' => self::defaults(), 'rules' => [
      '1–12 elements in reading order; exactly one heading; unique IDs start with a letter, then letters/digits/underscore/hyphen, at most 49 characters.',
      'Plain text only. Text elements max 1200 characters, buttons 40, headings/eyebrows 160, image_alt 300. Button destinations max 2048: /site-path, #anchor, or https:// without credentials.',
      'Focal points and overlay_strength are integers 0–100. Desktop/mobile focal points can differ. Heading level is independent of visual size.',
      'decorative is boolean; meaningful images need image_alt. Use only offered palettes, fonts and sizes; no raw HTML or CSS.',
      'Video backgrounds require an existing accessible poster image, decorative=true, and a user-supplied Vimeo or YouTube HTTPS URL. Never invent a video URL. The background is muted; essential information belongs in visible text.',
      'Preserve unrelated settings, image and element IDs when editing. Responsive previews and reduced-motion fallback use the normal renderer.',
    ]];
  }

  public const OPTIONS = [
    'background' => ['image', 'video'],
    'layout' => ['overlay', 'split', 'split-right', 'text'],
    'scheme' => ['light', 'dark', 'orange'],
    'surface' => ['solid', 'soft'],
    'position' => ['top-left', 'top-center', 'top-right', 'center-left', 'center', 'center-right', 'bottom-left', 'bottom-center', 'bottom-right'],
    'alignment' => ['left', 'center'],
    'height' => ['compact', 'standard', 'tall'],
    'width' => ['narrow', 'medium', 'wide'],
    'overlay' => ['none', 'charcoal', 'orange'],
    'heading_level' => ['h1', 'h2', 'h3'],
  ];

  public static function defaults(): array {
    return [
      'background' => 'image', 'video_url' => '',
      'layout' => 'split', 'scheme' => 'light', 'surface' => 'solid',
      'position' => 'center-left', 'alignment' => 'left',
      'height' => 'standard', 'width' => 'medium',
      'overlay' => 'none', 'overlay_strength' => 35,
      'focal_x' => 50, 'focal_y' => 50,
      'mobile_focal_x' => 50, 'mobile_focal_y' => 50,
      'decorative' => TRUE, 'image_alt' => '', 'heading_level' => 'h2',
      'elements' => [
        ['id' => 'heading', 'type' => 'heading', 'text' => 'Your story starts here', 'size' => 'large', 'font' => 'sans', 'url' => '', 'style' => 'primary'],
        ['id' => 'intro', 'type' => 'text', 'text' => 'Add a short introduction that tells visitors what matters and where to go next.', 'size' => 'medium', 'font' => 'sans', 'url' => '', 'style' => 'primary'],
      ],
    ];
  }

  /**
   * Rejects malformed input instead of silently saving a damaged composition.
   */
  public static function decode(string $json): array {
    if ($json === '' || strlen($json) > 32768) {
      throw new \InvalidArgumentException('Hero data is missing or too large. Use at most 12 content elements.');
    }
    try {
      $data = json_decode($json, TRUE, 16, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new \InvalidArgumentException('Hero data could not be read. Reload the editor or correct the JSON.', 0, $e);
    }
    if (!is_array($data) || array_is_list($data)) {
      throw new \InvalidArgumentException('Hero data must be a configuration object.');
    }
    return self::validate($data);
  }

  public static function validate(array $data): array {
    $data += self::defaults();
    $result = [];
    foreach (self::OPTIONS as $key => $options) {
      if (!in_array($data[$key], $options, TRUE)) {
        throw new \InvalidArgumentException('Choose a supported value for ' . str_replace('_', ' ', $key) . '.');
      }
      $result[$key] = $data[$key];
    }
    foreach (['focal_x', 'focal_y', 'mobile_focal_x', 'mobile_focal_y', 'overlay_strength'] as $key) {
      $value = filter_var($data[$key], FILTER_VALIDATE_INT);
      if ($value === FALSE || $value < 0 || $value > 100) {
        throw new \InvalidArgumentException('Image position and overlay strength must be between 0 and 100.');
      }
      $result[$key] = $value;
    }
    if (!is_bool($data['decorative'])) {
      throw new \InvalidArgumentException('Choose whether the image is decorative.');
    }
    $result['decorative'] = $data['decorative'];
    $result['video_url'] = self::plainText($data['video_url'], 2048);
    if ($data['background'] === 'video' && (!self::videoEmbed($result['video_url']) || !$data['decorative'] || $data['layout'] === 'text')) {
      throw new \InvalidArgumentException('A background video needs a Vimeo or YouTube HTTPS URL, a visual layout, and decorative media.');
    }
    $result['image_alt'] = self::plainText($data['image_alt'], 300);
    if (!$data['decorative'] && $result['image_alt'] === '') {
      throw new \InvalidArgumentException('Describe the image, or mark it as decorative if its meaning is already in the text.');
    }
    if (!is_array($data['elements']) || !array_is_list($data['elements']) || count($data['elements']) > 12 || count($data['elements']) < 1) {
      throw new \InvalidArgumentException('Use between 1 and 12 content elements.');
    }
    $headings = 0;
    $ids = [];
    $result['elements'] = [];
    foreach ($data['elements'] as $element) {
      if (!is_array($element) || !is_string($element['id'] ?? NULL) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,48}$/D', $element['id']) || isset($ids[$element['id']])) {
        throw new \InvalidArgumentException('Each hero element needs a unique, valid ID.');
      }
      $ids[$element['id']] = TRUE;
      foreach (self::ELEMENT_OPTIONS as $key => $options) {
        if (!in_array($element[$key] ?? NULL, $options, TRUE)) {
          throw new \InvalidArgumentException('Choose supported element type, size, font and button style.');
        }
      }
      $text = self::plainText($element['text'] ?? NULL, $element['type'] === 'text' ? 1200 : ($element['type'] === 'button' ? 40 : 160));
      if ($text === '') {
        throw new \InvalidArgumentException('Add text to each element, or remove unused elements.');
      }
      $url = '';
      if ($element['type'] === 'button') {
        $url = self::plainText($element['url'] ?? NULL, 2048);
        if (!self::validUrl($url)) {
          throw new \InvalidArgumentException('Each button needs a /site-path, #anchor, or complete https:// URL.');
        }
      }
      $headings += $element['type'] === 'heading' ? 1 : 0;
      $result['elements'][] = array_intersect_key($element, array_flip(['id', 'type', 'size', 'font', 'style'])) + ['text' => $text, 'url' => $url];
    }
    if ($headings !== 1) {
      throw new \InvalidArgumentException('Keep exactly one heading in the hero. Its size is independent of its heading level.');
    }
    return $result;
  }

  public static function validUrl(string $url): bool {
    if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $url)) {
      return FALSE;
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !preg_match('/^\/%2f/i', $url)) {
      return TRUE;
    }
    return (bool) preg_match('/^#[A-Za-z][A-Za-z0-9_:.\-]*$/D', $url)
      || (str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== FALSE && parse_url($url, PHP_URL_USER) === NULL && parse_url($url, PHP_URL_PASS) === NULL);
  }

  /** Canonical provider embeds only; never forward arbitrary query parameters. */
  public static function videoEmbed(string $url): ?string {
    if (!self::validUrl($url) || !str_starts_with($url, 'https://')) { return NULL; }
    $parts = parse_url($url);
    if (isset($parts['port'])) { return NULL; }
    $host = strtolower($parts['host'] ?? '');
    $path = $parts['path'] ?? '';
    parse_str($parts['query'] ?? '', $query);
    if (in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], TRUE) && preg_match('~^/(?:video/)?([0-9]+)/?(?:([a-zA-Z0-9]+))?$~D', $path, $match)) {
      $hash = $query['h'] ?? ($match[2] ?? '');
      if (!is_string($hash) || ($hash !== '' && !preg_match('/^[a-zA-Z0-9]+$/D', $hash))) { return NULL; }
      return 'https://player.vimeo.com/video/' . $match[1] . '?background=1&muted=1&autoplay=1&loop=1&dnt=1' . ($hash !== '' ? '&h=' . $hash : '');
    }
    $id = '';
    if ($host === 'youtu.be') { $id = trim($path, '/'); }
    elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'www.youtube-nocookie.com'], TRUE)) {
      if ($path === '/watch') { $id = $query['v'] ?? ''; }
      elseif (preg_match('~^/(?:embed|shorts)/([^/]+)/?$~D', $path, $match)) { $id = $match[1]; }
    }
    return is_string($id) && preg_match('/^[a-zA-Z0-9_-]{11}$/D', $id)
      ? 'https://www.youtube-nocookie.com/embed/' . $id . '?autoplay=1&mute=1&loop=1&playlist=' . $id . '&controls=0&playsinline=1&rel=0' : NULL;
  }

  private static function plainText(mixed $value, int $limit): string {
    if (!is_string($value) || mb_strlen($value) > $limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
      throw new \InvalidArgumentException("Use plain text of at most {$limit} characters.");
    }
    return trim($value);
  }

}
