<?php

namespace Drupal\moody_ai_assistant\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

class BlockDataCollectorService {
  protected $entityTypeManager;
  protected $blockManager;
  protected $state;
  protected $entityFieldManager;
  protected $logger;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    BlockManagerInterface $block_manager,
    StateInterface $state,
    EntityFieldManagerInterface $entity_field_manager,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->blockManager = $block_manager;
    $this->state = $state;
    $this->entityFieldManager = $entity_field_manager;
    $this->logger = $logger_factory->get('moody_ai_assistant');
  }

  public function collectBlockData() {
    $data = [
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

        $blocks[$type->id()] = [
          'label' => $type->label(),
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
        $blocks[$plugin_id] = [
          'label' => is_string($definition['admin_label']) ? $definition['admin_label'] : (string) ($definition['admin_label'] ?? $plugin_id),
          'provider' => $definition['provider'] ?? 'unknown',
          'category' => $definition['category'] ?? 'Other',
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

  public function getStoredData() {
    return $this->state->get('moody_ai_assistant.block_data', []);
  }

  public function exportJson() {
    $data = $this->getStoredData();
    return json_encode($data, JSON_PRETTY_PRINT);
  }
}
