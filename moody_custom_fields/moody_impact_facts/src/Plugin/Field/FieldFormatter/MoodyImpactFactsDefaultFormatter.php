<?php

namespace Drupal\moody_impact_facts\Plugin\Field\FieldFormatter;

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
 * Plugin implementation of the 'moody_impact_facts_default_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_impact_facts_default_formatter",
 *   label = @Translation("1 per row"),
 *   field_types = {
 *     "moody_impact_facts"
 *   }
 * )
 */
class MoodyImpactFactsDefaultFormatter extends FormatterBase implements ContainerFactoryPluginInterface {
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
    foreach ($items as $delta => $item) {
      $instances = [];
      $impact_items = is_string($item->impact_items) ? unserialize($item->impact_items) : $item->impact_items;
      if (!empty($impact_items)) {
        foreach ($impact_items as $key => $instance) {
          $instance_item = $instance['item'];
          if (!empty($instance_item['headline'])) {
            $instances[$key]['headline'] = $instance_item['headline'];
          }
          if (!empty($instance_item['subheadline'])) {
            $instances[$key]['subheadline'] = $instance_item['subheadline'];
          }
        }
      }
      $elements[] = [
        '#theme' => 'moody_impact_facts',
        '#headline' => $item->headline,
        '#style' => $item->style,
        '#col_number' => $item->col_number,
        '#impact_items' => $instances,
      ];
    }
    $elements['#attributes']['class'][] = 'moody-impact-facts-wrapper';
    $elements['#attached']['library'][] = 'moody_impact_facts/moody-impact-facts';
    return $elements;
  }

}
