<?php

namespace Drupal\moody_quotation\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Url;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;


/**
 * Plugin implementation of the 'moody_quotation_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_quotation_formatter",
 *   label = @Translation("Moody quotation formatter"),
 *   field_types = {
 *     "moody_quotation"
 *   }
 * )
 */
class MoodyQuotationFormatter extends FormatterBase implements ContainerFactoryPluginInterface {
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
    $responsive_image_style_name = 'moody_flex_grid_promo_style';
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
      }
      $elements[] = [
        '#theme' => 'moody_quotation',
        '#quote' => $item->quote,
        '#style' => $item->style,
        '#author' => $item->author,
        '#media' => $image_render_array,
      ];
    }

    return $elements;
  }

}
