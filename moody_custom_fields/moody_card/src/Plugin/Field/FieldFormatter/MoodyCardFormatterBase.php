<?php

namespace Drupal\moody_card\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\utexas_form_elements\UtexasLinkOptionsHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A base class for use with all hero formatters.
 */
abstract class MoodyCardFormatterBase extends FormatterBase implements ContainerFactoryPluginInterface {

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
   * Builds a card link without allowing malformed stored data to break pages.
   */
  protected static function buildCta($uri, $title, $options, array $classes) {
    $uri = trim((string) $uri);
    if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $uri, $matches)) {
      $allowed_schemes = array_merge(UrlHelper::getAllowedProtocols(), ['internal', 'entity', 'route', 'base']);
      if (!in_array(strtolower($matches[1]), $allowed_schemes, TRUE)) {
        return NULL;
      }
    }
    if ($uri !== '' && !parse_url($uri, PHP_URL_SCHEME) && !str_starts_with($uri, '//')) {
      if (!in_array($uri[0], ['/', '?', '#'], TRUE)) {
        $uri = '/' . $uri;
      }
      $uri = 'internal:' . $uri;
    }

    try {
      return UtexasLinkOptionsHelper::buildLink([
        'link' => [
          'uri' => $uri,
          'title' => $title,
          'options' => is_array($options) ? $options : [],
        ],
      ], $classes);
    }
    catch (\InvalidArgumentException $exception) {
      return NULL;
    }
  }

}
