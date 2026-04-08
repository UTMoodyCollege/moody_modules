<?php

namespace Drupal\moody_nice_letter\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;

/**
 * Provides a filter for decorative lead letters or words.
 *
 * @Filter(
 *   id = "moody_nice_letter",
 *   title = @Translation("Moody Nice Letter"),
 *   description = @Translation("Transforms a [nice_letter] shortcode into a Moody decorative lead letter or word block."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
 * )
 */
class MoodyNiceLetterFilter extends FilterBase {

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    if (strpos($text, '[nice_letter') === FALSE) {
      return new FilterProcessResult($text);
    }

    $pattern = '/\[nice_letter([^\]]*)\](.*?)\[\/nice_letter\]/is';
    $processed_text = preg_replace_callback($pattern, [$this, 'buildNiceLetter'], $text);

    $result = new FilterProcessResult($processed_text);
    $result->setAttachments([
      'library' => [
        'moody_nice_letter/moody-nice-letter',
      ],
    ]);

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    return $this->t('Use <code>[nice_letter lead="A"]s we reflected on the past year...[/nice_letter]</code>, <code>[nice_letter lead="Moody"]College is always evolving.[/nice_letter]</code>, or set <code>color="burnt-orange|orange|default|black|charcoal|ut-gray|ut-grey|gray|grey|ut"</code>.');
  }

  /**
   * Builds replacement markup for the shortcode.
   *
   * @param array $matches
   *   Regex matches from preg_replace_callback().
   *
   * @return string
   *   The rendered decorative wrapper.
   */
  protected function buildNiceLetter(array $matches) {
    $attributes = $this->parseAttributes($matches[1] ?? '');
    $lead = trim($attributes['lead'] ?? '');
    $content = trim($matches[2] ?? '');

    if ($lead === '' || $content === '') {
      return $matches[0];
    }

    $lead_classes = ['moody-nice-letter__lead'];
    if (mb_strlen($lead) > 1 || !empty($attributes['word'])) {
      $lead_classes[] = 'moody-nice-letter__lead--word';
    }

    $lead_classes[] = $this->resolveLeadColorClass($attributes['color'] ?? '');

    return '<div class="moody-nice-letter">'
      . '<div class="moody-nice-letter__rule" aria-hidden="true"></div>'
      . '<div class="moody-nice-letter__inner">'
      . '<div class="' . implode(' ', $lead_classes) . '">' . Html::escape($lead) . '</div>'
      . '<div class="moody-nice-letter__content">' . $content . '</div>'
      . '</div>'
      . '</div>';
  }

  /**
   * Maps the optional color attribute to a supported CSS class.
   *
   * @param string $color
   *   The requested color token.
   *
   * @return string
   *   The CSS class for the lead color.
   */
  protected function resolveLeadColorClass($color) {
    $color = trim(mb_strtolower($color));

    $map = [
      'burnt-orange' => 'moody-nice-letter__lead--burnt-orange',
      'burnt_orange' => 'moody-nice-letter__lead--burnt-orange',
      'orange' => 'moody-nice-letter__lead--burnt-orange',
      'default' => 'moody-nice-letter__lead--burnt-orange',
      '' => 'moody-nice-letter__lead--burnt-orange',
      'black' => 'moody-nice-letter__lead--black',
      'charcoal' => 'moody-nice-letter__lead--black',
      'dark' => 'moody-nice-letter__lead--black',
      'ut-gray' => 'moody-nice-letter__lead--ut-gray',
      'ut-grey' => 'moody-nice-letter__lead--ut-gray',
      'ut_grey' => 'moody-nice-letter__lead--ut-gray',
      'ut_gray' => 'moody-nice-letter__lead--ut-gray',
      'ut-gray-dark' => 'moody-nice-letter__lead--ut-gray',
      'ut' => 'moody-nice-letter__lead--ut-gray',
      'gray' => 'moody-nice-letter__lead--ut-gray',
      'grey' => 'moody-nice-letter__lead--ut-gray',
    ];

    return $map[$color] ?? 'moody-nice-letter__lead--burnt-orange';
  }

  /**
   * Parses simple shortcode-style key/value attributes.
   *
   * @param string $attribute_string
   *   The raw attribute string from the shortcode.
   *
   * @return array<string, string>
   *   Parsed attributes.
   */
  protected function parseAttributes($attribute_string) {
    $attributes = [];

    if (preg_match_all('/(\w+)="([^"]*)"/', $attribute_string, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $match) {
        $attributes[$match[1]] = $match[2];
      }
    }

    return $attributes;
  }

}
