<?php

namespace Drupal\moody_ai_assistant\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\InlineBlockUsageInterface;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;
use Drupal\block_content\BlockContentInterface;

class LayoutPlacementManager {

  use \Drupal\layout_builder\Context\LayoutBuilderContextTrait;

  /**
   * The UUID generator.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuid;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The section storage manager.
   *
   * @var \Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface
   */
  protected $sectionStorageManager;

  /**
   * The layout tempstore repository.
   *
   * @var \Drupal\layout_builder\LayoutTempstoreRepositoryInterface
   */
  protected $layoutTempstoreRepository;

  /**
   * The layout context collector.
   *
   * @var \Drupal\moody_ai_assistant\Service\LayoutContextCollector
   */
  protected $layoutContextCollector;

  /**
   * The inline block usage tracker.
   *
   * @var \Drupal\layout_builder\InlineBlockUsageInterface
   */
  protected $inlineBlockUsage;

  /**
   * Constructs the placement manager.
   */
  public function __construct(UuidInterface $uuid, LoggerChannelFactoryInterface $logger_factory, SectionStorageManagerInterface $section_storage_manager, LayoutTempstoreRepositoryInterface $layout_tempstore_repository, LayoutContextCollector $layout_context_collector, InlineBlockUsageInterface $inline_block_usage) {
    $this->uuid = $uuid;
    $this->logger = $logger_factory->get('moody_ai_assistant');
    $this->sectionStorageManager = $section_storage_manager;
    $this->layoutTempstoreRepository = $layout_tempstore_repository;
    $this->layoutContextCollector = $layout_context_collector;
    $this->inlineBlockUsage = $inline_block_usage;
  }

  /**
   * Places a block into an editable Layout Builder section.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The target page entity.
   * @param \Drupal\block_content\BlockContentInterface $block
   *   The reusable block content entity.
   *
   * @return array
   *   Placement metadata.
   */
  public function placeBlock(ContentEntityInterface $entity, BlockContentInterface $block, array $runtime_context = [], array $placement_target = []) {
    if (!$entity->hasField('layout_builder__layout')) {
      throw new \Exception('This page does not support Layout Builder placements.');
    }

    [$section_storage, $has_tempstore] = $this->getEditableSectionStorage($entity, $runtime_context);
    if (!$section_storage) {
      throw new \Exception('Could not load Layout Builder overrides storage for this page.');
    }

    if ($section_storage->count() === 0) {
      $section_storage->appendSection(new Section('layout_onecol'));
    }

    $section_delta = $this->resolveSectionDelta($section_storage, $placement_target);
    $section = $section_storage->getSection($section_delta);
    $region = $this->resolveRegion($section, $placement_target);
    $component = new SectionComponent($this->uuid->generate(), $region, [
      'id' => 'inline_block:' . $block->bundle(),
      'label' => $block->label(),
      'provider' => 'layout_builder',
      'label_display' => FALSE,
      'view_mode' => 'full',
      'block_revision_id' => $block->getRevisionId(),
    ]);
    $section->appendComponent($component);

    if ($has_tempstore) {
      $this->layoutTempstoreRepository->set($section_storage);
    }
    else {
      $section_storage->save();
    }

    $this->inlineBlockUsage->addUsage((int) $block->id(), $entity);

    $this->logger->notice('Placed AI-generated block @block on @entity_type/@entity_id in section @section region @region.', [
      '@block' => $block->label(),
      '@entity_type' => $entity->getEntityTypeId(),
      '@entity_id' => $entity->id(),
      '@section' => $section_delta,
      '@region' => $region,
    ]);

    return [
      'section_delta' => $section_delta,
      'region' => $region,
      'component_uuid' => $component->getUuid(),
    ];
  }

