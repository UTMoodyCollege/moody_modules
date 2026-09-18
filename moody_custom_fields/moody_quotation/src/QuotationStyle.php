<?php

namespace Drupal\moody_quotation;

/**
 * Allowlisted presentation tokens stored in the existing quotation style field.
 *
 * Legacy style names remain unchanged; split styles use colon-separated tokens.
 */
final class QuotationStyle {

  public const OPTIONS = [
    'palette' => ['blue' => 'White on UT blue', 'orange' => 'White on burnt orange', 'gray' => 'White on charcoal', 'light' => 'Charcoal on white'],
    'size' => ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'],
    'alignment' => ['left' => 'Left', 'center' => 'Center', 'right' => 'Right'],
    'position' => ['left' => 'Image left', 'right' => 'Image right'],
  ];

  public static function decode(?string $style): array {
    $parts = explode(':', $style ?? 'default');
    $result = ['style' => in_array($parts[0], ['default', 'orange', 'grey', 'feature', 'split'], TRUE) ? $parts[0] : 'default'];
    foreach (self::OPTIONS as $key => $options) {
      $value = $parts[count($result)] ?? '';
      $result[$key] = isset($options[$value]) ? $value : ['palette' => 'blue', 'size' => 'medium', 'alignment' => 'center', 'position' => 'left'][$key];
    }
    return $result;
  }

  public static function encode(array $values): string {
    $style = $values['style'] ?? 'default';
    if ($style !== 'split') {
      return self::decode($style)['style'];
    }
    $parts = ['split'];
    foreach (self::OPTIONS as $key => $options) {
      $parts[] = $values[$key] ?? '';
    }
    return implode(':', self::decode(implode(':', $parts)));
  }

}
