<?php

declare(strict_types=1);

namespace Drupal\moody_scroll_reveal_media;

/** Validated, opt-in responsive overlay settings. */
final class TextLayout {

  public static function normalize(array $input): array {
    $result = ['enabled' => !empty($input['enabled'])];
    foreach (['mobile', 'tablet', 'desktop'] as $device) {
      foreach (['title', 'body'] as $element) {
        $values = $input[$device][$element] ?? [];
        $defaults = ['x' => 10, 'y' => $element === 'title' ? 20 : 55, 'width' => 80, 'size' => $element === 'title' ? ($device === 'mobile' ? 32 : 56) : 20];
        foreach (['x' => [0, 90], 'y' => [0, 90], 'width' => [10, 100], 'size' => [16, 120]] as $key => [$min, $max]) {
          $value = $values[$key] ?? $defaults[$key];
          $result[$device][$element][$key] = (float) (is_numeric($value) && is_finite((float) $value) ? max($min, min($max, (float) $value)) : $defaults[$key]);
        }
        $result[$device][$element]['width'] = min($result[$device][$element]['width'], 100 - $result[$device][$element]['x']);
        $result[$device][$element]['align'] = in_array($values['align'] ?? '', ['left', 'center', 'right'], TRUE) ? $values['align'] : 'left';
      }
    }
    return $result;
  }

  public static function style(array $layout, string $element): string {
    $declarations = [];
    foreach (['mobile', 'tablet', 'desktop'] as $device) {
      foreach ($layout[$device][$element] as $key => $value) {
        $unit = in_array($key, ['x', 'y', 'width'], TRUE) ? '%' : ($key === 'size' ? 'rem' : '');
        $declarations[] = '--reveal-' . $device . '-' . $key . ':' . ($key === 'size' ? $value / 16 : $value) . $unit;
      }
    }
    return implode(';', $declarations);
  }

}
