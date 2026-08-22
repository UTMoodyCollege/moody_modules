<?php

declare(strict_types=1);

namespace Drupal\moody_ai_base;

/**
 * Restricts generated fragments to safe semantic HTML and Moody classes.
 */
final class HtmlSanitizer {

  private const ALLOWED_TAGS = [
    'a', 'aside', 'blockquote', 'br', 'caption', 'cite', 'code', 'dd', 'div',
    'dl', 'dt', 'em', 'figcaption', 'figure', 'h2', 'h3', 'h4', 'h5', 'h6',
    'hr', 'li', 'ol', 'p', 'pre', 'section', 'small', 'span', 'strong', 'sub',
    'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    'drupal-media',
  ];

  private const DROP_WITH_CONTENT = [
    'button', 'embed', 'form', 'iframe', 'input', 'math', 'object', 'script',
    'style', 'svg', 'template', 'textarea',
  ];

  private const ATTRIBUTES = [
    'a' => ['href', 'rel', 'target', 'title'],
    'ol' => ['reversed', 'start', 'type'],
    'li' => ['value'],
    'th' => ['colspan', 'rowspan', 'scope'],
    'td' => ['colspan', 'headers', 'rowspan'],
  ];

  private const COLORS = '(?:black|charcoal|white|burntorange|bluebonnet|turquoise|turtlepond|cactus|shade|tangerine|limestone|sunshine|moody-peach|moody-gray|moody-blue|error-red|bluebonnet--s20)';

  private const LEGACY_BACKGROUNDS = '(?:074d6a|138791|f9fafb|e6ebed|c4cdd4|7d8a92|5e686e|3e4549|487d39|9d4700|ebeced|c2c5c8|858c91|1f262b|fbfbf9|f2f1ed|e6e4dc|aba89e|807e76|56544e)';

  /**
   * Sanitizes one generated HTML fragment.
   */
  public function sanitize(string $html): string {
    if (trim($html) === '') {
      return '';
    }

    $document = new \DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(TRUE);
    $loaded = $document->loadHTML(
      '<!doctype html><html><head><meta charset="utf-8"></head><body><div id="moody-ai-root">' . $html . '</div></body></html>',
      LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
      return '';
    }

    $root = $document->getElementById('moody-ai-root');
    if (!$root instanceof \DOMElement) {
      return '';
    }

    $xpath = new \DOMXPath($document);
    foreach (iterator_to_array($xpath->query('.//comment()', $root) ?: []) as $comment) {
      $comment->parentNode?->removeChild($comment);
    }

    $elements = iterator_to_array($xpath->query('.//*', $root) ?: []);
    foreach (array_reverse($elements) as $element) {
      if (!$element instanceof \DOMElement || !$element->parentNode) {
        continue;
      }

      $tag = strtolower($element->tagName);
      if (!in_array($tag, self::ALLOWED_TAGS, TRUE)) {
        if (in_array($tag, self::DROP_WITH_CONTENT, TRUE)) {
          $element->parentNode->removeChild($element);
        }
        else {
          while ($element->firstChild) {
            $element->parentNode->insertBefore($element->firstChild, $element);
          }
          $element->parentNode->removeChild($element);
        }
        continue;
      }

      if ($tag === 'drupal-media') {
        if (!$this->sanitizeMediaPlaceholder($element)) {
          $element->parentNode->removeChild($element);
        }
        continue;
      }

      $this->sanitizeAttributes($element, $tag);
    }

    $result = '';
    foreach ($root->childNodes as $child) {
      $result .= $document->saveHTML($child);
    }
    return trim($result);
  }

