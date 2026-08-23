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
    [$section_storage, $has_tempstore] = $this->getEditableSectionStorage($entity, $runtime_context);
    if (!$section_storage) {
      throw new \Exception('Could not load Layout Builder overrides storage for this page.');
    }

    foreach ($section_storage->getSections() as $section_delta => $section) {
      foreach ($section->getComponents() as $uuid => $component) {
        if ($uuid !== $component_uuid) {
          continue;
        }

        $configuration = $component->get('configuration');
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
