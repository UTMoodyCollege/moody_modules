<?php

declare(strict_types=1);

namespace Drupal\moody_ai_assistant\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Removes an unused private upload owned by the current user.
 */
final class PrivateUploadActionController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $storageManager,
    private readonly AccountProxyInterface $account,
    private readonly FileUsageInterface $fileUsageService,
    private readonly CsrfTokenGenerator $tokenGenerator,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('file.usage'),
      $container->get('csrf_token'),
    );
  }

  /**
   * Removes one unused upload after ownership, path, and CSRF validation.
   */
  public function remove(int $file, Request $request): JsonResponse {
    $token = (string) $request->headers->get('X-CSRF-Token');
    if (!$this->tokenGenerator->validate($token, 'moody_ai_assistant.private_upload_remove')) {
      return $this->response(['message' => $this->t('Your session could not be verified. Refresh the page and try again.')], 403);
    }

    $uid = (int) $this->account->id();
    $upload = $this->storageManager->getStorage('file')->load($file);
    if (
      $uid < 1
      || !$upload instanceof FileInterface
      || (int) $upload->getOwnerId() !== $uid
      || !preg_match('#^private://' . $uid . '/\d{4}-\d{2}-\d{2}/moody-ai-ckeditor-uploads/[^/]+$#D', $upload->getFileUri())
    ) {
      return $this->response(['message' => $this->t('This private upload is unavailable.')], 404);
    }

    if ($this->fileUsageService->listUsage($upload) !== []) {
      return $this->response(['message' => $this->t('This upload is in use. Open upload management to remove its Media or content references first.')], 409);
    }

    $file_id = (int) $upload->id();
    $upload->delete();
    return $this->response(['removed' => $file_id]);
  }

  /**
   * Returns a private JSON response that browsers must not cache.
   */
  private function response(array $data, int $status = 200): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

}
