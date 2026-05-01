<?php

declare(strict_types=1);

namespace Drupal\moody_block_clone\Controller;

use Drupal\Core\Ajax\AjaxHelperTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\layout_builder\Controller\LayoutRebuildTrait;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\moody_block_clone\BlockCloneManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Handles cloning an inline block into the active Layout Builder section.
 */
final class CloneBlockController implements ContainerInjectionInterface {

  use AjaxHelperTrait;
  use LayoutRebuildTrait;

  /**
   * The layout tempstore repository.
   *
   * @var \Drupal\layout_builder\LayoutTempstoreRepositoryInterface
   */
  protected $layoutTempstoreRepository;

  /**
   * The block clone manager.
   *
   * @var \Drupal\moody_block_clone\BlockCloneManager
   */
  protected $blockCloneManager;

  /**
   * Constructs a new clone controller.
   */
  public function __construct(LayoutTempstoreRepositoryInterface $layout_tempstore_repository, BlockCloneManager $block_clone_manager) {
    $this->layoutTempstoreRepository = $layout_tempstore_repository;
    $this->blockCloneManager = $block_clone_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('layout_builder.tempstore_repository'),
      $container->get('moody_block_clone.manager')
    );
  }

  /**
   * Clones the selected block placement into the active section.
   */
  public function clone(SectionStorageInterface $section_storage, int $delta, string $region, NodeInterface $source_node, string $source_component_uuid) {
    $this->blockCloneManager->cloneComponentToSection($section_storage, $delta, $region, $source_node, $source_component_uuid);
    $this->layoutTempstoreRepository->set($section_storage);

    if ($this->isAjax()) {
      return $this->rebuildAndClose($section_storage);
    }

    return new RedirectResponse($section_storage->getLayoutBuilderUrl()->toString());
  }

}