<?php

namespace Drupal\moody_flex_grid\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'moody_flex_grid_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_flex_grid_formatter6",
 *   label = @Translation("Card Style"),
 *   field_types = {
 *     "moody_flex_grid"
 *   }
 * )
 */
class MoodyFlexGridFormatter6 extends FormatterBase implements ContainerFactoryPluginInterface {

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
      $plugin_id,
      $plugin_definition,
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
    $responsive_image_style_name = 'utexas_responsive_image_pu_square';
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
      $instances = [];
      $flex_grid_items = is_string($item->flex_grid_items) ? unserialize($item->flex_grid_items) : $item->flex_grid_items;
      if (!empty($flex_grid_items)) {
        foreach ($flex_grid_items as $key => $instance) {
          $instance_item = $instance['item'];
          if (!empty($instance_item['image'])) {
            $image = isset($instance_item['image']) ? $instance_item['image'] : FALSE;
            $instances[$key]['image'] = $this->generateImageRenderArray($image, $responsive_image_style_name, $cache_tags);
          }
          if (!empty($instance_item['headline'])) {
            $instances[$key]['headline'] = $instance_item['headline'];
          }
          if (!empty($instance_item['title'])) {
            $instances[$key]['title'] = $instance_item['title'];
          }
          if (!empty($instance_item['link']['uri'])) {
            $instances[$key]['link'] =   Url::fromUri($instance_item['link']['uri'], ['absolute' => TRUE]);
          }
          // copy
            if (!empty($instance_item['copy'])) {
              // Render as Markkup with the flex_html text format
              $instances[$key]['copy'] = [
                '#type' => 'processed_text',
                '#text' => $instance_item['copy'],
                '#format' => 'flex_html',
              ];
              
            }
        }
      }

      $elements[] = [
        '#theme' => 'moody_flex_grid_card',
        '#headline' => $item->headline,
        '#style' => $item->style,
        '#flex_grid_items' => $instances,
      ];
      $elements['#items'][$delta] = new \stdClass();
      $elements['#items'][$delta]->_attributes = [
        'class' => ['moody-flex-grid'],
      ];
      $elements['#attributes']['class'][] = 'moody-flex-grid-wrapper card-display';
    }
    $elements['#attached']['library'][] = 'moody_flex_grid/moody-flex-grid';
    $elements['#attached']['library'][] = 'moody_flex_grid/moody-flex-grid-card';
    return $elements;
  }

  /**
   * Helper method to prepare image array.
   */
  protected function generateImageRenderArray($image, $responsive_image_style_name, $cache_tags) {
    $image_render_array = FALSE;
    if (!empty($image) && $media = $this->entityTypeManager->getStorage('media')->load($image)) {
      $media_attributes = $media->get('field_utexas_media_image')->getValue();
      $image_render_array = [];
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
          '#item_attributes' => ['class' => ['ut-img--fluid']],
          '#responsive_image_style_id' => $responsive_image_style_name,
          '#cache' => [
            'tags' => $cache_tags,
          ],
        ];
        $this->renderer->addCacheableDependency($image_render_array, $file);
      }
    }
    return $image_render_array;
  }

}
