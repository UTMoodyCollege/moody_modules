<?php

declare(strict_types=1);

namespace Drupal\moody_vimeo\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\moody_vimeo\VimeoApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provisions Vimeo browser-upload sessions for the admin UI.
 */
class VimeoUploadController extends ControllerBase {

  /**
   * Constructs a VimeoUploadController.
   */
  public function __construct(
    protected readonly VimeoApiService $vimeoApi,
    protected readonly CsrfTokenGenerator $csrfToken,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('moody_vimeo.api'),
      $container->get('csrf_token'),
    );
  }

  /**
   * Creates a Vimeo tus session for browser-based uploads.
   */
  public function createUploadSession(Request $request): JsonResponse {
    $token = $request->headers->get('X-Moody-Vimeo-Token', '');
    if (!$this->csrfToken->validate($token, 'moody_vimeo.upload_session')) {
      return new JsonResponse(['message' => (string) $this->t('Invalid upload token.')], 403);
    }

    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse(['message' => (string) $this->t('Invalid upload payload.')], 400);
    }

    $name = trim((string) ($payload['name'] ?? ''));
    $description = trim((string) ($payload['description'] ?? ''));
    $privacy = (string) ($payload['privacy'] ?? 'nobody');
    $file_size = (int) ($payload['fileSize'] ?? 0);

    if ($name === '' || $file_size <= 0) {
      return new JsonResponse(['message' => (string) $this->t('Video title and file are required.')], 400);
    }

    $session = $this->vimeoApi->createUploadSession($file_size, $name, $description, $privacy);
    if ($session === NULL || empty($session['upload_link']) || empty($session['uri'])) {
      return new JsonResponse(['message' => (string) $this->t('Could not create a Vimeo upload session.')], 502);
    }

    $video_id = $this->vimeoApi->parseVideoId($session['uri']);

    return new JsonResponse([
      'uploadLink' => $session['upload_link'],
      'videoUri' => $session['uri'],
      'videoId' => $video_id,
      'detailUrl' => $video_id ? Url::fromRoute('moody_vimeo.video_detail', ['video_id' => $video_id])->toString() : '',
      'listUrl' => Url::fromRoute('moody_vimeo.video_list')->toString(),
    ]);
  }

}