  /**
   * Restricts Media markup to an internal, server-finalized placeholder.
   */
  private function sanitizeMediaPlaceholder(\DOMElement $element): bool {
    $allowed = [
      'data-moody-ai-attachment',
      'data-moody-ai-alt',
      'data-moody-ai-align',
      'data-moody-ai-generated-image',
      'data-moody-ai-image-prompt',
      'data-moody-ai-media',
    ];
    foreach (iterator_to_array($element->attributes) as $attribute) {
      if (!in_array(strtolower($attribute->name), $allowed, TRUE)) {
        $element->removeAttributeNode($attribute);
      }
    }

    $attachment = $element->getAttribute('data-moody-ai-attachment');
    $media = $element->getAttribute('data-moody-ai-media');
    $generated = $element->getAttribute('data-moody-ai-generated-image');
    $has_attachment = preg_match('/^[1-9][0-9]?$/', $attachment) === 1;
    $has_media = preg_match('/^[1-9][0-9]?$/', $media) === 1;
    $has_generated = $generated === '1';
    if ((int) $has_attachment + (int) $has_media + (int) $has_generated !== 1 || (($has_attachment || $has_generated) && !$element->hasAttribute('data-moody-ai-alt'))) {
      return FALSE;
    }

    if ($has_attachment) {
      $alt = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $element->getAttribute('data-moody-ai-alt')) ?? '';
      $element->setAttribute('data-moody-ai-alt', mb_substr(trim($alt), 0, 512));
      $element->removeAttribute('data-moody-ai-media');
      $element->removeAttribute('data-moody-ai-generated-image');
      $element->removeAttribute('data-moody-ai-image-prompt');
    }
    elseif ($has_media) {
      $element->removeAttribute('data-moody-ai-alt');
      $element->removeAttribute('data-moody-ai-attachment');
      $element->removeAttribute('data-moody-ai-generated-image');
      $element->removeAttribute('data-moody-ai-image-prompt');
    }
    else {
      $prompt = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $element->getAttribute('data-moody-ai-image-prompt')) ?? '';
      $alt = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $element->getAttribute('data-moody-ai-alt')) ?? '';
      $prompt = mb_substr(trim($prompt), 0, 2000);
      $alt = mb_substr(trim($alt), 0, 512);
      if ($prompt === '' || $alt === '') {
        return FALSE;
      }
      $element->setAttribute('data-moody-ai-generated-image', '1');
      $element->setAttribute('data-moody-ai-image-prompt', $prompt);
      $element->setAttribute('data-moody-ai-alt', $alt);
      $element->removeAttribute('data-moody-ai-attachment');
      $element->removeAttribute('data-moody-ai-media');
    }
    $align = $element->getAttribute('data-moody-ai-align');
    if ($element->hasAttribute('data-moody-ai-align') && !in_array($align, ['left', 'center', 'right'], TRUE)) {
      $element->removeAttribute('data-moody-ai-align');
    }
    while ($element->firstChild) {
      $element->removeChild($element->firstChild);
    }
    return TRUE;
  }

  /**
   * Sanitizes the attributes of an allowed element.
   */
  private function sanitizeAttributes(\DOMElement $element, string $tag): void {
    $allowed = array_merge(['class'], self::ATTRIBUTES[$tag] ?? []);
    foreach (iterator_to_array($element->attributes) as $attribute) {
      if (!in_array(strtolower($attribute->name), $allowed, TRUE)) {
        $element->removeAttributeNode($attribute);
      }
    }

    if ($element->hasAttribute('class')) {
      $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
      $classes = array_values(array_unique(array_filter($classes, [$this, 'isAllowedClass'])));
      if ($classes) {
        $element->setAttribute('class', implode(' ', $classes));
      }
      else {
        $element->removeAttribute('class');
      }
    }

    if ($tag === 'a') {
      $this->sanitizeLink($element);
    }

    foreach (['colspan', 'rowspan'] as $name) {
      if ($element->hasAttribute($name) && !preg_match('/^[1-9][0-9]{0,2}$/', $element->getAttribute($name))) {
        $element->removeAttribute($name);
      }
    }
    foreach (['start', 'value'] as $name) {
      if ($element->hasAttribute($name) && !preg_match('/^-?[0-9]{1,6}$/', $element->getAttribute($name))) {
        $element->removeAttribute($name);
      }
    }
    $allowed_scopes = ['col', 'colgroup', 'row', 'rowgroup'];
    if ($element->hasAttribute('scope') && !in_array($element->getAttribute('scope'), $allowed_scopes, TRUE)) {
      $element->removeAttribute('scope');
    }
    if ($element->hasAttribute('type') && !in_array($element->getAttribute('type'), ['1', 'A', 'a', 'I', 'i'], TRUE)) {
      $element->removeAttribute('type');
    }
    if ($element->hasAttribute('reversed')) {
      $element->setAttribute('reversed', 'reversed');
    }
  }

  /**
   * Returns TRUE for one class published by the Moody style guide.
   */
  private function isAllowedClass(string $class): bool {
    $patterns = [
      '/^(?:(?:md|lg|xl):)?ut-text-(?:xs|sm|base|lg|xl|2xl|3xl|4xl|5xl)$/',
      '/^(?:(?:md|lg|xl):)?ut-font-weight-(?:light|normal|medium|semibold|bold|black)$/',
      '/^(?:text|bg|border)-ut-' . self::COLORS . '$/',
      '/^utexas-bg-' . self::LEGACY_BACKGROUNDS . '$/',
      '/^(?:(?:md|lg|xl):)?ut-(?:p|pt|pb|pl|pr|m|mt|mb|ml|mr)-(?:1|2|4|8|16)$/',
      '/^(?:(?:md|lg|xl):)?ut-border-width-(?:none|thin|medium|thick)$/',
      '/^(?:(?:md|lg|xl):)?ut-border-radius-(?:none|sm|md|lg|xl|2xl|full)$/',
      '/^ut-(?:headline(?:--(?:underline|poster))?|copy|(?:cta-)?link(?:--darker)?)$/',
      '/^(?:text-center|dont-break-out|ut-fit-table|ut-50-50-table)$/',
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $class)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Removes unsafe link values and normalizes new-window links.
   */
  private function sanitizeLink(\DOMElement $element): void {
    if ($element->hasAttribute('href')) {
      $href = trim($element->getAttribute('href'));
      $scheme = parse_url($href, PHP_URL_SCHEME);
      $allowed_scheme = $scheme === NULL || in_array(strtolower((string) $scheme), ['http', 'https', 'mailto', 'tel'], TRUE);
      $unsafe_characters = preg_match('/[\x00-\x20\x7f\\\\]/', $href);
      if ($href === '' || $scheme === FALSE || !$allowed_scheme || $unsafe_characters || str_starts_with($href, '//')) {
        $element->removeAttribute('href');
      }
    }

    if ($element->hasAttribute('target') && !in_array($element->getAttribute('target'), ['_blank', '_self'], TRUE)) {
      $element->removeAttribute('target');
    }

    $rel = preg_split('/\s+/', strtolower(trim($element->getAttribute('rel')))) ?: [];
    $rel = array_intersect($rel, ['nofollow', 'noopener', 'noreferrer']);
    if ($element->getAttribute('target') === '_blank') {
      $rel[] = 'noopener';
      $rel[] = 'noreferrer';
    }
    $rel = array_values(array_unique($rel));
    if ($rel) {
      $element->setAttribute('rel', implode(' ', $rel));
    }
    else {
      $element->removeAttribute('rel');
    }
  }

}
