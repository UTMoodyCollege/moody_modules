<?php

declare(strict_types=1);

namespace Drupal\moody_card_builder;

use Drupal\moody_hero_builder\HeroConfiguration;

/** Bounded, typed composition; arbitrary HTML, CSS and remote images are excluded. */
final class CardConfiguration {

  public const DEVICES = ['mobile', 'tablet', 'desktop'];
  public const SPLITS = ['full' => [1], 'halves' => [1, 1], 'thirds' => [1, 1, 1], 'one-two' => [1, 2], 'two-one' => [2, 1]];

  public static function cell(string $type = 'text', string $text = 'Add supporting text.'): array {
    return ['type' => $type, 'text' => $text, 'url' => '', 'align' => 'left', 'size' => 'medium', 'font' => 'sans', 'style' => 'link'];
  }

  public static function card(string $id = 'card-1'): array {
    $responsive = [];
    foreach (self::DEVICES as $device) {
      $responsive[$device] = ['layout' => 'top', 'share' => 50, 'height' => 480, 'focal_x' => 50, 'focal_y' => 50];
    }
    return ['id' => $id, 'scheme' => 'light', 'image' => 0, 'alt' => '', 'decorative' => TRUE, 'responsive' => $responsive, 'rows' => [
      ['id' => 'title', 'split' => 'full', 'stack_mobile' => TRUE, 'divider' => FALSE, 'bottom' => FALSE, 'cells' => [self::cell('heading', 'Your card title')]],
      ['id' => 'body', 'split' => 'full', 'stack_mobile' => TRUE, 'divider' => FALSE, 'bottom' => FALSE, 'cells' => [self::cell()]],
      ['id' => 'footer', 'split' => 'halves', 'stack_mobile' => FALSE, 'divider' => TRUE, 'bottom' => TRUE, 'cells' => [self::cell('text', 'More information'), array_replace(self::cell('button', 'Explore'), ['url' => '/', 'align' => 'right'])]],
    ]];
  }

  public static function defaults(): array {
    return ['columns' => ['mobile' => 1, 'tablet' => 2, 'desktop' => 3], 'gap' => 'medium', 'radius' => 'rounded', 'heading_level' => 'h3', 'cards' => [self::card()]];
  }

  /** Complete bounded contract for the assistant; validate() remains authoritative. */
  public static function aiContract(): array {
    return [
      'example' => self::defaults(),
      'collection' => ['columns' => ['mobile' => 'integer 1–2', 'tablet' => 'integer 1–4', 'desktop' => 'integer 1–4'], 'gap' => ['small', 'medium', 'large'], 'radius' => ['square', 'soft', 'rounded'], 'heading_level' => ['h2', 'h3', 'h4']],
      'cards' => '1–12 cards in display order. Preserve IDs on edits; unique IDs match ^[a-zA-Z][a-zA-Z0-9_-]{0,48}$.',
      'card' => ['scheme' => ['light', 'paper', 'dark', 'orange'], 'image' => '0 or allowed image ID', 'alt' => 'up to 300 characters; required for meaningful images', 'decorative' => 'boolean'],
      'responsive' => ['devices' => self::DEVICES, 'layout' => ['top', 'left', 'right', 'none'], 'share' => 'integer 20–80, image percentage', 'height' => 'integer 240–800 pixels', 'focal_x' => 'integer 0–100', 'focal_y' => 'integer 0–100'],
      'rows' => ['count' => '1–8 per card, ordered, unique IDs within card', 'split' => self::SPLITS, 'stack_mobile' => 'boolean', 'divider' => 'boolean', 'bottom' => 'boolean, pushes row to card bottom'],
      'cells' => ['count' => 'must match split; exactly one heading per card', 'type' => ['heading', 'text', 'eyebrow', 'badge', 'button'], 'text' => 'nonempty plain text: text 1200, badge 8, button 60, other 160 characters maximum', 'url' => 'buttons only: /site-path, #anchor, or HTTPS URL; otherwise empty', 'align' => ['left', 'center', 'right'], 'size' => ['small', 'medium', 'large'], 'font' => ['sans', 'serif'], 'style' => ['link', 'primary', 'secondary']],
      'rules' => 'Return every field shown in example. No HTML, CSS, arbitrary colors, remote image URLs or video. Use approved palettes for contrast. Never invent links or media IDs. Use mobile stacking where appropriate.',
    ];
  }

  public static function decode(string $json): array {
    if ($json === '' || strlen($json) > 131072) {
      throw new \InvalidArgumentException('Card data is missing or too large (maximum 12 cards).');
    }
    try { $data = json_decode($json, TRUE, 20, JSON_THROW_ON_ERROR); }
    catch (\JsonException $e) { throw new \InvalidArgumentException('Card data could not be read.', 0, $e); }
    if (!is_array($data) || array_is_list($data)) { throw new \InvalidArgumentException('Card data must be an object.'); }
    return self::validate($data);
  }

