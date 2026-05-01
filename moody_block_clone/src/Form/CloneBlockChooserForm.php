<?php

declare(strict_types=1);

namespace Drupal\moody_block_clone\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Url;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\moody_block_clone\BlockCloneManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the off-canvas UI for cloning an inline block from another page.
 */
final class CloneBlockChooserForm extends FormBase {

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
  public function __construct(EntityTypeManagerInterface $entity_type_manager, BlockCloneManager $block_clone_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->blockCloneManager = $block_clone_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('moody_block_clone.manager')
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
    $form['#attached']['library'][] = 'moody_block_clone/chooser';

    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['moody-block-clone__intro']],
      'text' => [
        '#markup' => $this->t('Search for a published page with Layout Builder blocks, then choose an inline block to duplicate into this section.'),
      ],
    ];

    $form['source_page'] = [
      '#type' => 'entity_autocomplete',
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
    ];

    $form['actions'] = [
      '#type' => 'actions',
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
      '#attributes' => [
        'id' => 'moody-block-clone-results',
        'class' => ['moody-block-clone__results'],
      ],
    ];

    $source_node = $this->extractSelectedNode($form_state);
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
          '#markup' => $this->t('That page is not published or does not contain Layout Builder content blocks.'),
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
    foreach ($placements as $placement) {
      /** @var \Drupal\block_content\BlockContentInterface $block */
      $block = $placement['block'];
      $component_uuid = $placement['component_uuid'];

      $form['results']['grid'][$component_uuid] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['moody-block-clone__card']],
      ];

      $form['results']['grid'][$component_uuid]['title'] = [
        '#markup' => '<h3 class="moody-block-clone__card-title">' . Html::escape($placement['label']) . '</h3>',
      ];

      $form['results']['grid'][$component_uuid]['meta'] = [
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
        '#attributes' => ['class' => ['moody-block-clone__preview']],
        'content' => $view_builder->view($block, $placement['view_mode']),
      ];

      $form['results']['grid'][$component_uuid]['actions'] = [
        '#type' => 'container',
      ];
      $form['results']['grid'][$component_uuid]['actions']['clone'] = [
        '#type' => 'link',
        '#title' => $this->t('Clone this block'),
        '#url' => Url::fromRoute('moody_block_clone.clone', [
          'section_storage_type' => $section_storage->getStorageType(),
          'section_storage' => $section_storage->getStorageId(),
          'delta' => $delta,
          'region' => $region,
          'source_node' => $source_node->id(),
          'source_component_uuid' => $component_uuid,
        ]),
        '#attributes' => [
          'class' => ['button', 'button--primary', 'use-ajax'],
        ],
      ];
    }

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
    $form_state->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // The chooser is driven entirely by AJAX links.
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

    if (is_array($value) && isset($value['target_id'])) {
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
