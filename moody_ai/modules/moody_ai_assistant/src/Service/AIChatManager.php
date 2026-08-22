<?php

namespace Drupal\moody_ai_assistant\Service;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\moody_ai_assistant\Entity\AIChatThread;
use Drupal\Core\Url;
use Drupal\moody_ai_assistant\Service\AssistantPlanner;
use Drupal\moody_ai_assistant\Service\AIUsageTracker;
use Drupal\moody_ai_assistant\Service\AIBlockInspector;

class AIChatManager {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The instruction generator.
   *
   * @var \Drupal\moody_ai_assistant\Service\AIInstructionGenerator
   */
  protected $instructionGenerator;

  /**
   * The block parser.
   *
   * @var \Drupal\moody_ai_assistant\Service\BlockParser
   */
  protected $blockParser;

  /**
   * The layout context collector.
   *
   * @var \Drupal\moody_ai_assistant\Service\LayoutContextCollector
   */
  protected $layoutContextCollector;

  /**
   * The placement manager.
   *
   * @var \Drupal\moody_ai_assistant\Service\LayoutPlacementManager
   */
  protected $layoutPlacementManager;

  /**
   * The provider-neutral assistant planner.
   *
   * @var \Drupal\moody_ai_assistant\Service\AssistantPlanner
   */
  protected $planner;

  /**
   * The asset creator.
   *
   * @var \Drupal\moody_ai_assistant\Service\AIAssetCreator
   */
  protected $assetCreator;

  /**
   * The AI usage tracker.
   *
   * @var \Drupal\moody_ai_assistant\Service\AIUsageTracker
   */
  protected $usageTracker;

  /**
   * The AI block inspector.
   *
   * @var \Drupal\moody_ai_assistant\Service\AIBlockInspector
   */
  protected $blockInspector;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The CSRF token generator for deferred Layout Builder actions.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected $csrfToken;

  /**
   * Constructs the manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory, AIInstructionGenerator $instruction_generator, BlockParser $block_parser, LayoutContextCollector $layout_context_collector, LayoutPlacementManager $layout_placement_manager, AssistantPlanner $planner, LanguageManagerInterface $language_manager, AIAssetCreator $asset_creator, AIUsageTracker $usage_tracker, AIBlockInspector $block_inspector, CsrfTokenGenerator $csrf_token) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('moody_ai_assistant');
    $this->instructionGenerator = $instruction_generator;
    $this->blockParser = $block_parser;
    $this->layoutContextCollector = $layout_context_collector;
    $this->layoutPlacementManager = $layout_placement_manager;
    $this->planner = $planner;
    $this->languageManager = $language_manager;
    $this->assetCreator = $asset_creator;
    $this->usageTracker = $usage_tracker;
    $this->blockInspector = $block_inspector;
    $this->csrfToken = $csrf_token;
  }

  /**
   * Loads or creates a chat thread for the current user and page.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The page entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The active account.
   * @param bool $create
   *   Whether to create if missing.
   *
   * @return \Drupal\moody_ai_assistant\Entity\AIChatThread|null
   *   The loaded or created thread.
   */
  public function getThread(ContentEntityInterface $entity, AccountInterface $account, $create = TRUE) {
    $storage = $this->entityTypeManager->getStorage('moody_ai_chat_thread');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('user_id', $account->id())
      ->condition('target_entity_type', $entity->getEntityTypeId())
      ->condition('target_entity_id', $entity->id())
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();

    if ($ids) {
      /** @var \Drupal\moody_ai_assistant\Entity\AIChatThread $thread */
      $thread = $storage->load(reset($ids));
      return $thread;
    }

    if (!$create) {
      return NULL;
    }

    /** @var \Drupal\moody_ai_assistant\Entity\AIChatThread $thread */
    $thread = $storage->create([
      'label' => sprintf('AI chat for %s', $entity->label()),
      'user_id' => $account->id(),
      'target_entity_type' => $entity->getEntityTypeId(),
      'target_entity_id' => $entity->id(),
      'messages_json' => '[]',
    ]);
    $thread->save();

    return $thread;
  }

  /**
   * Gets recent conversation summaries for the current user.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The active account.
   * @param int $limit
   *   Maximum number of conversations to return.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $active_entity
   *   The current page entity, if available.
   *
   * @return array
   *   Conversation summary arrays for rendering.
   */
  public function getRecentThreadSummaries(AccountInterface $account, $limit = 12, ?ContentEntityInterface $active_entity = NULL) {
    $storage = $this->entityTypeManager->getStorage('moody_ai_chat_thread');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('user_id', $account->id())
      ->sort('changed', 'DESC')
      ->range(0, $limit)
      ->execute();

    if (!$ids) {
      return [];
    }

    /** @var \Drupal\moody_ai_assistant\Entity\AIChatThread[] $threads */
    $threads = $storage->loadMultiple($ids);
    $summaries = [];

    foreach ($threads as $thread) {
      $target_entity_type = (string) $thread->get('target_entity_type')->value;
      $target_entity_id = (int) $thread->get('target_entity_id')->value;

      if ($target_entity_type === '' || !$target_entity_id) {
        continue;
      }

      $target_storage = $this->entityTypeManager->getStorage($target_entity_type);
      if (!$target_storage) {
        continue;
      }

      $target_entity = $target_storage->load($target_entity_id);
      if (!$target_entity instanceof ContentEntityInterface || !$target_entity->access('update', $account)) {
        continue;
      }

      $messages = $thread->getMessages();
      $last_message = end($messages) ?: [];
      $is_active = $active_entity
        && $active_entity->getEntityTypeId() === $target_entity_type
        && (string) $active_entity->id() === (string) $target_entity_id;

      $summaries[] = [
        'thread_id' => (int) $thread->id(),
        'label' => $target_entity->label(),
        'entity_type' => $target_entity_type,
        'entity_id' => $target_entity_id,
        'url' => $this->buildEntityUrl($target_entity),
        'reset_url' => Url::fromRoute('moody_ai_assistant.chat_thread_reset', ['moody_ai_chat_thread' => $thread->id()])->toString(),
        'is_active' => $is_active,
        'message_count' => count($messages),
        'last_message' => !empty($last_message['content']) ? mb_substr((string) $last_message['content'], 0, 110) : '',
        'last_message_role' => !empty($last_message['role']) ? (string) $last_message['role'] : 'assistant',
        'updated' => (int) $thread->getChangedTime(),
      ];
    }

    return $summaries;
  }

