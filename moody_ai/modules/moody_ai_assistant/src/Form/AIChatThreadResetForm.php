<?php

namespace Drupal\moody_ai_assistant\Form;

use Drupal\moody_ai_assistant\Entity\AIChatThread;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AIChatThreadResetForm extends ConfirmFormBase {

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
   * The thread being reset.
   *
   * @var \Drupal\moody_ai_assistant\Entity\AIChatThread|null
   */
  protected $thread;

  /**
   * Constructs the form.
   */
  public function __construct(AccountProxyInterface $current_user, EntityTypeManagerInterface $entity_type_manager) {
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'moody_ai_chat_thread_reset_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?AIChatThread $moody_ai_chat_thread = NULL) {
    if (!$moody_ai_chat_thread) {
      throw new AccessDeniedHttpException();
    }

    if ((int) $moody_ai_chat_thread->get('user_id')->target_id !== (int) $this->currentUser->id() && !$this->currentUser->hasPermission('administer moody ai chat threads')) {
      throw new AccessDeniedHttpException();
    }

    $this->thread = $moody_ai_chat_thread;

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Reset the conversation for %label?', [
      '%label' => $this->getTargetEntityLabel(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This removes the saved messages for this page conversation. You can start a new conversation immediately after reset.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Reset conversation');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->getTargetEntityUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $label = $this->getTargetEntityLabel();
    $redirect_url = $this->getTargetEntityUrl();

    if ($this->thread) {
      $this->thread->delete();
    }

    $this->messenger()->addStatus($this->t('The conversation for %label has been reset.', [
      '%label' => $label,
    ]));

    $form_state->setRedirectUrl($redirect_url);
  }

  /**
   * Gets the thread target entity when available.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The target entity.
   */
  protected function getTargetEntity() {
    if (!$this->thread) {
      return NULL;
    }

    $entity_type = (string) $this->thread->get('target_entity_type')->value;
    $entity_id = (int) $this->thread->get('target_entity_id')->value;
    if ($entity_type === '' || !$entity_id) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage($entity_type);
    if (!$storage) {
      return NULL;
    }

    $entity = $storage->load($entity_id);
    return $entity instanceof ContentEntityInterface ? $entity : NULL;
  }

  /**
   * Gets the target entity label for confirmation copy.
   *
   * @return string
   *   The target entity label.
   */
  protected function getTargetEntityLabel() {
    $entity = $this->getTargetEntity();
    return $entity ? $entity->label() : $this->t('this page');
  }

  /**
   * Gets the target entity URL for redirects.
   *
   * @return \Drupal\Core\Url
   *   The redirect URL.
   */
  protected function getTargetEntityUrl() {
    $entity = $this->getTargetEntity();
    return $entity ? $entity->toUrl() : Url::fromRoute('<front>');
  }

}