  /**
   * Updates an existing component to point at a newly saved block revision.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The target page entity.
   * @param string $component_uuid
   *   The component UUID to update.
   * @param \Drupal\block_content\BlockContentInterface $block
   *   The updated block revision.
   *
   * @return array
   *   Update metadata.
   */
  public function updateInlineBlockComponent(ContentEntityInterface $entity, $component_uuid, BlockContentInterface $block, array $runtime_context = []) {
    [$section_storage, $has_tempstore] = !empty($runtime_context['edit_component_uuid'])
      ? [$this->layoutContextCollector->getResolvedSectionStorage($entity, $runtime_context), TRUE]
      : $this->getEditableSectionStorage($entity, $runtime_context);
    if (!$section_storage) {
      throw new \Exception('Could not load Layout Builder overrides storage for this page.');
    }

    foreach ($section_storage->getSections() as $section_delta => $section) {
      foreach ($section->getComponents() as $uuid => $component) {
        if ($uuid !== $component_uuid) {
          continue;
        }

        $configuration = $component->get('configuration');
        if (isset($runtime_context['expected_component_hash']) && !hash_equals($runtime_context['expected_component_hash'], hash('sha256', serialize($configuration)))) {
          throw new \InvalidArgumentException('This block changed while AI was working. The layout was not overwritten; retry against the current block.');
        }
        $configuration['block_revision_id'] = $block->getRevisionId();
        $configuration['label'] = $block->label();
        $component->setConfiguration($configuration);

        if ($has_tempstore) {
          $this->layoutTempstoreRepository->set($section_storage);
        }
        else {
          $section_storage->save();
        }

        $this->inlineBlockUsage->addUsage((int) $block->id(), $entity);

        return [
          'section_delta' => $section_delta,
          'region' => $component->getRegion(),
          'component_uuid' => $component_uuid,
          'block_revision_id' => $block->getRevisionId(),
        ];
      }
    }

    throw new \Exception('The selected Layout Builder component could not be found for update.');
  }

  /**
   * Loads overrides storage for a page entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The target page entity.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|null
   *   The overrides storage if available.
   */
  protected function getOverridesSectionStorage(ContentEntityInterface $entity) {
    $contexts = [
      'entity' => EntityContext::fromEntity($entity),
    ];
    $view_mode = LayoutBuilderEntityViewDisplay::collectRenderDisplay($entity, 'full')->getMode();
    $contexts['view_mode'] = new Context(new ContextDefinition('string'), $view_mode);

    return $this->sectionStorageManager->findByContext($contexts, new CacheableMetadata());
  }

  /**
   * Gets editable section storage, preferring live Layout Builder tempstore.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The target entity.
   *
   * @return array
   *   A two-item array: [section storage|null, uses tempstore bool].
   */
  protected function getEditableSectionStorage(ContentEntityInterface $entity, array $runtime_context = []) {
    $section_storage = $this->layoutContextCollector->getPreferredSectionStorage($entity, $runtime_context);
    if (!$section_storage) {
      return [NULL, FALSE];
    }

    if ($this->layoutTempstoreRepository->has($section_storage)) {
      return [$this->layoutTempstoreRepository->get($section_storage), TRUE];
    }

    // Assistant work started from Layout Builder must remain in its draft
    // workspace even before another Layout Builder action creates tempstore.
    if (!empty($runtime_context['is_layout_builder_context'])) {
      return [$section_storage, TRUE];
    }

    return [$section_storage, FALSE];
  }

  /** Places the supported record-driven plugin in the editor's draft only. */
  public function saveHeroBuilder(ContentEntityInterface $entity, array $configuration, array $runtime_context, array $target = [], string $uuid = '', string $expected_hash = ''): array {
    return $this->saveBuilder('moody_hero_builder', $entity, $configuration, $runtime_context, $target, $uuid, $expected_hash);
  }

