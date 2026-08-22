<?php

namespace Drupal\moody_ai_assistant\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\moody_ai_assistant\Service\AIChatManager;
use Drupal\moody_ai_assistant\Service\LayoutContextCollector;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a contextual AI chat assistant block.
 *
 * @Block(
 *   id = "moody_ai_assistant_block",
 *   admin_label = @Translation("Moody AI Assistant"),
 *   category = @Translation("Moody AI")
 * )
 */
class AIChatAssistantBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

  private const DEFAULT_WIDGET_TITLE = 'Moody AI Assistant';

  private const DEFAULT_WIDGET_COLOR = '#bf5700';

  private const DEFAULT_WIDGET_HOVER_COLOR = '#8f3d00';

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The layout context collector.
   *
   * @var \Drupal\moody_ai_assistant\Service\LayoutContextCollector
   */
  protected $layoutContextCollector;

  /**
   * The chat manager.
   *
   * @var \Drupal\moody_ai_assistant\Service\AIChatManager
   */
  protected $chatManager;

  /**
   * The CSRF token generator.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected $csrfToken;

  /**
   * Constructs a chat assistant block.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, FormBuilderInterface $form_builder, ConfigFactoryInterface $config_factory, LayoutContextCollector $layout_context_collector, AIChatManager $chat_manager, CsrfTokenGenerator $csrf_token) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->formBuilder = $form_builder;
    $this->configFactory = $config_factory;
    $this->layoutContextCollector = $layout_context_collector;
    $this->chatManager = $chat_manager;
    $this->csrfToken = $csrf_token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('form_builder'),
      $container->get('config.factory'),
      $container->get('moody_ai_assistant.layout_context_collector'),
      $container->get('moody_ai_assistant.chat_manager'),
      $container->get('csrf_token')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $entity = $this->layoutContextCollector->getRouteEntity();
    if (!$entity) {
      return [];
    }

    // The assistant is injected automatically, but sites may also have a
    // placed instance from the original block-only pilot. Render one instance
    // per request so form and Media Library element IDs stay unique.
    $request = \Drupal::request();
    if ($request->attributes->get('_moody_ai_assistant_built')) {
      return [];
    }
    $request->attributes->set('_moody_ai_assistant_built', TRUE);

    $thread = $this->chatManager->getThread($entity, \Drupal::currentUser(), FALSE);
    $is_layout_builder_context = $this->layoutContextCollector->isLayoutBuilderContext($entity);
    $config = $this->configFactory->get('moody_ai_assistant.settings');
    $widget_title_text = trim((string) $config->get('widget_title_text')) ?: self::DEFAULT_WIDGET_TITLE;
    $widget_bg_color = (string) $config->get('widget_bg_color');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $widget_bg_color)) {
      $widget_bg_color = self::DEFAULT_WIDGET_COLOR;
    }
    $widget_hover_color = (string) $config->get('widget_hover_color');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $widget_hover_color)) {
      $widget_hover_color = self::DEFAULT_WIDGET_HOVER_COLOR;
    }

    if ($thread && $is_layout_builder_context) {
      $resume_thread_id = (int) $request->query->get('moody_ai_assistant_resume_thread');
      $resume_action_id = (string) $request->query->get('moody_ai_assistant_resume_action');
      $resume_token = (string) $request->query->get('moody_ai_assistant_resume_token');
      if (
        $resume_thread_id
        && $resume_action_id !== ''
        && (int) $thread->id() === $resume_thread_id
        && $this->csrfToken->validate($resume_token, 'moody_ai_assistant.resume:' . $thread->id() . ':' . $resume_action_id)
        && ($pending = $thread->getPendingAction($resume_action_id))
        && (($pending['pending_action']['type'] ?? '') === 'place_existing_blocks')
      ) {
        try {
          $this->chatManager->handlePendingActionDecision($thread, \Drupal::currentUser(), $resume_action_id, 'approve');
          $thread = $this->chatManager->getThread($entity, \Drupal::currentUser(), FALSE);
        }
        catch (\Exception $exception) {
          \Drupal::messenger()->addError($exception->getMessage());
        }
      }
    }

    return [
      '#theme' => 'moody_ai_assistant',
      '#thread_id' => $thread ? $thread->id() : NULL,
      '#messages' => $thread ? $thread->getMessages() : [],
      '#conversation_threads' => $this->chatManager->getRecentThreadSummaries(\Drupal::currentUser(), 12, $entity),
      '#entity_label' => $entity->label(),
      '#entity_type' => $entity->getEntityTypeId(),
      '#entity_id' => $entity->id(),
      '#is_layout_builder_context' => $is_layout_builder_context,
      '#widget_title_text' => $widget_title_text,
      '#widget_bg_color' => $widget_bg_color,
      '#widget_hover_color' => $widget_hover_color,
      '#form' => $this->formBuilder->getForm('Drupal\\moody_ai_assistant\\Form\\AIChatBlockForm', $entity),
      '#attached' => [
        'library' => ['moody_ai_assistant/assistant'],
        'drupalSettings' => [
          'moodyAiAssistant' => [
            'streamUrl' => Url::fromRoute('moody_ai_assistant.chat_stream')->toString(),
            'csrfToken' => \Drupal::service('csrf_token')->get('moody_ai_assistant.chat_stream'),
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    $entity = $this->layoutContextCollector->getRouteEntity();
    if (!$account->isAuthenticated() || !$entity) {
      return AccessResult::forbidden();
    }

    if (!$entity->hasField('layout_builder__layout')) {
      return AccessResult::forbidden();
    }

    return AccessResult::allowedIfHasPermission($account, 'use moody ai assistant')
      ->andIf($entity->access('update', $account, TRUE));
  }

}
