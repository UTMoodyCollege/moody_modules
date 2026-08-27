<?php

declare(strict_types=1);

namespace Drupal\Tests\moody_ai_seo\Unit;

use Drupal\moody_ai_seo\EventSubscriber\AgentResponseSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests agent-facing response negotiation.
 *
 * @group moody_ai_seo
 */
final class AgentResponseSubscriberTest extends TestCase {

  /**
   * Tests that Markdown recovery retains the real 404 status.
   */
  public function testMarkdown404Recovery(): void {
    $request = Request::create('https://example.edu/missing');
    $request->headers->set('Accept', 'text/markdown, text/html;q=0.8');
    $response = new Response('<html>Missing</html>', 404, ['Content-Type' => 'text/html; charset=UTF-8']);
    $event = new ResponseEvent(
      $this->createMock(HttpKernelInterface::class),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
      $response,
    );

    (new AgentResponseSubscriber())->onResponse($event);

    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('text/markdown; charset=utf-8', $response->headers->get('Content-Type'));
    $this->assertContains('Accept', $response->getVary());
    $this->assertStringContainsString('https://example.edu/llms.txt', (string) $response->getContent());
  }

  /**
   * Tests recovery after Drupal has formatted a Markdown exception as text.
   */
  public function testFormattedMarkdown404Recovery(): void {
    $request = Request::create('https://example.edu/missing');
    $request->headers->set('Accept', 'text/markdown');
    $response = new Response('No route found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
    $event = new ResponseEvent(
      $this->createMock(HttpKernelInterface::class),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
      $response,
    );

    (new AgentResponseSubscriber())->onResponse($event);

    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('text/markdown; charset=utf-8', $response->headers->get('Content-Type'));
    $this->assertContains('Accept', $response->getVary());
    $this->assertStringContainsString('[Sitemap](https://example.edu/sitemap.xml)', (string) $response->getContent());
  }

  /**
   * Tests that browser HTML remains unchanged while discovery is added.
   */
  public function testHtmlIsPreserved(): void {
    $request = Request::create('https://example.edu/missing');
    $request->headers->set('Accept', 'text/html,application/xhtml+xml');
    $response = new Response('<html>Missing</html>', 404, ['Content-Type' => 'text/html; charset=UTF-8']);
    $event = new ResponseEvent(
      $this->createMock(HttpKernelInterface::class),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
      $response,
    );

    (new AgentResponseSubscriber())->onResponse($event);

    $this->assertSame('<html>Missing</html>', $response->getContent());
    $this->assertStringContainsString('rel="describedby"', (string) $response->headers->get('Link'));
    $this->assertContains('Accept', $response->getVary());
  }

  /**
   * Tests that a JSON API 404 is not converted or decorated.
   */
  public function testJson404IsPreserved(): void {
    $request = Request::create('https://example.edu/jsonapi/missing');
    $request->headers->set('Accept', 'text/markdown, application/vnd.api+json;q=0.8');
    $response = new Response('{"errors":[]}', 404, ['Content-Type' => 'application/vnd.api+json']);
    $event = new ResponseEvent(
      $this->createMock(HttpKernelInterface::class),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
      $response,
    );

    (new AgentResponseSubscriber())->onResponse($event);

    $this->assertSame('{"errors":[]}', $response->getContent());
    $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
    $this->assertNull($response->headers->get('Link'));
    $this->assertNotContains('Accept', $response->getVary());
  }

}