  /**
   * Processes a user chat request and places the generated block on the page.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The current page entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param string $message
   *   The user's message.
   *
   * @return array
   *   Result metadata.
   */
  public function processUserMessage(ContentEntityInterface $entity, AccountInterface $account, $message, array $runtime_context = [], array $uploaded_files = []) {
    $this->planner->resetUsageEvents();
    $this->planner->selectProviderAndModel($runtime_context['provider'] ?? NULL, $runtime_context['model'] ?? NULL);
    $thread = $this->getThread($entity, $account, TRUE);
    $usage_status = 'success';

    try {
      $this->usageTracker->assertUserHasBudget($account);
      $uploaded_assets = array_merge(
        $this->assetCreator->prepareExistingMediaAssets(
          $runtime_context['existing_media_ids'] ?? [],
          (string) ($runtime_context['existing_media_intent'] ?? 'inspiration'),
        ),
        $this->assetCreator->prepareUploadedAssets($uploaded_files),
      );
      $context = $this->layoutContextCollector->collectEntityContext($entity, $runtime_context);
      $runtime_context['selected_block_references'] = $context['selected_block_references'] ?? [];
      $runtime_context['selected_block_reference_ids'] = array_values(array_unique(array_filter(array_map(function (array $reference) {
        return !empty($reference['uuid']) ? (string) $reference['uuid'] : '';
      }, $context['selected_existing_block_references'] ?? []))));
      $thread->addMessage('user', $message, $this->buildUserMessageMetadata($runtime_context, $uploaded_assets));
      if ($uploaded_assets) {
        $context['uploaded_assets'] = $uploaded_assets;
      }
      $page_options = $this->getAvailablePageCreationOptions($account);
      $top_level_plan = $this->planner->planTopLevelAction($message, $context, $page_options);
      if (($top_level_plan['action'] ?? 'block') === 'guide') {
        return $this->prepareSiteFunctionGuide($thread, $top_level_plan, $account);
      }
      if (($top_level_plan['action'] ?? 'block') === 'redirect') {
        return $this->prepareRedirectPreview($thread, $top_level_plan, $account);
      }
      if (($top_level_plan['action'] ?? 'block') === 'create_page') {
        $structured_plan = $this->instructionGenerator->planStructuredBuild($message, $context, array_slice($thread->getMessages(), -8), $page_options);
        return $this->preparePageCreationGuide($thread, $top_level_plan, $page_options, $structured_plan);
      }

      $context = $this->enrichContextWithBlockInspection($entity, $message, $context, $runtime_context, $thread);
      $action_plan = $this->planner->planConversationAction($message, $context, array_slice($thread->getMessages(), -8));
      $explicit_target = !$this->hasSelectedNewBlockTokens($context) ? $this->getExplicitSelectedEditTarget($context) : NULL;
      if ($explicit_target) {
        $action_plan['action'] = 'edit';
        $action_plan['target_component_uuid'] = $explicit_target['uuid'];
        $action_plan['target_block_type'] = $explicit_target['block_type'] ?? '';
        $action_plan['preview_title'] = $action_plan['preview_title'] ?? 'Preview update to selected block';
        $action_plan['preview_summary'] = $action_plan['preview_summary'] ?? sprintf('I will update the selected block "%s" directly.', $explicit_target['block_label'] ?? $explicit_target['label'] ?? 'Selected block');
      }
      $follow_up_target = $this->inferFollowUpTarget($thread, $context, $message);
      if ($follow_up_target) {
        $action_plan['action'] = 'edit';
        $action_plan['target_component_uuid'] = $follow_up_target['uuid'];
        $action_plan['target_block_type'] = $follow_up_target['block_type'] ?? '';
        if (empty($action_plan['preview_title'])) {
          $action_plan['preview_title'] = 'Preview update to existing block';
        }
        if (empty($action_plan['preview_summary'])) {
          $action_plan['preview_summary'] = sprintf(
            'I found the earlier %s block from this conversation and will update that same block instead of creating a new one.',
            $follow_up_target['block_label'] ?? $follow_up_target['label'] ?? 'inline'
          );
        }
      }

      if (($action_plan['action'] ?? 'create') === 'edit') {
        return $this->prepareEditPreview($entity, $thread, $message, $context, $action_plan, $runtime_context, $uploaded_assets);
      }

      $structured_plan = $this->instructionGenerator->planStructuredBuild($message, $context, array_slice($thread->getMessages(), -8), $page_options);
      if (($structured_plan['mode'] ?? 'single') === 'multi' && !empty($structured_plan['blocks'])) {
        return $this->executeStructuredBlockPlan($entity, $thread, $message, $context, $structured_plan, NULL, NULL, $runtime_context, $uploaded_assets);
      }

      $instructions = $this->instructionGenerator->generate($this->buildPrompt($message, $context, $thread), [
        'uploaded_assets' => $uploaded_assets,
        'prefer_ai_images' => !empty($context['prefer_ai_images']),
      ]);
      $selected_type = $instructions['plan']['selected_block_type'] ?? 'block';
      $instructions['block_title'] = $this->buildBlockTitle($entity, $selected_type);
      $instructions['reusable'] = FALSE;

      $blocks = $this->blockParser->createBlocksFromInstructions($instructions);
      if (empty($blocks)) {
        throw new \Exception('No blocks were created from the assistant response.');
      }

      try {
        $placements = [];
        foreach ($blocks as $block) {
          $placements[] = $this->layoutPlacementManager->placeBlock($entity, $block, $runtime_context);
        }
      }
      catch (\Exception $placement_exception) {
        if ($this->isMissingLayoutBuilderOverridesException($placement_exception)) {
          return $this->prepareLayoutBuilderPlacementAction($entity, $thread, $blocks, 'I created the content for this request, but I need Layout Builder initialized before I can place it on the page.');
        }
        throw $placement_exception;
      }

      $assistant_message = $this->buildAssistantSuccessMessage($entity, $instructions, $placements);
      $thread->addMessage('assistant', $assistant_message, [
        'plan' => $instructions['plan'] ?? [],
        'placements' => $placements,
        'created_blocks' => $this->buildCreatedBlockMetadata($blocks, $placements),
      ]);
      $thread->save();

      return [
        'thread' => $thread,
        'status_message' => t('AI created and placed a new block on this page. Refresh complete; the chat thread has been preserved below.'),
      ];
    }
    catch (\Exception $e) {
      $usage_status = 'error';
      $thread->addMessage('assistant', 'I could not complete that request: ' . $e->getMessage());
      $thread->save();
      $this->logger->error('AI chat request failed for @entity_type/@entity_id: @message', [
        '@entity_type' => $entity->getEntityTypeId(),
        '@entity_id' => $entity->id(),
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
    finally {
      $this->recordWidgetUsage($account, $entity, $message, $thread, $usage_status);
    }
  }

  /**
   * Processes a user request while emitting progress events for streaming UIs.
   */
  public function processUserMessageStream(ContentEntityInterface $entity, AccountInterface $account, $message, callable $event_callback, array $runtime_context = [], array $uploaded_files = []) {
    $this->planner->resetUsageEvents();
    $this->planner->selectProviderAndModel($runtime_context['provider'] ?? NULL, $runtime_context['model'] ?? NULL);
    $thread = $this->getThread($entity, $account, TRUE);
    $usage_status = 'success';

    try {
      $this->usageTracker->assertUserHasBudget($account);
      $uploaded_assets = array_merge(
        $this->assetCreator->prepareExistingMediaAssets(
          $runtime_context['existing_media_ids'] ?? [],
          (string) ($runtime_context['existing_media_intent'] ?? 'inspiration'),
        ),
        $this->assetCreator->prepareUploadedAssets($uploaded_files),
      );
      $context = $this->layoutContextCollector->collectEntityContext($entity, $runtime_context);
      $runtime_context['selected_block_references'] = $context['selected_block_references'] ?? [];
      $runtime_context['selected_block_reference_ids'] = array_values(array_unique(array_filter(array_map(function (array $reference) {
        return !empty($reference['uuid']) ? (string) $reference['uuid'] : '';
      }, $context['selected_existing_block_references'] ?? []))));
      $thread->addMessage('user', $message, $this->buildUserMessageMetadata($runtime_context, $uploaded_assets));
      $thread->save();

      $emit = function ($status) use ($event_callback, $thread) {
        $event_callback('status', [
          'message' => $status,
          'thread_id' => (int) $thread->id(),
        ]);
      };

      $stream_ping = function () use (&$last_stream_emit, $emit) {
        $now = microtime(TRUE);
        if (!isset($last_stream_emit) || ($now - $last_stream_emit) >= 0.75) {
          $last_stream_emit = $now;
          $emit('Still working...');
        }
      };

      return $this->executeUserMessageStream($entity, $account, $thread, $message, $event_callback, $runtime_context, $uploaded_assets, function () use ($stream_ping) {
        $stream_ping();
      });
    }
    catch (\Exception $e) {
      $usage_status = 'error';
      $thread->addMessage('assistant', 'I could not complete that request: ' . $e->getMessage());
      $thread->save();
      $this->logger->error('AI streaming chat request failed for @entity_type/@entity_id: @message', [
        '@entity_type' => $entity->getEntityTypeId(),
        '@entity_id' => $entity->id(),
        '@message' => $e->getMessage(),
      ]);
      $event_callback('error', [
        'message' => $e->getMessage(),
      ]);
      throw $e;
    }
    finally {
      $this->recordWidgetUsage($account, $entity, $message, $thread, $usage_status);
    }
  }

  /**
   * Resumes a deferred AI request after navigating into Layout Builder.
   */
  public function resumeDeferredLayoutBuilderRequestStream(ContentEntityInterface $entity, AIChatThread $thread, AccountInterface $account, $action_id, callable $event_callback, array $runtime_context = []) {
    if ((int) $thread->get('user_id')->target_id !== (int) $account->id() && !$account->hasPermission('administer moody ai chat threads')) {
      throw new \Exception('You do not have access to manage this conversation.');
    }
    if ((string) $thread->get('target_entity_type')->value !== $entity->getEntityTypeId() || (int) $thread->get('target_entity_id')->value !== (int) $entity->id() || !$entity->access('update', $account)) {
      throw new \Exception('The saved operation does not belong to this editable page.');
    }
    $this->planner->resetUsageEvents();
    $usage_status = 'success';
    $message = '';
    try {
      $pending = $thread->getPendingAction($action_id);
      $action = $pending['pending_action'] ?? [];
      $request = $action['request'] ?? [];
      $message = trim((string) ($request['message'] ?? ''));

      $this->usageTracker->assertUserHasBudget($account);
      if (!$pending) {
        throw new \Exception('The requested AI operation is no longer pending.');
      }

      if (($action['type'] ?? '') !== 'resume_request_in_layout_builder') {
        throw new \Exception('The requested AI operation cannot be resumed automatically.');
      }

      if ($message === '') {
        throw new \Exception('The deferred AI request is missing its original prompt.');
      }

      $thread->resolvePendingAction($action_id, 'approved', [
        'resolved_at' => time(),
        'auto_resumed' => TRUE,
      ]);
      $thread->save();

      $uploaded_assets = [];
      foreach (($request['uploaded_assets'] ?? []) as $asset) {
        if (is_array($asset)) {
          $uploaded_assets[] = $asset;
        }
      }

      $runtime_context['selected_block_references'] = is_array($request['selected_block_references'] ?? NULL) ? $request['selected_block_references'] : [];
      $runtime_context['selected_block_reference_ids'] = array_values(array_unique(array_filter(array_map('strval', $request['selected_block_reference_ids'] ?? []))));
      $runtime_context['prefer_ai_images'] = !empty($request['prefer_ai_images']);
      $runtime_context['provider'] = (string) ($request['provider'] ?? 'openai');
      $runtime_context['model'] = (string) ($request['model'] ?? '');
      $this->planner->selectProviderAndModel($runtime_context['provider'], $runtime_context['model']);

      return $this->executeUserMessageStream($entity, $account, $thread, $message, $event_callback, $runtime_context, $uploaded_assets, NULL, FALSE);
    }
    catch (\Exception $exception) {
      $usage_status = 'error';
      throw $exception;
    }
    finally {
      $this->recordWidgetUsage($account, $entity, $message, $thread, $usage_status);
    }
  }

  /**
   * Executes a streamed AI request against a thread.
   */
  protected function executeUserMessageStream(ContentEntityInterface $entity, AccountInterface $account, AIChatThread $thread, $message, callable $event_callback, array $runtime_context = [], array $uploaded_assets = [], ?callable $stream_ping = NULL, $allow_layout_builder_redirect = TRUE) {
    $emit = function ($status) use ($event_callback, $thread) {
      $event_callback('status', [
        'message' => $status,
        'thread_id' => (int) $thread->id(),
      ]);
    };

    $ping = $stream_ping ?: static function () {
    };

    $emit('Understanding request...');
    $context = $this->layoutContextCollector->collectEntityContext($entity, $runtime_context);
    if ($uploaded_assets) {
      $context['uploaded_assets'] = $uploaded_assets;
    }
    $page_options = $this->getAvailablePageCreationOptions($account);
    $top_level_plan = $this->planner->planTopLevelAction($message, $context, $page_options, function () use ($ping) {
      $ping();
    });

    if ($allow_layout_builder_redirect && $this->shouldResumeInLayoutBuilder($entity, $top_level_plan, $runtime_context)) {
      $emit('Opening Layout Builder to continue...');
      $result = $this->prepareLayoutBuilderExecutionAction($entity, $thread, $message, $uploaded_assets, $runtime_context);
      $event_callback('complete', [
        'redirect_url' => $result['redirect_url'],
        'status_message' => (string) $result['status_message'],
      ]);
      return $result;
    }

    if (($top_level_plan['action'] ?? 'block') === 'guide') {
      $emit('Finding the right site tool...');
      $result = $this->prepareSiteFunctionGuide($thread, $top_level_plan, $account);
      $event_callback('complete', [
        'reload_url' => $this->buildAssistantCompletionUrl($entity, $runtime_context),
        'status_message' => (string) $result['status_message'],
      ]);
      return $result;
    }

    if (($top_level_plan['action'] ?? 'block') === 'redirect') {
      $emit('Preparing redirect preview...');
      $result = $this->prepareRedirectPreview($thread, $top_level_plan, $account);
      $event_callback('complete', [
        'reload_url' => $this->buildAssistantCompletionUrl($entity, $runtime_context),
        'status_message' => (string) $result['status_message'],
      ]);
      return $result;
    }

    if (($top_level_plan['action'] ?? 'block') === 'create_page') {
      $emit('Preparing page creation options...');
      $structured_plan = $this->instructionGenerator->planStructuredBuild($message, $context, array_slice($thread->getMessages(), -8), $page_options, function () use ($ping) {
        $ping();
      });
      $result = $this->preparePageCreationGuide($thread, $top_level_plan, $page_options, $structured_plan);
      $event_callback('complete', [
        'reload_url' => $this->buildAssistantCompletionUrl($entity, $runtime_context),
        'status_message' => (string) $result['status_message'],
      ]);
      return $result;
    }

    $emit('Inspecting editable page blocks...');
    $context = $this->enrichContextWithBlockInspection($entity, $message, $context, $runtime_context, $thread, function () use ($ping) {
      $ping();
    });

    $emit('Reviewing page context...');
    $action_plan = $this->planner->planConversationAction($message, $context, array_slice($thread->getMessages(), -8), function () use ($ping) {
      $ping();
    });
    $explicit_target = !$this->hasSelectedNewBlockTokens($context) ? $this->getExplicitSelectedEditTarget($context) : NULL;
    if ($explicit_target) {
      $action_plan['action'] = 'edit';
      $action_plan['target_component_uuid'] = $explicit_target['uuid'];
      $action_plan['target_block_type'] = $explicit_target['block_type'] ?? '';
      $action_plan['preview_title'] = $action_plan['preview_title'] ?? 'Preview update to selected block';
      $action_plan['preview_summary'] = $action_plan['preview_summary'] ?? sprintf('I will update the selected block "%s" directly.', $explicit_target['block_label'] ?? $explicit_target['label'] ?? 'Selected block');
    }
    $follow_up_target = $this->inferFollowUpTarget($thread, $context, $message);
    if ($follow_up_target) {
      $action_plan['action'] = 'edit';
      $action_plan['target_component_uuid'] = $follow_up_target['uuid'];
      $action_plan['target_block_type'] = $follow_up_target['block_type'] ?? '';
      if (empty($action_plan['preview_title'])) {
        $action_plan['preview_title'] = 'Preview update to existing block';
      }
      if (empty($action_plan['preview_summary'])) {
        $action_plan['preview_summary'] = sprintf(
          'I found the earlier %s block from this conversation and will update that same block instead of creating a new one.',
          $follow_up_target['block_label'] ?? $follow_up_target['label'] ?? 'inline'
        );
      }
    }

    if (($action_plan['action'] ?? 'create') === 'edit') {
      $emit('Preparing edit preview...');
      $result = $this->prepareEditPreviewStream($entity, $thread, $message, $context, $action_plan, function () use ($ping) {
        $ping();
      }, $runtime_context, $uploaded_assets);
      $event_callback('complete', [
        'reload_url' => $this->buildAssistantCompletionUrl($entity, $runtime_context),
        'status_message' => (string) $result['status_message'],
      ]);
      return $result;
    }

    $emit('Planning the page structure...');
    $structured_plan = $this->instructionGenerator->planStructuredBuild($message, $context, array_slice($thread->getMessages(), -8), $page_options, function () use ($ping) {
      $ping();
    });
    if (($structured_plan['mode'] ?? 'single') === 'multi' && !empty($structured_plan['blocks'])) {
      $emit('Building multiple components...');
      $result = $this->executeStructuredBlockPlan($entity, $thread, $message, $context, $structured_plan, $emit, function () use ($ping) {
        $ping();
      }, $runtime_context, $uploaded_assets);
      $event_callback('complete', [
        'reload_url' => $this->buildAssistantCompletionUrl($entity, $runtime_context),
        'status_message' => (string) $result['status_message'],
      ]);
      return $result;
    }

    $emit('Choosing a block and generating instructions...');
    $instructions = $this->instructionGenerator->generate($this->buildPrompt($message, $context, $thread), [
      'uploaded_assets' => $uploaded_assets,
      'prefer_ai_images' => !empty($context['prefer_ai_images']),
    ], function () use ($ping) {
      $ping();
    });
    $selected_type = $instructions['plan']['selected_block_type'] ?? 'block';
    $instructions['block_title'] = $this->buildBlockTitle($entity, $selected_type);
    $instructions['reusable'] = FALSE;

    $emit('Creating the block...');
    $blocks = $this->blockParser->createBlocksFromInstructions($instructions);
    if (empty($blocks)) {
      throw new \Exception('No blocks were created from the assistant response.');
    }

    $emit('Placing the block into the layout...');
    try {
      $placements = [];
      foreach ($blocks as $block) {
        $placements[] = $this->layoutPlacementManager->placeBlock($entity, $block, $runtime_context);
      }
    }
    catch (\Exception $placement_exception) {
      if ($this->isMissingLayoutBuilderOverridesException($placement_exception)) {
        $result = $this->prepareLayoutBuilderPlacementAction($entity, $thread, $blocks, 'I created the content for this request, but I need Layout Builder initialized before I can place it on the page.');
        $event_callback('complete', [
          'redirect_url' => $result['redirect_url'] ?? NULL,
          'reload_url' => $this->buildAssistantCompletionUrl($entity, $runtime_context),
          'status_message' => (string) $result['status_message'],
        ]);
        return $result;
      }
      throw $placement_exception;
    }

    $assistant_message = $this->buildAssistantSuccessMessage($entity, $instructions, $placements);
    $thread->addMessage('assistant', $assistant_message, [
      'plan' => $instructions['plan'] ?? [],
      'placements' => $placements,
      'created_blocks' => $this->buildCreatedBlockMetadata($blocks, $placements),
    ]);
    $thread->save();

    $result = [
      'thread' => $thread,
      'status_message' => t('AI created and placed a new block on this page. Refresh complete; the chat thread has been preserved below.'),
    ];
    $event_callback('complete', [
      'reload_url' => $this->buildAssistantCompletionUrl($entity, $runtime_context),
      'status_message' => (string) $result['status_message'],
    ]);
    return $result;
  }

  /**
   * Handles a pending preview decision.
   *
   * @param \Drupal\moody_ai_assistant\Entity\AIChatThread $thread
   *   The target thread.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The acting account.
   * @param string $action_id
   *   The action ID.
   * @param string $decision
   *   The decision: approve or reject.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The target page entity for redirect.
   */
  public function handlePendingActionDecision(AIChatThread $thread, AccountInterface $account, $action_id, $decision) {
    if ((int) $thread->get('user_id')->target_id !== (int) $account->id() && !$account->hasPermission('administer moody ai chat threads')) {
      throw new \Exception('You do not have access to manage this conversation.');
    }

    $pending = $thread->getPendingAction($action_id);
    if (!$pending) {
      throw new \Exception('That preview is no longer pending.');
    }

    $entity = $this->loadThreadTargetEntity($thread);
    if (!$entity) {
      throw new \Exception('The target page for this conversation could not be loaded.');
    }
    if (!$entity->access('update', $account)) {
      throw new \Exception('You no longer have permission to update the target page.');
    }

    if ($decision === 'reject') {
      $dismiss_message = 'Dismissed the proposed action. No changes were made.';
      if (($pending['pending_action']['type'] ?? '') === 'edit_existing_block') {
        $dismiss_message = 'Dismissed the proposed block edit. No changes were made.';
      }
      elseif (($pending['pending_action']['type'] ?? '') === 'create_redirect') {
        $dismiss_message = 'Dismissed the proposed redirect. No redirect was created.';
      }
      elseif (($pending['pending_action']['type'] ?? '') === 'place_existing_blocks') {
        $dismiss_message = 'Dismissed the pending layout placement. No blocks were attached to the page.';
      }

      $thread->resolvePendingAction($action_id, 'rejected', ['resolved_at' => time()]);
      $thread->addMessage('assistant', $dismiss_message, [
        'action_resolution' => [
          'id' => $action_id,
          'decision' => 'reject',
        ],
      ]);
      $thread->save();
      return $entity;
    }

    $action = $pending['pending_action'];
    if (($action['type'] ?? '') === 'create_redirect') {
      return $this->handleRedirectApproval($thread, $action_id, $action, $entity, $account);
    }
    if (($action['type'] ?? '') === 'place_existing_blocks') {
      return $this->handleDeferredPlacementApproval($thread, $action_id, $action, $entity);
    }

    $target = $action['target_component'] ?? [];
    if (empty($target['block_revision_id']) || empty($target['component_uuid'])) {
      throw new \Exception('The preview is missing the target block revision details needed for execution.');
    }

    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $block = $block_storage->loadRevision($target['block_revision_id']);
    if (!$block) {
      throw new \Exception('The target inline block revision could not be loaded.');
    }

    $updated_block = $this->blockParser->updateBlockFromInstructions($block, $action['instructions']);
    $placement = $this->layoutPlacementManager->updateInlineBlockComponent($entity, $target['component_uuid'], $updated_block);

    $thread->resolvePendingAction($action_id, 'approved', [
      'resolved_at' => time(),
      'placement' => $placement,
    ]);
    $thread->addMessage('assistant', sprintf('Applied the approved edit to "%s". The updated block revision is now attached to this page.', $target['block_label'] ?? $entity->label()), [
      'action_resolution' => [
        'id' => $action_id,
        'decision' => 'approve',
        'placement' => $placement,
        'target_component' => $target,
      ],
    ]);
    $thread->save();

    return $entity;
  }

  /**
   * Builds a prompt enriched with page context and recent thread history.
   */
  protected function buildPrompt($message, array $context, AIChatThread $thread) {
    $recent_messages = array_slice($thread->getMessages(), -6);
    $selected_refs_note = '';
    if (!empty($context['selected_block_references'])) {
      $selected_new_labels = [];
      $selected_edit_labels = [];

      foreach ($context['selected_block_references'] as $reference) {
        $label = trim((string) ($reference['label'] ?? ''));
        if ($label === '') {
          continue;
        }

        if (($reference['selection_mode'] ?? 'new') === 'edit') {
          $selected_edit_labels[] = $label;
        }
        else {
          $selected_new_labels[] = $label;
        }
      }

      $selected_refs_note = "\n\nThe user explicitly selected one or more component tokens in the assistant UI. Respect those tokens as direct instructions about what should be created or edited.";
      if ($selected_new_labels) {
        $selected_refs_note .= "\nNew component tokens: " . implode(', ', $selected_new_labels) . ".";
        $selected_refs_note .= "\nTreat those as requested new component families to create, especially for multi-component requests.";
      }
      if ($selected_edit_labels) {
        $selected_refs_note .= "\nEdit existing block tokens: " . implode(', ', $selected_edit_labels) . ".";
        $selected_refs_note .= "\nDirect revision requests specifically at those existing page blocks.";
      }
      if ($selected_new_labels && $selected_edit_labels) {
        $selected_refs_note .= "\nThe user intentionally mixed new-component and edit-existing selections, so the response may need to cover both creation and revision work in one request.";
      }
    }

    $image_preference_note = !empty($context['prefer_ai_images'])
      ? 'The user explicitly selected Generate AI Images. Strongly prefer newly generated AI artwork over existing or uploaded media unless the request clearly points to a supplied file or direct image URL.'
      : 'If uploaded media assets are provided, prefer reusing those media IDs before generating or downloading a new asset.';

    return "The user is asking for block creation and/or revision on the current page. Use the page context to choose the right blocks to create or update. " . $image_preference_note . " If an image would help and the chosen block supports it, prepare one.\n\n"
      . "Page context JSON:\n" . json_encode($context, JSON_PRETTY_PRINT)
      . $selected_refs_note
      . "\n\nRecent chat JSON:\n" . json_encode($recent_messages, JSON_PRETTY_PRINT)
      . "\n\nUser request:\n" . $message;
  }

  /**
   * Records usage for the current widget request.
   */
  protected function recordWidgetUsage(AccountInterface $account, ContentEntityInterface $entity, $message, AIChatThread $thread, $status) {
    $usage_events = $this->planner->consumeUsageEvents();
    $tokens_used = $this->planner->sumUsageTokens($usage_events);
    $this->usageTracker->recordUsage($account, $entity, $message, $tokens_used, $status, $thread->id(), 'widget');
  }

  /**
   * Adds targeted block inspection context before edit target planning.
   */
  protected function enrichContextWithBlockInspection(ContentEntityInterface $entity, $message, array $context, array $runtime_context, AIChatThread $thread, ?callable $stream_callback = NULL) {
    $editable_blocks = $this->blockInspector->listEditableBlocks($entity, $runtime_context);
    $context['block_tools'] = [
      'available_blocks' => $editable_blocks,
      'inspected_blocks' => [],
      'usage' => [
        'list_page_blocks' => 'Use available_blocks to reason about editable block types, labels, sections, regions, and component UUIDs.',
        'get_block_contents' => 'Use inspected_blocks as the exact current content for selected candidate blocks.',
        'update_specific_block' => 'When editing, target one component_uuid and return full replacement field values. To add another item/delta, include existing items plus the new item in the updated field value.',
      ],
    ];

    if (!$editable_blocks) {
      return $context;
    }

    $component_uuids = [];
    foreach (($context['selected_existing_block_references'] ?? []) as $component) {
      if (!empty($component['uuid'])) {
        $component_uuids[] = (string) $component['uuid'];
      }
    }

    if (!$component_uuids) {
      try {
        $component_uuids = $this->planner->selectRelevantExistingBlocks($message, $editable_blocks, array_slice($thread->getMessages(), -8), $stream_callback);
      }
      catch (\Exception $exception) {
        $this->logger->warning('AI block inspection selector failed: @message', [
          '@message' => $exception->getMessage(),
        ]);
        $component_uuids = [];
      }
    }

    $context['block_tools']['inspected_blocks'] = $this->blockInspector->getBlockContents($entity, $component_uuids, $runtime_context);

    return $context;
  }

  /**
   * Determines whether the current token selection includes new components.
   */
  protected function hasSelectedNewBlockTokens(array $context) {
    foreach (($context['selected_block_references'] ?? []) as $reference) {
      if (($reference['selection_mode'] ?? 'new') !== 'edit') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Builds a default block title for the created block.
   */
  protected function buildBlockTitle(ContentEntityInterface $entity, $selected_type, $suffix = '') {
    $title = sprintf('%s for %s', ucwords(str_replace('_', ' ', $selected_type)), $entity->label());
    if ($suffix !== '') {
      $title .= ' - ' . $suffix;
    }

    return $title;
  }

  /**
   * Creates the persisted assistant summary.
   */
  protected function buildAssistantSuccessMessage(ContentEntityInterface $entity, array $instructions, array $placements) {
    $selected_type = $instructions['plan']['selected_block_type'] ?? 'block';
    $placement = $placements[0] ?? ['section_delta' => 0, 'region' => 'content'];

    return sprintf(
      'Created a %s block for "%s" and placed it into section %d, region %s. Refreshing the page will show the new block and this chat will remain here.',
      str_replace('_', ' ', $selected_type),
      $entity->label(),
      $placement['section_delta'],
      $placement['region']
    );
  }

  /**
   * Creates multiple coordinated blocks from a structured plan.
   */
  protected function executeStructuredBlockPlan(ContentEntityInterface $entity, AIChatThread $thread, $message, array $context, array $structured_plan, ?callable $status_callback = NULL, ?callable $stream_callback = NULL, array $runtime_context = [], array $uploaded_assets = []) {
    $blocks = [];
    $placements = [];
    $total = count($structured_plan['blocks']);

    foreach ($structured_plan['blocks'] as $index => $plan_item) {
      $component_label = (string) ($plan_item['label'] ?? ('Component ' . ($index + 1)));
      if ($status_callback) {
        $status_callback(sprintf('Generating component %d of %d: %s...', $index + 1, $total, $component_label));
      }

      $component_prompt = $this->buildStructuredPlanPrompt($message, $context, $thread, $plan_item, $index + 1, $total);
      $instructions = $this->instructionGenerator->generateFromStructuredPlanItem($component_prompt, $plan_item, [
        'uploaded_assets' => $uploaded_assets,
        'prefer_ai_images' => !empty($context['prefer_ai_images']),
      ], $stream_callback);
      $selected_type = $instructions['plan']['selected_block_type'] ?? ($plan_item['selected_block_type'] ?? 'block');
      $instructions['block_title'] = $this->buildBlockTitle($entity, $selected_type, $component_label);
      $instructions['reusable'] = FALSE;
      $created_blocks = $this->blockParser->createBlocksFromInstructions($instructions);

      foreach ($created_blocks as $block) {
        $blocks[] = $block;
        try {
          if ($status_callback) {
            $status_callback(sprintf('Placing component %d of %d: %s...', $index + 1, $total, $component_label));
          }
          $placements[] = $this->layoutPlacementManager->placeBlock($entity, $block, $runtime_context);
        }
        catch (\Exception $placement_exception) {
          if ($this->isMissingLayoutBuilderOverridesException($placement_exception)) {
            return $this->prepareLayoutBuilderPlacementAction($entity, $thread, $blocks, 'I created the planned page components, but I need Layout Builder initialized before I can place them on the page.');
          }
          throw $placement_exception;
        }
      }
    }

    $assistant_message = $this->buildStructuredBuildSuccessMessage($entity, $structured_plan, $placements);
    $thread->addMessage('assistant', $assistant_message, [
      'structured_plan' => $structured_plan,
      'placements' => $placements,
      'created_blocks' => $this->buildCreatedBlockMetadata($blocks, $placements),
    ]);
    $thread->save();

    return [
      'thread' => $thread,
      'status_message' => t('AI created and placed multiple blocks on this page from a structured plan. Refresh complete; the chat thread has been preserved below.'),
    ];
  }

  /**
   * Builds a component-specific prompt for structured plans.
   */
  protected function buildStructuredPlanPrompt($message, array $context, AIChatThread $thread, array $plan_item, $position, $total) {
    $recent_messages = array_slice($thread->getMessages(), -6);

    return "The user is asking for a structured page build on the current page.\n\n"
      . "Overall request:\n" . $message
      . (!empty($context['prefer_ai_images']) ? "\n\nThe user explicitly selected Generate AI Images. Strongly prefer newly generated artwork over existing media unless they explicitly pointed to a supplied file or direct image URL." : '')
      . "\n\nCurrent component JSON:\n" . json_encode([
        'position' => $position,
        'total' => $total,
        'label' => $plan_item['label'] ?? ('Component ' . $position),
        'goal' => $plan_item['goal'] ?? '',
        'placement_hint' => $plan_item['placement_hint'] ?? '',
        'selected_block_type' => $plan_item['selected_block_type'] ?? '',
      ], JSON_PRETTY_PRINT)
        . "\n\nPage context JSON:\n" . json_encode($context, JSON_PRETTY_PRINT)
      . "\n\nRecent chat JSON:\n" . json_encode($recent_messages, JSON_PRETTY_PRINT);
  }

  /**
   * Builds a summary for a structured multi-block build.
   */
  protected function buildStructuredBuildSuccessMessage(ContentEntityInterface $entity, array $structured_plan, array $placements) {
    $count = count($placements);
    $labels = [];
    foreach (array_slice($structured_plan['blocks'] ?? [], 0, 3) as $plan_item) {
      if (!empty($plan_item['label'])) {
        $labels[] = $plan_item['label'];
      }
    }

    return sprintf(
      'Created %d coordinated block%s for "%s"%s. Refreshing the page will show the new layout and this chat will remain here.',
      $count,
      $count === 1 ? '' : 's',
      $entity->label(),
      $labels ? ' including ' . implode(', ', $labels) : ''
    );
  }

  /**
   * Converts a placement failure into a guided Layout Builder resume action.
   */
  protected function prepareLayoutBuilderPlacementAction(ContentEntityInterface $entity, AIChatThread $thread, array $blocks, $summary) {
    $action_id = bin2hex(random_bytes(8));
    $layout_builder_url = $this->layoutContextCollector->getLayoutBuilderUrl($entity);
    $resume_in_layout_url = $this->buildLayoutBuilderResumeUrl($layout_builder_url, $thread, $action_id);
    $thread->addMessage('assistant', $summary, [
      'pending_action' => [
        'id' => $action_id,
        'status' => 'pending',
        'type' => 'place_existing_blocks',
        'summary' => $summary,
        'changes' => [
          'I already created the block content.',
          'Open Layout Builder to initialize the page layout context if needed.',
          'Then resume the build and I will attach the prepared blocks to the page.',
        ],
        'layout_builder_url' => $layout_builder_url,
        'resume_in_layout_url' => $resume_in_layout_url,
        'layout_builder_label' => 'Open Layout Builder',
        'approve_url' => Url::fromRoute('moody_ai_assistant.chat_thread_action', [
          'moody_ai_chat_thread' => $thread->id(),
          'action_id' => $action_id,
          'decision' => 'approve',
        ])->toString(),
        'approve_label' => 'Resume build',
        'reject_url' => Url::fromRoute('moody_ai_assistant.chat_thread_action', [
          'moody_ai_chat_thread' => $thread->id(),
          'action_id' => $action_id,
          'decision' => 'reject',
        ])->toString(),
        'reject_label' => 'No, dismiss',
        'blocks' => array_values(array_map(function ($block) {
          return [
            'block_id' => (int) $block->id(),
            'block_revision_id' => (int) $block->getRevisionId(),
            'block_type' => $block->bundle(),
            'block_label' => $block->label(),
          ];
        }, $blocks)),
      ],
    ]);
    $thread->save();

    return [
      'thread' => $thread,
      'redirect_url' => $resume_in_layout_url,
      'status_message' => t('Open Layout Builder to finish placing the prepared blocks on this page.'),
    ];
  }

  /**
   * Prepares a deferred AI request that should continue in Layout Builder.
   */
  protected function prepareLayoutBuilderExecutionAction(ContentEntityInterface $entity, AIChatThread $thread, $message, array $uploaded_assets = [], array $runtime_context = []) {
    $action_id = bin2hex(random_bytes(8));
    $layout_builder_url = $this->layoutContextCollector->getLayoutBuilderUrl($entity);
    $resume_in_layout_url = $this->buildLayoutBuilderResumeUrl($layout_builder_url, $thread, $action_id);
    $summary = 'I need Layout Builder open so I can place and edit blocks directly on the page. I am switching there now and will continue automatically.';
    $selected_refs = $this->buildSelectedBlockReferenceMetadata($runtime_context);
    $selected_ref_ids = array_values(array_unique(array_filter(array_map(function (array $reference) {
      return !empty($reference['uuid']) ? (string) $reference['uuid'] : '';
    }, $selected_refs))));

    $thread->addMessage('assistant', $summary, [
      'pending_action' => [
        'id' => $action_id,
        'status' => 'pending',
        'type' => 'resume_request_in_layout_builder',
        'title' => 'Continuing in Layout Builder',
        'summary' => $summary,
        'changes' => [
          'I will open Layout Builder automatically.',
          'Once it loads, I will resume this request there and continue placing or editing blocks.',
        ],
        'layout_builder_url' => $layout_builder_url,
        'resume_in_layout_url' => $resume_in_layout_url,
        'layout_builder_label' => 'Open Layout Builder',
        'request' => [
          'message' => (string) $message,
          'uploaded_assets' => array_values($uploaded_assets),
          'prefer_ai_images' => !empty($runtime_context['prefer_ai_images']),
          'provider' => (string) ($runtime_context['provider'] ?? 'openai'),
          'model' => (string) ($runtime_context['model'] ?? ''),
          'selected_block_references' => $selected_refs,
          'selected_block_reference_ids' => $selected_ref_ids,
        ],
      ],
    ]);
    $thread->save();

    return [
      'thread' => $thread,
      'redirect_url' => $resume_in_layout_url,
      'status_message' => t('Opening Layout Builder so the assistant can continue this request automatically.'),
    ];
  }

  /**
   * Builds a Layout Builder URL that resumes a pending placement in context.
   */
  protected function buildLayoutBuilderResumeUrl($layout_builder_url, AIChatThread $thread, $action_id) {
    $separator = strpos($layout_builder_url, '?') === FALSE ? '?' : '&';
    return $layout_builder_url . $separator . http_build_query([
      'moody_ai_assistant_resume_thread' => (int) $thread->id(),
      'moody_ai_assistant_resume_action' => (string) $action_id,
      'moody_ai_assistant_resume_token' => $this->csrfToken->get('moody_ai_assistant.resume:' . $thread->id() . ':' . $action_id),
    ]);
  }

  /**
   * Determines whether a request should continue in Layout Builder.
   */
  protected function shouldResumeInLayoutBuilder(ContentEntityInterface $entity, array $top_level_plan, array $runtime_context = []) {
    if ($this->layoutContextCollector->isLayoutBuilderContext($entity, $runtime_context)) {
      return FALSE;
    }

    if (!$entity->hasField('layout_builder__layout')) {
      return FALSE;
    }

    return !in_array(($top_level_plan['action'] ?? 'block'), ['redirect', 'create_page', 'guide'], TRUE);
  }

  /**
   * Builds the most useful post-action destination for the assistant UI.
   */
  protected function buildAssistantCompletionUrl(ContentEntityInterface $entity, array $runtime_context = []) {
    if ($this->layoutContextCollector->isLayoutBuilderContext($entity, $runtime_context)) {
      return $this->layoutContextCollector->getLayoutBuilderUrl($entity);
    }

    return $entity->toUrl()->toString();
  }

  /**
   * Places previously created blocks after Layout Builder is available.
   */
  protected function handleDeferredPlacementApproval(AIChatThread $thread, $action_id, array $action, ContentEntityInterface $entity) {
    $placements = [];
    $blocks = [];
    $storage = $this->entityTypeManager->getStorage('block_content');

    foreach ($action['blocks'] ?? [] as $block_info) {
      $block = NULL;
      if (!empty($block_info['block_revision_id'])) {
        $block = $storage->loadRevision($block_info['block_revision_id']);
      }
      if (!$block && !empty($block_info['block_id'])) {
        $block = $storage->load($block_info['block_id']);
      }
      if (!$block) {
        throw new \Exception('One of the prepared blocks could not be loaded for placement.');
      }

      $blocks[] = $block;
      $placements[] = $this->layoutPlacementManager->placeBlock($entity, $block);
    }

    $thread->resolvePendingAction($action_id, 'approved', [
      'resolved_at' => time(),
      'placements' => $placements,
    ]);
    $thread->addMessage('assistant', sprintf('Placed %d prepared block%s onto "%s" after Layout Builder was initialized.', count($placements), count($placements) === 1 ? '' : 's', $entity->label()), [
      'action_resolution' => [
        'id' => $action_id,
        'decision' => 'approve',
        'placements' => $placements,
      ],
      'created_blocks' => $this->buildCreatedBlockMetadata($blocks, $placements),
    ]);
    $thread->save();

    return $entity;
  }

  /**
   * Prepares an edit preview instead of executing immediately.
   */
  protected function prepareEditPreview(ContentEntityInterface $entity, AIChatThread $thread, $message, array $context, array $action_plan, array $runtime_context = [], array $uploaded_assets = []) {
    if (!$this->layoutContextCollector->isLayoutBuilderContext($entity, $runtime_context)) {
      $layout_builder_url = $this->layoutContextCollector->getLayoutBuilderUrl($entity);
      $thread->addMessage('assistant', 'I can preview and apply edits to existing blocks only from the Layout Builder page for this content, so I can use the current editing context and tempstore state.', [
        'layout_builder_required' => [
          'url' => $layout_builder_url,
          'label' => 'Open Layout Builder',
        ],
      ]);
      $thread->save();

      return [
        'thread' => $thread,
        'status_message' => t('Open this page in Layout Builder to preview or approve edits to existing blocks.'),
      ];
    }

    $target_component = $this->findContextComponentByUuid($context, (string) ($action_plan['target_component_uuid'] ?? ''));
    if (!$target_component || empty($target_component['block_type']) || empty($target_component['block_revision_id'])) {
      $instructions = $this->instructionGenerator->generate($this->buildPrompt($message, $context, $thread), [
        'uploaded_assets' => $uploaded_assets,
        'prefer_ai_images' => !empty($context['prefer_ai_images']),
      ]);
      $selected_type = $instructions['plan']['selected_block_type'] ?? 'block';
      $instructions['block_title'] = $this->buildBlockTitle($entity, $selected_type);
      $instructions['reusable'] = FALSE;
      $blocks = $this->blockParser->createBlocksFromInstructions($instructions);
      try {
        $placements = [];
        foreach ($blocks as $block) {
          $placements[] = $this->layoutPlacementManager->placeBlock($entity, $block, $runtime_context);
        }
      }
      catch (\Exception $placement_exception) {
        if ($this->isMissingLayoutBuilderOverridesException($placement_exception)) {
          return $this->prepareLayoutBuilderPlacementAction($entity, $thread, $blocks, 'I created the content for this request, but I need Layout Builder initialized before I can place it on the page.');
        }
        throw $placement_exception;
      }
      $assistant_message = $this->buildAssistantSuccessMessage($entity, $instructions, $placements);
      $thread->addMessage('assistant', $assistant_message, [
        'plan' => $instructions['plan'] ?? [],
        'placements' => $placements,
      ]);
      $thread->save();

      return [
        'thread' => $thread,
        'status_message' => t('AI created and placed a new block on this page. Refresh complete; the chat thread has been preserved below.'),
      ];
    }

    $block = $this->entityTypeManager->getStorage('block_content')->loadRevision($target_component['block_revision_id']);
    if (!$block) {
      throw new \Exception('The target block revision could not be loaded for preview.');
    }

    $existing_instruction = $this->blockParser->exportBlockToInstruction($block);
    $instructions = $this->instructionGenerator->generateForExistingBlock($message, $target_component['block_type'], $existing_instruction, [
      'uploaded_assets' => $uploaded_assets,
      'prefer_ai_images' => !empty($context['prefer_ai_images']),
      'block_tools' => $context['block_tools'] ?? [],
    ]);
    $change_lines = $this->buildInstructionChangePreview($block, $existing_instruction, $instructions);
    $action_id = bin2hex(random_bytes(8));

    $thread->addMessage('assistant', (string) ($action_plan['preview_summary'] ?? 'I prepared a preview for an edit to an existing block on this page.'), [
      'pending_action' => [
        'id' => $action_id,
        'status' => 'pending',
        'type' => 'edit_existing_block',
        'title' => (string) ($action_plan['preview_title'] ?? 'Preview existing block edit'),
        'summary' => (string) ($action_plan['preview_summary'] ?? ''),
        'changes' => $change_lines ?: (!empty($action_plan['changes']) && is_array($action_plan['changes']) ? array_values($action_plan['changes']) : []),
        'layout_builder_url' => $this->layoutContextCollector->getLayoutBuilderUrl($entity),
        'approve_url' => Url::fromRoute('moody_ai_assistant.chat_thread_action', [
          'moody_ai_chat_thread' => $thread->id(),
          'action_id' => $action_id,
          'decision' => 'approve',
        ])->toString(),
        'reject_url' => Url::fromRoute('moody_ai_assistant.chat_thread_action', [
          'moody_ai_chat_thread' => $thread->id(),
          'action_id' => $action_id,
          'decision' => 'reject',
        ])->toString(),
        'target_component' => [
          'component_uuid' => $target_component['uuid'],
          'block_revision_id' => $target_component['block_revision_id'],
          'block_id' => $target_component['block_id'] ?? NULL,
          'block_type' => $target_component['block_type'],
          'block_label' => $target_component['block_label'] ?? $target_component['label'] ?? '',
          'section' => $target_component['section'],
          'region' => $target_component['region'],
        ],
        'instructions' => $instructions,
      ],
    ]);
    $thread->save();

    return [
      'thread' => $thread,
      'status_message' => t('AI prepared an edit preview. Review it below and approve or dismiss it in the conversation.'),
    ];
  }

  /**
   * Prepares an edit preview with streamed instruction generation callbacks.
   */
  protected function prepareEditPreviewStream(ContentEntityInterface $entity, AIChatThread $thread, $message, array $context, array $action_plan, callable $stream_callback, array $runtime_context = [], array $uploaded_assets = []) {
    if (!$this->layoutContextCollector->isLayoutBuilderContext($entity, $runtime_context)) {
      return $this->prepareEditPreview($entity, $thread, $message, $context, $action_plan, $runtime_context, $uploaded_assets);
    }

    $target_component = $this->findContextComponentByUuid($context, (string) ($action_plan['target_component_uuid'] ?? ''));
    if (!$target_component || empty($target_component['block_type']) || empty($target_component['block_revision_id'])) {
      $instructions = $this->instructionGenerator->generate($this->buildPrompt($message, $context, $thread), [
        'uploaded_assets' => $uploaded_assets,
        'prefer_ai_images' => !empty($context['prefer_ai_images']),
      ], $stream_callback);
      $selected_type = $instructions['plan']['selected_block_type'] ?? 'block';
      $instructions['block_title'] = $this->buildBlockTitle($entity, $selected_type);
      $instructions['reusable'] = FALSE;
      $blocks = $this->blockParser->createBlocksFromInstructions($instructions);
      try {
        $placements = [];
        foreach ($blocks as $block) {
          $placements[] = $this->layoutPlacementManager->placeBlock($entity, $block, $runtime_context);
        }
      }
      catch (\Exception $placement_exception) {
        if ($this->isMissingLayoutBuilderOverridesException($placement_exception)) {
          return $this->prepareLayoutBuilderPlacementAction($entity, $thread, $blocks, 'I created the content for this request, but I need Layout Builder initialized before I can place it on the page.');
        }
        throw $placement_exception;
      }
      $assistant_message = $this->buildAssistantSuccessMessage($entity, $instructions, $placements);
      $thread->addMessage('assistant', $assistant_message, [
        'plan' => $instructions['plan'] ?? [],
        'placements' => $placements,
      ]);
      $thread->save();

      return [
        'thread' => $thread,
        'status_message' => t('AI created and placed a new block on this page. Refresh complete; the chat thread has been preserved below.'),
      ];
    }

    $block = $this->entityTypeManager->getStorage('block_content')->loadRevision($target_component['block_revision_id']);
    if (!$block) {
      throw new \Exception('The target block revision could not be loaded for preview.');
    }

    $existing_instruction = $this->blockParser->exportBlockToInstruction($block);
    $instructions = $this->instructionGenerator->generateForExistingBlock($message, $target_component['block_type'], $existing_instruction, [
      'uploaded_assets' => $uploaded_assets,
      'prefer_ai_images' => !empty($context['prefer_ai_images']),
      'block_tools' => $context['block_tools'] ?? [],
    ], $stream_callback);
    $change_lines = $this->buildInstructionChangePreview($block, $existing_instruction, $instructions);
    $action_id = bin2hex(random_bytes(8));

    $thread->addMessage('assistant', (string) ($action_plan['preview_summary'] ?? 'I prepared a preview for an edit to an existing block on this page.'), [
      'pending_action' => [
        'id' => $action_id,
        'status' => 'pending',
        'type' => 'edit_existing_block',
        'title' => (string) ($action_plan['preview_title'] ?? 'Preview existing block edit'),
        'summary' => (string) ($action_plan['preview_summary'] ?? ''),
        'changes' => $change_lines ?: (!empty($action_plan['changes']) && is_array($action_plan['changes']) ? array_values($action_plan['changes']) : []),
        'layout_builder_url' => $this->layoutContextCollector->getLayoutBuilderUrl($entity),
        'approve_url' => Url::fromRoute('moody_ai_assistant.chat_thread_action', [
          'moody_ai_chat_thread' => $thread->id(),
          'action_id' => $action_id,
          'decision' => 'approve',
        ])->toString(),
        'reject_url' => Url::fromRoute('moody_ai_assistant.chat_thread_action', [
          'moody_ai_chat_thread' => $thread->id(),
          'action_id' => $action_id,
          'decision' => 'reject',
        ])->toString(),
        'target_component' => [
          'component_uuid' => $target_component['uuid'],
          'block_revision_id' => $target_component['block_revision_id'],
          'block_id' => $target_component['block_id'] ?? NULL,
          'block_type' => $target_component['block_type'],
          'block_label' => $target_component['block_label'] ?? $target_component['label'] ?? '',
          'section' => $target_component['section'],
          'region' => $target_component['region'],
        ],
        'instructions' => $instructions,
      ],
    ]);
    $thread->save();

    return [
      'thread' => $thread,
      'status_message' => t('AI prepared an edit preview. Review it below and approve or dismiss it in the conversation.'),
    ];
  }

  /**
   * Prepares a redirect preview for approval.
   */
  protected function prepareRedirectPreview(AIChatThread $thread, array $top_level_plan, AccountInterface $account) {
    if (!$account->hasPermission('administer redirects')) {
      $thread->addMessage('assistant', 'I can preview redirect creation, but your account does not have permission to administer redirects on this site.');
      $thread->save();

      return [
        'thread' => $thread,
        'status_message' => t('Your account does not have permission to create redirects.'),
      ];
    }

    $redirect = $top_level_plan['redirect'] ?? [];
    $source = $this->normalizeRedirectSource((string) ($redirect['source'] ?? ''));
    $destination = $this->normalizeRedirectDestination((string) ($redirect['destination'] ?? ''));
    $status_code = (int) ($redirect['status_code'] ?? 301);
    $status_code = in_array($status_code, [301, 302, 307, 308], TRUE) ? $status_code : 301;

    if ($source === '' || $destination === '') {
      throw new \Exception('I need both a source path and a destination to preview a redirect.');
    }

    $action_id = bin2hex(random_bytes(8));
    $summary = (string) ($redirect['summary'] ?? '');
    if ($summary === '') {
      $summary = sprintf('I prepared a %d redirect from %s to %s.', $status_code, $source, $destination);
    }

    $thread->addMessage('assistant', $summary, [
      'pending_action' => [
        'id' => $action_id,
        'status' => 'pending',
        'type' => 'create_redirect',
        'title' => 'Preview redirect',
        'summary' => $summary,
        'changes' => [
          sprintf('I will create a %d redirect from %s to %s.', $status_code, $this->quotePreviewValue($source), $this->quotePreviewValue($destination)),
        ],
        'approve_label' => 'Yes, create redirect',
        'reject_label' => 'No, dismiss',
        'approve_url' => Url::fromRoute('moody_ai_assistant.chat_thread_action', [
          'moody_ai_chat_thread' => $thread->id(),
          'action_id' => $action_id,
          'decision' => 'approve',
        ])->toString(),
        'reject_url' => Url::fromRoute('moody_ai_assistant.chat_thread_action', [
          'moody_ai_chat_thread' => $thread->id(),
          'action_id' => $action_id,
          'decision' => 'reject',
        ])->toString(),
        'redirect' => [
          'source' => $source,
          'destination' => $destination,
          'status_code' => $status_code,
        ],
      ],
    ]);
    $thread->save();

    return [
      'thread' => $thread,
      'status_message' => t('AI prepared a redirect preview. Review it below and approve or dismiss it in the conversation.'),
    ];
  }

  /**
   * Prepares page creation guidance with bundle choices.
   */
  protected function preparePageCreationGuide(AIChatThread $thread, array $top_level_plan, array $page_options, array $structured_plan = []) {
    if (!$page_options) {
      $thread->addMessage('assistant', 'I can guide page creation, but you do not appear to have access to any page creation forms on this site.');
      $thread->save();

      return [
        'thread' => $thread,
        'status_message' => t('No available page creation options were found for your account.'),
      ];
    }

    $page_plan = $top_level_plan['page'] ?? [];
    $suggested_bundles = array_flip($page_plan['suggested_bundles'] ?? []);
    $options = [];

    foreach ($page_options as $option) {
      if ($suggested_bundles && !isset($suggested_bundles[$option['bundle']])) {
        continue;
      }
      $options[] = $option;
    }

    if (!$options) {
      $options = array_slice($page_options, 0, 3);
    }

    $summary = trim((string) ($page_plan['summary'] ?? ''));
    if ($summary === '' && !empty($structured_plan['page_blueprint']['summary'])) {
      $summary = trim((string) $structured_plan['page_blueprint']['summary']);
    }
    if ($summary === '') {
      $summary = 'Choose a page type below and I will send you to the standard Drupal page creation form for that content type.';
    }

    $thread->addMessage('assistant', $summary, [
      'page_creation_guide' => [
        'title' => 'Create a new page',
        'summary' => $summary,
        'options' => $options,
        'sections' => $structured_plan['page_blueprint']['sections'] ?? [],
      ],
    ]);
    $thread->save();

    return [
      'thread' => $thread,
      'status_message' => t('AI suggested page creation options below. Choose a page type to continue.'),
    ];
  }

  /**
   * Guides the user to an accessible Drupal administration page.
   */
  protected function prepareSiteFunctionGuide(AIChatThread $thread, array $top_level_plan, AccountInterface $account) {
    $guides = [
      'menus' => ['Manage menus', [['entity.menu.collection', 'Open menus']]],
      'redirects' => ['Manage URL redirects', [['redirect.list', 'View redirects'], ['redirect.add', 'Add redirect']]],
      'content' => ['Manage content', [['system.admin_content', 'Open content']]],
      'media' => ['Manage media', [['entity.media.collection', 'Open media']]],
      'users' => ['Manage users', [['entity.user.collection', 'Open users']]],
      'taxonomy' => ['Manage taxonomy', [['entity.taxonomy_vocabulary.collection', 'Open taxonomy']]],
      'configuration' => ['Site configuration', [['system.admin_config', 'Open configuration']]],
    ];
    $guide_plan = $top_level_plan['guide'] ?? [];
    $topic = isset($guides[$guide_plan['topic'] ?? '']) ? $guide_plan['topic'] : 'configuration';
    [$title, $route_options] = $guides[$topic];
    $options = [];

    foreach ($route_options as [$route_name, $label]) {
      try {
        $url = Url::fromRoute($route_name);
        if ($url->access($account)) {
          $options[] = ['label' => $label, 'url' => $url->toString()];
        }
      }
      catch (\Exception $exception) {
      }
    }

    $summary = trim((string) ($guide_plan['summary'] ?? ''));
    if (!$options) {
      $summary = 'Your account does not appear to have access to this administration area.';
    }
    elseif ($summary === '') {
      $summary = 'Use the standard Drupal administration page below to continue.';
    }

    $thread->addMessage('assistant', $summary, [
      'site_function_guide' => [
        'title' => $title,
        'summary' => $summary,
        'options' => $options,
      ],
    ]);
    $thread->save();

    return [
      'thread' => $thread,
      'status_message' => $options
        ? t('AI found the relevant site-management page below.')
        : t('No accessible site-management page was found for your account.'),
    ];
  }

  /**
   * Finds a context component by UUID.
   */
  protected function findContextComponentByUuid(array $context, $component_uuid) {
    foreach ($context['existing_components'] ?? [] as $component) {
      if (($component['uuid'] ?? '') === $component_uuid) {
        return $component;
      }
    }

    return NULL;
  }

  /**
   * Loads the target entity for a conversation thread.
   */
  protected function loadThreadTargetEntity(AIChatThread $thread) {
    $entity_type = (string) $thread->get('target_entity_type')->value;
    $entity_id = (int) $thread->get('target_entity_id')->value;
    if ($entity_type === '' || !$entity_id) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage($entity_type);
    return $storage ? $storage->load($entity_id) : NULL;
  }

  /**
   * Builds human-readable before/after change lines for a proposed edit.
   *
   * @param \Drupal\block_content\BlockContentInterface $block
   *   The current block revision.
   * @param array $existing_instruction
   *   Serialized current block state.
   * @param array $instructions
   *   Proposed updated instructions.
   *
   * @return array
   *   Change description strings.
   */
  protected function buildInstructionChangePreview($block, array $existing_instruction, array $instructions) {
    $changes = [];
    $current_fields = $existing_instruction['field_info'] ?? [];
    $new_fields = $instructions['instructions'][0]['field_info'] ?? [];

    foreach ($new_fields as $field_name => $field_data) {
      $old_value = $this->stringifyFieldPreviewValue($current_fields[$field_name] ?? []);
      $new_value = $this->stringifyFieldPreviewValue($field_data);
      $old_detail = $this->stringifyFieldPreviewDetailValue($current_fields[$field_name] ?? []);
      $new_detail = $this->stringifyFieldPreviewDetailValue($field_data);

      if ($old_value === $new_value) {
        continue;
      }

      $field_label = $field_name;
      if ($block->hasField($field_name)) {
        $field_label = (string) $block->getFieldDefinition($field_name)->getLabel();
      }

      $changes[] = [
        'field_name' => $field_name,
        'field_label' => $field_label,
        'summary' => sprintf('%s: %s -> %s', $field_label, $old_value, $new_value),
        'before' => $old_detail,
        'after' => $new_detail,
      ];
    }

    return $changes;
  }

  /**
   * Builds metadata for newly created blocks so follow-up prompts can target them.
   *
   * @param array $blocks
   *   Created block entities.
   * @param array $placements
   *   Placement metadata aligned with the blocks.
   *
   * @return array
   *   Serialized block metadata.
   */
  protected function buildCreatedBlockMetadata(array $blocks, array $placements) {
    $created_blocks = [];

    foreach ($blocks as $delta => $block) {
      $placement = $placements[$delta] ?? [];
      $created_blocks[] = [
        'block_id' => (int) $block->id(),
        'block_revision_id' => (int) $block->getRevisionId(),
        'block_type' => $block->bundle(),
        'block_label' => $block->label(),
        'component_uuid' => $placement['component_uuid'] ?? '',
        'section' => $placement['section_delta'] ?? 0,
        'region' => $placement['region'] ?? 'content',
      ];
    }

    return $created_blocks;
  }

  /**
   * Infers a likely follow-up target from recent thread activity.
   *
   * @param \Drupal\moody_ai_assistant\Entity\AIChatThread $thread
   *   The current thread.
   * @param array $context
   *   The current page context.
   * @param string $message
   *   The user message.
   *
   * @return array|null
   *   The matched component context, or NULL.
   */
  protected function inferFollowUpTarget(AIChatThread $thread, array $context, $message) {
    if (!$this->isLikelyFollowUpEditMessage($message)) {
      return NULL;
    }

    foreach (array_reverse($thread->getMessages()) as $thread_message) {
      if (($thread_message['role'] ?? '') !== 'assistant') {
        continue;
      }

      $metadata = $thread_message['metadata'] ?? [];
      foreach ($metadata['created_blocks'] ?? [] as $created_block) {
        $matched = $this->findContextComponentForRecentBlock($context, $created_block);
        if ($matched) {
          return $matched;
        }
      }

      $target_component = $metadata['action_resolution']['target_component'] ?? NULL;
      if (is_array($target_component)) {
        $matched = $this->findContextComponentForRecentBlock($context, $target_component);
        if ($matched) {
          return $matched;
        }
      }
    }

    return NULL;
  }

  /**
   * Matches stored recent block metadata to the current page context.
   *
   * @param array $context
   *   The current page context.
   * @param array $recent_block
   *   Recent block metadata from the thread.
   *
   * @return array|null
   *   The matched component context, or NULL.
   */
  protected function findContextComponentForRecentBlock(array $context, array $recent_block) {
    foreach ($context['existing_components'] ?? [] as $component) {
      if (!empty($recent_block['component_uuid']) && ($component['uuid'] ?? '') === $recent_block['component_uuid']) {
        return $component;
      }
    }

    foreach ($context['existing_components'] ?? [] as $component) {
      if (
        !empty($recent_block['block_type'])
        && !empty($component['block_type'])
        && $component['block_type'] === $recent_block['block_type']
        && !empty($recent_block['block_label'])
        && !empty($component['block_label'])
        && $component['block_label'] === $recent_block['block_label']
      ) {
        return $component;
      }
    }

    return NULL;
  }

  /**
   * Detects follow-up prompts that likely mean "edit the prior block".
   *
   * @param string $message
   *   The user message.
   *
   * @return bool
   *   TRUE when the message likely refers to the earlier block.
   */
  protected function isLikelyFollowUpEditMessage($message) {
    $normalized = mb_strtolower(trim((string) $message));
    if ($normalized === '') {
      return FALSE;
    }

    $patterns = [
      '/\bthat same\b/',
      '/\bsame (showcase|block|one)\b/',
      '/\banother (instance|item|entry|slide|card|showcase)\b/',
      '/\badd (another|one more|more)\b/',
      '/\bto that\b/',
      '/\bto the same\b/',
      '/\bupdate that\b/',
      '/\badd .* to (that|the same)\b/',
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $normalized)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Builds persisted metadata for a user-authored message.
   *
   * @param array $runtime_context
   *   Request runtime context.
   * @param array $uploaded_assets
   *   Prepared uploaded asset metadata.
   *
   * @return array
   *   Thread metadata.
   */
  protected function buildUserMessageMetadata(array $runtime_context, array $uploaded_assets = []) {
    $metadata = [];
    if ($uploaded_assets) {
      $metadata['uploaded_assets'] = $uploaded_assets;
    }

    if (!empty($runtime_context['prefer_ai_images'])) {
      $metadata['prefer_ai_images'] = TRUE;
    }

    $metadata['provider'] = (string) ($runtime_context['provider'] ?? 'openai');
    $metadata['model'] = (string) ($runtime_context['model'] ?? '');

    $selected_refs = $this->buildSelectedBlockReferenceMetadata($runtime_context);
    if ($selected_refs) {
      $metadata['selected_block_references'] = $selected_refs;
    }

    return $metadata;
  }

  /**
   * Normalizes selected block reference metadata for thread storage.
   */
  protected function buildSelectedBlockReferenceMetadata(array $runtime_context) {
    $selected_refs = $runtime_context['selected_block_references'] ?? [];
    if (!is_array($selected_refs)) {
      return [];
    }

    $normalized = [];
    foreach ($selected_refs as $reference) {
      if (!is_array($reference)) {
        continue;
      }

      $reference_id = trim((string) ($reference['reference_id'] ?? $reference['plugin_id'] ?? $reference['uuid'] ?? ''));
      if ($reference_id === '') {
        continue;
      }

      $normalized[] = array_filter([
        'reference_id' => $reference_id,
        'uuid' => (string) ($reference['uuid'] ?? ''),
        'label' => (string) ($reference['label'] ?? $reference['block_label'] ?? 'Selected block'),
        'type_label' => (string) ($reference['type_label'] ?? $reference['block_type'] ?? ''),
        'plugin_id' => (string) ($reference['plugin_id'] ?? ''),
        'block_type' => (string) ($reference['block_type'] ?? ''),
        'selection_mode' => (string) ($reference['selection_mode'] ?? 'new'),
        'group_label' => (string) ($reference['group_label'] ?? ''),
        'section' => isset($reference['section']) ? (int) $reference['section'] : NULL,
        'region' => (string) ($reference['region'] ?? ''),
        'existing_count' => isset($reference['existing_count']) ? (int) $reference['existing_count'] : NULL,
        'can_edit' => !empty($reference['can_edit']) || (!empty($reference['block_revision_id']) && !empty($reference['block_type'])),
      ], function ($value) {
        return $value !== NULL && $value !== '';
      });
    }

    return $normalized;
  }

  /**
   * Returns an explicit selected block edit target when one was chosen.
   */
  protected function getExplicitSelectedEditTarget(array $context) {
    $selected = $context['selected_existing_block_references'] ?? [];
    if (count($selected) !== 1) {
      return NULL;
    }

    $candidate = $selected[0];
    if (empty($candidate['block_revision_id']) || empty($candidate['block_type']) || empty($candidate['uuid'])) {
      return NULL;
    }

    return $candidate;
  }

  /**
   * Normalizes field preview values for before/after summaries.
   *
   * @param array $field_data
   *   A serialized field payload.
   *
   * @return string
   *   A compact preview string.
   */
  protected function stringifyFieldPreviewValue(array $field_data) {
    return $this->stringifyFieldPreviewDisplayValue($field_data, TRUE);
  }

  /**
   * Builds a full preview value for expanded before/after details.
   *
   * @param array $field_data
   *   A serialized field payload.
   *
   * @return string
   *   A readable full preview string.
   */
  protected function stringifyFieldPreviewDetailValue(array $field_data) {
    return $this->stringifyFieldPreviewDisplayValue($field_data, FALSE);
  }

  /**
   * Normalizes field preview values for compact or expanded summaries.
   *
   * @param array $field_data
   *   A serialized field payload.
   * @param bool $truncate
   *   Whether to shorten long values.
   *
   * @return string
   *   A readable preview string.
   */
  protected function stringifyFieldPreviewDisplayValue(array $field_data, $truncate = TRUE) {
    if (array_key_exists('target_id', $field_data) && !empty($field_data['target_id'])) {
      return $this->quotePreviewValue('existing media #' . $field_data['target_id'], $truncate);
    }

    $value = $field_data['value'] ?? NULL;
    if ($value === NULL || $value === '') {
      if (!empty($field_data['image_prompt'])) {
        return $this->quotePreviewValue($field_data['image_prompt'], $truncate);
      }
      if (!empty($field_data['alt'])) {
        return $this->quotePreviewValue($field_data['alt'], $truncate);
      }
      return $this->quotePreviewValue('empty', $truncate);
    }

    if (is_array($value)) {
      if (isset($value['value']) && is_scalar($value['value'])) {
        return $this->quotePreviewValue((string) $value['value'], $truncate);
      }

      return $this->quotePreviewValue(json_encode($value, JSON_UNESCAPED_SLASHES), $truncate);
    }

    return $this->quotePreviewValue((string) $value, $truncate);
  }

  /**
   * Quotes and truncates preview values for readable thread summaries.
   *
   * @param string $value
   *   The raw preview value.
   *
   * @return string
   *   The formatted preview value.
   */
  protected function quotePreviewValue($value, $truncate = TRUE) {
    $value = trim(strip_tags($value));
    $value = preg_replace('/\s+/', ' ', $value);
    if ($value === '') {
      $value = 'empty';
    }
    if ($truncate && mb_strlen($value) > 80) {
      $value = mb_substr($value, 0, 77) . '...';
    }

    return '"' . $value . '"';
  }

  /**
   * Creates an approved redirect and records the result in the thread.
   */
  protected function handleRedirectApproval(AIChatThread $thread, $action_id, array $action, ContentEntityInterface $entity, AccountInterface $account) {
    if (!$this->entityTypeManager->hasDefinition('redirect')) {
      throw new \Exception('The Redirect module is not available on this site.');
    }
    if (!$account->hasPermission('administer redirects')) {
      throw new \Exception('You do not have permission to create redirects.');
    }

    $redirect_data = $action['redirect'] ?? [];
    $source = $this->normalizeRedirectSource((string) ($redirect_data['source'] ?? ''));
    $destination = $this->normalizeRedirectDestination((string) ($redirect_data['destination'] ?? ''));
    $status_code = (int) ($redirect_data['status_code'] ?? 301);
    $status_code = in_array($status_code, [301, 302, 307, 308], TRUE) ? $status_code : 301;

    if ($source === '' || $destination === '') {
      throw new \Exception('The redirect preview is missing a valid source or destination.');
    }

    $storage = $this->entityTypeManager->getStorage('redirect');
    $redirect = $storage->create([]);
    if (!$redirect->access('create', $account)) {
      throw new \Exception('You do not have permission to create redirects.');
    }
    $redirect->setSource($source);
    $redirect->setRedirect($destination);
    $redirect->setStatusCode($status_code);
    $redirect->setLanguage($this->languageManager->getDefaultLanguage()->getId());
    $redirect->save();

    $thread->resolvePendingAction($action_id, 'approved', [
      'resolved_at' => time(),
      'redirect_id' => (int) $redirect->id(),
    ]);
    $thread->addMessage('assistant', sprintf('Created the approved %d redirect from %s to %s.', $status_code, $this->quotePreviewValue($source), $this->quotePreviewValue($destination)), [
      'action_resolution' => [
        'id' => $action_id,
        'decision' => 'approve',
        'redirect_id' => (int) $redirect->id(),
        'redirect' => [
          'source' => $source,
          'destination' => $destination,
          'status_code' => $status_code,
        ],
        'result_link' => [
          'url' => $redirect->toUrl('edit-form')->toString(),
          'label' => 'View redirect',
        ],
      ],
    ]);
    $thread->save();

    return $entity;
  }

  /**
   * Returns page creation options available to the current account.
   */
  protected function getAvailablePageCreationOptions(AccountInterface $account) {
    if (!$this->entityTypeManager->hasDefinition('node_type')) {
      return [];
    }

    $options = [];
    $types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    foreach ($types as $type) {
      $bundle = $type->id();
      if (!$account->hasPermission('create ' . $bundle . ' content')) {
        continue;
      }

      $options[] = [
        'bundle' => $bundle,
        'label' => $type->label(),
        'url' => Url::fromUri('internal:/node/add/' . $bundle)->toString(),
      ];
    }

    usort($options, function (array $a, array $b) {
      return strnatcasecmp($a['label'], $b['label']);
    });

    return $options;
  }

  /**
   * Normalizes a redirect source path to Drupal's expected relative format.
   */
  protected function normalizeRedirectSource($source) {
    $source = trim($source);
    if ($source === '') {
      return '';
    }

    $parts = parse_url($source);
    if ($parts === FALSE) {
      return '';
    }

    $path = $parts['path'] ?? $source;
    $path = '/' . ltrim($path, '/');
    return $path === '/' ? '/' : rtrim($path, '/');
  }

  /**
   * Normalizes a redirect destination to an internal path or absolute URL.
   */
  protected function normalizeRedirectDestination($destination) {
    $destination = trim($destination);
    if ($destination === '') {
      return '';
    }

    if (preg_match('@^https?://@i', $destination)) {
      return $destination;
    }

    $parts = parse_url($destination);
    if ($parts === FALSE) {
      return '';
    }

    $path = $parts['path'] ?? $destination;
    $normalized = '/' . ltrim($path, '/');
    if (!empty($parts['query'])) {
      $normalized .= '?' . $parts['query'];
    }

    return $normalized;
  }

  /**
   * Detects the known missing-overrides failure from Layout Builder placement.
   */
  protected function isMissingLayoutBuilderOverridesException(\Exception $exception) {
    return strpos($exception->getMessage(), 'Could not load Layout Builder overrides storage for this page.') !== FALSE;
  }

  /**
   * Builds an accessible URL string for an entity when possible.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The target entity.
   *
   * @return string
   *   The resolved relative URL, or an empty string when unavailable.
   */
  protected function buildEntityUrl(ContentEntityInterface $entity) {
    try {
      return $entity->toUrl()->toString();
    }
    catch (\Exception $exception) {
      return Url::fromRoute('<front>')->toString();
    }
  }

}
