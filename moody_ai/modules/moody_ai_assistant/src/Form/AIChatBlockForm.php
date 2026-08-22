<?php

namespace Drupal\moody_ai_assistant\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\moody_ai_assistant\Service\AIChatManager;
use Drupal\moody_ai_assistant\Service\AIAssetCreator;
use Drupal\moody_ai_assistant\Service\AIUsageTracker;
use Drupal\moody_ai_assistant\Service\BlockReferenceCatalog;
use Drupal\moody_ai_assistant\Service\LayoutContextCollector;
use Drupal\moody_ai_base\AiGenerationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;

class AIChatBlockForm extends FormBase {

  /**
   * The chat manager.
   *
   * @var \Drupal\moody_ai_assistant\Service\AIChatManager
   */
  protected $chatManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The layout context collector.
   *
   * @var \Drupal\moody_ai_assistant\Service\LayoutContextCollector
   */
  protected $layoutContextCollector;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleExtensionList;

  /**
   * The available block reference catalog.
   *
   * @var \Drupal\moody_ai_assistant\Service\BlockReferenceCatalog
   */
  protected $blockReferenceCatalog;

  /**
   * The AI usage tracker.
   *
   * @var \Drupal\moody_ai_assistant\Service\AIUsageTracker
   */
  protected $usageTracker;

  protected $generator;

  protected $flood;

  /**
   * Constructs the form.
   */
  public function __construct(AIChatManager $chat_manager, AccountProxyInterface $current_user, EntityTypeManagerInterface $entity_type_manager, LayoutContextCollector $layout_context_collector, RequestStack $request_stack, ModuleExtensionList $module_extension_list, BlockReferenceCatalog $block_reference_catalog, AIUsageTracker $usage_tracker, AiGenerationService $generator, FloodInterface $flood) {
    $this->chatManager = $chat_manager;
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->layoutContextCollector = $layout_context_collector;
    $this->requestStack = $request_stack;
    $this->moduleExtensionList = $module_extension_list;
    $this->blockReferenceCatalog = $block_reference_catalog;
    $this->usageTracker = $usage_tracker;
    $this->generator = $generator;
    $this->flood = $flood;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('moody_ai_assistant.chat_manager'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('moody_ai_assistant.layout_context_collector'),
      $container->get('request_stack'),
      $container->get('extension.list.module'),
      $container->get('moody_ai_assistant.block_reference_catalog'),
      $container->get('moody_ai_assistant.usage_tracker'),
      $container->get('moody_ai_base.generator'),
      $container->get('flood')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'moody_ai_assistant_chat_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ContentEntityInterface $entity = NULL) {
    $upload_input_id = 'ai-chat-block-attachments-' . ($entity ? $entity->id() : '0');
    $ui = $this->generator->uiSettings();
    $form['#attributes']['enctype'] = 'multipart/form-data';

    if (!$ui['enabled']) {
      $form['offline'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['messages', 'messages--warning', 'moody-ai-ui__offline'],
          'role' => 'status',
          'aria-live' => 'polite',
        ],
        'message' => [
          '#markup' => '<p>' . Html::escape($ui['offlineMessage']) . '</p>',
        ],
      ];
      return $form;
    }

    $starter_prompts = $this->getStarterPrompts();
    $is_layout_builder_context = $entity ? $this->layoutContextCollector->isLayoutBuilderContext($entity) : FALSE;
    $picker_context = ['is_layout_builder_context' => $is_layout_builder_context];
    $block_reference_groups = $entity ? $this->blockReferenceCatalog->getGroupedReferences($entity, $picker_context) : [];
    $existing_block_reference_groups = $entity ? $this->blockReferenceCatalog->getGroupedExistingReferences($entity, $picker_context) : [];
    $budget_summary = $this->usageTracker->getUserBudgetSummary($this->currentUser->id());
    $media_bundle_ids = array_keys($this->entityTypeManager->getStorage('media_type')->loadMultiple());

    $form['utility_links'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ai-moody-assistant__utility-links'],
      ],
    ];

