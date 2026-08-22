<?php

namespace Drupal\moody_ai_assistant\Controller;

use Drupal\moody_ai_assistant\Entity\AIChatThread;
use Drupal\moody_ai_assistant\Service\AIChatManager;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

class AIChatThreadActionController extends ControllerBase {

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
   * Constructs the controller.
   */
  public function __construct(AIChatManager $chat_manager, AccountProxyInterface $current_user) {
    $this->chatManager = $chat_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('moody_ai_assistant.chat_manager'),
      $container->get('current_user')
    );
  }

  /**
   * Applies an approve/reject decision for a pending preview.
   */
  public function handle(AIChatThread $moody_ai_chat_thread, $action_id, $decision) {
    $pending = $moody_ai_chat_thread->getPendingAction($action_id);
    $redirect_url = $pending['pending_action']['layout_builder_url'] ?? NULL;

    try {
      $entity = $this->chatManager->handlePendingActionDecision($moody_ai_chat_thread, $this->currentUser, $action_id, $decision);
      if ($entity) {
        $entity_url = $entity->toUrl()->toString();
        $destination = is_string($redirect_url) && str_starts_with($redirect_url, '/') && !str_starts_with($redirect_url, '//')
          ? $redirect_url
          : $entity_url;
        return $this->redirectUrl($destination);
      }
    }
    catch (\Exception $exception) {
      $this->messenger()->addError($exception->getMessage());
    }

    return $this->redirect('<front>');
  }

  /**
   * Redirect helper.
   */
  protected function redirectUrl($url) {
    return new RedirectResponse($url);
  }

}
