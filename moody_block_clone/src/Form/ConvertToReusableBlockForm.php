<?php

declare(strict_types=1);

namespace Drupal\moody_block_clone\Form;

use Drupal\Core\Ajax\AjaxFormHelperTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\layout_builder\Controller\LayoutRebuildTrait;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\moody_block_clone\BlockCloneManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms converting an inline block to a reusable block.
 */
final class ConvertToReusableBlockForm extends FormBase {

  use AjaxFormHelperTrait;
  use LayoutRebuildTrait;

  /**
   * The tempstore repository.
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
   * The current section storage.
   *
   * @var \Drupal\layout_builder\SectionStorageInterface|null
   */
  protected $sectionStorage;

  /**
   * Constructs the form.
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
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'moody_block_clone_convert_reusable_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SectionStorageInterface $section_storage = NULL, $delta = NULL, $region = NULL, $uuid = NULL): array {
    $this->sectionStorage = $section_storage;

    $form['message'] = [
      '#markup' => $this->t('Create a reusable copy of this inline block in the block library and replace the current Layout Builder block with that reusable copy?'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Convert to reusable block'),
      '#button_type' => 'primary',
      '#ajax' => [
        'callback' => '::ajaxSubmit',
      ],
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $section_storage ? $section_storage->getLayoutBuilderUrl() : Url::fromRoute('<front>'),
      '#attributes' => [
        'class' => ['button', 'dialog-cancel'],
      ],
    ];

    $form_state->set('delta', (int) $delta);
    $form_state->set('uuid', (string) $uuid);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $delta = (int) $form_state->get('delta');
    $uuid = (string) $form_state->get('uuid');

    $reusable_block = $this->blockCloneManager->convertInlineComponentToReusable($this->sectionStorage, $delta, $uuid);
    $this->layoutTempstoreRepository->set($this->sectionStorage);
    $this->messenger()->addStatus($this->t('Created reusable block %label and replaced the current inline block.', ['%label' => $reusable_block->label()]));
    $form_state->setRedirectUrl($this->sectionStorage->getLayoutBuilderUrl());
  }

  /**
   * {@inheritdoc}
   */
  protected function successfulAjaxSubmit(array $form, FormStateInterface $form_state) {
    return $this->rebuildAndClose($this->sectionStorage);
  }

}