  public function saveBuilder(string $plugin_id, ContentEntityInterface $entity, array $configuration, array $runtime_context, array $target = [], string $uuid = '', string $expected_hash = ''): array {
    if (!in_array($plugin_id, ['moody_hero_builder', 'moody_card_builder'], TRUE)) { throw new \InvalidArgumentException('Unsupported builder.'); }
    $card = $plugin_id === 'moody_card_builder';
    $key = $card ? 'collection' : 'hero';
    $media_key = $card ? 'images' : 'image';
    $form_key = $card ? 'card_builder' : 'hero_builder';
    $keys = [$key, $media_key];
    if (!$card && (!is_int($configuration['image'] ?? NULL) || $configuration['image'] < 0)) { throw new \InvalidArgumentException('Invalid hero image reference.'); }
    $account = \Drupal::currentUser();
    [$storage, $draft] = $this->getEditableSectionStorage($entity, $runtime_context);
    if (!$account->hasPermission('use moody ai assistant') || !$entity->access('update', $account) || !$storage || !$draft || !$storage->access('update', $account)) {
      throw new \RuntimeException('Open an editable Layout Builder draft to compose a builder block.');
    }
    $configuration[$key] = $card
      ? \Drupal\moody_card_builder\CardConfiguration::decode(json_encode($configuration[$key] ?? NULL, JSON_THROW_ON_ERROR))
      : \Drupal\moody_hero_builder\HeroConfiguration::decode(json_encode($configuration[$key] ?? NULL, JSON_THROW_ON_ERROR));
    if ($card) {
      $images = $configuration['images'] ?? [];
      if (!is_array($images) || !array_is_list($images) || count(array_filter($images, fn($id) => is_int($id) && $id > 0)) !== count($images)) { throw new \InvalidArgumentException('Invalid card media library.'); }
      $configuration['images'] = \Drupal\moody_card_builder\Plugin\Block\MoodyCardBuilderBlock::imageIds(implode(',', $images));
    }
    $plugin = \Drupal::service('plugin.manager.block')->createInstance($plugin_id, array_intersect_key($configuration, array_flip($keys)) + ['label' => $card ? 'Moody Card Builder' : 'Moody Hero Builder', 'label_display' => FALSE]);
    $state = new \Drupal\Core\Form\FormState();
    $state->setValues([$form_key => ['source' => json_encode($configuration[$key]), $media_key => $card ? implode(',', $configuration[$media_key]) : $configuration[$media_key]]]);
    $form = $plugin->blockForm([], $state);
    $form[$form_key]['source']['#parents'] = [$form_key, 'source'];
    $form[$form_key][$media_key]['#parents'] = [$form_key, $media_key];
    $plugin->blockValidate($form, $state);
    if ($state->hasAnyErrors() || !$plugin->access($account)) {
      throw new \InvalidArgumentException('The builder composition or selected media is unavailable. Choose an accessible image/poster and valid builder settings.');
    }
    if ($uuid !== '') {
      foreach ($storage->getSections() as $delta => $section) {
        foreach ($section->getComponents() as $component) {
          if ($component->getUuid() !== $uuid) { continue; }
          $current = (array) $component->get('configuration');
          if (($current['id'] ?? '') !== $plugin_id || $expected_hash === '' || !hash_equals($expected_hash, hash('sha256', serialize($current)))) {
            throw new \RuntimeException('The builder changed while AI was working. Retry against its current draft.');
          }
          $component->setConfiguration(array_replace($current, array_intersect_key($configuration, array_flip($keys))));
          $this->layoutTempstoreRepository->set($storage);
          return ['section_delta' => $delta, 'region' => $component->getRegion(), 'component_uuid' => $uuid, 'plugin_id' => $plugin_id];
        }
      }
      throw new \RuntimeException('The selected builder no longer exists.');
    }
    if (!$storage->count()) { $storage->appendSection(new Section('layout_onecol')); }
    $delta = $this->resolveSectionDelta($storage, $target);
    $section = $storage->getSection($delta);
    $region = $this->resolveRegion($section, $target);
    $definitions = \Drupal::service('plugin.manager.block')->getFilteredDefinitions('layout_builder', $this->getPopulatedContexts($storage), ['section_storage' => $storage, 'delta' => $delta, 'region' => $region]);
    if (!isset($definitions[$plugin_id])) { throw new \RuntimeException('This builder is not allowed in this layout.'); }
    $component = new SectionComponent($this->uuid->generate(), $region, $plugin->getConfiguration());
    $section->appendComponent($component);
    $this->layoutTempstoreRepository->set($storage);
    return ['section_delta' => $delta, 'region' => $region, 'component_uuid' => $component->getUuid(), 'plugin_id' => $plugin_id];
  }