    if (!empty($budget_summary['has_budget'])) {
      $remaining = (int) $budget_summary['remaining'];
      $form['usage_budget'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['ai-moody-assistant__usage-budget'],
        ],
      ];
      $form['usage_budget']['summary'] = [
        '#markup' => $remaining > 0
          ? '<p>' . $this->t('@remaining usage tokens remaining.', ['@remaining' => number_format($remaining)]) . '</p>'
          : '<p>' . $this->t('You have insufficient usage tokens.') . '</p>',
      ];
    }

    $form['utility_links']['help'] = [
      '#type' => 'details',
      '#title' => $this->t('Help'),
      '#open' => FALSE,
      '#attributes' => [
        'class' => ['ai-moody-assistant__help'],
      ],
      'content' => [
        '#markup' => '<p>' . $this->t('Ask AI to create or revise a block for this page. Example: <em>Create a new block on this page promoting our graduate program with an editorial image of students in class.</em> You can also attach one or more files and tell the assistant to use them in the generated block.') . '</p>',
      ],
    ];

    if ($starter_prompts) {
      $form['utility_links']['prompts'] = [
        '#type' => 'details',
        '#title' => $this->t('Ideas'),
        '#open' => FALSE,
        '#attributes' => [
          'class' => ['ai-moody-assistant__help', 'ai-moody-assistant__prompts'],
        ],
      ];

      $form['utility_links']['prompts']['content'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['ai-moody-assistant__prompt-list'],
        ],
      ];

      foreach ($starter_prompts as $index => $starter_prompt) {
        $form['utility_links']['prompts']['content']['prompt_' . $index] = [
          '#type' => 'button',
          '#value' => $starter_prompt['label'],
          '#attributes' => [
            'class' => ['ai-moody-assistant__prompt-button'],
            'data-ai-assistant-prompt' => $starter_prompt['prompt'],
          ],
        ];
      }
    }

    if ($block_reference_groups) {
      $form['utility_links']['blocks'] = [
        '#type' => 'details',
        '#title' => $this->t('Add block'),
        '#open' => FALSE,
        '#attributes' => [
          'class' => ['ai-moody-assistant__help', 'ai-moody-assistant__blocks'],
        ],
      ];

      $form['utility_links']['blocks']['content'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['ai-moody-assistant__block-library'],
          'data-ai-assistant-block-picker' => 'new',
        ],
      ];

      foreach ($block_reference_groups as $group_index => $group) {
        $group_key = 'group_' . $group_index;
        $form['utility_links']['blocks']['content'][$group_key] = [
          '#type' => 'details',
          '#title' => $group['label'],
          '#open' => !empty($group['opened']),
          '#attributes' => [
            'class' => ['ai-moody-assistant__block-library-group'],
          ],
        ];

        $form['utility_links']['blocks']['content'][$group_key]['items'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['ai-moody-assistant__block-library-items'],
          ],
        ];

        foreach ($group['items'] as $item_index => $item) {
          $form['utility_links']['blocks']['content'][$group_key]['items']['item_' . $item_index] = $this->buildBlockReferenceElement($item);
        }
      }
    }

    if ($existing_block_reference_groups) {
      $form['utility_links']['existing_blocks'] = [
        '#type' => 'details',
        '#title' => $this->t('Edit block'),
        '#open' => FALSE,
        '#attributes' => [
          'class' => ['ai-moody-assistant__help', 'ai-moody-assistant__blocks', 'ai-moody-assistant__blocks--existing'],
        ],
      ];

      $form['utility_links']['existing_blocks']['content'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['ai-moody-assistant__block-library'],
          'data-ai-assistant-block-picker' => 'edit',
        ],
      ];

      foreach ($existing_block_reference_groups as $group_index => $group) {
        $group_key = 'group_' . $group_index;
        $form['utility_links']['existing_blocks']['content'][$group_key] = [
          '#type' => 'details',
          '#title' => $group['label'],
          '#open' => !empty($group['opened']),
          '#attributes' => [
            'class' => ['ai-moody-assistant__block-library-group'],
          ],
        ];

        $form['utility_links']['existing_blocks']['content'][$group_key]['items'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['ai-moody-assistant__block-library-items'],
          ],
        ];

        foreach ($group['items'] as $item_index => $item) {
          $form['utility_links']['existing_blocks']['content'][$group_key]['items']['item_' . $item_index] = $this->buildBlockReferenceElement($item);
        }
      }
    }

    $form['utility_links']['generation_options'] = [
      '#type' => 'details',
      '#title' => $this->t('AI options'),
      '#open' => FALSE,
      '#attributes' => [
        'class' => ['ai-moody-assistant__help', 'ai-moody-assistant__generation-options'],
      ],
    ];

    $form['utility_links']['generation_options']['choices'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['moody-ai-ui__choices', 'ai-moody-assistant__model-choices'],
      ],
    ];

    $form['utility_links']['generation_options']['choices']['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Provider'),
      '#options' => $ui['providerOptions'],
      '#default_value' => $ui['defaultProvider'],
      '#wrapper_attributes' => ['class' => ['moody-ai-ui__field']],
      '#attributes' => ['class' => ['moody-ai-ui__control']],
    ];

    $form['utility_links']['generation_options']['choices']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Model'),
      '#options' => $ui['modelOptions'],
      '#default_value' => $ui['defaultModel'],
      '#wrapper_attributes' => ['class' => ['moody-ai-ui__field']],
      '#attributes' => ['class' => ['moody-ai-ui__control']],
    ];

    if ($media_bundle_ids) {
      $form['utility_links']['generation_options']['existing_media'] = [
        '#type' => 'media_library',
        '#allowed_bundles' => $media_bundle_ids,
        '#cardinality' => AiGenerationService::MAX_ATTACHMENTS,
        '#title' => $this->t('Existing Media'),
        '#default_value' => NULL,
        '#description' => $this->t('Add existing Media as source material for this request.'),
        '#attributes' => ['data-ai-assistant-existing-media' => TRUE],
      ];

      $form['utility_links']['generation_options']['existing_media_intent'] = [
        '#type' => 'select',
        '#title' => $this->t('How may Moody AI use selected Media?'),
        '#options' => [
          'inspiration' => $this->t('Inspiration only'),
          'content' => $this->t('May use in page content'),
        ],
        '#default_value' => 'inspiration',
        '#wrapper_attributes' => ['class' => ['moody-ai-ui__field']],
        '#attributes' => ['class' => ['moody-ai-ui__control']],
      ];
    }

    $form['entity_type'] = [
      '#type' => 'hidden',
      '#value' => $entity ? $entity->getEntityTypeId() : '',
    ];

    $form['entity_id'] = [
      '#type' => 'hidden',
      '#value' => $entity ? $entity->id() : '',
    ];

    $form['is_layout_builder_context'] = [
      '#type' => 'hidden',
      '#value' => $entity ? (int) $is_layout_builder_context : 0,
    ];

    $form['selected_block_references_json'] = [
      '#type' => 'hidden',
      '#value' => '[]',
      '#attributes' => [
        'data-ai-assistant-selected-block-input' => TRUE,
      ],
    ];

    $form['prefer_ai_images'] = [
      '#type' => 'hidden',
      '#value' => 0,
      '#attributes' => [
        'data-ai-assistant-prefer-ai-images-input' => TRUE,
      ],
    ];

    $form['selected_block_references'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ai-moody-assistant__composer-surface'],
        'data-ai-assistant-composer-shell' => TRUE,
        'data-ai-assistant-selected-blocks' => TRUE,
      ],
    ];

    $form['selected_block_references']['items'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['ai-moody-assistant__composer-tokens'],
        'data-ai-assistant-selected-block-list' => TRUE,
      ],
    ];

    $form['selected_block_references']['editor'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => '',
      '#attributes' => [
        'class' => ['ai-moody-assistant__composer-editor'],
        'contenteditable' => 'true',
        'role' => 'textbox',
        'aria-multiline' => 'true',
        'aria-label' => $this->t('Describe the change'),
        'data-placeholder' => $this->t('Describe the block or edit you want…'),
        'data-ai-assistant-composer-editor' => TRUE,
      ],
    ];

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#title_display' => 'invisible',
      '#rows' => 2,
      '#maxlength' => $this->generator->maxPromptCharacters(),
      '#required' => TRUE,
      '#placeholder' => $this->t('Describe the block or edit you want…'),
      '#attributes' => [
        'class' => ['ai-moody-assistant__composer-input', 'ai-moody-assistant__composer-input--native'],
        'data-ai-assistant-composer-source' => TRUE,
      ],
    ];

    $form['composer_controls'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ai-moody-assistant__composer-controls'],
      ],
    ];

    $form['composer_controls']['image_preference'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ai-moody-assistant__image-preference'],
      ],
    ];

    $form['composer_controls']['image_preference']['toggle'] = [
      '#type' => 'checkbox',
      '#attributes' => [
        'class' => ['ai-moody-assistant__image-preference-checkbox'],
        'data-ai-assistant-prefer-ai-images-toggle' => TRUE,
      ],
      '#title' => $this->t('Create image'),
      '#wrapper_attributes' => [
        'class' => ['ai-moody-assistant__image-preference-toggle'],
      ],
    ];

    $form['composer_controls']['token_counter'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ai-moody-assistant__token-counter'],
        'data-ai-assistant-token-counter' => TRUE,
      ],
    ];

    $form['composer_controls']['token_counter']['button'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('~0 tokens'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['ai-moody-assistant__token-counter-button'],
        'aria-expanded' => 'false',
        'data-ai-assistant-token-counter-toggle' => TRUE,
      ],
    ];

    $form['composer_controls']['token_counter']['popover'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ai-moody-assistant__token-counter-popover'],
        'data-ai-assistant-token-counter-popover' => TRUE,
        'hidden' => 'hidden',
      ],
    ];

    $form['composer_controls']['token_counter']['popover']['header'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ai-moody-assistant__token-counter-popover-header'],
      ],
    ];

    $form['composer_controls']['token_counter']['popover']['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Context Estimate'),
      '#attributes' => [
        'class' => ['ai-moody-assistant__token-counter-popover-title'],
      ],
    ];

    $form['composer_controls']['token_counter']['popover']['header']['close'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Close'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['ai-moody-assistant__token-counter-popover-close'],
        'data-ai-assistant-token-counter-close' => TRUE,
      ],
    ];

    $form['composer_controls']['token_counter']['popover']['summary'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Combined: estimating...'),
      '#attributes' => [
        'class' => ['ai-moody-assistant__token-counter-line'],
        'data-ai-assistant-token-counter-summary' => TRUE,
        'aria-live' => 'polite',
      ],
    ];

    $form['composer_controls']['token_counter']['popover']['conversation'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Conversation context: estimating...'),
      '#attributes' => [
        'class' => ['ai-moody-assistant__token-counter-line'],
        'data-ai-assistant-token-counter-conversation' => TRUE,
      ],
    ];

    $form['composer_controls']['token_counter']['popover']['request'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Next send: estimating...'),
      '#attributes' => [
        'class' => ['ai-moody-assistant__token-counter-line'],
        'data-ai-assistant-token-counter-request' => TRUE,
      ],
    ];

    $form['composer_controls']['token_counter']['popover']['note'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Approximate token estimate based on the visible thread, current draft, selected components, files, and image preference.'),
      '#attributes' => [
        'class' => ['ai-moody-assistant__token-counter-note'],
      ],
    ];

    $form['attachments'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ai-moody-assistant__composer-files'],
      ],
    ];

    $form['attachments']['label'] = [
      '#type' => 'html_tag',
      '#tag' => 'label',
      '#value' => $this->t('Attachments'),
      '#attributes' => [
        'class' => ['ai-moody-assistant__composer-files-label'],
        'for' => $upload_input_id,
      ],
    ];

    $form['attachments']['input'] = [
      '#type' => 'container',
    ];

    $form['attachments']['input']['dropzone'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['ai-moody-assistant__dropzone'],
        'data-ai-assistant-dropzone' => TRUE,
        'role' => 'button',
        'tabindex' => '0',
        'aria-controls' => $upload_input_id,
        'aria-label' => $this->t('Add files by dragging them here or browsing your computer.'),
      ],
    ];

    $form['attachments']['input']['dropzone']['file_input'] = [
      '#type' => 'html_tag',
      '#tag' => 'input',
      '#attributes' => [
        'id' => $upload_input_id,
        'class' => ['ai-moody-assistant__composer-file-input'],
        'type' => 'file',
        'name' => 'attachments[]',
        'multiple' => 'multiple',
        'accept' => implode(',', array_map(static fn(string $extension): string => '.' . $extension, AIAssetCreator::ALLOWED_UPLOAD_EXTENSIONS)),
        'data-max-files' => AiGenerationService::MAX_ATTACHMENTS,
        'data-max-file-bytes' => AiGenerationService::MAX_ATTACHMENT_BYTES,
        'data-max-total-bytes' => AiGenerationService::MAX_TOTAL_ATTACHMENT_BYTES,
      ],
    ];

    $form['attachments']['input']['dropzone']['head'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['ai-moody-assistant__dropzone-head'],
      ],
    ];

    $form['attachments']['input']['dropzone']['head']['copy'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['ai-moody-assistant__dropzone-copy'],
      ],
    ];

    $form['attachments']['input']['dropzone']['head']['copy']['primary'] = [
      '#type' => 'html_tag',
      '#tag' => 'strong',
      '#value' => $this->t('Add files'),
      '#attributes' => [
        'data-ai-assistant-dropzone-primary' => TRUE,
      ],
    ];

    $form['attachments']['input']['dropzone']['head']['copy']['secondary'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $this->t('Drop or browse'),
      '#attributes' => [
        'data-ai-assistant-dropzone-secondary' => TRUE,
      ],
    ];

    $form['attachments']['input']['dropzone']['head']['count'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => '0',
      '#attributes' => [
        'class' => ['ai-moody-assistant__dropzone-count'],
        'data-ai-assistant-dropzone-count' => TRUE,
        'hidden' => 'hidden',
      ],
    ];

    $form['attachments']['input']['dropzone']['hint'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Up to @count references; @size MB combined. PNG, JPG, GIF, WebP, PDF, DOC, DOCX, TXT, or CSV.', [
        '@count' => AiGenerationService::MAX_ATTACHMENTS,
        '@size' => (int) (AiGenerationService::MAX_TOTAL_ATTACHMENT_BYTES / 1048576),
      ]),
      '#attributes' => [
        'class' => ['ai-moody-assistant__dropzone-hint'],
        'data-ai-assistant-dropzone-hint' => TRUE,
      ],
    ];

    $form['attachments']['input']['file_list'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['ai-moody-assistant__file-list'],
        'data-ai-assistant-file-list' => TRUE,
        'hidden' => 'hidden',
      ],
    ];

    $form['attachments']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Attach images or documents. Uploaded images are converted into media with AI-generated alt text automatically.'),
      '#attributes' => [
        'class' => ['description'],
      ],
    ];

    $form['privacy_notice'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $ui['privacyNotice'],
      '#attributes' => [
        'class' => ['ai-moody-assistant__privacy-notice', 'moody-ai-ui__privacy'],
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => [
        'class' => ['ai-moody-assistant__composer-actions'],
      ],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send'),
      '#button_type' => 'primary',
      '#disabled' => !empty($budget_summary['is_exhausted']),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if (!$this->generator->isEnabled()) {
      $this->messenger()->addWarning($this->generator->offlineMessage());
      return;
    }

    $entity_type = $form_state->getValue('entity_type');
    $entity_id = $form_state->getValue('entity_id');
    $message = trim((string) $form_state->getValue('message'));
    $uploaded_files = $this->getUploadedFiles();
    $existing_media_ids = AiGenerationService::normalizeMediaIds($form_state->getValue('existing_media'));
    $provider = (string) $form_state->getValue('provider');
    $model = (string) $form_state->getValue('model');
    $runtime_context = [
      'is_layout_builder_context' => (bool) $form_state->getValue('is_layout_builder_context'),
      'selected_block_references' => $this->extractSelectedBlockReferences((string) $form_state->getValue('selected_block_references_json')),
      'prefer_ai_images' => (bool) $form_state->getValue('prefer_ai_images'),
      'provider' => $provider,
      'model' => $model,
      'existing_media_ids' => $existing_media_ids,
      'existing_media_intent' => (string) ($form_state->getValue('existing_media_intent') ?: 'inspiration'),
    ];

    if ($entity_type === '' || $entity_id === '' || $message === '') {
      $this->messenger()->addError($this->t('Missing page context for AI chat submission.'));
      return;
    }

    try {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
    }
    catch (\Exception $exception) {
      $entity = NULL;
    }
    if (!$entity instanceof ContentEntityInterface || !$entity->hasField('layout_builder__layout')) {
      $this->messenger()->addError($this->t('The current page could not be loaded.'));
      return;
    }
    if (!$this->currentUser->hasPermission('use moody ai assistant') || !$entity->access('update', $this->currentUser)) {
      $this->messenger()->addError($this->t('You do not have access to use Moody AI on this page.'));
      return;
    }
    if (!isset($this->generator->providerOptions()[$provider]) || !isset($this->generator->modelOptions()[$model])) {
      $this->messenger()->addError($this->t('Select an available AI provider and model.'));
      return;
    }
    if (mb_strlen($message) > $this->generator->maxPromptCharacters() || count($uploaded_files) + count($existing_media_ids) > AiGenerationService::MAX_ATTACHMENTS) {
      $this->messenger()->addError($this->t('The message or attachment count exceeds the configured limit.'));
      return;
    }
    $upload_bytes = array_sum(array_map(static fn (UploadedFile $file): int => (int) ($file->getSize() ?? 0), $uploaded_files));
    if ($upload_bytes > AiGenerationService::MAX_TOTAL_ATTACHMENT_BYTES) {
      $this->messenger()->addError($this->t('The attachments exceed the combined size limit.'));
      return;
    }

    $request = $this->requestStack->getCurrentRequest();
    $identifier = $this->currentUser->id() . ':' . ($request ? $request->getClientIp() : 'unknown');
    if (!$this->flood->isAllowed('moody_ai_assistant.generate', $this->generator->hourlyRequestLimit(), 3600, $identifier)) {
      $this->messenger()->addError($this->t('The hourly Moody AI request limit has been reached.'));
      return;
    }
    $this->flood->register('moody_ai_assistant.generate', 3600, $identifier);

    try {
      $this->usageTracker->assertUserHasBudget($this->currentUser);
      $result = $this->chatManager->processUserMessage($entity, $this->currentUser, $message, $runtime_context, $uploaded_files);
      $this->messenger()->addStatus($result['status_message']);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('AI chat request failed: @message', ['@message' => $e->getMessage()]));
    }

    $form_state->setRedirectUrl($entity->toUrl());
  }

  /**
   * Gets uploaded files from the current request.
   *
   * @return \Symfony\Component\HttpFoundation\File\UploadedFile[]
   *   The uploaded files.
   */
  protected function getUploadedFiles() {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return [];
    }

    $files = $request->files->get('attachments', []);
    if ($files instanceof UploadedFile) {
      return [$files];
    }

    if (!is_array($files)) {
      return [];
    }

    return array_values(array_filter($files, function ($file) {
      return $file instanceof UploadedFile;
    }));
  }

  /**
   * Gets editable starter prompts for the assistant picker.
   *
   * @return array<int, array{label: string, prompt: string}>
   *   The starter prompts.
   */
  protected function getStarterPrompts() {
    $path = $this->moduleExtensionList->getPath('moody_ai_assistant') . '/config/assistant-starter-prompts.json';
    if (!is_readable($path)) {
      return [];
    }

    $decoded = json_decode((string) file_get_contents($path), TRUE);
    if (!is_array($decoded)) {
      return [];
    }

    $current_year = date('Y');
    $prompts = [];
    foreach ($decoded as $item) {
      if (!is_array($item) || empty($item['label']) || empty($item['prompt'])) {
        continue;
      }

      $prompts[] = [
        'label' => (string) $item['label'],
        'prompt' => str_replace('[current year]', $current_year, (string) $item['prompt']),
      ];
    }

    return $prompts;
  }

  /**
   * Builds the render array for one clickable block reference item.
   */
  protected function buildBlockReferenceElement(array $item) {
    $label = (string) ($item['label'] ?? 'Untitled block');
    $type_label = (string) ($item['type_label'] ?? 'Block');
    $reference_id = (string) ($item['reference_id'] ?? $item['uuid'] ?? '');
    $uuid = (string) ($item['uuid'] ?? '');
    $plugin_id = (string) ($item['plugin_id'] ?? '');
    $block_type = (string) ($item['block_type'] ?? '');
    $group_label = (string) ($item['group_label'] ?? 'Available blocks');
    $selection_mode = (string) ($item['selection_mode'] ?? 'new');
    $existing_count = isset($item['existing_count']) ? (int) $item['existing_count'] : 0;
    $image_path = (string) ($item['image_path'] ?? '');
    $image_alt = (string) ($item['image_alt'] ?? $item['type_label'] ?? '');
    $can_edit = !empty($item['can_edit']) ? 'true' : 'false';
    $copy = $type_label . ' • ' . $group_label . ($existing_count > 0 ? ' • Already on page' : '');

    $element = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ai-moody-assistant__block-ref'],
        'role' => 'button',
        'tabindex' => '0',
        'data-ai-assistant-block-ref' => TRUE,
        'data-ai-assistant-block-ref-reference-id' => $reference_id,
        'data-ai-assistant-block-ref-id' => $uuid,
        'data-ai-assistant-block-ref-label' => $label,
        'data-ai-assistant-block-ref-type' => $type_label,
        'data-ai-assistant-block-ref-plugin-id' => $plugin_id,
        'data-ai-assistant-block-ref-block-type' => $block_type,
        'data-ai-assistant-block-ref-group-label' => $group_label,
        'data-ai-assistant-block-ref-existing-count' => $existing_count,
        'data-ai-assistant-block-ref-can-edit' => $can_edit,
        'data-ai-assistant-block-ref-mode' => $selection_mode,
        'aria-label' => $this->t('Add @label to chat', ['@label' => $label]),
      ],
    ];

    if ($image_path !== '') {
      $element['media'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => [
          'class' => ['ai-moody-assistant__block-ref-media'],
        ],
        'image' => [
          '#markup' => '<img src="' . Html::escape($image_path) . '" alt="' . Html::escape($image_alt) . '">',
        ],
      ];
    }
    else {
      $element['media'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => mb_substr($type_label !== '' ? $type_label : 'B', 0, 1),
        '#attributes' => [
          'class' => ['ai-moody-assistant__block-ref-media', 'ai-moody-assistant__block-ref-media--placeholder'],
        ],
      ];
    }

    $element['meta'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ai-moody-assistant__block-ref-meta'],
      ],
    ];

    $element['meta']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $label,
      '#attributes' => [
        'class' => ['ai-moody-assistant__block-ref-title'],
      ],
    ];

    $element['meta']['copy'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $copy,
      '#attributes' => [
        'class' => ['ai-moody-assistant__block-ref-copy'],
      ],
    ];

    $element['action'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $selection_mode === 'edit' ? $this->t('Click to add edit token') : $this->t('Click to add to chat'),
      '#attributes' => [
        'class' => ['ai-moody-assistant__block-ref-action'],
      ],
    ];

    return $element;
  }

  /**
   * Extracts selected block references from a JSON payload.
   */
  protected function extractSelectedBlockReferences($raw_value) {
    if ($raw_value === '') {
      return [];
    }

    $decoded = json_decode($raw_value, TRUE);
    if (!is_array($decoded)) {
      return [];
    }

    $references = [];
    foreach ($decoded as $item) {
      if (!is_array($item)) {
        continue;
      }

      $reference_id = trim((string) ($item['reference_id'] ?? $item['plugin_id'] ?? $item['uuid'] ?? ''));
      $label = trim((string) ($item['label'] ?? ''));
      if ($reference_id === '' || $label === '') {
        continue;
      }

      $references[$reference_id] = array_filter([
        'reference_id' => $reference_id,
        'uuid' => trim((string) ($item['uuid'] ?? '')),
        'label' => $label,
        'type_label' => trim((string) ($item['type_label'] ?? '')),
        'plugin_id' => trim((string) ($item['plugin_id'] ?? '')),
        'block_type' => trim((string) ($item['block_type'] ?? '')),
        'selection_mode' => trim((string) ($item['selection_mode'] ?? 'new')),
        'group_label' => trim((string) ($item['group_label'] ?? '')),
        'existing_count' => isset($item['existing_count']) ? (int) $item['existing_count'] : NULL,
        'can_edit' => !empty($item['can_edit']),
      ], function ($value) {
        return $value !== NULL && $value !== '';
      });
    }

    return array_values($references);
  }

}
