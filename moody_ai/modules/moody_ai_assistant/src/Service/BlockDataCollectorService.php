<?php

namespace Drupal\moody_ai_assistant\Service;

use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

class BlockDataCollectorService {
  const DATA_SCHEMA_VERSION = 3;

  private const BLOCK_SELECTION_GUIDANCE = [
    'ambient_video' => 'Use for an intentional video-led page introduction when motion adds meaning; prefer Moody Hero when a still image is sufficient.',
    'basic' => 'Use for pure text, semantic formatted copy, or small chunks of safe custom markup that do not need a specialized visual component.',
    'call_to_action' => 'Use for one focused next-step link, not as a substitute for a full content section.',
    'moody_accordion' => 'Use for FAQs or grouped details with clear, predictable panel titles.',
    'moody_contact_info' => 'Use for a concise contact or next-step panel with structured copy and a call to action.',
    'moody_flex_color_blocks' => 'Use for up to four short, parallel choices or topics distinguished by approved color treatments.',
    'moody_flex_grid' => 'Use for responsive cards representing programs, topics, resources, people, or destinations.',
    'moody_flex_tabs' => 'Use for a small set of parallel content panels whose labels remain understandable out of context.',
    'moody_focus_areas' => 'Use for a small, intentional set of mission areas, disciplines, or high-level topic destinations.',
    'moody_hero' => 'Use as the primary visual introduction at the top of a page; normally use no more than one per page.',
    'moody_impact_facts' => 'Use for a concise set of verified headline statistics, outcomes, or short data points; use a Chart for comparative datasets.',
    'moody_newsletter' => 'Use for a compact newsletter-signup promotion with one clear destination.',
    'moody_promotion' => 'Use for one image-led event, announcement, opportunity, or campaign.',
    'moody_quotation' => 'Use for one verified quotation with an accurate attribution, never for invented testimonial copy.',
    'moody_resource_group' => 'Use for a clearly labeled, structured group of related resource links.',
    'moody_showcase' => 'Use for editorial image-or-video storytelling paired with substantial copy; prefer Flex Grid for independent cards.',
    'social_links' => 'Use for an official set of social-network destinations, not general-purpose links.',
    'utexas_featured_highlight' => 'Use for one timely or important in-page item with optional media, date, summary, and destination.',
    'utexas_flex_content_area' => 'Use for one or more media-and-copy rows; prefer Basic for prose-only content and Showcase for a more editorial treatment.',
    'utexas_flex_list' => 'Use for repeated heading-and-content pairs displayed as a list, accordion, or tabs.',
    'utexas_image_link' => 'Use for one compact linked image; use Promo List or Flex Grid for a collection.',
    'utexas_photo_content_area' => 'Use for image-led content with supporting copy when the Photo Content Area is offered by the site.',
    'utexas_promo_list' => 'Use for several compact related promotions with square imagery.',
    'utexas_promo_unit' => 'Use for a prominent group of larger promotional items.',
    'utexas_quick_links' => 'Use for a short, scannable set of important destination links.',
    'utexas_resources' => 'Use for related resources that may combine a group heading, image, and multiple links.',
  ];

