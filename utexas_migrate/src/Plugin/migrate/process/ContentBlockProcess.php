<?php

namespace Drupal\utexas_migrate\Plugin\migrate\process;

use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\Component\Serialization\Json;

/**
 * Convert a Drupal 7 media tag to a rendered image field.
 *
 * @MigrateProcessPlugin(
 *   id = "utexas_content_blocks_process",
 * )
 */
class ContentBlockProcess extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $value = ' ' . $value . ' ';
    $value = preg_replace_callback('/\[\[.*?\]\]/s', [&$this, 'replaceToken'], $value);
    return $value;
  }

  /**
   * Replace callback to convert a media file tag into HTML markup.
   *
   * Partially copied from 7.x media module media.filter.inc (media_filter).
   *
   * @param string $match
   *   Takes a match of tag code
   */
  private function replaceToken($match) {
    $settings = [];
    $match = str_replace("[[", "", $match);
    $match = str_replace("]]", "", $match);
    $tag = $match[0];

    try {
      if (!is_string($tag)) {
        throw new MigrateSkipRowException('No File Tag', TRUE);
      }

      // Make it into a fancy array.
      $tag_info = Json::decode($tag);
      if (!isset($tag_info['fid'])) {
        throw new MigrateSkipRowException('No FID', TRUE);
      }

      // Load the file.
      $file = file_load($tag_info['fid']);
      if (!$file) {
        throw new MigrateSkipRowException('Couldn\'t Load File', TRUE);
      }

      // Grab the uri.
      $uri = $file->getFileUri();

      // The class attributes is a string, but drupal requires it to be an array, so we fix it here.
      if (!empty($tag_info['attributes']['class'])) {
        $tag_info['attributes']['class'] = explode(" ", $tag_info['attributes']['class']);
      }

      $settings['attributes'] = is_array($tag_info['attributes']) ? $tag_info['attributes'] : [];

      // Many media formatters will want to apply width and height independently
      // of the style attribute or the corresponding HTML attributes, so pull
      // these two out into top-level settings. Different WYSIWYG editors have
      // different behavior with respect to whether they store user-specified
      // dimensions in the HTML attributes or the style attribute, so check both.
      // Per http://www.w3.org/TR/html5/the-map-element.html#attr-dim-width, the
      // HTML attributes are merely hints: CSS takes precedence.
      if (isset($settings['attributes']['style'])) {
        $css_properties = $this->MediaParseCssDeclarations($settings['attributes']['style']);
        foreach (['width', 'height'] as $dimension) {
          if (isset($css_properties[$dimension]) && substr($css_properties[$dimension], -2) == 'px') {
            $settings[$dimension] = substr($css_properties[$dimension], 0, -2);
          }
          elseif (isset($settings['attributes'][$dimension])) {
            $settings[$dimension] = $settings['attributes'][$dimension];
          }
        }
      }
    }
    catch (Exception $e) {
      $msg = t('Unable to render media from %tag. Error: %error', ['%tag' => $tag, '%error' => $e->getMessage()]);
      throw new MigrateSkipRowException($msg, TRUE);
    }

    // Render the image.
    $element = [
      '#theme' => 'image',
      '#uri' => $uri,
      '#attributes' => isset($settings['attributes']) ? $settings['attributes'] : '',
      '#width' => $settings['width'],
      '#height' => $settings['height'],
    ];

    $output = \Drupal::service('renderer')->renderRoot($element);

    return $output;
  }

  /**
   * Copied from 7.x media module media.filter.inc (media_parse_css_declarations).
   *
   * Parses the contents of a CSS declaration block and returns a keyed array of property names and values.
   *
   * @param $declarations
   *   One or more CSS declarations delimited by a semicolon. The same as a CSS
   *   declaration block (see http://www.w3.org/TR/CSS21/syndata.html#rule-sets),
   *   but without the opening and closing curly braces. Also the same as the
   *   value of an inline HTML style attribute.
   *
   * @return
   *   A keyed array. The keys are CSS property names, and the values are CSS
   *   property values.
   */
  private function MediaParseCssDeclarations($declarations) {
    $properties = [];
    foreach (array_map('trim', explode(";", $declarations)) as $declaration) {
      if ($declaration != '') {
        list($name, $value) = array_map('trim', explode(':', $declaration, 2));
        $properties[strtolower($name)] = $value;
      }
    }
    return $properties;
  }

}
