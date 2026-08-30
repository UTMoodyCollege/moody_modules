<?php

declare(strict_types=1);

namespace Drupal\moody_media_remediation\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\moody_media_remediation\MediaRemediationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirms a guarded operation undo.
 */
final class UndoOperationForm extends ConfirmFormBase {

  private array $operation;

  public function __construct(
    private readonly MediaRemediationManager $manager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('moody_media_remediation.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'moody_media_remediation_undo_operation';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $operation_id = NULL): array {
    $operation = $this->manager->getOperation((int) $operation_id);
    if (!$operation || $operation['status'] !== 'applied') {
      throw new NotFoundHttpException('This operation is not available to undo.');
    }
    $this->operation = $operation;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Undo operation @operation?', [
      '@operation' => $this->operation['operation_id'],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Undo restores only the affected fields. It stops without changing anything if one of those fields has changed since remediation.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Undo reference changes');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('moody_media_remediation.dashboard');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $result = $this->manager->undoOperation((int) $this->operation['operation_id']);
      $this->messenger()->addStatus($this->t('Operation @operation was undone across @fields fields on @entities entities.', [
        '@operation' => $this->operation['operation_id'],
        '@fields' => $result['changed_fields'],
        '@entities' => $result['changed_entities'],
      ]));
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($exception->getMessage());
    }
    $form_state->setRedirect('moody_media_remediation.dashboard');
  }

}
