<?php

namespace Drupal\moody_showcase\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\utexas_form_elements\UtexasLinkOptionsHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'moody_showcase_formatter3' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_showcase_formatter3",
 *   label = @Translation("Moody showcase formatter3"),
 *   field_types = {
 *     "moody_showcase"
 *   }
 * )
 */
class MoodyShowcaseFormatter3 extends MoodyShowcaseFormatter {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    $responsive_image_style_name = 'utexas_responsive_image_fca';
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

    $elements = [];
    foreach ($items as $delta => $item) {
      $cta_item['link']['uri'] = $item->link_uri;
      $cta_item['link']['title'] = $item->link_title ?? NULL;
      $cta_item['link']['options'] = $item->link_options ?? [];
      $cta = UtexasLinkOptionsHelper::buildLink($cta_item, ['ut-btn--homepage', 'mt-4']);
      $image_render_array = [];
      if ($media = $this->entityTypeManager->getStorage('media')->load($item->image)) {
        // Alter if it's a video.
        $media_bundle = $media->bundle();
        switch ($media_bundle) {
          case 'utexas_video_external':
            $entity_type = 'media';
            $entity_id = $media->id();
            $view_mode = 'default';

            $entity = \Drupal::entityTypeManager()->getStorage($entity_type)->load($entity_id);
            $view_builder = \Drupal::entityTypeManager()->getViewBuilder('media');
            $pre_render = $view_builder->view($entity, $view_mode);
            $video_render_array = $pre_render;
            break;

          case 'utexas_image':
            $media_attributes = $media->get('field_utexas_media_image')->getValue();
            if ($file = $this->entityTypeManager->getStorage('file')->load($media_attributes[0]['target_id'])) {
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
            break;
        }

      
      }
      $data = [
        '#theme' => 'moody_showcase',
        '#headline' => $item->headline,
        '#copy' => check_markup($item->copy_value, $item->copy_format),
        '#cta' => $cta,
      ];

      switch ($media_bundle) {
        case 'utexas_image':
          if (!empty($image_render_array)) {
            $data['#image'] = $image_render_array;

          }
          break;

        case 'utexas_video_external':
          if (!empty($video_render_array)) {
            $data['#video'] = $video_render_array;

          }
          break;
      }
      $elements[$delta] = $data;
      $elements['#items'][$delta] = new \stdClass();
      $elements['#items'][$delta]->_attributes = [
        'class' => ['moody-showcase-marketing-style'],
      ];


    
    }

    

    return $elements;
  }

}
