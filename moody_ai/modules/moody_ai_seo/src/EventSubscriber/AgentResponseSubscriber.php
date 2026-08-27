<?php

declare(strict_types=1);

namespace Drupal\moody_ai_seo\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds agent discovery and a concise negotiated 404 representation.
 */
final class AgentResponseSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', -10]];
  }

  /**
   * Adds discovery headers and preserves a useful 404 status for agents.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $response = $event->getResponse();
    $content_type = (string) $response->headers->get('Content-Type');
    $is_html = str_starts_with($content_type, 'text/html');
    $is_negotiated_markdown_404 = $response->getStatusCode() === Response::HTTP_NOT_FOUND
      && $this->prefersMarkdown($request)
      && (str_starts_with($content_type, 'text/plain') || str_starts_with($content_type, 'text/markdown'));
    if (!$is_html && !$is_negotiated_markdown_404) {
      return;
    }

    $base_url = $request->getSchemeAndHttpHost();
    $described_by = sprintf('<%s/llms.txt>; rel="describedby"; type="text/markdown"', $base_url);
    if (!str_contains((string) $response->headers->get('Link'), 'rel="describedby"')) {
      $response->headers->set('Link', $described_by, FALSE);
    }

    if ($response->getStatusCode() !== Response::HTTP_NOT_FOUND) {
      return;
    }

    $response->setVary('Accept', FALSE);
    if (!$this->prefersMarkdown($request)) {
      return;
    }

    $body = <<<MARKDOWN
# Page not found

The requested page does not exist. Use one of these official resources to continue:

- [Home]($base_url/)
- [Sitemap]($base_url/sitemap.xml)
- [Agent guidance]($base_url/llms.txt)
MARKDOWN;
    $response->setContent($request->isMethod('HEAD') ? '' : $body . "\n");
    $response->headers->set('Content-Type', 'text/markdown; charset=utf-8');
  }

  /**
   * Returns true only when the client explicitly prefers Markdown.
   */
  private function prefersMarkdown(Request $request): bool {
    foreach ($request->getAcceptableContentTypes() as $type) {
      if ($type === 'text/markdown') {
        return TRUE;
      }
      if ($type === 'text/html' || $type === 'application/xhtml+xml' || $type === '*/*') {
        return FALSE;
      }
    }
    return FALSE;
  }

}
