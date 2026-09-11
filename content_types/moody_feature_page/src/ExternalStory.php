<?php

namespace Drupal\moody_feature_page;

/**
 * Shared validation for editor-supplied external story destinations.
 */
final class ExternalStory {

  public static function validUrl(string $url): bool {
    $parts = parse_url($url);
    return strlen($url) <= 2048
      && filter_var($url, FILTER_VALIDATE_URL) !== FALSE
      && in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], TRUE)
      && !isset($parts['user']) && !isset($parts['pass'])
      && !preg_match('/[\x00-\x20\\\\]/', $url);
  }

}
