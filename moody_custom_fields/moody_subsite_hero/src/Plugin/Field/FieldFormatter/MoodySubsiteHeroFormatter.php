<?php

namespace Drupal\moody_subsite_hero\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'moody_subsite_hero' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_subsite_hero",
 *   label = @Translation("Default: Large media with optional caption and credit"),
 *   field_types = {
 *     "moody_subsite_hero"
 *   }
 * )
 */
class MoodySubsiteHeroFormatter extends MoodySubsiteHeroFormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    $responsive_image_style_name = 'moody_responsive_image_subsite_hero';
    $responsive_image_style = $this->entityTypeManager->getStorage('responsive_image_style')->load($responsive_image_style_name);
    $image_styles_to_load = [];
    $cache_tags = [];
    if ($responsive_image_style) {
      $cache_tags = Cache::mergeTags($cache_tags, $responsive_image_style->getCacheTags());
      $image_styles_to_load = $responsive_image_style->getImageStyleIds();
    }
    $image_styles = $this->entityTypeManager->getStorage('image_style')->loadMultiple($image_styles_to_load);
    foreach ($image_styles as $image_style) {
      $cache_tags = Cache::mergeTags($cache_tags, $image_style->getCacheTags());
    }

    foreach ($items as $item) {
      $image_render_array = [];
      if ($media = $this->entityTypeManager->getStorage('media')->load($item->media)) {
        $media_attributes = $media->get('field_utexas_media_image')->getValue();
        if ($file = $this->entityTypeManager->getStorage('file')->load($media_attributes[0]['target_id'])) {
          $image = new \stdClass();
          $image->title = NULL;
          $image->alt = $media_attributes[0]['alt'];
          $image->entity = $file;
          $image->uri = $file->getFileUri();
          $image->width = NULL;
          $image->height = NULL;
          // Check if image styles have been disabled (e.g., animated GIF)
          if ($item->disable_image_styles == 0) {
            $image_render_array = [
              '#theme' => 'responsive_image_formatter',
              '#item' => $image,
              '#item_attributes' => [],
              '#responsive_image_style_id' => $responsive_image_style_name,
              '#cache' => [
                'tags' => $cache_tags,
              ],
            ];
          }
          else {
            $image_render_array = [
              '#theme' => 'image',
              '#uri' => $image->uri,
              '#alt' => $image->alt,
            ];
          }
        }
      }
      $elements[] = [
        '#theme' => 'moody_subsite_hero',
        '#media' => $image_render_array,
        '#caption' => $item->caption,
        '#credit' => $item->credit,
      ];

    }
    $elements['#attached']['library'][] = 'moody_subsite_hero/moody-subsite-hero';
    return $elements;
  }

}
