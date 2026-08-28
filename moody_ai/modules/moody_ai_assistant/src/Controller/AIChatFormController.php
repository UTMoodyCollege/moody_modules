<?php

namespace Drupal\moody_ai_assistant\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\moody_ai_assistant\Form\AIChatBlockForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Rebuilds the embedded assistant form for Drupal AJAX callbacks.
 */
final class AIChatFormController implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FormBuilderInterface $formBuilder,
    private readonly AccountProxyInterface $account,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('form_builder'),
      $container->get('current_user'),
    );
  }

  /**
   * Returns the contextual form on its stable AJAX action route.
   */
  public function form(Request $request, string $entity_type, string $entity_id): array {
    if (!$this->entityTypeManager->hasDefinition($entity_type)) {
      throw new NotFoundHttpException();
    }

    $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
    if (!$entity instanceof ContentEntityInterface) {
      throw new NotFoundHttpException();
    }
    if (!$entity->access('update', $this->account)) {
      throw new AccessDeniedHttpException();
    }

    // This form is embedded into Layout Builder after the page route has
    // already built its primary form. Rebuild it from the submitted values on
    // this dedicated route instead of looking for a page-scoped cached build.
    $input = $request->request->all();
    unset($input['form_build_id']);
    $form_state = new FormState();
    $form_state->setUserInput($input);
    $form_state->addBuildInfo('args', [$entity]);

    return $this->formBuilder->buildForm(AIChatBlockForm::class, $form_state);
  }

}
