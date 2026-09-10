<?php

declare(strict_types=1);

namespace Drupal\moody_block_clone\Form;

use Drupal\Core\Ajax\AjaxFormHelperTrait;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\layout_builder\Controller\LayoutRebuildTrait;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\moody_block_clone\BlockCloneManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the off-canvas UI for cloning an inline block from another page.
 */
final class CloneBlockChooserForm extends FormBase {

  use AjaxFormHelperTrait;
  use LayoutRebuildTrait;

  protected ?SectionStorageInterface $sectionStorage = NULL;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The block clone manager.
   *
   * @var \Drupal\moody_block_clone\BlockCloneManager
   */
  protected $blockCloneManager;

  /**
   * Constructs a new chooser form.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, BlockCloneManager $block_clone_manager, protected LayoutTempstoreRepositoryInterface $layoutTempstoreRepository) {
    $this->entityTypeManager = $entity_type_manager;
    $this->blockCloneManager = $block_clone_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('moody_block_clone.manager'),
      $container->get('layout_builder.tempstore_repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'moody_block_clone_chooser_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SectionStorageInterface $section_storage = NULL, $delta = NULL, $region = NULL): array {
    $this->sectionStorage = $section_storage;
    $form_state->set('delta', (int) $delta);
    $form_state->set('region', (string) $region);
    $form['#attached']['library'][] = 'moody_block_clone/chooser';

    $form['intro'] = [
      '#type' => 'container',
      '#weight' => -30,
      '#attributes' => ['class' => ['moody-block-clone__intro']],
      'text' => [
        '#markup' => $this->t('Choose a published page, then select one or more blocks to copy. Copies are added to this section in source-page order and can be edited independently. Save your layout to keep them.'),
      ],
    ];

    $form['source_page'] = [
      '#type' => 'entity_autocomplete',
      '#weight' => -20,
      '#title' => $this->t('Search pages'),
      '#target_type' => 'node',
      '#selection_settings' => [
        'target_bundles' => array_combine($this->blockCloneManager->getCloneableNodeBundles(), $this->blockCloneManager->getCloneableNodeBundles()),
      ],
      '#tags' => FALSE,
      '#ajax' => [
        'callback' => '::refreshResults',
        'wrapper' => 'moody-block-clone-results',
        'event' => 'change',
      ],
      '#description' => $this->t('Choose a published page to inspect its inline blocks.'),
      '#limit_validation_errors' => [['source_page']],
      '#submit' => ['::rebuildResults'],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => -10,
    ];
    $form['actions']['load_blocks'] = [
      '#type' => 'submit',
      '#value' => $this->t('Load blocks'),
      '#ajax' => [
        'callback' => '::refreshResults',
        'wrapper' => 'moody-block-clone-results',
      ],
      '#limit_validation_errors' => [['source_page']],
      '#submit' => ['::rebuildResults'],
    ];

    $form['results'] = [
      '#type' => 'container',
      '#weight' => 10,
      '#attributes' => [
        'id' => 'moody-block-clone-results',
        'class' => ['moody-block-clone__results'],
      ],
    ];

    $source_node = $this->extractSelectedNode($form_state);
    $form_state->set('source_node_id', $source_node?->id());
    if (!$source_node instanceof NodeInterface) {
      $form['results']['empty'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['moody-block-clone__empty']],
        'text' => [
          '#markup' => $this->t('Select a page to browse its available inline blocks.'),
        ],
      ];
      return $form;
    }

    if (!$this->blockCloneManager->isCloneableNode($source_node)) {
      $form['results']['empty'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['moody-block-clone__empty']],
        'text' => [
          '#markup' => $this->t('That page is unavailable, unpublished, or does not contain Layout Builder content blocks.'),
        ],
      ];
      return $form;
    }

    $placements = $this->blockCloneManager->getCloneableBlocks($source_node);
    if ($placements === []) {
      $form['results']['empty'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['moody-block-clone__empty']],
        'text' => [
          '#markup' => $this->t('No published inline blocks were found on the selected page.'),
        ],
      ];
      return $form;
    }

    $form['results']['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['moody-block-clone__summary']],
      'text' => [
        '#markup' => $this->t('@count blocks found on %title.', [
          '@count' => count($placements),
          '%title' => $source_node->label(),
        ]),
      ],
    ];

    $form['results']['grid'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['moody-block-clone__grid']],
    ];

    $view_builder = $this->entityTypeManager->getViewBuilder('block_content');
    $block_types = $this->entityTypeManager->getStorage('block_content_type')->loadMultiple();
    $position = 0;
    foreach ($placements as $placement) {
      /** @var \Drupal\block_content\BlockContentInterface $block */
      $block = $placement['block'];
      $component_uuid = $placement['component_uuid'];

