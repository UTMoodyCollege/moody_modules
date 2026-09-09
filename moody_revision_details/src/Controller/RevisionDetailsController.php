<?php

namespace Drupal\moody_revision_details\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\moody_revision_details\RevisionSummary;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads one comparison only when requested; never modifies a revision.
 */
final class RevisionDetailsController extends ControllerBase {

  public function show(NodeInterface $node, NodeInterface $node_revision, Request $request): array|AjaxResponse {
    if ($node->id() != $node_revision->id() || !$node_revision->hasTranslation($node->language()->getId())) {
      throw new NotFoundHttpException();
    }
    $revision = $node_revision->getTranslation($node->language()->getId());
    if (!$node->access('view all revisions') || !$revision->access('view revision')) {
      throw new AccessDeniedHttpException();
    }
    $summary = new RevisionSummary($this->entityTypeManager(), $this->currentUser());
    $id = "moody-revision-details-{$node->id()}-{$revision->getRevisionId()}";
    $details = [
      '#type' => 'details',
      '#title' => $this->t('Saved changes · revision @revision', ['@revision' => $revision->getRevisionId()]),
      '#open' => TRUE,
      '#attributes' => ['id' => $id],
      '#cache' => ['max-age' => 0],
    ] + $summary->build($revision);

    if ($request->query->get('_wrapper_format') === 'drupal_ajax') {
      $response = new AjaxResponse();
      $response->addCommand(new ReplaceCommand('#' . $id, $details));
      $response->addCommand(new InvokeCommand('#' . $id . ' > summary', 'focus'));
      $response->headers->set('Cache-Control', 'private, no-store');
      return $response;
    }
    // The same link remains useful without JavaScript.
    return [
      '#cache' => ['max-age' => 0],
      'details' => $details,
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Back to revisions'),
        '#url' => Url::fromRoute('entity.node.version_history', ['node' => $node->id()], ['language' => $node->language()]),
      ],
    ];
  }

}
