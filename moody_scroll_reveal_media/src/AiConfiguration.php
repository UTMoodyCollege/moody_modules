<?php

declare(strict_types=1);

namespace Drupal\moody_scroll_reveal_media;

/** Strict contract for AI-created configurations; legacy rendering is unchanged. */
final class AiConfiguration {

  public static function contract(): array {
    return [
      'headline' => 'Optional plain section heading',
      'animation_style' => ['fade', 'slide'],
      'slides' => '1–6 ordered slides; preserve order and unrelated settings on edits',
      'slide' => [
        'media' => '0 or an exact allowed_media_ids integer; never invent an ID',
        'video_url' => 'Empty or an exact supplied Vimeo URL; overrides media',
        'video_autoplay' => 'boolean; default false',
        'eyebrow' => 'plain text', 'title' => 'plain heading text',
        'body' => ['value' => 'safe formatted subheading HTML', 'format' => 'an exact allowed_formats ID'],
        'title_display' => ['inline', 'overlay'],
        'title_position' => ['top-left', 'top-center', 'top-right', 'center-left', 'center', 'center-right', 'bottom-left', 'bottom-center', 'bottom-right'],
        'direction' => ['top', 'right', 'bottom', 'left'],
        'text_layout' => [
          'enabled' => 'true for independent overlay heading/subheading; false preserves legacy layout',
          'mobile/tablet/desktop' => 'Each has separate title and body boxes; breakpoints 768px and 1200px',
          'box' => ['x' => '0–90 percent from left', 'y' => '0–90 percent from top', 'width' => '10–100 percent, no wider than 100 minus x', 'size' => '16–120 px rendered in scalable rem', 'align' => ['left', 'center', 'right']],
        ],
      ],
      'rules' => 'Set title_display=overlay for independent positioning. Width and font size control wrapping, not truncation. Keep heading/subheading apart and within the viewport at all three sizes. Content must remain readable without motion. Do not enable autoplay unless requested.',
    ];
  }

  public static function validate(array $input): array {
    $input += ['headline' => '', 'animation_style' => 'fade'];
    if (!is_string($input['headline'] ?? NULL) || strlen($input['headline']) > 10000 || !in_array($input['animation_style'] ?? '', ['fade', 'slide'], TRUE) || !is_array($input['slides'] ?? NULL) || !array_is_list($input['slides']) || count($input['slides']) < 1 || count($input['slides']) > 6) {
      throw new \InvalidArgumentException('Invalid Scroll Reveal headline, animation or slide count.');
    }
    $output = array_intersect_key($input, array_flip(['headline', 'animation_style', 'slides']));
    foreach ($output['slides'] as &$slide) {
      if (!is_array($slide) || !is_int($slide['media'] ?? NULL) || $slide['media'] < 0 || !is_bool($slide['video_autoplay'] ?? NULL)) { throw new \InvalidArgumentException('Invalid Scroll Reveal media or autoplay.'); }
      foreach (['title', 'eyebrow', 'video_url'] as $key) {
        if (!is_string($slide[$key] ?? NULL) || strlen($slide[$key]) > 10000) { throw new \InvalidArgumentException('Invalid slide text.'); }
      }
      if ($slide['video_url'] !== '' && !preg_match('~^https://(?:www\.)?(?:vimeo\.com/[0-9]+|player\.vimeo\.com/video/[0-9]+)(?:[/?#].*)?$~D', $slide['video_url'])) { throw new \InvalidArgumentException('Provide a valid HTTPS Vimeo URL.'); }
      foreach (['title_display' => ['inline', 'overlay'], 'direction' => ['top', 'right', 'bottom', 'left'], 'title_position' => ['top-left', 'top-center', 'top-right', 'center-left', 'center', 'center-right', 'bottom-left', 'bottom-center', 'bottom-right']] as $key => $allowed) {
        if (!in_array($slide[$key] ?? '', $allowed, TRUE)) { throw new \InvalidArgumentException('Invalid slide display setting.'); }
      }
      if (!is_array($slide['body'] ?? NULL) || !is_string($slide['body']['value'] ?? NULL) || strlen($slide['body']['value']) > 100000 || !is_string($slide['body']['format'] ?? NULL)) { throw new \InvalidArgumentException('Invalid slide formatted text.'); }
      $layout = $slide['text_layout'] ?? NULL;
      if (!is_array($layout) || !is_bool($layout['enabled'] ?? NULL)) { throw new \InvalidArgumentException('Invalid responsive text layout.'); }
      if ($layout['enabled']) {
        if ($slide['title_display'] !== 'overlay') { throw new \InvalidArgumentException('Independent text positions require overlay display.'); }
        foreach (['mobile', 'tablet', 'desktop'] as $device) {
          foreach (['title', 'body'] as $element) {
            $box = $layout[$device][$element] ?? [];
            foreach (['x' => [0, 90], 'y' => [0, 90], 'width' => [10, 100], 'size' => [16, 120]] as $key => [$min, $max]) {
              $value = $box[$key] ?? NULL;
              if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < $min || $value > $max) { throw new \InvalidArgumentException('Invalid responsive text size or position.'); }
            }
            if ($box['x'] + $box['width'] > 100 || !in_array($box['align'] ?? '', ['left', 'center', 'right'], TRUE)) { throw new \InvalidArgumentException('Text must fit horizontally and use a supported alignment.'); }
          }
        }
      }
      $slide = array_intersect_key($slide, array_flip(['media', 'video_url', 'video_autoplay', 'eyebrow', 'title', 'body', 'title_display', 'title_position', 'direction', 'text_layout']));
      $slide['body'] = array_intersect_key($slide['body'], array_flip(['value', 'format']));
      $slide['text_layout'] = TextLayout::normalize($layout);
    }
    return $output;
  }
}
