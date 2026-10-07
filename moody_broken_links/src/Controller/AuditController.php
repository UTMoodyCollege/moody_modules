<?php

declare(strict_types=1);

namespace Drupal\moody_broken_links\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;

/** Permission-gated access only; audit files are not public file entities. */
final class AuditController extends ControllerBase {

  public function listing(): array {
    $rows = [];
    foreach (\Drupal::service('moody_broken_links.bulk_removal')->audits() as $id => $plan) {
      [$date, $token] = explode('/', $id);
      $url = Url::fromRoute('moody_broken_links.audit_download', ['date' => $date, 'token' => $token]);
      $rows[] = [
        \Drupal::service('date.formatter')->format($plan['created'], 'short'),
        ['data' => ['#plain_text' => $plan['actor_name'] . ' (#' . $plan['actor_uid'] . ')']],
        implode(', ', $plan['codes']), count($plan['pages']),
        ['data' => Link::fromTextAndUrl($this->t('Download preview and outcomes'), $url)->toRenderable()],
      ];
    }
    return ['#type' => 'table', '#header' => [$this->t('Created'), $this->t('Actor'), $this->t('HTTP codes'), $this->t('Pages'), $this->t('Private report')], '#rows' => $rows, '#empty' => $this->t('No bulk-removal audits yet.'), '#cache' => ['max-age' => 0]];
  }

  public function download(string $date, string $token): JsonResponse {
    $report = \Drupal::service('moody_broken_links.bulk_removal')->report($date . '/' . $token);
    $response = new JsonResponse($report);
    $response->headers->set('Content-Disposition', 'attachment; filename="broken-links-' . $date . '-' . $token . '.json"');
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

}
