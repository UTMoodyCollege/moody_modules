<?php

namespace Drupal\moody_ai_assistant\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;

class LayoutContextCollector {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
   * Constructs the collector.
   */
  public function __construct(RouteMatchInterface $route_match, EntityTypeManagerInterface $entity_type_manager, SectionStorageManagerInterface $section_storage_manager, LayoutTempstoreRepositoryInterface $layout_tempstore_repository) {
    $this->routeMatch = $route_match;
    $this->entityTypeManager = $entity_type_manager;
    $this->sectionStorageManager = $section_storage_manager;
    $this->layoutTempstoreRepository = $layout_tempstore_repository;
  }

  /**
   * Gets the current route entity.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The route entity if available.
   */
  public function getRouteEntity() {
    $section_storage = $this->getRouteSectionStorage();
    if ($section_storage) {
      $entity = $this->extractEntityFromSectionStorage($section_storage);
      if ($entity instanceof ContentEntityInterface && $entity->hasLinkTemplate('canonical')) {
        return $entity;
      }
    }

    if (($route = $this->routeMatch->getRouteObject()) && ($parameters = $route->getOption('parameters'))) {
      foreach ($parameters as $name => $options) {
        if (isset($options['type']) && strpos($options['type'], 'entity:') === 0) {
          $entity = $this->routeMatch->getParameter($name);
          if ($entity instanceof ContentEntityInterface && $entity->hasLinkTemplate('canonical')) {
            return $entity;
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Collects route entity and layout context.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The page entity.
   *
   * @return array
   *   Context information for prompting and placement.
   */
  public function collectEntityContext(ContentEntityInterface $entity, array $runtime_context = []) {
    $components = [];
    $section_storage = $this->getResolvedSectionStorage($entity, $runtime_context);
    if ($section_storage && $section_storage->count() > 0) {
      foreach ($section_storage->getSections() as $section_delta => $section) {
        foreach ($section->getComponents() as $component) {
          $configuration = (array) $component->get('configuration');
          $component_data = [
            'uuid' => $component->getUuid(),
            'section' => $section_delta,
            'region' => $component->getRegion(),
            'plugin_id' => $configuration['id'] ?? '',
            'label' => $configuration['label'] ?? '',
          ];

          if (!empty($configuration['block_revision_id']) && str_starts_with((string) ($configuration['id'] ?? ''), 'inline_block:')) {
            $block_revision = $this->entityTypeManager->getStorage('block_content')->loadRevision($configuration['block_revision_id']);
            if ($block_revision) {
              $component_data['block_revision_id'] = (int) $configuration['block_revision_id'];
              $component_data['block_id'] = (int) $block_revision->id();
              $component_data['block_type'] = $block_revision->bundle();
              $component_data['block_label'] = $block_revision->label();
            }
          }

          $components[] = $component_data;
        }
      }
    }

    $selected_references = $this->normalizeSelectedBlockReferences($runtime_context['selected_block_references'] ?? []);
    $selected_ids = is_array($runtime_context['selected_block_reference_ids'] ?? NULL)
      ? $runtime_context['selected_block_reference_ids']
      : [];
    foreach ($selected_references as $reference) {
      if (($reference['selection_mode'] ?? 'new') === 'edit' && !empty($reference['uuid'])) {
        $selected_ids[] = (string) $reference['uuid'];
      }
    }

    return [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id(),
      'bundle' => $entity->bundle(),
      'label' => $entity->label(),
      'is_layout_builder_context' => $this->isLayoutBuilderContext($entity, $runtime_context),
      'prefer_ai_images' => !empty($runtime_context['prefer_ai_images']),
      'existing_components' => $components,
      'selected_block_references' => $selected_references,
      'selected_existing_block_references' => $this->resolveSelectedBlockReferences($components, array_values(array_unique($selected_ids))),
    ];
  }

  /**
   * Normalizes selected block references passed in runtime context.
   */
  protected function normalizeSelectedBlockReferences(array $references) {
    $normalized = [];

    foreach ($references as $reference) {
      if (!is_array($reference)) {
        continue;
      }

      $reference_id = trim((string) ($reference['reference_id'] ?? $reference['plugin_id'] ?? $reference['uuid'] ?? ''));
      $label = trim((string) ($reference['label'] ?? $reference['block_label'] ?? ''));
      if ($reference_id === '' || $label === '') {
        continue;
      }

      $normalized[] = array_filter([
        'reference_id' => $reference_id,
        'uuid' => trim((string) ($reference['uuid'] ?? '')),
        'label' => $label,
        'type_label' => trim((string) ($reference['type_label'] ?? '')),
        'plugin_id' => trim((string) ($reference['plugin_id'] ?? '')),
        'block_type' => trim((string) ($reference['block_type'] ?? '')),
        'selection_mode' => trim((string) ($reference['selection_mode'] ?? 'new')),
        'group_label' => trim((string) ($reference['group_label'] ?? '')),
        'existing_count' => isset($reference['existing_count']) ? (int) $reference['existing_count'] : NULL,
        'can_edit' => !empty($reference['can_edit']),
      ], function ($value) {
        return $value !== NULL && $value !== '';
      });
    }

    return array_values($normalized);
  }

  /**
   * Resolves explicit selected block references from runtime context.
   *
   * @param array $components
   *   The current page components.
   * @param array $selected_ids
   *   Explicitly selected component UUIDs.
   *
   * @return array
   *   Matching component entries.
   */
  protected function resolveSelectedBlockReferences(array $components, array $selected_ids) {
    if (!$selected_ids) {
      return [];
    }

    $selected_lookup = array_fill_keys(array_filter(array_map('strval', $selected_ids)), TRUE);
    if (!$selected_lookup) {
      return [];
    }

    $matches = [];
    foreach ($components as $component) {
      $uuid = (string) ($component['uuid'] ?? '');
      if ($uuid !== '' && isset($selected_lookup[$uuid])) {
        $matches[] = $component;
      }
    }

    return $matches;
  }

  /**
   * Determines whether the current route is the Layout Builder UI for the entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The page entity.
   *
   * @return bool
   *   TRUE when currently in Layout Builder context.
   */
  public function isLayoutBuilderContext(ContentEntityInterface $entity, array $runtime_context = []) {
    if (!empty($runtime_context['is_layout_builder_context'])) {
      return TRUE;
    }

    $route = $this->routeMatch->getRouteObject();
    if (!$route || !$route->getOption('_layout_builder')) {
      return FALSE;
    }

    $section_storage = $this->getRouteSectionStorage($entity);
    if ($section_storage) {
      return TRUE;
    }

    $route_entity = $this->getRouteEntity();
    return $route_entity
      && $route_entity->getEntityTypeId() === $entity->getEntityTypeId()
      && (string) $route_entity->id() === (string) $entity->id();
  }

  /**
   * Gets the Layout Builder URL for the given entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The page entity.
   *
   * @return string
   *   The layout builder URL, or the canonical URL as fallback.
   */
  public function getLayoutBuilderUrl(ContentEntityInterface $entity) {
    $route_section_storage = $this->getRouteSectionStorage($entity);
    if ($route_section_storage) {
      try {
        return $route_section_storage->getLayoutBuilderUrl()->toString();
      }
      catch (\Exception $exception) {
      }
    }

    $section_storage = $this->getOverridesSectionStorage($entity);
    if ($section_storage) {
      try {
        return $section_storage->getLayoutBuilderUrl()->toString();
      }
      catch (\Exception $exception) {
      }
    }

    try {
      return Url::fromRoute('layout_builder.overrides.' . $entity->getEntityTypeId() . '.view', [
        $entity->getEntityTypeId() => $entity->id(),
      ])->toString();
    }
    catch (\Exception $exception) {
    }

    return $entity->toUrl()->toString();
  }

  /**
   * Loads section storage and resolves tempstore when present.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The page entity.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|null
   *   The current section storage.
   */
  public function getResolvedSectionStorage(ContentEntityInterface $entity, array $runtime_context = []) {
    $section_storage = $this->getPreferredSectionStorage($entity, $runtime_context);
    if (!$section_storage) {
      return NULL;
    }

    if ($this->layoutTempstoreRepository->has($section_storage)) {
      return $this->layoutTempstoreRepository->get($section_storage);
    }

    return $section_storage;
  }

  /**
   * Gets the best available section storage for the current/runtime context.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The page entity.
   * @param array $runtime_context
   *   Additional context passed from a non-page request.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|null
   *   The best matching section storage.
   */
  public function getPreferredSectionStorage(ContentEntityInterface $entity, array $runtime_context = []) {
    $route_section_storage = $this->getRouteSectionStorage($entity);
    if ($route_section_storage) {
      return $route_section_storage;
    }

    if (!empty($runtime_context['is_layout_builder_context'])) {
      $direct_overrides = $this->loadDirectOverridesSectionStorage($entity);
      if ($direct_overrides) {
        return $direct_overrides;
      }
    }

    return $this->getOverridesSectionStorage($entity);
  }

  /**
   * Loads overrides storage for a page entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The page entity.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|null
   *   The overrides storage, when available.
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
   * Loads overrides storage directly, even before it becomes "applicable".
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The page entity.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|null
   *   The overrides section storage, if it can be instantiated.
   */
  protected function loadDirectOverridesSectionStorage(ContentEntityInterface $entity) {
    $contexts = [
      'entity' => EntityContext::fromEntity($entity),
    ];
    $view_mode = LayoutBuilderEntityViewDisplay::collectRenderDisplay($entity, 'full')->getMode();
    $contexts['view_mode'] = new Context(new ContextDefinition('string'), $view_mode);

    try {
      return $this->sectionStorageManager->load('overrides', $contexts);
    }
    catch (\Exception $exception) {
      return NULL;
    }
  }

  /**
   * Gets the current route section storage when in Layout Builder.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $entity
   *   The expected target entity, when available.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|null
   *   The matching route section storage.
   */
  public function getRouteSectionStorage(?ContentEntityInterface $entity = NULL) {
    $section_storage = $this->routeMatch->getParameter('section_storage');
    if (!$section_storage instanceof SectionStorageInterface) {
      return NULL;
    }

    if (!$entity) {
      return $section_storage;
    }

    $storage_entity = $this->extractEntityFromSectionStorage($section_storage);
    if (!$storage_entity) {
      return NULL;
    }

    return $storage_entity->getEntityTypeId() === $entity->getEntityTypeId()
      && (string) $storage_entity->id() === (string) $entity->id()
      ? $section_storage
      : NULL;
  }

  /**
   * Extracts the target content entity from section storage.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The matched entity, if available.
   */
  protected function extractEntityFromSectionStorage(SectionStorageInterface $section_storage) {
    try {
      $entity = $section_storage->getContextValue('entity');
      return $entity instanceof ContentEntityInterface ? $entity : NULL;
    }
    catch (\Exception $exception) {
      return NULL;
    }
  }

}
