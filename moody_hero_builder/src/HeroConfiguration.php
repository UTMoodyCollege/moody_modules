<?php

declare(strict_types=1);

namespace Drupal\moody_hero_builder;

/**
 * The shared, bounded configuration contract (no arbitrary HTML or CSS).
 */
final class HeroConfiguration {

  public const OPTIONS = [
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
      foreach (['type' => ['heading', 'text', 'eyebrow', 'button'], 'size' => ['small', 'medium', 'large'], 'font' => ['sans', 'serif'], 'style' => ['primary', 'secondary']] as $key => $options) {
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

  private static function plainText(mixed $value, int $limit): string {
    if (!is_string($value) || mb_strlen($value) > $limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
      throw new \InvalidArgumentException("Use plain text of at most {$limit} characters.");
    }
    return trim($value);
  }

}
