<?php

declare(strict_types=1);

namespace Drupal\moody_block_clone;

use Drupal\block_content\BlockContentInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\layout_builder\Field\LayoutSectionItemList;
use Drupal\layout_builder\InlineBlockUsageInterface;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides helpers for discovering and cloning inline blocks.
 */
final class BlockCloneManager {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $bundleInfo;

  /**
   * The inline block usage service.
   *
   * @var \Drupal\layout_builder\InlineBlockUsageInterface
   */
  protected $inlineBlockUsage;

  /**
   * The UUID generator.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuid;

  /**
   * Constructs a new block clone manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, EntityTypeBundleInfoInterface $bundle_info, InlineBlockUsageInterface $inline_block_usage, UuidInterface $uuid) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->bundleInfo = $bundle_info;
    $this->inlineBlockUsage = $inline_block_usage;
    $this->uuid = $uuid;
  }

  /**
   * Gets node bundles that expose Layout Builder override sections.
   *
   * @return string[]
   *   Bundle machine names.
   */
  public function getCloneableNodeBundles(): array {
    $bundles = [];
    foreach (array_keys($this->bundleInfo->getBundleInfo('node')) as $bundle) {
      $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
      if (isset($field_definitions['layout_builder__layout'])) {
        $bundles[] = $bundle;
      }
    }
    return $bundles;
  }

  /**
   * Determines if a node can provide cloneable Layout Builder blocks.
   */
  public function isCloneableNode(NodeInterface $node): bool {
    return $node->isPublished() && $node->hasField('layout_builder__layout') && !$node->get('layout_builder__layout')->isEmpty();
  }

  /**
   * Returns cloneable inline block placements for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The source node.
   *
   * @return array[]
   *   Cloneable block data keyed by section component UUID.
   */
  public function getCloneableBlocks(NodeInterface $node): array {
    if (!$this->isCloneableNode($node)) {
      return [];
    }

    $placements = [];
    /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $sections */
    $sections = $node->get('layout_builder__layout');
    foreach ($sections->getSections() as $section_delta => $section) {
      foreach ($section->getComponents() as $component_uuid => $component) {
        $configuration = (array) $component->get('configuration');
        $plugin_id = (string) ($configuration['id'] ?? '');
        if (!str_starts_with($plugin_id, 'inline_block:')) {
          continue;
        }

        $block = $this->resolveInlineBlockEntity($configuration);
        if (!$block instanceof BlockContentInterface) {
          continue;
        }

        if (method_exists($block, 'isPublished') && !$block->isPublished()) {
          continue;
        }

        $placements[$component_uuid] = [
          'component_uuid' => $component_uuid,
          'section_delta' => $section_delta,
          'region' => $component->getRegion(),
          'configuration' => $configuration,
          'block' => $block,
          'label' => $this->resolvePlacementLabel($configuration, $block),
          'view_mode' => (string) ($configuration['view_mode'] ?? 'full'),
        ];
      }
    }

    return $placements;
  }

  /**
   * Clones an inline block component into the target section storage.
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   The appended component.
   */
  public function cloneComponentToSection(SectionStorageInterface $section_storage, int $delta, string $region, NodeInterface $source_node, string $source_component_uuid): SectionComponent {
    if (!$this->isCloneableNode($source_node)) {
      throw new AccessDeniedHttpException('The selected source page is not available for cloning.');
    }

    $placements = $this->getCloneableBlocks($source_node);
    if (empty($placements[$source_component_uuid])) {
      throw new NotFoundHttpException('The selected source block could not be found.');
    }

    $source = $placements[$source_component_uuid];
    /** @var \Drupal\block_content\BlockContentInterface $duplicate */
    $duplicate = $source['block']->createDuplicate();
    $duplicate->save();

    $configuration = $source['configuration'];
    $configuration['block_id'] = $duplicate->id();
    $configuration['block_revision_id'] = $duplicate->getRevisionId();
    $configuration['label'] = $this->resolvePlacementLabel($configuration, $duplicate);
    unset($configuration['block_serialized']);

    $component = new SectionComponent($this->uuid->generate(), $region, $configuration);
    $section_storage->getSection($delta)->appendComponent($component);

    if ($section_storage instanceof ContextAwarePluginInterface) {
      $entity = $section_storage->getContextValue('entity');
      if ($entity instanceof EntityInterface) {
        $this->inlineBlockUsage->addUsage((int) $duplicate->id(), $entity);
      }
    }

    return $component;
  }

  /**
   * Resolves an inline block entity from stored component configuration.
   */
  protected function resolveInlineBlockEntity(array $configuration): ?BlockContentInterface {
    if (!empty($configuration['block_serialized'])) {
      $entity = @unserialize($configuration['block_serialized']);
      return $entity instanceof BlockContentInterface ? $entity : NULL;
    }

    if (!empty($configuration['block_revision_id'])) {
      $storage = $this->entityTypeManager->getStorage('block_content');
      if (!$storage instanceof RevisionableStorageInterface) {
        return NULL;
      }

      $entity = $storage->loadRevision((int) $configuration['block_revision_id']);
      return $entity instanceof BlockContentInterface ? $entity : NULL;
    }

    return NULL;
  }

  /**
   * Resolves a human readable placement label.
   */
  protected function resolvePlacementLabel(array $configuration, BlockContentInterface $block): string {
    $label = trim((string) ($configuration['label'] ?? ''));
    if ($label !== '') {
      return $label;
    }

    $label = trim((string) $block->label());
    if ($label !== '') {
      return $label;
    }

    return (string) $block->bundle();
  }

}
