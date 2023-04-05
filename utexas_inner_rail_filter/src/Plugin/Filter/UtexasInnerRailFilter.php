<?php

namespace Drupal\utexas_inner_rail_filter\Plugin\Filter;

use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\FilterProcessResult;

/**
 * @Filter(
 *   id = "utexas_inner_rail_filter",
 *   title = @Translation("UTexas Inner Rail"),
 *   description = @Translation("Enter inner rail content into WYSIWYG editor."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
 * )
 */
class UtexasInnerRailFilter extends FilterBase {
  public function process($text, $langcode) {
    // Return untouched text if no inner rails are found.
    if (!strpos($text, "[inner_rail")) {
      return new FilterProcessResult($text);
    }
    else {
      // Get inner_rail blocks.
      if (preg_match_all('/\[inner_rail?(.+)?\[\/inner_rail\]/iUs', $text, $matches_code)) {
        foreach ($matches_code[0] as $key => $value) {
          $opening_tag = '<div class="wysiwyg_inner_rail">';
          $closing_tag = '</div>';
          // Get opening inner_rail tag and check if options are set.
          if (preg_match('/\[inner_rail?(.+)?\]/iUs', $value, $options)) {
            // Set the title, size and float if provided by user.
            $float_value = FALSE;
            $size_value = FALSE;
            if (preg_match_all('/ float:"(.*)"/iU', $options[0], $float_ouput)) {
              $tmp_float = $float_ouput[1][0];
              $float_value = 'align-' . $tmp_float;
              $value = str_replace($float_ouput[0][0], '', $value);
            }
            if (preg_match_all('/ size:"(.*)"/iU', $options[0], $size_ouput)) {
              $tmp_size = $size_ouput[1][0];
              $size_value = 'size-' . $tmp_size;
              $value = str_replace($size_ouput[0][0], '', $value);
            }
            $opening_tag = '<div class="wysiwyg_inner_rail ' . $float_value . ' ' . $size_value . '">';
            if (preg_match_all('/ title:"(.*)"/iU', $options[0], $title_ouput)) {
              $tmp_title = $title_ouput[1][0];
              $title = '<h3 class="ut-headline--underline">' . $tmp_title . '</h3>';
              $opening_tag = $opening_tag . $title;
              $value = str_replace($title_ouput[0][0], '', $value);
            }
            $value = str_replace('[inner_rail]', $opening_tag, $value);
            $value = str_replace('[/inner_rail]', $closing_tag, $value);
            $text = str_replace($matches_code[0][$key], $value, $text);
          }
        }
      }
      $result = new FilterProcessResult($text);
      return $result;
    }
  }
}