      $form['results']['grid'][$component_uuid] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['moody-block-clone__card']],
      ];

      $form['results']['grid'][$component_uuid]['header'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['moody-block-clone__card-header']],
        'type' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['moody-block-clone__card-type']],
          '#markup' => $this->t('Block @position · @type', [
            '@position' => ++$position,
            '@type' => isset($block_types[$block->bundle()]) ? $block_types[$block->bundle()]->label() : $block->bundle(),
          ]),
        ],
        'select' => [
          '#type' => 'checkbox',
          '#title' => $this->t('@label', ['@label' => $placement['label']]),
          '#parents' => ['selected_blocks', $component_uuid],
          '#return_value' => $component_uuid,
          '#attributes' => ['class' => ['moody-block-clone__select']],
        ],
      ];

      $form['results']['grid'][$component_uuid]['header']['meta'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['moody-block-clone__card-meta']],
        'text' => [
          '#markup' => $this->t('Section @section, region %region', [
            '@section' => $placement['section_delta'] + 1,
            '%region' => $placement['region'],
          ]),
        ],
      ];

      $form['results']['grid'][$component_uuid]['preview'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['moody-block-clone__preview'],
          'tabindex' => '0',
          'role' => 'region',
          'aria-label' => $this->t('Preview: @label', ['@label' => $placement['label']]),
        ],
        'content' => $view_builder->view($block, $placement['view_mode']),
      ];
    }

    $form['results']['copy_actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['moody-block-clone__copy-actions']],
      'count' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['moody-block-clone__selection-count'], 'role' => 'status', 'aria-live' => 'polite'],
        '#markup' => $this->t('Select blocks to copy.'),
      ],
      'copy' => [
        '#type' => 'submit',
        '#name' => 'clone_selected',
        '#value' => $this->t('Copy selected blocks'),
        '#button_type' => 'primary',
        '#attributes' => ['class' => ['moody-block-clone__copy']],
        '#ajax' => ['callback' => '::ajaxSubmit'],
      ],
    ];

    return $form;
  }

  /**
   * AJAX callback for refreshing block results.
   */
  public function refreshResults(array $form, FormStateInterface $form_state): array {
    return $form['results'];
  }

  /**
   * Submit handler to force a rebuild for AJAX result loading.
   */
  public function rebuildResults(array &$form, FormStateInterface $form_state): void {
    $input = $form_state->getUserInput();
    unset($input['selected_blocks']);
    $form_state->setUserInput($input)->setValue('selected_blocks', []);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Validates the source and the entire selection without creating any copies.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (($form_state->getTriggeringElement()['#name'] ?? '') !== 'clone_selected') {
      return;
    }
    $source = $this->extractSelectedNode($form_state);
    if (!$source || !$this->blockCloneManager->isCloneableNode($source) || (string) $source->id() !== (string) $form_state->get('source_node_id')) {
      $form_state->setErrorByName('source_page', $this->t('The source page changed or is no longer available. Load its blocks again before copying.'));
      return;
    }
    $selected = array_filter((array) $form_state->getValue('selected_blocks', []));
    if (!$selected) {
      $form_state->setErrorByName('selected_blocks', $this->t('Select at least one block to copy.'));
    }
    elseif (array_diff($selected, array_keys($this->blockCloneManager->getCloneableBlocks($source)))) {
      $form_state->setErrorByName('selected_blocks', $this->t('A selected block is no longer available. Load the blocks again before copying.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (($form_state->getTriggeringElement()['#name'] ?? '') !== 'clone_selected') {
      $this->rebuildResults($form, $form_state);
      return;
    }
    $components = $this->blockCloneManager->cloneComponentsToSection(
      $this->sectionStorage,
      $form_state->get('delta'),
      $form_state->get('region'),
      $this->extractSelectedNode($form_state),
      array_values(array_filter($form_state->getValue('selected_blocks', [])))
    );
    $this->layoutTempstoreRepository->set($this->sectionStorage);
    $this->messenger()->addStatus($this->formatPlural(count($components), 'Copied 1 block. Save your layout to keep the copy.', 'Copied @count blocks. Save your layout to keep the copies.'));
    $form_state->setRedirectUrl($this->sectionStorage->getLayoutBuilderUrl());
  }

  /**
   * Closes either browser presentation after rebuilding the draft once.
   */
  protected function successfulAjaxSubmit(array $form, FormStateInterface $form_state) {
    $response = $this->rebuildAndClose($this->sectionStorage);
    $response->addCommand(new CloseDialogCommand('#layout-builder-modal'));
    return $response;
  }

  /**
   * Extracts the selected source node from autocomplete input.
   */
  protected function extractSelectedNode(FormStateInterface $form_state): ?NodeInterface {
    $value = $form_state->getValue('source_page');
    if ($value === NULL) {
      $user_input = $form_state->getUserInput();
      $value = $user_input['source_page'] ?? NULL;
    }

    if (is_int($value)) {
      $target_id = $value;
    }
    elseif (is_array($value) && isset($value['target_id'])) {
      $target_id = (int) $value['target_id'];
    }
    elseif (is_string($value)) {
      $target_id = (int) EntityAutocomplete::extractEntityIdFromAutocompleteInput($value);
      if ($target_id <= 0 && preg_match('/\((\d+)\)\s*$/', $value, $matches)) {
        $target_id = (int) $matches[1];
      }
      if ($target_id <= 0 && ctype_digit(trim($value))) {
        $target_id = (int) trim($value);
      }
    }
    else {
      $target_id = 0;
    }

    if ($target_id <= 0) {
      return is_string($value) ? $this->loadNodeByTitle($value) : NULL;
    }

    $node = $this->entityTypeManager->getStorage('node')->load($target_id);
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Falls back to loading a published node by exact title.
   */
  protected function loadNodeByTitle(string $title): ?NodeInterface {
    $title = trim($title);
    if ($title === '') {
      return NULL;
    }

    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $query->accessCheck(TRUE)
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('type', $this->blockCloneManager->getCloneableNodeBundles(), 'IN')
      ->condition('title', $title)
      ->range(0, 1);

    $ids = $query->execute();
    if ($ids === []) {
      return NULL;
    }

    $node = $this->entityTypeManager->getStorage('node')->load((int) reset($ids));
    return $node instanceof NodeInterface ? $node : NULL;
  }

}
