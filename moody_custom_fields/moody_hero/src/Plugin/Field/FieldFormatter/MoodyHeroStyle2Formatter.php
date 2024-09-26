<?php

namespace Drupal\moody_hero\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Field\FieldItemListInterface;

use Drupal\utexas_form_elements\UtexasLinkOptionsHelper;

/**
 * Plugin implementation of the 'moody_hero' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_hero_2",
 *   label = @Translation("Style 2: Bold heading on dark background, anchored at base of media, image anchored center"),
 *   field_types = {
 *     "moody_hero"
 *   }
 * )
 */
class MoodyHeroStyle2Formatter extends MoodyHeroFormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $cache_tags = [];
    $elements = [];
    $large_image_style_name = 'utexas_image_style_2250w_900h';
    $medium_image_style_name = 'utexas_image_style_1200w';
    $small_image_style_name = 'utexas_image_style_1200w';

    // First load image styles & store their style in the cache for this page.
    $large_image_style = $this->entityTypeManager->getStorage('image_style')->load($large_image_style_name);
    $cache_tags = Cache::mergeTags($cache_tags, $large_image_style->getCacheTags());

    $medium_image_style = $this->entityTypeManager->getStorage('image_style')->load($medium_image_style_name);
    $cache_tags = Cache::mergeTags($cache_tags, $medium_image_style->getCacheTags());

    $small_image_style = $this->entityTypeManager->getStorage('image_style')->load($small_image_style_name);
    $cache_tags = Cache::mergeTags($cache_tags, $small_image_style->getCacheTags());

    foreach ($items as $delta => $item) {
      $cta_item['link']['uri'] = $item->link_uri;
      $cta_item['link']['title'] = $item->link_title ?? NULL;
      $cta_item['link']['options'] = $item->link_options ?? [];
      $cta = UtexasLinkOptionsHelper::buildLink($cta_item, ['ut-btn']);
      $id = 'a' . substr(md5(uniqid(mt_rand(), TRUE)), 0, 5);
      if ($media = $this->entityTypeManager->getStorage('media')->load($item->media)) {
        $media_attributes = $media->get('field_utexas_media_image')->getValue();
        if ($file = $this->entityTypeManager->getStorage('file')->load($media_attributes[0]['target_id'])) {
          $uri = $file->getFileUri();
          // Check if image styles have been disabled (e.g., animated GIF)
          if (!$item->disable_image_styles) {
            // Apply an image style in an attempt to optimize huge images.
            $large_src = $large_image_style->buildUrl($uri);
            $medium_src = $medium_image_style->buildUrl($uri);
            $small_src = $small_image_style->buildUrl($uri);
          }
          else {
            $large_src = $file->createFileUrl();
            $medium_src = $file->createFileUrl();
            $small_src = $file->createFileUrl();
          }
          $css = "
          #" . $id . ".hero--photo-gradient {
            background-image: url(" . $large_src . ");
          }
          @media screen and (max-width: 900px) {
            #" . $id . ".hero--photo-gradient {
              background-image: url(" . $medium_src . ");
            }
          }
          @media screen and (max-width: 600px) {
            #" . $id . ".hero--photo-gradient {
              background-image: url(" . $small_src . ");
            }
          }";
          $elements['#attached']['html_head'][] = [
            [
              '#tag' => 'style',
              '#value' => $css,
            ],
            'moody-hero-' . $id,
          ];
        }
      }
      $elements[$delta] = [
        '#theme' => 'moody_hero_2',
        '#media_identifier' => $id,
        '#alt' => isset($media_attributes) ? $media_attributes[0]['alt'] : '',
        '#heading' => $item->heading,
        '#cta' => $cta,
        '#anchor_position' => 'center',
      ];
    }
    $elements['#attached']['library'][] = 'moody_hero/hero-style-2';
    return $elements;
  }

}
