<?php

namespace Drupal\moody_promotion\Plugin\Field\FieldFormatter;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Language\Language;
use Drupal\Core\Url;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\date_ap_style\ApStyleDateFormatter;
use Drupal\media\IFrameUrlHelper;
use Drupal\media\MediaInterface;
use Drupal\media\OEmbed\ResourceException;
use Drupal\media\OEmbed\ResourceFetcherInterface;
use Drupal\media\OEmbed\UrlResolverInterface;
use Drupal\utexas_form_elements\UtexasLinkOptionsHelper;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'moody_promotion_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_promotion_formatter",
 *   label = @Translation("Moody promotion formatter"),
 *   field_types = {
 *     "moody_promotion"
 *   }
 * )
 */
class MoodyPromotionFormatter extends FormatterBase implements ContainerFactoryPluginInterface {
  /**
   * The date formatter.
   *
   * @var \Drupal\date_ap_style\ApStyleDateFormatter
   */
  protected $apStyleDateFormatter;

  /**
   * The iFrame URL helper service.
   *
   * @var \Drupal\media\IFrameUrlHelper
   */
  protected $iFrameUrlHelper;

  /**
   * The oEmbed resource fetcher.
   *
   * @var \Drupal\media\OEmbed\ResourceFetcherInterface
   */
  protected $resourceFetcher;

  /**
   * The oEmbed URL resolver service.
   *
   * @var \Drupal\media\OEmbed\UrlResolverInterface
   */
  protected $urlResolver;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

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
   * The Config.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a TimestampAgoFormatter object.
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
   * @param \Drupal\date_ap_style\ApStyleDateFormatter $date_formatter
   *   The date formatter.
   * @param \Drupal\media\IFrameUrlHelper $iframe_url_helper
   *   The iFrame URL helper service.
   * @param \Drupal\media\OEmbed\ResourceFetcherInterface $resource_fetcher
   *   The oEmbed resource fetcher service.
   * @param \Drupal\media\OEmbed\UrlResolverInterface $url_resolver
   *   The oEmbed URL resolver service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory services.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, ApStyleDateFormatter $date_formatter, IFrameUrlHelper $iframe_url_helper, ResourceFetcherInterface $resource_fetcher, UrlResolverInterface $url_resolver, LoggerChannelFactoryInterface $logger_factory, EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer, ConfigFactoryInterface $config_factory) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->apStyleDateFormatter = $date_formatter;
    $this->iFrameUrlHelper = $iframe_url_helper;
    $this->resourceFetcher = $resource_fetcher;
    $this->urlResolver = $url_resolver;
    $this->logger = $logger_factory->get('media');
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    // @see \Drupal\Core\Field\FormatterPluginManager::createInstance().
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('date_ap_style.formatter'),
      $container->get('media.oembed.iframe_url_helper'),
      $container->get('media.oembed.resource_fetcher'),
      $container->get('media.oembed.url_resolver'),
      $container->get('logger.factory'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('config.factory')
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
      $headline = $item->headline ?? '';
      if (isset($item->date)) {
        $options = [
          'always_display_year' => 1,
          'display_noon_and_midnight' => 1,
          'timezone' => '',
          'display_day' => 0,
          'display_time' => 0,
          'time_before_date' => 0,
          'use_all_day' => 0,
          'capitalize_noon_and_midnight' => 0,
        ];
        $timezone = $this->configFactory->get('system.date')->get('timezone');
        $item->date = $this->apStyleDateFormatter->formatTimestamp(strtotime($item->date), $options, $timezone['default'], Language::LANGCODE_NOT_SPECIFIED);
      }
      if (!empty($item->link_uri)) {
        $link_item['link']['uri'] = $item->link_uri;
        $link_item['link']['title'] = $item->link_text ?? NULL;
        $link_item['link']['options'] = $item->link_options ?? [];
        $cta = UtexasLinkOptionsHelper::buildLink($link_item, ['ut-btn--homepage']);
      }
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
        '#theme' => 'moody_promotion',
        '#headline' => $headline,
        '#media' => $image_render_array,
        '#date' => $item->date,
        '#copy' => check_markup($item->copy_value, $item->copy_format),
        '#cta' => $cta ?? '',
        '#style' => '',
      ];
    }
    return $elements;
  }

}
