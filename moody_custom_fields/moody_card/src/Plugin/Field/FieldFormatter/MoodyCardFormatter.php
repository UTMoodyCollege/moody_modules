<?php

namespace Drupal\moody_card\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Cache\Cache;
use Drupal\utexas_form_elements\UtexasLinkOptionsHelper;

/**
 * Plugin implementation of the 'moody_card_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_card_formatter",
 *   label = @Translation("Moody Card Formatter"),
 *   field_types = {
 *     "moody_card"
 *   }
 * )
 */
class MoodyCardFormatter extends MoodyCardFormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    $responsive_image_style_name = 'moody_responsive_image_me';
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

    foreach ($items as $delta => $item) {
      $cta_item['link']['uri'] = $item->link_uri;
      $cta_item['link']['title'] = $item->link_title ?? NULL;
      $cta_item['link']['options'] = $item->link_options ?? [];
      $cta = UtexasLinkOptionsHelper::buildLink($cta_item, ['ut-btn']);
      $image_render_array = [];
      if ($media = $this->entityTypeManager->getStorage('media')->load($item->media)) {
        $media_attributes = $media->get('field_utexas_media_image')->getValue();
        if ($file = $this->entityTypeManager->getStorage('file')->load($media_attributes[0]['target_id'])) {
          if ($delta > 0) {
            $image = new \stdClass();
            $image->title = NULL;
            $image->alt = $media_attributes[0]['alt'];
            $image->entity = $file;
            $image->uri = $file->getFileUri();
            $image->width = NULL;
            $image->height = NULL;
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
          elseif ($delta == 0) {
            $image_render_array = [
              '#theme' => 'image_style',
              '#style_name' => 'moody_image_style_900w_506h',
              '#uri' => $file->getFileUri(),
              '#alt' => $media_attributes[0]['alt'],
            ];
          }
        }
      }
      $elements[] = [
        '#theme' => 'moody_card',
        '#media' => $image_render_array,
        '#title' => $item->title,
        '#subtitle' => $item->subtitle,
        '#cta' => $cta,
      ];

    }
    return $elements;
  }

}