  /** Places the supported record-driven plugin in the editor's draft only. */
  public function placeEditorsPicks(ContentEntityInterface $entity, array $node_ids, array $lookup_results, array $runtime_context, array $target = []): array {
    $account = \Drupal::currentUser();
    [$storage, $draft] = $this->getEditableSectionStorage($entity, $runtime_context);
    if (!$account->hasPermission('use moody ai assistant') || !$entity->access('update', $account) || !$storage || !$draft || !$storage->access('update', $account)) {
      throw new \RuntimeException('Open an editable Layout Builder draft before adding Editors Picks.');
    }
    $allowed = [];
    foreach ($lookup_results as $result) {
      foreach ($result['items'] ?? [] as $item) {
        if (($item['entity_type'] ?? '') === 'node' && ($item['bundle'] ?? '') === 'moody_feature_page') {
          $allowed[(string) $item['id']] = TRUE;
        }
      }
    }
    if (!$node_ids || count($node_ids) > 20 || count(array_unique($node_ids)) !== count($node_ids)) {
      throw new \InvalidArgumentException('Editors Picks needs 1–20 distinct, discovered Feature Pages.');
    }
    foreach ($node_ids as $id) {
      if ((!is_int($id) && !is_string($id)) || !ctype_digit((string) $id) || !isset($allowed[(string) $id])) {
        throw new \InvalidArgumentException('Editors Picks referenced a page outside the verified lookup.');
      }
      $node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($id);
      if (!$node || $node->bundle() !== 'moody_feature_page' || !$node->isPublished() || !$node->access('view', $account)) {
        throw new \RuntimeException('A selected Feature Page is no longer available.');
      }
    }
    $plugin_id = 'moody_feature_page_feature_pages_editors_picks';
    if (!$storage->count()) {
      $storage->appendSection(new Section('layout_onecol'));
    }
    $delta = $this->resolveSectionDelta($storage, $target);
    $section = $storage->getSection($delta);
    $region = $this->resolveRegion($section, $target);
    $manager = \Drupal::service('plugin.manager.block');
    $definitions = $manager->getFilteredDefinitions('layout_builder', $this->getPopulatedContexts($storage), [
      'section_storage' => $storage, 'delta' => $delta, 'region' => $region,
    ]);
    if (!isset($definitions[$plugin_id])) {
      throw new \RuntimeException('Editors Picks is not allowed in this layout.');
    }
    $plugin = $manager->createInstance($plugin_id, ['selected_nodes' => array_map('intval', $node_ids), 'label_display' => FALSE]);
    if (!$plugin->access($account)) {
      throw new \RuntimeException('Editors Picks is not accessible.');
    }
    $component = new SectionComponent($this->uuid->generate(), $region, $plugin->getConfiguration());
    $section->appendComponent($component);
    $this->layoutTempstoreRepository->set($storage);
    return ['section_delta' => $delta, 'region' => $region, 'component_uuid' => $component->getUuid(), 'plugin_id' => $plugin_id];
  }

  /**
   * Resolves a requested section with a safe fallback to the first section.
   */
  protected function resolveSectionDelta($section_storage, array $placement_target) {
    $section_delta = isset($placement_target['section_delta']) ? (int) $placement_target['section_delta'] : 0;
    return $section_delta >= 0 && $section_delta < $section_storage->count() ? $section_delta : 0;
  }

  /**
   * Resolves a requested region against the selected layout definition.
   */
  protected function resolveRegion(Section $section, array $placement_target) {
    $region = trim((string) ($placement_target['region'] ?? ''));
    $regions = array_keys($section->getLayout()->getPluginDefinition()->getRegions());
    return $region !== '' && in_array($region, $regions, TRUE) ? $region : $section->getDefaultRegion();
  }

}
