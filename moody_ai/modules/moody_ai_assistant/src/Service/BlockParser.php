<?php

namespace Drupal\moody_ai_assistant\Service;

use Drupal\block_content\BlockContentInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;

class BlockParser {
  protected $entityTypeManager;
  protected $logger;
  protected $messenger;
  protected $assetCreator;

  protected $currentUser;

  const TEXT_WITH_FORMAT_FIELD_TYPES = ['text_with_summary', 'text_long', 'text', 'string_long'];

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    MessengerInterface $messenger,
    AIAssetCreator $asset_creator,
    AccountProxyInterface $current_user
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('moody_ai_assistant');
    $this->messenger = $messenger;
    $this->assetCreator = $asset_creator;
    $this->currentUser = $current_user;
  }

  public function createBlocksFromInstructions($instructions) {
    $blocks = [];

    try {
      if (empty($instructions['instructions'])) {
        throw new \Exception('No instructions provided for block creation');
      }

      $this->assertInstructionPayloads($instructions);

      foreach ($instructions['instructions'] as $instruction) {
        $block_type = (string) ($instruction['block_type'] ?? '');
        if ($block_type === '' || !$this->entityTypeManager->getAccessControlHandler('block_content')->createAccess($block_type, $this->currentUser)) {
          throw new \Exception('You do not have permission to create the selected block type.');
        }
        /** @var \Drupal\block_content\Entity\BlockContent $block */
        $block = $this->entityTypeManager->getStorage('block_content')
          ->create([
            'type' => $instruction['block_type'],
            'info' => !empty($instructions['block_title'])
              ? $instructions['block_title']
              : 'AI Generated Block - ' . date('Y-m-d H:i:s'),
            'reusable' => $instructions['reusable'] ?? TRUE,
          ]);
        $blocks[] = $this->applyInstructionToBlock($block, $instruction, $instructions, TRUE);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Error creating blocks: @error', ['@error' => $e->getMessage()]);
      $this->messenger->addError(t('Error creating block: @error', ['@error' => $e->getMessage()]));
      throw $e;
    }

    return $blocks;
  }

  /**
   * Applies an instruction payload to an existing block and saves a new revision.
   *
   * @param \Drupal\block_content\BlockContentInterface $block
   *   The target block.
   * @param array $instructions
   *   The top-level instruction payload.
   *
   * @return \Drupal\block_content\BlockContentInterface
   *   The saved block revision.
   */
  public function updateBlockFromInstructions(BlockContentInterface $block, array $instructions, ?EntityInterface $access_dependency = NULL) {
    if (empty($instructions['instructions'][0])) {
      throw new \Exception('No instructions provided for block update.');
    }

    $this->assertInstructionPayloads($instructions);
    if ($access_dependency && method_exists($block, 'setAccessDependency')) {
      $block->setAccessDependency($access_dependency);
    }
    if (!$block->access('update', $this->currentUser)) {
      throw new \Exception('You do not have permission to update the selected block.');
    }

    $block->setNewRevision(TRUE);
    return $this->applyInstructionToBlock($block, $instructions['instructions'][0], $instructions, FALSE);
  }

  /**
   * Validates known custom block payloads before entity creation/update.
   *
   * @param array $instructions
   *   The top-level instruction payload.
   *
   * @throws \Exception
   *   Thrown when a known block payload is structurally empty.
   */
  protected function assertInstructionPayloads(array $instructions) {
    foreach (($instructions['instructions'] ?? []) as $instruction) {
      $block_type = (string) ($instruction['block_type'] ?? '');
      $field_info = $instruction['field_info'] ?? [];

      if ($block_type === 'moody_impact_facts' && !$this->hasUsableImpactFactsPayload($field_info)) {
        throw new \Exception('Moody Impact Facts blocks must include at least one non-empty fact item plus valid style and items-per-row options.');
      }
    }
  }

  /**
   * Determines whether the payload contains a usable Impact Facts value.
   *
   * @param array $field_info
   *   The instruction field info array.
   *
   * @return bool
   *   TRUE when a usable Impact Facts payload is present.
   */
  protected function hasUsableImpactFactsPayload(array $field_info) {
    foreach ($field_info as $field_data) {
      if (($field_data['type'] ?? '') !== 'moody_impact_facts' && ($field_data['value']['items'] ?? NULL) === NULL && ($field_data['items'] ?? NULL) === NULL && ($field_data['facts'] ?? NULL) === NULL) {
        continue;
      }

      $normalized = $this->normalizeImpactFactsFieldValue((array) $field_data);
      if (!empty($normalized['impact_items'])) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Serializes an existing block into instruction-like field data.
   *
   * @param \Drupal\block_content\BlockContentInterface $block
   *   The block to serialize.
   *
   * @return array
   *   Instruction-like block data for revision context.
   */
  public function exportBlockToInstruction(BlockContentInterface $block) {
    $field_info = [];

    foreach ($block->getFields() as $field_name => $field_item_list) {
      if ($field_item_list->getFieldDefinition()->getFieldStorageDefinition()->isBaseField()) {
        continue;
      }

      if ($field_item_list->isEmpty()) {
        continue;
      }

      $first_item = $field_item_list->first();
      $raw_value = $field_item_list->getValue();
      $field_type = $field_item_list->getFieldDefinition()->getType();

      $single_value = count($raw_value) === 1 && is_array($raw_value[0] ?? NULL)
        ? $raw_value[0]
        : NULL;

      if (in_array($field_type, self::TEXT_WITH_FORMAT_FIELD_TYPES, TRUE) && $single_value !== NULL) {
        $field_info[$field_name] = [
          'type' => $field_type,
          'value' => $single_value['value'] ?? '',
          'format' => $single_value['format'] ?? 'full_html',
        ];
        continue;
      }

      if ($single_value !== NULL && array_key_exists('value', $single_value)) {
        $field_info[$field_name] = [
          'type' => $field_type,
          'value' => $single_value['value'],
        ];
        continue;
      }

      if ($single_value !== NULL && count($single_value) === 1 && array_key_exists('target_id', $single_value)) {
        $field_info[$field_name] = [
          'type' => $field_type,
          'target_id' => $single_value['target_id'],
        ];
        continue;
      }

      if ($field_type === 'utexas_promo_unit' && !empty($raw_value[0]) && is_array($raw_value[0])) {
        $field_info[$field_name] = [
          'type' => $field_type,
          'value' => $this->exportPromoUnitValue($raw_value[0]),
        ];
        continue;
      }

      if ($field_type === 'moody_impact_facts' && !empty($raw_value[0]) && is_array($raw_value[0])) {
        $field_info[$field_name] = [
          'type' => $field_type,
          'value' => $this->exportImpactFactsValue($raw_value[0]),
        ];
        continue;
      }

      if ($field_type === 'moody_flex_grid' && !empty($raw_value[0]) && is_array($raw_value[0])) {
        $field_info[$field_name] = [
          'type' => $field_type,
          'value' => $this->exportFlexGridValue($raw_value[0]),
        ];
        continue;
      }

      $field_info[$field_name] = [
        'type' => $field_type,
        'value' => count($raw_value) === 1 ? $raw_value[0] : $raw_value,
      ];
    }

    return [
      'block_type' => $block->bundle(),
      'description' => 'Existing block state',
      'field_info' => $field_info,
    ];
  }

  /**
   * Applies a single instruction to a block entity.
   */
  protected function applyInstructionToBlock(BlockContentInterface $block, array $instruction, array $instructions, $is_new) {
    $field_info = $instruction['field_info'] ?? [];
    foreach ($field_info as $field_name => $field_data) {
      $field_data = is_array($field_data) ? $field_data : ['value' => $field_data];
      if (!$block->hasField($field_name)) {
        $this->logger->warning('Field @field does not exist on block type @type', [
          '@field' => $field_name,
          '@type' => $instruction['block_type'] ?? $block->bundle(),
        ]);
        continue;
      }

      $field_definition = $block->getFieldDefinition($field_name);
      $field_type = $field_definition->getType();
      $target_type = $field_definition->getFieldStorageDefinition()->getSetting('target_type');
      $is_required = $field_definition->isRequired();

      if ($this->isCompoundField($field_definition)) {
        $items = $this->buildCompoundFieldItems($field_name, $field_data, $field_definition, $instructions);
        if (!empty($items)) {
          $block->set($field_name, $items);
          continue;
        }

        if ($is_required) {
          throw new \Exception(sprintf('Required field "%s" did not receive a usable compound field payload.', $field_name));
        }

        continue;
      }

      if ($field_type === 'entity_reference' && $target_type === 'media') {
        $media_reference = $this->resolveMediaReference($field_name, (array) $field_data, $field_definition, $instructions);
        if ($media_reference !== NULL) {
          $block->set($field_name, ['target_id' => $media_reference]);
          continue;
        }

        if ($is_required) {
          throw new \Exception(sprintf('Required media field "%s" could not be populated with a generated asset.', $field_name));
        }

        continue;
      }

      if ($field_type === 'moody_impact_facts') {
        $normalized = $this->normalizeImpactFactsFieldValue((array) $field_data);
        if (!empty($normalized)) {
          $block->set($field_name, [$normalized]);
          continue;
        }

        if ($is_required) {
          throw new \Exception(sprintf('Required field "%s" did not receive a usable Moody Impact Facts payload.', $field_name));
        }

        continue;
      }

      if ($field_type === 'moody_flex_grid') {
        $normalized = $this->normalizeFlexGridFieldValue($field_name, (array) $field_data, $instructions);
        if (!empty($normalized)) {
          $block->set($field_name, [$normalized]);
          continue;
        }

        if ($is_required) {
          throw new \Exception(sprintf('Required field "%s" did not receive a usable Moody Flex Grid payload.', $field_name));
        }

        continue;
      }

      if ($field_type === 'moody_hero') {
        $normalized = $this->normalizeMoodyHeroFieldValue($field_name, (array) $field_data, $instructions);
        if (!empty($normalized)) {
          $block->set($field_name, [$normalized]);
          continue;
        }

        if ($is_required) {
          throw new \Exception(sprintf('Required field "%s" did not receive a usable Moody Hero payload.', $field_name));
        }

        continue;
      }

      if ($field_type === 'utexas_promo_unit') {
        $normalized = $this->normalizePromoUnitFieldValue($field_name, (array) $field_data, $instructions);
        if (!empty($normalized['promo_unit_items'])) {
          $block->set($field_name, [$normalized]);
          continue;
        }

        throw new \Exception(sprintf('Promo Unit field "%s" must include at least one non-empty item with visible content.', $field_name));
      }

      if ($field_type === 'utexas_promo_list') {
        $normalized = $this->normalizePromoListFieldValue($field_name, $field_data, $instructions);
        if (!empty($normalized['promo_list_items'])) {
          $block->set($field_name, [$normalized]);
          continue;
        }

        throw new \Exception(sprintf('Promo List field "%s" must include at least one non-empty item with visible content.', $field_name));
      }

      if ($field_type === 'utexas_resources') {
        $normalized = $this->normalizeResourcesFieldValue($field_name, $field_data, $instructions);
        if (!empty($normalized['resource_items'])) {
          $block->set($field_name, [$normalized]);
          continue;
        }

        throw new \Exception(sprintf('Resources field "%s" must include at least one non-empty item with visible content.', $field_name));
      }

      if ($field_type === 'moody_focus_areas') {
        $normalized = $this->normalizeFocusAreasFieldValue($field_name, $field_data, $instructions);
        if (!empty($normalized['focus_areas_items'])) {
          $block->set($field_name, [$normalized]);
          continue;
        }

        throw new \Exception(sprintf('Focus Areas field "%s" must include at least one non-empty item with visible content.', $field_name));
      }

      if ($field_type === 'utexas_flex_content_area') {
        $normalized = $this->normalizeFlexContentAreaFieldValue($field_name, $field_data, $instructions);
        if ($normalized) {
          $block->set($field_name, [$normalized]);
          continue;
        }

        if ($is_required) {
          throw new \Exception(sprintf('Flex Content Area field "%s" did not receive usable content.', $field_name));
        }
        continue;
      }

      if (in_array($field_type, self::TEXT_WITH_FORMAT_FIELD_TYPES, TRUE)) {
        $block->set($field_name, $this->normalizeTextFieldValue((array) $field_data));
      }
      else {
        $value = $field_data['value'] ?? NULL;
        if (is_array($value) && array_key_exists('value', $value) && !array_key_exists('target_id', $value)) {
          $value = $value['value'];
        }
        if ($value === NULL && array_key_exists('target_id', $field_data)) {
          $value = ['target_id' => $field_data['target_id']];
        }

        if ($field_type === 'entity_reference' && $target_type === 'entity_view_mode') {
          $value = $this->normalizeEntityViewModeReference($value);
        }

        if ($value === NULL || $value === '') {
          if ($is_required) {
            throw new \Exception(sprintf('Required field "%s" did not receive a usable value.', $field_name));
          }
          continue;
        }
        $block->set($field_name, $value);
      }
    }

    $violations = $block->validate();
    if (count($violations) !== 0) {
      $messages = [];
      foreach ($violations as $violation) {
        $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
      }
      throw new \Exception('Block validation failed: ' . implode('; ', $messages));
    }

    $block->save();
    $this->messenger->addStatus($is_new ? t('Created block: @title', ['@title' => $block->label()]) : t('Updated block: @title', ['@title' => $block->label()]));
    return $block;
  }

  /**
   * Normalizes generated text field payloads into Drupal's expected structure.
   *
   * @param array $field_data
   *   The generated field payload.
   *
   * @return array
   *   A Drupal-compatible text field value.
   */
  protected function normalizeTextFieldValue(array $field_data) {
    $value = $field_data['value'] ?? '';
    $summary = $field_data['summary'] ?? '';

    if (is_array($value)) {
      if (array_key_exists('value', $value)) {
        $summary = $summary !== '' ? $summary : ($value['summary'] ?? '');
        $field_data['format'] = $field_data['format'] ?? ($value['format'] ?? NULL);
        $value = $value['value'];
      }
      else {
        $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      }
    }

    if (!is_scalar($value)) {
      $value = '';
    }

    return [
      'value' => (string) $value,
      'format' => $field_data['format'] ?? 'full_html',
      'summary' => is_scalar($summary) ? (string) $summary : '',
    ];
  }

  /**
   * Normalizes Moody Impact Facts field payloads into stored field properties.
   *
   * @param array $field_data
   *   The generated field payload.
   *
   * @return array
   *   A Drupal-compatible field item value.
   */
  protected function normalizeImpactFactsFieldValue(array $field_data) {
    $value = $field_data['value'] ?? [];
    if (is_array($value) && $value) {
      $field_data = $value + $field_data;
    }

    $headline = $this->normalizeScalarString($field_data['headline'] ?? '');
    $style = $this->normalizeImpactFactsOption($field_data['style'] ?? '', [
      'orange-headline',
      'grey-headline',
    ], 'orange-headline');
    $col_number = $this->normalizeImpactFactsOption($field_data['col_number'] ?? '', [
      'two-per-row',
      'three-per-row',
      'four-per-row',
    ], 'three-per-row');

    $impact_items = $field_data['impact_items'] ?? ($field_data['items'] ?? ($field_data['facts'] ?? []));
    $normalized_items = $this->normalizeImpactFactsItems($impact_items);

    return [
      'headline' => $headline,
      'style' => $style,
      'col_number' => $col_number,
      'impact_items' => $normalized_items ? serialize($normalized_items) : NULL,
    ];
  }

  /**
   * Normalizes Moody Flex Grid field payloads into stored field properties.
   *
   * @param string $field_name
   *   The field machine name.
   * @param array $field_data
   *   The generated field payload.
   * @param array $instructions
   *   The top-level instruction payload.
   *
   * @return array
   *   A Drupal-compatible field item value.
   */
  protected function normalizeFlexGridFieldValue($field_name, array $field_data, array $instructions) {
    $value = $field_data['value'] ?? [];
    if (is_array($value) && $value) {
      $field_data = $value + $field_data;
    }

    $headline = $this->normalizeScalarString($field_data['headline'] ?? '');
    $style = $this->normalizeImpactFactsOption($field_data['style'] ?? '', [
      'one',
      'two',
      'three',
      'four',
      'five',
      'six',
    ], 'three');

    $raw_items = $field_data['flex_grid_items'] ?? ($field_data['items'] ?? []);
    $normalized_items = $this->normalizeFlexGridItems($field_name, $raw_items, $field_data, $instructions);

    return array_filter([
      'headline' => $headline,
      'style' => $style,
      'rounded_edges' => !empty($field_data['rounded_edges']) ? 1 : 0,
      'overlay_text' => !empty($field_data['overlay_text']) ? 1 : 0,
      'flex_grid_items' => $normalized_items ? serialize($normalized_items) : NULL,
    ], function ($item_value) {
      return $item_value !== NULL && $item_value !== '';
    });
  }

  /**
   * Normalizes Promo Unit field payloads into stored field properties.
   *
   * @param string $field_name
   *   The field machine name.
   * @param array $field_data
   *   The generated field payload.
   * @param array $instructions
   *   The top-level instruction payload.
   *
   * @return array
   *   A Drupal-compatible field item value.
   */
  protected function normalizePromoUnitFieldValue($field_name, array $field_data, array $instructions) {
    $value = $field_data['value'] ?? $field_data;
    if (is_array($value) && array_key_exists('value', $value) && is_array($value['value'])) {
      $value = $value['value'];
    }
    if (!is_array($value)) {
      $value = [];
    }

    $headline = $this->normalizeScalarString($value['headline'] ?? $field_data['headline'] ?? '');
    $raw_items = $value['items'] ?? $value['promo_unit_items'] ?? $field_data['items'] ?? $field_data['promo_unit_items'] ?? [];
    $raw_items = $this->decodeStructuredItemCollection($raw_items);

    if ($this->isAssociativeArray($raw_items)) {
      $raw_items = [$raw_items];
    }

    if (empty($raw_items) && $this->looksLikePromoUnitItem($value)) {
      $raw_items = [$value];
    }
    if (empty($raw_items) && $this->looksLikePromoUnitItem($field_data)) {
      $raw_items = [$field_data];
    }

    $planned_asset = $this->getPlannedAssetForField($field_name, $instructions);
    $normalized_items = [];
    foreach ($raw_items as $delta => $raw_item) {
      if (!is_array($raw_item)) {
        continue;
      }

      $item_values = isset($raw_item['item']) && is_array($raw_item['item']) ? $raw_item['item'] : $raw_item;
      $item_headline = $this->normalizeScalarString($item_values['headline'] ?? '');
      $copy_value = $this->normalizeScalarString($item_values['copy_value'] ?? ($item_values['copy']['value'] ?? ''));
      $copy_format = $this->normalizeScalarString($item_values['copy_format'] ?? ($item_values['copy']['format'] ?? ''));
      if ($copy_value !== '' && $copy_format === '') {
        $copy_format = 'flex_html';
      }

      $link_uri = $this->normalizeLinkUri($item_values['link_uri'] ?? ($item_values['link']['uri'] ?? ''));
      $link_title = $this->normalizeScalarString($item_values['link_title'] ?? ($item_values['link']['title'] ?? ''));
      $link_options = $item_values['link_options'] ?? ($item_values['link']['options'] ?? []);
      $link_options = is_array($link_options) ? $link_options : [];

      $image_candidate = $item_values['image'] ?? $item_values['media'] ?? NULL;
      $image = $this->resolveCompoundMediaValue('image', $image_candidate, $field_data, $planned_asset, (int) $delta, $instructions);

      $normalized_item = array_filter([
        'headline' => $item_headline,
        'image' => $image ?: NULL,
        'copy' => $copy_value !== '' ? [
          'value' => $copy_value,
          'format' => $copy_format !== '' ? $copy_format : 'flex_html',
        ] : NULL,
        'link' => $link_uri !== '' ? array_filter([
          'uri' => $link_uri,
          'title' => $link_title,
          'options' => $link_options ?: NULL,
        ], function ($item_value) {
          return $item_value !== NULL && $item_value !== '' && $item_value !== [];
        }) : NULL,
      ], function ($item_value) {
        return $item_value !== NULL && $item_value !== '' && $item_value !== [];
      });

      if (!$normalized_item) {
        continue;
      }

      $normalized_items[] = ['item' => $normalized_item];
    }

    return array_filter([
      'headline' => $headline,
      'promo_unit_items' => $normalized_items ? serialize(array_values($normalized_items)) : NULL,
    ], function ($item_value) {
      return $item_value !== NULL && $item_value !== '';
    });
  }

  /**
   * Normalizes Promo List payloads and protects its serialized storage format.
   */
  protected function normalizePromoListFieldValue($field_name, array $field_data, array $instructions) {
    $value = $field_data['value'] ?? $field_data;
    if (!is_array($value)) {
      $value = [];
    }

    $raw_items = $value['items'] ?? $value['promo_list_items'] ?? $field_data['items'] ?? $field_data['promo_list_items'] ?? [];
    if (is_string($raw_items) && !$this->decodeStructuredItemCollection($raw_items)) {
      $raw_items = $this->extractPromoListItemsFromHtml($raw_items);
    }

    $value['items'] = $raw_items;
    $normalized = $this->normalizePromoUnitFieldValue($field_name, ['value' => $value], $instructions);

    return array_filter([
      'headline' => $normalized['headline'] ?? '',
      'promo_list_items' => $normalized['promo_unit_items'] ?? NULL,
    ], function ($item_value) {
      return $item_value !== NULL && $item_value !== '';
    });
  }

  /**
   * Converts a generated HTML list into Promo List item data.
   */
  protected function extractPromoListItemsFromHtml($html) {
    $html = trim((string) $html);
    if ($html === '') {
      return [];
    }

    $document = Html::load($html);
    $items = [];
    foreach ($document->getElementsByTagName('li') as $list_item) {
      $link = $list_item->getElementsByTagName('a')->item(0);
      $headline = trim($link ? $link->textContent : $list_item->textContent);
      $copy = trim($list_item->textContent);
      if ($headline !== '' && str_starts_with($copy, $headline)) {
        $copy = ltrim(mb_substr($copy, mb_strlen($headline)), " \t\n\r\0\x0B—–:-");
      }

      $item = array_filter([
        'headline' => $headline,
        'copy_value' => $copy,
        'copy_format' => $copy !== '' ? 'flex_html' : '',
        'link_uri' => $link ? trim($link->getAttribute('href')) : '',
        'link_title' => $link ? $headline : '',
      ], function ($value) {
        return $value !== '';
      });
      if ($item) {
        $items[] = $item;
      }
    }

    return $items;
  }

  /**
   * Normalizes Resources payloads and protects its serialized storage format.
   */
  protected function normalizeResourcesFieldValue($field_name, array $field_data, array $instructions) {
    $value = $field_data['value'] ?? $field_data;
    if (!is_array($value)) {
      $value = [];
    }

    $raw_items = $value['items'] ?? $value['resource_items'] ?? $field_data['items'] ?? $field_data['resource_items'] ?? [];
    if (is_string($raw_items) && !$this->decodeStructuredItemCollection($raw_items)) {
      $raw_items = $this->extractResourceItemsFromHtml($raw_items);
    }
    else {
      $raw_items = $this->decodeStructuredItemCollection($raw_items);
    }
    if ($this->isAssociativeArray($raw_items)) {
      $raw_items = [$raw_items];
    }

    $planned_asset = $this->getPlannedAssetForField($field_name, $instructions);
    $normalized_items = [];
    foreach ($raw_items as $delta => $raw_item) {
      if (!is_array($raw_item)) {
        continue;
      }

      $item_values = isset($raw_item['item']) && is_array($raw_item['item']) ? $raw_item['item'] : $raw_item;
      $links = $item_values['links'] ?? [];
      if (!$links && (!empty($item_values['link_uri']) || !empty($item_values['link']))) {
        $links = [$item_values['link'] ?? [
          'uri' => $item_values['link_uri'] ?? '',
          'title' => $item_values['link_title'] ?? '',
        ]];
      }
      if ($this->isAssociativeArray($links)) {
        $links = [$links];
      }

      $normalized_links = [];
      foreach ($links as $link) {
        if (!is_array($link)) {
          continue;
        }
        $uri = $this->normalizeLinkUri($link['uri'] ?? '');
        if ($uri === '') {
          continue;
        }
        $normalized_links[] = [
          'uri' => $uri,
          'title' => $this->normalizeScalarString($link['title'] ?? ''),
          'options' => is_array($link['options'] ?? NULL) ? $link['options'] : [],
        ];
      }

      $image = $this->resolveCompoundMediaValue('image', $item_values['image'] ?? NULL, $field_data, $planned_asset, (int) $delta, $instructions);
      $item = array_filter([
        'headline' => $this->normalizeScalarString($item_values['headline'] ?? ''),
        'image' => $image ?: NULL,
        'links' => $normalized_links,
      ], function ($item_value) {
        return $item_value !== NULL && $item_value !== '' && $item_value !== [];
      });
      if ($item) {
        $normalized_items[] = ['item' => $item];
      }
    }

    return array_filter([
      'headline' => $this->normalizeScalarString($value['headline'] ?? $field_data['headline'] ?? ''),
      'resource_items' => $normalized_items ? serialize(array_values($normalized_items)) : NULL,
    ], function ($item_value) {
      return $item_value !== NULL && $item_value !== '';
    });
  }

  /**
   * Converts a generated HTML link list into Resources item data.
   */
  protected function extractResourceItemsFromHtml($html) {
    $document = Html::load(trim((string) $html));
    $items = [];
    foreach ($document->getElementsByTagName('li') as $list_item) {
      $link = $list_item->getElementsByTagName('a')->item(0);
      if (!$link || trim($link->getAttribute('href')) === '') {
        continue;
      }

      $headline = trim($link->textContent);
      $items[] = [
        'headline' => $headline,
        'links' => [[
          'uri' => trim($link->getAttribute('href')),
          'title' => $headline,
        ]],
      ];
    }

    return $items;
  }

  /**
   * Normalizes Moody Focus Areas payloads and serialized nested items.
   */
  protected function normalizeFocusAreasFieldValue($field_name, array $field_data, array $instructions) {
    $value = $field_data['value'] ?? $field_data;
    if (!is_array($value)) {
      $value = [];
    }

    $raw_items = $value['items'] ?? $value['focus_areas_items'] ?? $field_data['items'] ?? $field_data['focus_areas_items'] ?? [];
    if (is_string($raw_items) && !$this->decodeStructuredItemCollection($raw_items)) {
      $raw_items = $this->extractFocusAreaItemsFromHtml($raw_items);
    }
    else {
      $raw_items = $this->decodeStructuredItemCollection($raw_items);
    }
    if ($this->isAssociativeArray($raw_items)) {
      $raw_items = [$raw_items];
    }

    $planned_asset = $this->getPlannedAssetForField($field_name, $instructions);
    $normalized_items = [];
    foreach ($raw_items as $delta => $raw_item) {
      if (!is_array($raw_item)) {
        continue;
      }
      $item_values = isset($raw_item['item']) && is_array($raw_item['item']) ? $raw_item['item'] : $raw_item;
      $copy_value = $this->normalizeScalarString($item_values['copy_value'] ?? ($item_values['copy']['value'] ?? ''));
      $link_uri = $this->normalizeLinkUri($item_values['link_uri'] ?? ($item_values['link']['uri'] ?? ''));
      $image = $this->resolveCompoundMediaValue('image', $item_values['image'] ?? NULL, $field_data, $planned_asset, (int) $delta, $instructions);
      $item = array_filter([
        'headline' => $this->normalizeScalarString($item_values['headline'] ?? ''),
        'image' => $image ?: NULL,
        'copy' => $copy_value !== '' ? [
          'value' => $copy_value,
          'format' => $this->normalizeScalarString($item_values['copy_format'] ?? ($item_values['copy']['format'] ?? 'flex_html')) ?: 'flex_html',
        ] : NULL,
        'link' => $link_uri !== '' ? [
          'uri' => $link_uri,
          'title' => $this->normalizeScalarString($item_values['link_title'] ?? ($item_values['link']['title'] ?? '')),
          'options' => is_array($item_values['link_options'] ?? ($item_values['link']['options'] ?? NULL)) ? ($item_values['link_options'] ?? $item_values['link']['options']) : [],
        ] : NULL,
      ], function ($item_value) {
        return $item_value !== NULL && $item_value !== '' && $item_value !== [];
      });
      if ($item) {
        $normalized_items[] = ['item' => $item];
      }
    }

    return array_filter([
      'link_uri' => $this->normalizeLinkUri($value['link_uri'] ?? ''),
      'link_title' => $this->normalizeScalarString($value['link_title'] ?? ''),
      'link_options' => is_array($value['link_options'] ?? NULL) ? $value['link_options'] : [],
      'items_style' => $this->normalizeScalarString($value['items_style'] ?? '') ?: 'default',
      'items_gap' => isset($value['items_gap']) ? max(0, (int) $value['items_gap']) : 3,
      'items_row_gap' => isset($value['items_row_gap']) ? max(0, (int) $value['items_row_gap']) : 3,
      'items_title' => $this->normalizeScalarString($value['items_title'] ?? $value['headline'] ?? ''),
      'focus_areas_items' => $normalized_items ? serialize(array_values($normalized_items)) : NULL,
    ], function ($item_value) {
      return $item_value !== NULL && $item_value !== '';
    });
  }

  /**
   * Converts generated heading/copy groups into Focus Areas item data.
   */
  protected function extractFocusAreaItemsFromHtml($html) {
    $document = Html::load(trim((string) $html));
    $items = [];
    foreach ($document->getElementsByTagName('div') as $group) {
      $heading = NULL;
      foreach (['h2', 'h3', 'h4'] as $tag) {
        $heading = $group->getElementsByTagName($tag)->item(0);
        if ($heading) {
          break;
        }
      }
      if (!$heading) {
        continue;
      }
      $paragraph = $group->getElementsByTagName('p')->item(0);
      $items[] = [
        'headline' => trim($heading->textContent),
        'copy_value' => $paragraph ? trim($paragraph->textContent) : '',
        'copy_format' => 'flex_html',
      ];
    }

    return $items;
  }

  /**
   * Normalizes Flex Content Area links before they reach serialized storage.
   */
  protected function normalizeFlexContentAreaFieldValue($field_name, array $field_data, array $instructions) {
    $value = $field_data['value'] ?? $field_data;
    if (!is_array($value)) {
      $value = [];
    }

    $raw_links = $value['links'] ?? $field_data['links'] ?? [];
    $raw_links = $this->decodeStructuredItemCollection($raw_links);
    if ($this->isAssociativeArray($raw_links)) {
      $raw_links = [$raw_links];
    }
    $links = [];
    foreach ($raw_links as $link) {
      if (!is_array($link)) {
        continue;
      }
      $uri = $this->normalizeLinkUri($link['uri'] ?? $link['link_uri'] ?? '');
      if ($uri === '') {
        continue;
      }
      $links[] = [
        'uri' => $uri,
        'title' => $this->normalizeScalarString($link['title'] ?? $link['link_title'] ?? ''),
        'options' => is_array($link['options'] ?? NULL) ? $link['options'] : [],
      ];
    }

    $planned_asset = $this->getPlannedAssetForField($field_name, $instructions);
    $image = $this->resolveCompoundMediaValue('image', $value['image'] ?? NULL, $field_data, $planned_asset, 0, $instructions);
    $copy_value = $this->normalizeScalarString($value['copy_value'] ?? ($value['copy']['value'] ?? ''));
    $link_uri = $this->normalizeLinkUri($value['link_uri'] ?? '');

    return [
      'image' => $image ?: 0,
      'headline' => $this->normalizeScalarString($value['headline'] ?? ''),
      'copy_value' => $copy_value,
      'copy_format' => $copy_value !== '' ? ($this->normalizeScalarString($value['copy_format'] ?? ($value['copy']['format'] ?? 'flex_html')) ?: 'flex_html') : '',
      'links' => serialize(array_values($links)),
      'link_uri' => $link_uri,
      'link_text' => $link_uri !== '' ? $this->normalizeScalarString($value['link_text'] ?? $value['link_title'] ?? '') : '',
      'link_options' => is_array($value['link_options'] ?? NULL) ? $value['link_options'] : [],
    ];
  }

  /**
   * Resolves generated entity view mode aliases to an installed view mode.
   */
  protected function normalizeEntityViewModeReference($value) {
    $target_id = is_array($value)
      ? $this->normalizeScalarString($value['target_id'] ?? $value['value'] ?? '')
      : $this->normalizeScalarString($value);
    $storage = $this->entityTypeManager->getStorage('entity_view_mode');
    $candidates = [$target_id];
    if ($target_id !== '' && !str_contains($target_id, '.')) {
      $candidates[] = 'node.' . $target_id;
    }
    $candidates[] = 'node.full';

    foreach (array_unique(array_filter($candidates)) as $candidate) {
      if ($storage->load($candidate)) {
        return ['target_id' => $candidate];
      }
    }

    throw new \Exception('No usable content view mode is configured for this block.');
  }

  /**
   * Normalizes Moody Hero payloads into stored field properties.
   */
  protected function normalizeMoodyHeroFieldValue($field_name, array $field_data, array $instructions) {
    $value = $field_data['value'] ?? $field_data;
    if (is_array($value) && array_key_exists('value', $value) && is_array($value['value'])) {
      $value = $value['value'];
    }
    if (!is_array($value)) {
      $value = [];
    }

    $planned_asset = $this->getPlannedAssetForField($field_name, $instructions);
    $media = $this->resolveCompoundMediaValue('media', $value['media'] ?? $field_data['media'] ?? NULL, $field_data, $planned_asset, 0, $instructions);
    $heading = $this->normalizeScalarString($value['heading'] ?? $field_data['heading'] ?? '');
    $subheading = $this->normalizeScalarString($value['subheading'] ?? $field_data['subheading'] ?? '');
    $caption = $this->normalizeScalarString($value['caption'] ?? $field_data['caption'] ?? '');
    $credit = $this->normalizeScalarString($value['credit'] ?? $field_data['credit'] ?? '');
    $link_uri = $this->normalizeLinkUri($value['link_uri'] ?? ($value['link']['uri'] ?? ($field_data['link_uri'] ?? '')));
    $link_title = $this->normalizeScalarString($value['link_title'] ?? ($value['link']['title'] ?? ($field_data['link_title'] ?? '')));
    $link_options = $value['link_options'] ?? ($value['link']['options'] ?? ($field_data['link_options'] ?? []));
    $link_options = is_array($link_options) ? $link_options : [];

    if ($media === 0 && $heading === '' && $subheading === '' && $caption === '' && $credit === '' && $link_uri === '') {
      return [];
    }

    return array_filter([
      'media' => $media ?: 0,
      'heading' => $heading,
      'subheading' => $subheading,
      'caption' => $caption,
      'credit' => $credit,
      'text_position' => $this->normalizeMoodyHeroOption($value['text_position'] ?? $field_data['text_position'] ?? '', [
        'center' => 'centered',
        'centered' => 'centered',
        'middle' => 'centered',
        'left' => 'top-left',
        'top-left' => 'top-left',
        'upper-left' => 'top-left',
        'right' => 'top-right',
        'top-right' => 'top-right',
        'upper-right' => 'top-right',
        'bottom-left' => 'bottom-left',
        'lower-left' => 'bottom-left',
        'bottom-right' => 'bottom-right',
        'lower-right' => 'bottom-right',
      ], 'centered'),
      'text_color' => $this->normalizeMoodyHeroOption($value['text_color'] ?? $field_data['text_color'] ?? '', [
        'white' => 'white-text',
        'white-text' => 'white-text',
        'light' => 'white-text',
        'orange' => 'orange-text',
        'orange-text' => 'orange-text',
        'charcoal' => 'charcoal-text',
        'charcoal-text' => 'charcoal-text',
        'dark' => 'charcoal-text',
        'black' => 'charcoal-text',
      ], 'white-text'),
      'overlay' => $this->normalizeMoodyHeroOption($value['overlay'] ?? $field_data['overlay'] ?? '', [
        'none' => 'no-overlay',
        'no' => 'no-overlay',
        'no-overlay' => 'no-overlay',
        'orange' => 'orange-overlay',
        'orange-overlay' => 'orange-overlay',
        'charcoal' => 'charcoal-overlay',
        'charcoal-overlay' => 'charcoal-overlay',
        'dark' => 'charcoal-overlay',
        'heavy-orange' => 'heavy-orange-overlay',
        'heavy-orange-overlay' => 'heavy-orange-overlay',
        'heavy-charcoal' => 'heavy-charcoal-overlay',
        'heavy-charcoal-overlay' => 'heavy-charcoal-overlay',
        'heavy-dark' => 'heavy-charcoal-overlay',
      ], 'no-overlay'),
      'link_uri' => $link_uri,
      'link_title' => $link_title,
      'link_options' => $link_options,
      'disable_image_styles' => !empty($value['disable_image_styles'] ?? $field_data['disable_image_styles'] ?? 0) ? 1 : 0,
    ], function ($item_value, $item_key) {
      if ($item_key === 'media' || $item_key === 'disable_image_styles') {
        return TRUE;
      }
      return $item_value !== NULL && $item_value !== '';
    }, ARRAY_FILTER_USE_BOTH);
  }

  /**
   * Normalizes Moody Hero enum-like options.
   */
  protected function normalizeMoodyHeroOption($value, array $map, $default) {
    $normalized = strtolower($this->normalizeScalarString($value));
    return $map[$normalized] ?? $default;
  }

  /**
   * Normalizes the nested impact fact items array.
   *
   * @param mixed $impact_items
   *   The generated items payload.
   *
   * @return array
   *   Consecutive serialized-item-ready values.
   */
  protected function normalizeImpactFactsItems($impact_items) {
    $impact_items = $this->decodeStructuredItemCollection($impact_items);

    $normalized = [];
    foreach ($impact_items as $item) {
      if (!is_array($item)) {
        continue;
      }

      $item_values = isset($item['item']) && is_array($item['item']) ? $item['item'] : $item;
      $headline = $this->normalizeScalarString($item_values['headline'] ?? '');
      $subheadline = $this->normalizeScalarString($item_values['subheadline'] ?? '');

      if ($headline === '' && $subheadline === '') {
        continue;
      }

      $normalized[] = [
        'item' => array_filter([
          'headline' => $headline,
          'subheadline' => $subheadline,
        ], function ($value) {
          return $value !== '';
        }),
      ];
    }

    return array_values($normalized);
  }

  /**
   * Normalizes the nested Flex Grid items array.
   *
   * @param string $field_name
   *   The field machine name.
   * @param mixed $flex_grid_items
   *   The generated items payload.
   * @param array $field_data
   *   The full field payload.
   * @param array $instructions
   *   The top-level instruction payload.
   *
   * @return array
   *   Consecutive serialized-item-ready values.
   */
  protected function normalizeFlexGridItems($field_name, $flex_grid_items, array $field_data, array $instructions) {
    $flex_grid_items = $this->decodeStructuredItemCollection($flex_grid_items);

    if ($this->isAssociativeArray($flex_grid_items) && $this->looksLikeFlexGridItem($flex_grid_items)) {
      $flex_grid_items = [$flex_grid_items];
    }

    $planned_asset = $this->getPlannedAssetForField($field_name, $instructions);
    $normalized = [];
    foreach ($flex_grid_items as $delta => $item) {
      if (!is_array($item)) {
        continue;
      }

      $item_values = isset($item['item']) && is_array($item['item']) ? $item['item'] : $item;
      $item_headline = $this->normalizeScalarString(
        $item_values['headline'] ?? ($item_values['title'] ?? '')
      );
      $copy_value = $this->normalizeScalarString(
        $item_values['copy'] ?? ($item_values['copy_value'] ?? ($item_values['description'] ?? ''))
      );
      $copy_format = $this->normalizeScalarString(
        $item_values['copy_format'] ?? ($item_values['copy']['format'] ?? 'flex_html')
      );
      $headline_color = $this->normalizeImpactFactsOption(
        $item_values['headline_color'] ?? '',
        ['', 'burnt-orange', 'charcoal', 'white', 'black'],
        ''
      );
      $headline_alignment = $this->normalizeImpactFactsOption(
        $item_values['headline_alignment'] ?? '',
        ['left', 'center', 'right'],
        'left'
      );
      $link_uri = $this->normalizeLinkUri(
        $item_values['link_uri'] ?? ($item_values['link']['uri'] ?? ($item_values['cta_url'] ?? ''))
      );
      $link_title = $this->normalizeScalarString(
        $item_values['link_title'] ?? ($item_values['link']['title'] ?? '')
      );
      $link_button_text = $this->normalizeScalarString(
        $item_values['link_button_text'] ?? ($item_values['cta_text'] ?? '')
      );
      $link_button_alignment = $this->normalizeImpactFactsOption(
        $item_values['link_button_alignment'] ?? '',
        ['left', 'center', 'right'],
        'left'
      );
      $link_options = $item_values['link_options'] ?? ($item_values['link']['options'] ?? []);
      $link_options = is_array($link_options) ? $link_options : [];
      $image_candidate = $item_values['image'] ?? $item_values['media'] ?? NULL;
      $image = $this->resolveCompoundMediaValue('image', $image_candidate, $field_data, $planned_asset, (int) $delta, $instructions);

      if ($item_headline === '' && $copy_value === '' && $link_uri === '' && !$image) {
        continue;
      }

      $normalized_item = array_filter([
        'image' => $image ?: NULL,
        'headline' => $item_headline,
        'headline_color' => $headline_color,
        'copy' => $copy_value,
        'copy_format' => $copy_value !== '' ? ($copy_format ?: 'flex_html') : NULL,
        'headline_alignment' => $headline_alignment,
        'link' => $link_uri !== '' ? array_filter([
          'uri' => $link_uri,
          'title' => $link_title,
          'options' => $link_options ?: NULL,
        ], function ($item_value) {
          return $item_value !== NULL && $item_value !== '' && $item_value !== [];
        }) : NULL,
        'link_button_text' => $link_uri !== '' ? $link_button_text : NULL,
        'link_button_alignment' => $link_uri !== '' && $link_button_text !== '' ? $link_button_alignment : NULL,
      ], function ($item_value) {
        return $item_value !== NULL && $item_value !== '' && $item_value !== [];
      });

      if (!$normalized_item) {
        continue;
      }

      $normalized[] = ['item' => $normalized_item];
    }

    return array_values($normalized);
  }

  /**
   * Converts stored Promo Unit field values into prompt-friendly data.
   *
   * @param array $raw_value
   *   The stored field item values.
   *
   * @return array
   *   A prompt-friendly representation.
   */
  protected function exportPromoUnitValue(array $raw_value) {
    $items = [];
    $raw_items = $this->decodeStructuredItemCollection($raw_value['promo_unit_items'] ?? NULL);
    foreach ($raw_items as $item) {
      if (!is_array($item)) {
        continue;
      }

      $item_values = isset($item['item']) && is_array($item['item']) ? $item['item'] : $item;
      $items[] = array_filter([
        'headline' => $this->normalizeScalarString($item_values['headline'] ?? ''),
        'image' => $item_values['image'] ?? NULL,
        'copy_value' => $this->normalizeScalarString($item_values['copy']['value'] ?? ''),
        'copy_format' => $this->normalizeScalarString($item_values['copy']['format'] ?? ''),
        'link_uri' => $this->normalizeScalarString($item_values['link']['uri'] ?? ''),
        'link_title' => $this->normalizeScalarString($item_values['link']['title'] ?? ''),
        'link_options' => is_array($item_values['link']['options'] ?? NULL) ? $item_values['link']['options'] : NULL,
      ], function ($item_value) {
        return $item_value !== NULL && $item_value !== '' && $item_value !== [];
      });
    }

    return array_filter([
      'headline' => $this->normalizeScalarString($raw_value['headline'] ?? ''),
      'items' => $items,
    ], function ($item_value) {
      return $item_value !== NULL && $item_value !== '' && $item_value !== [];
    });
  }

  /**
   * Converts stored Impact Facts field values into prompt-friendly data.
   *
   * @param array $raw_value
   *   The stored field item values.
   *
   * @return array
   *   A prompt-friendly representation.
   */
  protected function exportImpactFactsValue(array $raw_value) {
    $items = [];
    $raw_items = $this->decodeStructuredItemCollection($raw_value['impact_items'] ?? NULL);
    foreach ($raw_items as $item) {
      if (!is_array($item)) {
        continue;
      }

      $item_values = isset($item['item']) && is_array($item['item']) ? $item['item'] : $item;
      $items[] = array_filter([
        'headline' => $this->normalizeScalarString($item_values['headline'] ?? ''),
        'subheadline' => $this->normalizeScalarString($item_values['subheadline'] ?? ''),
      ], function ($item_value) {
        return $item_value !== NULL && $item_value !== '';
      });
    }

    return array_filter([
      'headline' => $this->normalizeScalarString($raw_value['headline'] ?? ''),
      'style' => $this->normalizeScalarString($raw_value['style'] ?? ''),
      'col_number' => $this->normalizeScalarString($raw_value['col_number'] ?? ''),
      'items' => array_values(array_filter($items)),
    ], function ($item_value) {
      return $item_value !== NULL && $item_value !== '' && $item_value !== [];
    });
  }

  /**
   * Converts stored Flex Grid field values into prompt-friendly data.
   *
   * @param array $raw_value
   *   The stored field item values.
   *
   * @return array
   *   A prompt-friendly representation.
   */
  protected function exportFlexGridValue(array $raw_value) {
    $items = [];
    $raw_items = $this->decodeStructuredItemCollection($raw_value['flex_grid_items'] ?? NULL);
    foreach ($raw_items as $item) {
      if (!is_array($item)) {
        continue;
      }

      $item_values = isset($item['item']) && is_array($item['item']) ? $item['item'] : $item;
      $link = $item_values['link'] ?? [];
      $items[] = array_filter([
        'image' => !empty($item_values['image']) ? (int) $item_values['image'] : NULL,
        'headline' => $this->normalizeScalarString($item_values['headline'] ?? ''),
        'copy' => $this->normalizeScalarString($item_values['copy'] ?? ''),
        'headline_alignment' => $this->normalizeScalarString($item_values['headline_alignment'] ?? ''),
        'link_uri' => $this->normalizeScalarString($item_values['link_uri'] ?? ($link['uri'] ?? '')),
        'link_title' => $this->normalizeScalarString($item_values['link_title'] ?? ($link['title'] ?? '')),
        'link_options' => is_array($item_values['link_options'] ?? NULL) ? $item_values['link_options'] : (is_array($link['options'] ?? NULL) ? $link['options'] : NULL),
      ], function ($item_value) {
        return $item_value !== NULL && $item_value !== '' && $item_value !== [];
      });
    }

    return array_filter([
      'headline' => $this->normalizeScalarString($raw_value['headline'] ?? ''),
      'style' => $this->normalizeScalarString($raw_value['style'] ?? ''),
      'items' => array_values(array_filter($items)),
    ], function ($item_value) {
      return $item_value !== NULL && $item_value !== '' && $item_value !== [];
    });
  }

  /**
   * Determines whether a payload resembles a Promo Unit item.
   *
   * @param array $value
   *   The candidate payload.
   *
   * @return bool
   *   TRUE when the structure resembles a Promo Unit item.
   */
  protected function looksLikePromoUnitItem(array $value) {
    foreach (['headline', 'image', 'media', 'copy_value', 'copy', 'link_uri', 'link_title', 'link'] as $key) {
      if (array_key_exists($key, $value)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Determines whether a payload resembles a Flex Grid item.
   *
   * @param array $value
   *   The candidate payload.
   *
   * @return bool
   *   TRUE when the structure resembles a Flex Grid item.
   */
  protected function looksLikeFlexGridItem(array $value) {
    $supported_keys = [
      'image',
      'media',
      'headline',
      'title',
      'copy',
      'description',
      'headline_alignment',
      'link',
      'link_uri',
      'link_title',
      'cta_url',
      'cta_text',
    ];
    foreach ($supported_keys as $key) {
      if (array_key_exists($key, $value)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Normalizes a string-like value from AI field payloads.
   *
   * @param mixed $value
   *   The raw value.
   *
   * @return string
   *   The normalized string.
   */
  protected function normalizeScalarString($value) {
    if (is_array($value) && array_key_exists('value', $value)) {
      $value = $value['value'];
    }

    return is_scalar($value) ? trim((string) $value) : '';
  }

  /**
   * Normalizes generated link input into a safe Drupal URI.
   */
  protected function normalizeLinkUri($value) {
    $uri = $this->normalizeScalarString($value);
    if ($uri === '' || $uri === '#') {
      return '';
    }

    if ($uri === '<front>' || str_starts_with($uri, '<front>#')) {
      $uri = 'internal:/' . substr($uri, 7);
    }
    elseif (!parse_url($uri, PHP_URL_SCHEME)) {
      if (str_starts_with($uri, '//')) {
        throw new \InvalidArgumentException(sprintf('Link URI "%s" must include an explicit scheme.', $uri));
      }
      if (!in_array($uri[0], ['/', '?', '#'], TRUE)) {
        $uri = '/' . $uri;
      }
      $uri = 'internal:' . $uri;
    }

    try {
      $url = Url::fromUri($uri);
    }
    catch (\InvalidArgumentException $exception) {
      throw new \InvalidArgumentException(sprintf('Link URI "%s" is invalid.', $uri), 0, $exception);
    }

    $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));
    if ($url->isExternal() && !in_array($scheme, UrlHelper::getAllowedProtocols(), TRUE)) {
      throw new \InvalidArgumentException(sprintf('Link URI scheme "%s" is not allowed.', $scheme));
    }

    return $uri;
  }

  /**
   * Normalizes an option against a supported allowlist.
   *
   * @param mixed $value
   *   The raw option.
   * @param array $allowed
   *   Supported values.
   * @param string $default
   *   The fallback value.
   *
   * @return string
   *   The normalized option.
   */
  protected function normalizeImpactFactsOption($value, array $allowed, $default) {
    $normalized = $this->normalizeScalarString($value);
    return in_array($normalized, $allowed, TRUE) ? $normalized : $default;
  }

  /**
   * Decodes structured nested field items from arrays, serialized PHP, or JSON.
   *
   * @param mixed $value
   *   The raw stored or generated value.
   *
   * @return array
   *   A normalized array value, or an empty array when decoding fails.
   */
  protected function decodeStructuredItemCollection($value) {
    if (is_array($value)) {
      return $value;
    }

    if (!is_string($value)) {
      return [];
    }

    $value = trim($value);
    if ($value === '') {
      return [];
    }

    $decoded = @unserialize($value, ['allowed_classes' => FALSE]);
    if (is_array($decoded)) {
      return $decoded;
    }

    $decoded = json_decode($value, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Resolves a media reference for a media-backed field.
   *
   * @param string $field_name
   *   The field machine name.
   * @param array $field_data
   *   The generated field payload.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param array $instructions
   *   The top-level instruction payload.
   *
   * @return int|null
   *   The media entity ID if one was resolved.
   */
  protected function resolveMediaReference($field_name, array $field_data, $field_definition, array $instructions) {
    $existing_target_id = $field_data['target_id'] ?? $field_data['value']['target_id'] ?? NULL;
    if (!empty($existing_target_id)) {
      return (int) $existing_target_id;
    }

    $asset_data = $field_data;
    if (!$this->isCreatableMediaAsset($asset_data)) {
      $asset_data = $this->getPlannedAssetForField($field_name, $instructions) ?: $asset_data;
    }

    if (!$this->isCreatableMediaAsset($asset_data)) {
      return NULL;
    }

    $handler_settings = $field_definition->getSetting('handler_settings') ?: [];
    $target_bundles = array_keys($handler_settings['target_bundles'] ?? []);
    $media_bundle = $target_bundles[0] ?? 'utexas_image';
    if (!$this->shouldPreferGeneratedImages($instructions, $asset_data)) {
      $matched_media_id = $this->assetCreator->findExistingMediaImageIdFromFieldData($asset_data, $media_bundle);
      if ($matched_media_id) {
        return $matched_media_id;
      }
    }

    $media = $this->assetCreator->createMediaAssetFromFieldData($asset_data, $media_bundle);

    if (!$media || !$media->id()) {
      throw new \Exception(sprintf('Failed to create media entity for field "%s".', $field_name));
    }

    return (int) $media->id();
  }

  /**
   * Looks up a planned asset requirement for a field.
   *
   * @param string $field_name
   *   The field machine name.
   * @param array $instructions
   *   The top-level instruction payload.
   *
   * @return array|null
   *   The planned asset data if available.
   */
  protected function getPlannedAssetForField($field_name, array $instructions) {
    $requirements = $instructions['plan']['asset_requirements'] ?? [];
    foreach ($requirements as $requirement) {
      if (($requirement['field_name'] ?? NULL) !== $field_name) {
        continue;
      }

      return [
        'asset_type' => $requirement['asset_type'] ?? 'image',
        'image_url' => $requirement['image_url'] ?? '',
        'source_url' => $requirement['source_url'] ?? $requirement['image_url'] ?? '',
        'image_prompt' => $requirement['prompt'] ?? '',
        'alt' => $requirement['alt'] ?? 'AI generated image',
        'title' => $requirement['title'] ?? 'AI generated image',
      ];
    }

    return NULL;
  }

  /**
   * Builds Moody Showcase field items from AI payload data.
   *
   * @param string $field_name
   *   The field machine name.
   * @param array $field_data
   *   The generated field payload.
   * @param array $instructions
   *   The top-level instruction payload.
   *
   * @return array
   *   A normalized array of Moody Showcase items.
   */
  protected function buildCompoundFieldItems($field_name, array $field_data, $field_definition, array $instructions) {
    $raw_items = $field_data['value'] ?? $field_data['items'] ?? [];
    if ($this->isAssociativeArray($raw_items)) {
      $raw_items = [$raw_items];
    }

    if (empty($raw_items) && $this->looksLikeCompoundFieldItem($field_data, $field_definition)) {
      $raw_items = [$field_data];
    }

    if (empty($raw_items)) {
      return [];
    }

    $property_definitions = $field_definition->getFieldStorageDefinition()->getPropertyDefinitions();
    $cardinality = $field_definition->getFieldStorageDefinition()->getCardinality();
    $planned_asset = $this->getPlannedAssetForField($field_name, $instructions);
    $items = [];

    foreach ($raw_items as $delta => $raw_item) {
      if (!is_array($raw_item)) {
        continue;
      }

      $item = [];
      foreach ($property_definitions as $property_name => $property_definition) {
        $item[$property_name] = $this->resolveCompoundPropertyValue($field_name, $property_name, $raw_item, $field_data, $property_definition, $planned_asset, $delta, $instructions);
      }

      if ($this->isCompoundItemEmpty($item)) {
        continue;
      }

      $items[] = $item;
      if ($cardinality !== FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED && count($items) >= $cardinality) {
        break;
      }
    }

    return $items;
  }

  /**
   * Resolves the image value for a Moody Showcase item.
   *
   * @param string $field_name
   *   The field machine name.
   * @param array $item_data
   *   The showcase item payload.
   * @param array $field_data
   *   The top-level field payload.
   * @param array|null $planned_asset
   *   Planned image data.
   * @param int $delta
   *   The item delta.
   *
   * @return int
   *   A media entity ID or 0 when no media should be attached.
   */
  protected function resolveCompoundPropertyValue($field_name, $property_name, array $item_data, array $field_data, $property_definition, ?array $planned_asset, $delta, array $instructions = []) {
    $data_type = $property_definition->getDataType();
    $candidate = $item_data[$property_name] ?? NULL;

    if (in_array($property_name, ['image', 'media'], TRUE)) {
      return $this->resolveCompoundMediaValue($property_name, $candidate, $field_data, $planned_asset, $delta, $instructions);
    }

    if ($property_name === 'copy_value') {
      return (string) ($candidate ?? $item_data['copy']['value'] ?? $item_data['copy'] ?? '');
    }

    if ($property_name === 'copy_format') {
      return (string) ($candidate ?? $item_data['copy']['format'] ?? $field_data['format'] ?? 'flex_html');
    }

    if ($property_name === 'link_options') {
      return is_array($candidate) ? $candidate : [];
    }

    if ($property_name === 'link_uri') {
      return $this->normalizeLinkUri($candidate);
    }

    if ($data_type === 'boolean') {
      return !empty($candidate) ? 1 : 0;
    }

    if ($data_type === 'map') {
      return is_array($candidate) ? $candidate : [];
    }

    if (in_array($data_type, ['integer', 'float'], TRUE)) {
      return ($candidate === NULL || $candidate === '') ? 0 : (int) $candidate;
    }

    return $this->normalizeScalarString($candidate);
  }

  /**
   * Resolves media/image subproperty values for compound fields.
   */
  protected function resolveCompoundMediaValue($property_name, $candidate, array $field_data, ?array $planned_asset, $delta, array $instructions = []) {

    if (is_numeric($candidate) && (int) $candidate > 0) {
      return (int) $candidate;
    }

    if (is_array($candidate)) {
      $existing_target_id = $candidate['target_id'] ?? $candidate['value']['target_id'] ?? NULL;
      if (!empty($existing_target_id)) {
        return (int) $existing_target_id;
      }

      if ($this->isCreatableMediaAsset($candidate)) {
        if (!$this->shouldPreferGeneratedImages($instructions, $candidate)) {
          $matched_media_id = $this->assetCreator->findExistingMediaImageIdFromFieldData($candidate, 'utexas_image');
          if ($matched_media_id) {
            return $matched_media_id;
          }
        }
        $media = $this->assetCreator->createMediaAssetFromFieldData($candidate, 'utexas_image');
        return (int) $media->id();
      }
    }

    $field_level_candidate = $field_data[$property_name] ?? NULL;
    if (is_array($field_level_candidate) && $this->isCreatableMediaAsset($field_level_candidate)) {
      if (!$this->shouldPreferGeneratedImages($instructions, $field_level_candidate)) {
        $matched_media_id = $this->assetCreator->findExistingMediaImageIdFromFieldData($field_level_candidate, 'utexas_image');
        if ($matched_media_id) {
          return $matched_media_id;
        }
      }
      $media = $this->assetCreator->createMediaAssetFromFieldData($field_level_candidate, 'utexas_image');
      return (int) $media->id();
    }

    if ($delta === 0 && is_array($planned_asset) && $this->isCreatableMediaAsset($planned_asset)) {
      if (!$this->shouldPreferGeneratedImages($instructions, $planned_asset)) {
        $matched_media_id = $this->assetCreator->findExistingMediaImageIdFromFieldData($planned_asset, 'utexas_image');
        if ($matched_media_id) {
          return $matched_media_id;
        }
      }
      $media = $this->assetCreator->createMediaAssetFromFieldData($planned_asset, 'utexas_image');
      return (int) $media->id();
    }

    return 0;
  }

  /**
   * Determines whether the array looks like a single Moody Showcase item.
   *
   * @param array $value
   *   The candidate value.
   *
   * @return bool
   *   TRUE when the structure resembles a showcase item.
   */
  protected function looksLikeCompoundFieldItem(array $value, $field_definition) {
    $keys = array_keys($field_definition->getFieldStorageDefinition()->getPropertyDefinitions());
    $keys[] = 'copy';
    foreach (array_unique($keys) as $key) {
      if (array_key_exists($key, $value)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Determines whether the payload contains enough information to create media.
   */
  protected function isCreatableMediaAsset(array $asset_data) {
    return !empty($asset_data['target_id'])
      || in_array((string) ($asset_data['asset_type'] ?? ''), ['image', 'external_video'], TRUE)
      || !empty($asset_data['image_url'])
      || !empty($asset_data['source_url'])
      || !empty($asset_data['image_prompt'])
      || !empty($asset_data['prompt']);
  }

  /**
   * Determines whether generated images should be preferred over fuzzy media reuse.
   */
  protected function shouldPreferGeneratedImages(array $instructions, array $asset_data = []) {
    if (empty($instructions['prefer_ai_images'])) {
      return FALSE;
    }

    if (!empty($asset_data['target_id'])) {
      return FALSE;
    }

    if (!empty($asset_data['image_url']) || !empty($asset_data['source_url'])) {
      return FALSE;
    }

    return !empty($asset_data['image_prompt']) || !empty($asset_data['prompt']) || (($asset_data['asset_type'] ?? '') === 'image');
  }

  /**
   * Determines whether the value is an associative array.
   *
   * @param mixed $value
   *   The value to inspect.
   *
   * @return bool
   *   TRUE when the value is associative.
   */
  protected function isAssociativeArray($value) {
    if (!is_array($value)) {
      return FALSE;
    }

    return array_keys($value) !== range(0, count($value) - 1);
  }

  /**
   * Determines whether a Moody Showcase item is effectively empty.
   *
   * @param array $item
   *   The normalized item.
   *
   * @return bool
   *   TRUE when the item has no useful content.
   */
  protected function isCompoundItemEmpty(array $item) {
    foreach ($item as $value) {
      if (is_array($value) && !empty($value)) {
        return FALSE;
      }

      if (is_scalar($value) && (string) $value !== '' && (string) $value !== '0') {
        return FALSE;
      }

      if (is_int($value) && $value !== 0) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Determines whether a field should be treated as a compound custom field.
   */
  protected function isCompoundField($field_definition) {
    $field_type = $field_definition->getType();
    $dedicated_or_simple_types = [
      'entity_reference',
      'text_long',
      'text_with_summary',
      'text',
      'string',
      'string_long',
      'boolean',
      'integer',
      'list_string',
      'link',
      'moody_impact_facts',
      'moody_flex_grid',
      'moody_hero',
      'utexas_promo_unit',
      'utexas_promo_list',
      'utexas_resources',
      'moody_focus_areas',
      'utexas_flex_content_area',
    ];
    if (in_array($field_type, $dedicated_or_simple_types, TRUE)) {
      return FALSE;
    }

    return count($field_definition->getFieldStorageDefinition()->getPropertyDefinitions()) > 1;
  }
}
