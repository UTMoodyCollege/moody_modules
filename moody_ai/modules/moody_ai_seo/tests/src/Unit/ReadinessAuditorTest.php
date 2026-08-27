<?php

declare(strict_types=1);

namespace Drupal\Tests\moody_ai_seo\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\moody_ai_seo\ReadinessAuditor;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests the public readiness audit.
 *
 * @group moody_ai_seo
 */
final class ReadinessAuditorTest extends TestCase {

  /**
   * Tests a complete set of public agent signals.
   */
  public function testReadySite(): void {
    $llms = <<<'MARKDOWN'
# Example College

## When to use this site

Use this site when finding official college information.

## Developer resources

- [API documentation](https://example.edu/developers/api)
MARKDOWN;
    $result = $this->auditor($llms)->audit('https://example.edu');

    $this->assertTrue($result['ready']);
    foreach ($result['checks'] as $check) {
      $this->assertTrue($check['pass']);
    }
  }

  /**
   * Tests that unrelated HTTPS links do not satisfy developer discoverability.
   */
  public function testDeveloperResourcesRequireDedicatedSection(): void {
    $llms = <<<'MARKDOWN'
# Example College

## When to use this site

Use this site when finding official college information.

## Key resources

- [Home](https://example.edu/)
MARKDOWN;
    $result = $this->auditor($llms)->audit('https://example.edu');

    $this->assertFalse($result['ready']);
    $this->assertFalse($result['checks']['developer_resources']['pass']);
    $this->assertTrue($result['checks']['llms_txt']['pass']);
  }

  /**
   * Tests that the audit cannot be pointed at an insecure URL.
   */
  public function testRejectsNonHttpsUrl(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->auditor('')->audit('http://example.edu');
  }

  /**
   * Tests that credentials cannot be included in audit output or requests.
   */
  public function testRejectsCredentialedUrl(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->auditor('')->audit('https://user:secret@example.edu');
  }

  /**
   * Tests that unexpectedly large public responses are bounded.
   */
  public function testRejectsOversizedResponse(): void {
    $client = new Client(['handler' => HandlerStack::create(new MockHandler([
      new Response(200, ['Content-Type' => 'text/html'], str_repeat('x', 5_000_001)),
    ]))]);
    $factory = $this->createMock(ConfigFactoryInterface::class);

    $this->expectException(\RuntimeException::class);
    (new ReadinessAuditor($client, $factory))->audit('https://example.edu');
  }

  /**
   * Builds an auditor with deterministic public responses.
   */
  private function auditor(string $llms): ReadinessAuditor {
    $meaningful = str_repeat('Meaningful official college information. ', 20);
    $home = <<<HTML
<!doctype html><html><head><script type="application/ld+json">{"@context":"https://schema.org","@type":"CollegeOrUniversity","name":"Example College","contactPoint":{"@type":"ContactPoint","contactType":"general inquiries","email":"webmaster@example.edu"},"address":{"@type":"PostalAddress","streetAddress":"300 Test Street","addressLocality":"Austin","addressRegion":"TX","postalCode":"78712","addressCountry":"US"}}</script></head><body><main><h1>Example College</h1><p>$meaningful</p><h2>Programs</h2><p>$meaningful</p></main></body></html>
HTML;
    $trust = "<!doctype html><html><body><main><h1>Trust page</h1><p>$meaningful$meaningful</p></main></body></html>";
    $responses = [
      new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $home),
      new Response(200, ['Content-Type' => 'text/markdown; charset=utf-8', 'Vary' => 'Accept, Accept-Encoding'], '# Example College'),
      new Response(200, ['Content-Type' => 'text/markdown; charset=utf-8'], $llms),
      new Response(200, ['Content-Type' => 'application/xml'], '<?xml version="1.0"?><urlset/>'),
      new Response(404, ['Content-Type' => 'text/html; charset=utf-8'], '<h1>Page not found</h1>'),
      new Response(404, ['Content-Type' => 'text/markdown; charset=utf-8', 'Vary' => 'Accept'], '# Page not found\n\n/llms.txt'),
      new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $trust),
      new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $trust),
      new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $trust),
    ];
    $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('trust')->willReturn([
      'about' => '/about',
      'contact' => '/contact',
      'privacy' => '/privacy',
    ]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('moody_ai_seo.settings')->willReturn($config);

    return new ReadinessAuditor($client, $factory);
  }

}