  protected $entityTypeManager;
  protected $blockManager;
  protected $state;
  protected $entityFieldManager;
  protected $logger;
  protected $typedConfigManager;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    BlockManagerInterface $block_manager,
    StateInterface $state,
    EntityFieldManagerInterface $entity_field_manager,
    LoggerChannelFactoryInterface $logger_factory,
    TypedConfigManagerInterface $typed_config_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->blockManager = $block_manager;
    $this->state = $state;
    $this->entityFieldManager = $entity_field_manager;
    $this->logger = $logger_factory->get('moody_ai_assistant');
    $this->typedConfigManager = $typed_config_manager;
  }

  public function collectBlockData() {
    $data = [
      'schema_version' => static::DATA_SCHEMA_VERSION,
      'content_blocks' => $this->getContentBlockData(),
      'plugin_blocks' => $this->getPluginBlockData(),
      'last_updated' => time(),
    ];

    $this->state->set('moody_ai_assistant.block_data', $data);
    return $data;
  }

  protected function getContentBlockData() {
    $blocks = [];
    try {
      $block_storage = $this->entityTypeManager->getStorage('block_content_type');
      $types = $block_storage->loadMultiple();

      foreach ($types as $type) {
        $fields = $this->entityFieldManager->getFieldDefinitions('block_content', $type->id());

        $field_data = [];
        foreach ($fields as $field) {
          try {
            $storage_definition = $field->getFieldStorageDefinition();
            $field_record = [
              'type' => $field->getType(),
              'label' => (string) $field->getLabel(),
              'required' => $field->isRequired(),
              'description' => (string) $field->getDescription(),
              'cardinality' => $storage_definition->getCardinality(),
            ];

            $property_definitions = $storage_definition->getPropertyDefinitions();
            if (!empty($property_definitions) && count($property_definitions) > 1) {
              $field_record['properties'] = [];
              foreach ($property_definitions as $property_name => $property_definition) {
                $field_record['properties'][$property_name] = [
                  'data_type' => $property_definition->getDataType(),
                  'required' => $property_definition->isRequired(),
                  'label' => method_exists($property_definition, 'getLabel') ? (string) $property_definition->getLabel() : $property_name,
                ];
              }
            }

            if ($field->getType() === 'utexas_promo_unit') {
              $field_record['properties'] = [
                'headline' => [
                  'data_type' => 'string',
                  'required' => FALSE,
                  'label' => 'Promo Unit Group Headline',
                ],
                'items' => [
                  'data_type' => 'sequence',
                  'required' => FALSE,
                  'label' => 'Promo Unit Items',
                ],
                'image' => [
                  'data_type' => 'media',
                  'required' => FALSE,
                  'label' => 'Item Image',
                ],
                'copy_value' => [
                  'data_type' => 'string',
                  'required' => FALSE,
                  'label' => 'Item Copy',
                ],
                'copy_format' => [
                  'data_type' => 'string',
                  'required' => FALSE,
                  'label' => 'Item Copy Format',
                ],
                'link_uri' => [
                  'data_type' => 'string',
                  'required' => FALSE,
                  'label' => 'Item Link URL',
                ],
                'link_title' => [
                  'data_type' => 'string',
                  'required' => FALSE,
                  'label' => 'Item Link Label',
                ],
                'link_options' => [
                  'data_type' => 'map',
                  'required' => FALSE,
                  'label' => 'Item Link Options',
                ],
              ];
              $field_record['guidance'] = 'Return value as an object with an optional headline and an items array. Each item may include headline, image, copy_value, copy_format, link_uri, link_title, and link_options.';
            }

            if ($field->getType() === 'utexas_promo_list') {
              $field_record['properties'] = [
                'headline' => [
                  'data_type' => 'string',
                  'required' => FALSE,
                  'label' => 'Promo List Group Headline',
                ],
                'items' => [
                  'data_type' => 'sequence',
                  'required' => FALSE,
                  'label' => 'Promo List Items',
                ],
                'image' => [
                  'data_type' => 'media',
                  'required' => FALSE,
                  'label' => 'Item Image',
                ],
                'copy_value' => [
                  'data_type' => 'string',
                  'required' => FALSE,
                  'label' => 'Item Copy',
                ],
                'copy_format' => [
                  'data_type' => 'string',
                  'required' => FALSE,
                  'label' => 'Item Copy Format',
                ],
                'link_uri' => [
                  'data_type' => 'string',
                  'required' => FALSE,
                  'label' => 'Item Link URL',
                ],
                'link_title' => [
                  'data_type' => 'string',
                  'required' => FALSE,
                  'label' => 'Item Link Label',
                ],
              ];
              $field_record['guidance'] = 'Return value as an object with an optional headline and an items array. Each item may include headline, image, copy_value, copy_format, link_uri, and link_title. Never return HTML or a raw serialized promo_list_items value.';
            }

            if ($field->getType() === 'utexas_resources') {
              $field_record['properties'] = [
                'headline' => [
                  'data_type' => 'string',
                  'required' => FALSE,
                  'label' => 'Resources Group Headline',
                ],
                'items' => [
                  'data_type' => 'sequence',
                  'required' => FALSE,
                  'label' => 'Resource Items',
                ],
                'image' => [
                  'data_type' => 'media',
                  'required' => FALSE,
                  'label' => 'Item Image',
                ],
                'links' => [
                  'data_type' => 'sequence',
                  'required' => FALSE,
                  'label' => 'Item Links',
                ],
              ];
              $field_record['guidance'] = 'Return value as an object with an optional headline and an items array. Each item may include headline, image, and links. Each link must include uri and title. Never return HTML or a raw serialized resource_items value.';
            }

            if ($field->getType() === 'moody_focus_areas') {
              $field_record['properties']['items'] = [
                'data_type' => 'sequence',
                'required' => FALSE,
                'label' => 'Focus Area Items',
              ];
              $field_record['properties']['headline'] = [
                'data_type' => 'string',
                'required' => FALSE,
                'label' => 'Item Headline',
              ];
              $field_record['properties']['image'] = [
                'data_type' => 'media',
                'required' => FALSE,
                'label' => 'Item Image',
              ];
              $field_record['properties']['copy_value'] = [
                'data_type' => 'string',
                'required' => FALSE,
                'label' => 'Item Copy',
              ];
              $field_record['properties']['copy_format'] = [
                'data_type' => 'string',
                'required' => FALSE,
                'label' => 'Item Copy Format',
              ];
              $field_record['guidance'] = 'Return value as an object with items_title, optional link settings, and an items array. Each item may include headline, image, copy_value, copy_format, link_uri, and link_title. Never return HTML or a raw serialized focus_areas_items value.';
            }

            if ($field->getType() === 'utexas_flex_content_area') {
              $field_record['guidance'] = 'Return value as an object with optional image, headline, copy_value, copy_format, links, link_uri, link_text, and link_options. The links value must be an array of objects with uri and title; never return a delimited string or raw serialized value.';
            }

            if ($field->getType() === 'moody_hero') {
              $field_record['guidance'] = 'Use media, heading, subheading, caption, credit, link_uri, link_title, link_options, disable_image_styles, and these exact option tokens when needed: text_position=centered|top-left|top-right|bottom-left|bottom-right, text_color=white-text|orange-text|charcoal-text, overlay=no-overlay|orange-overlay|charcoal-overlay|heavy-orange-overlay|heavy-charcoal-overlay.';
            }

            if ($field->getType() === 'moody_impact_facts') {
              $field_record['properties'] = [
                'headline' => [
                  'data_type' => 'string',
                  'required' => FALSE,
                  'label' => 'Impact Facts Group Headline',
                ],
                'style' => [
                  'data_type' => 'string',
                  'required' => FALSE,
                  'label' => 'Impact Facts Style',
                ],
                'col_number' => [
                  'data_type' => 'string',
                  'required' => FALSE,
                  'label' => 'Impact Facts Items Per Row',
                ],
                'items' => [
                  'data_type' => 'sequence',
                  'required' => FALSE,
                  'label' => 'Impact Fact Items',
                ],
                'headline_item' => [
                  'data_type' => 'string',
                  'required' => FALSE,
                  'label' => 'Impact Fact Headline',
                ],
                'subheadline_item' => [
                  'data_type' => 'string',
                  'required' => FALSE,
                  'label' => 'Impact Fact Subheadline',
                ],
              ];
              $field_record['guidance'] = 'Return value as an object with headline, style, col_number, and an items array. Use these exact style tokens: orange-headline or grey-headline. Use these exact col_number tokens: two-per-row, three-per-row, or four-per-row. Each item in items must include a non-empty headline and may include subheadline. Include at least one non-empty fact item whenever you choose a Moody Impact Facts block.';
            }

            if ($field->getType() === 'entity_reference') {
              $handler_settings = $field->getSetting('handler_settings') ?: [];
              $field_record['target_type'] = $storage_definition->getSetting('target_type');
              $field_record['target_bundles'] = array_keys($handler_settings['target_bundles'] ?? []);
              if ($field_record['target_type'] === 'entity_view_mode') {
                $field_record['allowed_values'] = array_keys($this->entityTypeManager->getStorage('entity_view_mode')->loadMultiple());
              }
            }

            $allowed_values = $field->getSetting('allowed_values');
            if (!empty($allowed_values) && is_array($allowed_values)) {
              $field_record['allowed_values'] = array_keys($allowed_values);
            }

            $field_data[$field->getName()] = $field_record;
          }
          catch (\Exception $e) {
            $this->logger->error('Failed collecting field data for @field on @bundle: @message', [
              '@field' => $field->getName(),
              '@bundle' => $type->id(),
              '@message' => $e->getMessage(),
            ]);
            continue;
          }
        }

        $block_type = (string) $type->id();
        $blocks[$block_type] = [
          'label' => $type->label(),
          'description' => trim((string) $type->get('description')),
          'best_for' => self::BLOCK_SELECTION_GUIDANCE[$block_type] ?? '',
          'fields' => $field_data,
        ];
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed collecting block content metadata: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $blocks;
  }

  protected function getPluginBlockData() {
    $definitions = $this->blockManager->getDefinitions();
    $blocks = [];

    foreach ($definitions as $plugin_id => $definition) {
      try {
        $provider = (string) ($definition['provider'] ?? 'unknown');
        $configuration = [];
        $configuration_schema = [];
        if (str_starts_with($provider, 'moody_')) {
          try {
            $configuration = $this->blockManager->createInstance($plugin_id, [])->defaultConfiguration();
            $configuration_schema = $this->getPluginConfigurationSchema($plugin_id);
          }
          catch (\Throwable $e) {
            $this->logger->warning('Could not inspect configuration for @plugin: @message', [
              '@plugin' => $plugin_id,
              '@message' => $e->getMessage(),
            ]);
          }
        }
        $blocks[$plugin_id] = [
          'label' => is_string($definition['admin_label']) ? $definition['admin_label'] : (string) ($definition['admin_label'] ?? $plugin_id),
          'provider' => $provider,
          'category' => $definition['category'] ?? 'Other',
          'configuration' => $configuration,
          'configuration_schema' => $configuration_schema,
        ];
      }
      catch (\Exception $e) {
        $this->logger->error('Failed collecting plugin block metadata for @plugin: @message', [
          '@plugin' => $plugin_id,
          '@message' => $e->getMessage(),
        ]);
        continue;
      }
    }

    return $blocks;
  }

  /**
   * Returns a compact typed-config schema for one block plugin.
   */
  protected function getPluginConfigurationSchema($plugin_id) {
    $definition = $this->typedConfigManager->getDefinition('block.settings.' . $plugin_id, FALSE);
    return $this->normalizeConfigurationSchema($definition['mapping'] ?? []);
  }

  /**
   * Removes schema bookkeeping while preserving valid keys, types, and labels.
   */
  protected function normalizeConfigurationSchema(array $mapping) {
    $schema = [];
    foreach ($mapping as $key => $definition) {
      if (!is_array($definition)) {
        continue;
      }
      $record = array_filter([
        'type' => (string) ($definition['type'] ?? ''),
        'label' => isset($definition['label']) ? (string) $definition['label'] : '',
      ], static fn ($value): bool => $value !== '');
      if (!empty($definition['mapping']) && is_array($definition['mapping'])) {
        $record['mapping'] = $this->normalizeConfigurationSchema($definition['mapping']);
      }
      elseif (str_contains((string) ($definition['type'] ?? ''), '.')) {
        $nested = $this->typedConfigManager->getDefinition((string) $definition['type'], FALSE);
        if (!empty($nested['mapping']) && is_array($nested['mapping'])) {
          $record['mapping'] = $this->normalizeConfigurationSchema($nested['mapping']);
        }
      }
      if (!empty($definition['sequence']) && is_array($definition['sequence'])) {
        $sequence = $definition['sequence'];
        $record['sequence'] = array_filter([
          'type' => (string) ($sequence['type'] ?? ''),
          'label' => isset($sequence['label']) ? (string) $sequence['label'] : '',
        ], static fn ($value): bool => $value !== '');
        if (!empty($sequence['mapping']) && is_array($sequence['mapping'])) {
          $record['sequence']['mapping'] = $this->normalizeConfigurationSchema($sequence['mapping']);
        }
      }
      $schema[$key] = $record;
    }
    return $schema;
  }

  public function getStoredData() {
    $data = $this->state->get('moody_ai_assistant.block_data', []);
    return ($data['schema_version'] ?? 0) === static::DATA_SCHEMA_VERSION ? $data : [];
  }

  public function exportJson() {
    $data = $this->getStoredData();
    return json_encode($data, JSON_PRETTY_PRINT);
  }
}
