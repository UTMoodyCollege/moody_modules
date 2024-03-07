<?php

namespace Drupal\moody_showcase\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\utexas_form_elements\UtexasLinkOptionsHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'moody_showcase_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_showcase_formatter",
 *   label = @Translation("Moody showcase formatter"),
 *   field_types = {
 *     "moody_showcase"
 *   }
 * )
 */
class MoodyShowcaseFormatter extends FormatterBase implements ContainerFactoryPluginInterface {
  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a FormatterBase object.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('entity_type.manager'),
      $container->get('renderer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    $responsive_image_style_name = 'moody_showcase_default_image';
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

      $elements[] = $data;
      $elements['#items'][$delta] = new \stdClass();
      $elements['#items'][$delta]->_attributes = [
        'class' => ['moody-showcase-default-style'],
      ];
    }
    $elements['#attached']['library'][] = 'moody_showcase/moody-showcase';
    return $elements;
  }

}