  public static function validate(array $data): array {
    $result = [];
    foreach (['gap' => ['small', 'medium', 'large'], 'radius' => ['square', 'soft', 'rounded'], 'heading_level' => ['h2', 'h3', 'h4']] as $key => $options) {
      $result[$key] = self::choice($data[$key] ?? NULL, $options, $key);
    }
    foreach (self::DEVICES as $device) {
      $result['columns'][$device] = self::integer($data['columns'][$device] ?? NULL, 1, $device === 'mobile' ? 2 : 4, 'columns');
    }
    $ids = [];
    foreach (self::items($data['cards'] ?? NULL, 12, 'cards') as $card) {
      $id = self::id($card['id'] ?? NULL, $ids);
      $clean = ['id' => $id, 'scheme' => self::choice($card['scheme'] ?? NULL, ['light', 'paper', 'dark', 'orange'], 'palette'), 'image' => self::integer($card['image'] ?? NULL, 0, PHP_INT_MAX, 'image'), 'alt' => self::text($card['alt'] ?? NULL, 300), 'decorative' => self::boolean($card['decorative'] ?? NULL)];
      if ($clean['image'] && !$clean['decorative'] && $clean['alt'] === '') { throw new \InvalidArgumentException('Describe meaningful images, or mark them decorative.'); }
      foreach (self::DEVICES as $device) {
        $settings = $card['responsive'][$device] ?? [];
        $clean['responsive'][$device] = ['layout' => self::choice($settings['layout'] ?? NULL, ['top', 'left', 'right', 'none'], 'image position')];
        foreach (['share' => [20, 80], 'height' => [240, 800], 'focal_x' => [0, 100], 'focal_y' => [0, 100]] as $key => [$min, $max]) {
          $clean['responsive'][$device][$key] = self::integer($settings[$key] ?? NULL, $min, $max, $key);
        }
      }
      $row_ids = []; $headings = 0;
      foreach (self::items($card['rows'] ?? NULL, 8, 'rows per card') as $row) {
        $clean_row = ['id' => self::id($row['id'] ?? NULL, $row_ids), 'split' => self::choice($row['split'] ?? NULL, array_keys(self::SPLITS), 'row split')];
        foreach (['stack_mobile', 'divider', 'bottom'] as $flag) { $clean_row[$flag] = self::boolean($row[$flag] ?? NULL); }
        $cells = self::items($row['cells'] ?? NULL, 3, 'cells per row');
        if (count($cells) !== count(self::SPLITS[$clean_row['split']])) { throw new \InvalidArgumentException('The row split must match its number of cells.'); }
        foreach ($cells as $cell) {
          $type = self::choice($cell['type'] ?? NULL, ['heading', 'text', 'eyebrow', 'badge', 'button'], 'element');
          $text = self::text($cell['text'] ?? NULL, $type === 'text' ? 1200 : ($type === 'badge' ? 8 : ($type === 'button' ? 60 : 160)));
          if ($text === '') { throw new \InvalidArgumentException('Fill in each element or remove its row.'); }
          $url = $type === 'button' ? self::text($cell['url'] ?? NULL, 2048) : '';
          if ($type === 'button' && !HeroConfiguration::validUrl($url)) { throw new \InvalidArgumentException('Links need a /site-path, #anchor or complete HTTPS URL.'); }
          $clean_cell = ['type' => $type, 'text' => $text, 'url' => $url];
          foreach (['align' => ['left', 'center', 'right'], 'size' => ['small', 'medium', 'large'], 'font' => ['sans', 'serif'], 'style' => ['link', 'primary', 'secondary']] as $key => $options) { $clean_cell[$key] = self::choice($cell[$key] ?? NULL, $options, $key); }
          $clean_row['cells'][] = $clean_cell;
          $headings += $type === 'heading' ? 1 : 0;
        }
        $clean['rows'][] = $clean_row;
      }
      if ($headings !== 1) { throw new \InvalidArgumentException('Keep exactly one heading per card.'); }
      $result['cards'][] = $clean;
    }
    return $result;
  }

  private static function items(mixed $items, int $max, string $name): array {
    if (!is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > $max || count(array_filter($items, 'is_array')) !== count($items)) { throw new \InvalidArgumentException("Use 1–{$max} {$name}."); }
    return $items;
  }

  private static function id(mixed $id, array &$used): string {
    if (!is_string($id) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,48}$/D', $id) || isset($used[$id])) { throw new \InvalidArgumentException('Each card and row needs a unique valid ID.'); }
    $used[$id] = TRUE; return $id;
  }

  private static function choice(mixed $value, array $options, string $name): string {
    if (!in_array($value, $options, TRUE)) { throw new \InvalidArgumentException('Choose a supported ' . $name . '.'); }
    return $value;
  }

  private static function integer(mixed $value, int $min, int $max, string $name): int {
    if (!is_int($value) || $value < $min || $value > $max) { throw new \InvalidArgumentException("{$name} must be a whole number from {$min} to {$max}."); }
    return $value;
  }

  private static function boolean(mixed $value): bool {
    if (!is_bool($value)) { throw new \InvalidArgumentException('Use true or false for card options.'); }
    return $value;
  }

  private static function text(mixed $value, int $max): string {
    if (!is_string($value) || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) { throw new \InvalidArgumentException("Use plain text of at most {$max} characters."); }
    return trim($value);
  }
}
