<?php

namespace Drupal\moody_accordion\Plugin\Field\FieldFormatter;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'moody_accordion_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_accordion_formatter",
 *   label = @Translation("Moody accordion formatter"),
 *   field_types = {
 *     "moody_accordion"
 *   }
 * )
 */
class MoodyAccordionDefaultFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

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
   * The block manager service.
   *
   * @var \Drupal\Core\Block\BlockManagerInterface
   */
  protected $blockManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

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
   * @param \Drupal\Core\Block\BlockManagerInterface $block_manager
   *   The block manager service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer, BlockManagerInterface $block_manager, AccountInterface $current_user) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
    $this->blockManager = $block_manager;
    $this->currentUser = $current_user;
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
      $container->get('renderer'),
      $container->get('plugin.manager.block'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    foreach ($items as $delta => $item) {
      $item_delta = mt_rand();
      if (!empty($item->block_id)) {
        $panel_content = $this->buildBlockContent($item->block_id, $item->block_config);
      }
      else {
        $panel_content = check_markup($item->copy_value, $item->copy_format);
      }
      $elements[] = [
        '#theme' => 'moody_accordion',
        '#panel_title' => $item->title,
        '#panel_content' => $panel_content,
        '#item_delta' => $item_delta,
      ];
    }

    return $elements;
  }

  /**
   * Builds the render array for an embedded block.
   *
   * @param string $block_id
   *   The block plugin ID.
   * @param string|null $block_config
   *   Serialized block configuration.
   *
   * @return array
   *   A render array for the block output.
   */
  protected function buildBlockContent($block_id, $block_config) {
    try {
      $config = !empty($block_config) ? unserialize($block_config, ['allowed_classes' => FALSE]) : [];
      if (!is_array($config)) {
        $config = [];
      }
      $block_instance = $this->blockManager->createInstance($block_id, $config);
      if (!$block_instance->access($this->currentUser)) {
        return [];
      }
      return $block_instance->build();
    }
    catch (\Exception $e) {
      return [];
    }
  }

}

