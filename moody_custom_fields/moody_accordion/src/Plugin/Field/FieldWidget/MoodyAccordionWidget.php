<?php

namespace Drupal\moody_accordion\Plugin\Field\FieldWidget;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'moody_accordion_widget' widget.
 *
 * @FieldWidget(
 *   id = "moody_accordion_widget",
 *   module = "moody_accordion",
 *   label = @Translation("Moody accordion widget"),
 *   field_types = {
 *     "moody_accordion"
 *   }
 * )
 */
class MoodyAccordionWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The block manager service.
   *
   * @var \Drupal\Core\Block\BlockManagerInterface
   */
  protected $blockManager;

  /**
   * Constructs a MoodyAccordionWidget object.
   *
   * @param string $plugin_id
   *   The plugin_id for the widget.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the widget is associated.
   * @param array $settings
   *   The widget settings.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\Core\Block\BlockManagerInterface $block_manager
   *   The block manager service.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, BlockManagerInterface $block_manager) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->blockManager = $block_manager;
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
      $configuration['third_party_settings'],
      $container->get('plugin.manager.block')
    );
  }

  /**
   * Returns a sorted list of available block plugin options.
   *
   * @return array
   *   An associative array of block plugin IDs keyed to their admin labels,
   *   grouped by category.
   */
  protected function getBlockOptions() {
    $definitions = $this->blockManager->getDefinitions();
    $options = [];
    $grouped = [];
    foreach ($definitions as $id => $definition) {
      $category = (string) ($definition['category'] ?? $this->t('Other'));
      $grouped[$category][$id] = (string) $definition['admin_label'];
    }
    ksort($grouped);
    foreach ($grouped as $category => $blocks) {
      asort($blocks);
      $options[$category] = $blocks;
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $item = $items[$delta];

    // Determine the current content mode: 'wysiwyg' or 'block'.
    $parents = array_merge($element['#field_parents'], [
      $this->fieldDefinition->getName(),
      $delta,
    ]);
    $mode_parents = array_merge($parents, ['content_mode']);
    $current_mode = $form_state->getValue($mode_parents)
      ?? ((!empty($item->block_id)) ? 'block' : 'wysiwyg');

    $ajax_wrapper_id = 'moody-accordion-block-config-' . implode('-', $parents);

    $element['content_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Panel content type'),
      '#options' => [
        'wysiwyg' => $this->t('Custom content (WYSIWYG)'),
        'block' => $this->t('Embedded block'),
      ],
      '#default_value' => $current_mode,
      '#ajax' => [
        'callback' => [static::class, 'contentModeAjaxCallback'],
        'wrapper' => $ajax_wrapper_id,
        'event' => 'change',
      ],
    ];

    $element['wysiwyg_wrapper'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="' . $this->getRadioName($parents, 'content_mode') . '"]' => ['value' => 'wysiwyg'],
        ],
      ],
    ];

    $element['wysiwyg_wrapper']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Panel title'),
      '#default_value' => $item->title ?? NULL,
    ];

    $element['wysiwyg_wrapper']['copy'] = [
      '#title' => $this->t('Panel contents'),
      '#type' => 'text_format',
      '#wysiwyg' => TRUE,
      '#default_value' => $item->copy_value ?? NULL,
      '#format' => $item->copy_format ?? 'flex_html',
    ];

    // Container replaced by AJAX when content mode or block selection changes.
    $element['block_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => $ajax_wrapper_id],
      '#states' => [
        'visible' => [
          ':input[name="' . $this->getRadioName($parents, 'content_mode') . '"]' => ['value' => 'block'],
        ],
      ],
    ];

    // Current block_id from form state or saved value.
    $block_id_parents = array_merge($parents, ['block_wrapper', 'block_id']);
    $current_block_id = $form_state->getValue($block_id_parents)
      ?? ($item->block_id ?? '');

    $element['block_wrapper']['block_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Select block'),
      '#options' => $this->getBlockOptions(),
      '#default_value' => $current_block_id,
      '#ajax' => [
        'callback' => [static::class, 'contentModeAjaxCallback'],
        'wrapper' => $ajax_wrapper_id,
        'event' => 'change',
      ],
      '#empty_option' => $this->t('- Select a block -'),
      '#empty_value' => '',
    ];

    // If a block is selected, render its configuration form.
    if ($current_mode === 'block' && !empty($current_block_id)) {
      $this->addBlockConfigForm($element, $current_block_id, $item->block_config ?? '', $form_state, $parents);
    }

    return $element;
  }

  /**
   * Builds and attaches the block configuration sub-form to the element.
   *
   * @param array $element
   *   The form element being built.
   * @param string $block_id
   *   The block plugin ID.
   * @param string $stored_config
   *   Serialized block configuration, if any.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   * @param array $parents
   *   The form parents leading to this accordion item.
   */
  protected function addBlockConfigForm(array &$element, $block_id, $stored_config, FormStateInterface $form_state, array $parents) {
    try {
      $config = !empty($stored_config) ? unserialize($stored_config, ['allowed_classes' => FALSE]) : [];
      if (!is_array($config)) {
        $config = [];
      }
      $block_instance = $this->blockManager->createInstance($block_id, $config);
      $block_form = [];
      $block_form_state = clone $form_state;
      $block_config_form = $block_instance->blockForm($block_form, $block_form_state);
      if (!empty($block_config_form)) {
        $element['block_wrapper']['block_config_form'] = [
          '#type' => 'details',
          '#title' => $this->t('Block configuration'),
          '#open' => TRUE,
          'config' => $block_config_form,
        ];
      }
    }
    catch (\Exception $e) {
      $element['block_wrapper']['block_config_error'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Unable to load block configuration form.'),
      ];
    }
  }

  /**
   * AJAX callback to replace the block configuration wrapper.
   */
  public static function contentModeAjaxCallback(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    // Walk up the tree to find the block_wrapper container.
    $parents = $trigger['#array_parents'];
    // Remove the last two elements (field name + 'content_mode' or
    // 'block_id') to get to the accordion item level.
    array_pop($parents);
    // If triggered from inside block_wrapper, pop one more.
    if (end($parents) === 'block_wrapper') {
      array_pop($parents);
    }
    $parents[] = 'block_wrapper';
    $element = $form;
    foreach ($parents as $key) {
      if (isset($element[$key])) {
        $element = $element[$key];
      }
    }
    return $element;
  }

  /**
   * Returns the form field name for a radio element given its parents and key.
   *
   * @param array $parents
   *   The parent keys for this accordion item.
   * @param string $key
   *   The element key.
   *
   * @return string
   *   The HTML input name attribute value.
   */
  protected function getRadioName(array $parents, $key) {
    $all = array_merge($parents, [$key]);
    $first = array_shift($all);
    return $first . '[' . implode('][', $all) . ']';
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      $mode = $value['content_mode'] ?? 'wysiwyg';
      if ($mode === 'block') {
        $block_id = $value['block_wrapper']['block_id'] ?? '';
        $value['block_id'] = $block_id;
        // Serialize the block sub-form config values if present.
        if (!empty($block_id) && isset($value['block_wrapper']['block_config_form']['config'])) {
          $block_config_values = $value['block_wrapper']['block_config_form']['config'];
          if (is_array($block_config_values)) {
            $value['block_config'] = serialize($block_config_values);
          }
        }
        elseif (!empty($block_id) && empty($value['block_config'])) {
          $value['block_config'] = serialize([]);
        }
        // Clear WYSIWYG data when using block mode.
        $value['copy_value'] = '';
        $value['copy_format'] = '';
      }
      else {
        // WYSIWYG mode.
        $value['copy_value'] = $value['wysiwyg_wrapper']['copy']['value'] ?? '';
        $value['copy_format'] = $value['wysiwyg_wrapper']['copy']['format'] ?? 'flex_html';
        // Preserve title from wysiwyg wrapper.
        $value['title'] = $value['wysiwyg_wrapper']['title'] ?? ($value['title'] ?? '');
        $value['block_id'] = '';
        $value['block_config'] = '';
      }
    }
    return $values;
  }

}

