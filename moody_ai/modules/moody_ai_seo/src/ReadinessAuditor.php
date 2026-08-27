<?php

declare(strict_types=1);

namespace Drupal\moody_ai_seo;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * Audits public agent-readiness signals without returning response bodies.
 */
final class ReadinessAuditor {

  private const MAX_RESPONSE_BYTES = 5_000_000;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Audits one public base URL and returns non-sensitive diagnostics.
   */
  public function audit(string $base_url): array {
    $base_url = rtrim($base_url, '/');
    $parts = parse_url($base_url);
    if (!filter_var($base_url, FILTER_VALIDATE_URL)
      || !is_array($parts)
      || ($parts['scheme'] ?? '') !== 'https'
      || empty($parts['host'])
      || isset($parts['user'])
      || isset($parts['pass'])
      || isset($parts['query'])
      || isset($parts['fragment'])
      || !in_array($parts['path'] ?? '', ['', '/'], TRUE)) {
      throw new \InvalidArgumentException('The audit base URL must be an absolute HTTPS URL.');
    }

    $home = $this->request($base_url . '/');
    $home_markdown = $this->request($base_url . '/', ['Accept' => 'text/markdown']);
    $llms = $this->request($base_url . '/llms.txt');
    $sitemap = $this->request($base_url . '/sitemap.xml');
    $missing_url = $base_url . '/moody-ai-seo-readiness-path-that-does-not-exist';
    $missing = $this->request($missing_url);
    $missing_markdown = $this->request($missing_url, ['Accept' => 'text/markdown']);
    $semantics = $this->inspectHtml((string) $home->getBody());

    $checks = [
      'homepage_http' => $this->check($home->getStatusCode() === 200, ['status' => $home->getStatusCode()]),
      'server_rendered_content' => $this->check(
        $semantics['h1_count'] === 1 && $semantics['main_characters'] >= 500,
        $semantics,
      ),
      'heading_structure' => $this->check(
        $semantics['h1_count'] === 1 && $semantics['h2_count'] > 0 && !$semantics['heading_jump'],
        [
          'h1_count' => $semantics['h1_count'],
          'h2_count' => $semantics['h2_count'],
          'heading_levels' => $semantics['heading_levels'],
          'heading_jump' => $semantics['heading_jump'],
        ],
      ),
      'organization_json_ld' => $this->check($semantics['organization_json_ld'], []),
      'organization_schema_completeness' => $this->check($semantics['organization_schema_complete'], []),
      'llms_txt' => $this->check(
        $llms->getStatusCode() === 200
        && $this->hasContentType($llms, 'text/markdown')
        && preg_match('/^#\s+\S/m', (string) $llms->getBody()) === 1
        && stripos((string) $llms->getBody(), 'Use this site when') !== FALSE,
        $this->responseFacts($llms),
      ),
      'developer_resources' => $this->check(
        $this->hasDeveloperResources((string) $llms->getBody()),
        ['guidance' => 'Add a Developer resources section only when the site publishes a real public API, specification, integration, or developer guide.'],
      ),
      'sitemap' => $this->check(
        $sitemap->getStatusCode() === 200
        && (str_contains(strtolower($sitemap->getHeaderLine('Content-Type')), 'xml') || str_starts_with(ltrim((string) $sitemap->getBody()), '<?xml')),
        $this->responseFacts($sitemap),
      ),
      'markdown_negotiation' => $this->check(
        $home_markdown->getStatusCode() === 200
        && $this->hasContentType($home_markdown, 'text/markdown')
        && $this->variesByAccept($home_markdown),
        $this->responseFacts($home_markdown),
      ),
      'html_404' => $this->check($missing->getStatusCode() === 404, $this->responseFacts($missing)),
      'markdown_404' => $this->check(
        $missing_markdown->getStatusCode() === 404
        && $this->hasContentType($missing_markdown, 'text/markdown')
        && $this->variesByAccept($missing_markdown)
        && str_contains((string) $missing_markdown->getBody(), '/llms.txt'),
        $this->responseFacts($missing_markdown),
      ),
    ];

    $trust = $this->configFactory->get('moody_ai_seo.settings')->get('trust') ?? [];
    foreach (['about', 'contact', 'privacy'] as $name) {
      $url = $this->absoluteUrl($base_url, (string) ($trust[$name] ?? "/$name"));
      $response = $this->request($url);
      $facts = $this->inspectHtml((string) $response->getBody());
      $checks["trust_$name"] = $this->check(
        $response->getStatusCode() === 200 && $facts['document_characters'] >= 500,
        [
          'status' => $response->getStatusCode(),
          'characters' => $facts['document_characters'],
        ],
      );
    }

    return [
      'base_url' => $base_url,
      'ready' => !in_array(FALSE, array_column($checks, 'pass'), TRUE),
      'checks' => $checks,
    ];
  }

  /**
   * Makes a bounded public GET request.
   */
  private function request(string $url, array $headers = []): ResponseInterface {
    $response = $this->httpClient->request('GET', $url, [
      'headers' => $headers,
      'http_errors' => FALSE,
      'allow_redirects' => ['max' => 5, 'track_redirects' => TRUE],
      'connect_timeout' => 10,
      'timeout' => 20,
      'stream' => TRUE,
    ]);
    $body = Utils::copyToString($response->getBody(), self::MAX_RESPONSE_BYTES + 1);
    if (strlen($body) > self::MAX_RESPONSE_BYTES) {
      throw new \RuntimeException('An audit response exceeded the 5 MB safety limit.');
    }
    return $response->withBody(Utils::streamFor($body));
  }

  /**
   * Extracts only semantic counts and structured-data presence from HTML.
   */
  private function inspectHtml(string $html): array {
    if (trim($html) === '') {
      return [
        'h1_count' => 0,
        'h2_count' => 0,
        'heading_levels' => [],
        'heading_jump' => FALSE,
        'main_characters' => 0,
        'document_characters' => 0,
        'organization_json_ld' => FALSE,
        'organization_schema_complete' => FALSE,
      ];
    }

    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $document->loadHTML($html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new \DOMXPath($document);

    foreach ($xpath->query('//script[not(@type="application/ld+json")] | //style | //svg | //noscript') ?: [] as $node) {
      $node->parentNode?->removeChild($node);
    }
    $main = $xpath->query('//main')->item(0);
    $main_text = $main ? $main->textContent : '';
    $levels = [];
    foreach ($xpath->query('//main//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]') ?: [] as $heading) {
      $levels[] = (int) substr($heading->nodeName, 1);
    }
    $heading_jump = FALSE;
    for ($index = 1; $index < count($levels); $index++) {
      if ($levels[$index] > $levels[$index - 1] + 1) {
        $heading_jump = TRUE;
        break;
      }
    }

    $organization = NULL;
    foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
      $decoded = json_decode($script->textContent, TRUE);
      if (is_array($decoded) && ($organization = $this->findOrganization($decoded)) !== NULL) {
        break;
      }
    }
    foreach ($xpath->query('//script') ?: [] as $node) {
      $node->parentNode?->removeChild($node);
    }

    return [
      'h1_count' => $xpath->query('//main//h1')->length,
      'h2_count' => $xpath->query('//main//h2')->length,
      'heading_levels' => array_slice($levels, 0, 100),
      'heading_jump' => $heading_jump,
      'main_characters' => mb_strlen(trim(preg_replace('/\s+/u', ' ', $main_text) ?? '')),
      'document_characters' => mb_strlen(trim(preg_replace('/\s+/u', ' ', $document->textContent) ?? '')),
      'organization_json_ld' => $organization !== NULL,
      'organization_schema_complete' => $organization !== NULL && $this->hasCompleteOrganization($organization),
    ];
  }

  /**
   * Finds an organization or educational organization in decoded JSON-LD.
   */
  private function findOrganization(array $value): ?array {
    $types = array_map(
      static fn (mixed $type): string => is_scalar($type) ? (string) $type : '',
      isset($value['@type']) ? (array) $value['@type'] : [],
    );
    if (array_intersect($types, ['Organization', 'EducationalOrganization', 'CollegeOrUniversity'])) {
      return $value;
    }
    foreach ($value as $child) {
      if (is_array($child) && ($organization = $this->findOrganization($child)) !== NULL) {
        return $organization;
      }
    }
    return NULL;
  }

  /**
   * Tests the contact and address fields needed for identity verification.
   */
  private function hasCompleteOrganization(array $organization): bool {
    $contact = $organization['contactPoint'] ?? NULL;
    $contact = is_array($contact) && array_is_list($contact) ? ($contact[0] ?? NULL) : $contact;
    $address = $organization['address'] ?? NULL;
    return is_array($contact)
      && $this->hasValue($contact['contactType'] ?? NULL)
      && ($this->hasValue($contact['email'] ?? NULL) || $this->hasValue($contact['telephone'] ?? NULL))
      && is_array($address)
      && $this->hasValue($address['streetAddress'] ?? NULL)
      && $this->hasValue($address['addressLocality'] ?? NULL)
      && $this->hasValue($address['addressRegion'] ?? NULL)
      && $this->hasValue($address['postalCode'] ?? NULL)
      && $this->hasValue($address['addressCountry'] ?? NULL);
  }

  /**
   * Tests for a nonempty scalar value in untrusted structured data.
   */
  private function hasValue(mixed $value): bool {
    return is_scalar($value) && trim((string) $value) !== '';
  }

  /**
   * Tests for at least one real HTTPS link in a developer-resources section.
   */
  private function hasDeveloperResources(string $markdown): bool {
    if (preg_match('/^##\s+Developer resources\s*$\R(.*?)(?=^##\s|\z)/msi', $markdown, $matches) !== 1) {
      return FALSE;
    }
    return preg_match('/\[[^\]]+\]\(https:\/\/[^)]+\)/i', $matches[1]) === 1;
  }

  /**
   * Returns public response metadata suitable for troubleshooting.
   */
  private function responseFacts(ResponseInterface $response): array {
    return [
      'status' => $response->getStatusCode(),
      'content_type' => $response->getHeaderLine('Content-Type'),
      'vary' => $response->getHeaderLine('Vary'),
      'markdown_tokens' => $response->getHeaderLine('x-markdown-tokens'),
    ];
  }

  /**
   * Creates one consistent check result.
   */
  private function check(bool $pass, array $details): array {
    return ['pass' => $pass, 'details' => $details];
  }

  /**
   * Tests one response media type.
   */
  private function hasContentType(ResponseInterface $response, string $type): bool {
    return str_starts_with(strtolower($response->getHeaderLine('Content-Type')), strtolower($type));
  }

  /**
   * Tests whether a response varies by Accept.
   */
  private function variesByAccept(ResponseInterface $response): bool {
    return in_array('accept', array_map(
      static fn (string $value): string => strtolower(trim($value)),
      explode(',', $response->getHeaderLine('Vary')),
    ), TRUE);
  }

  /**
   * Resolves a configured internal path or absolute URL.
   */
  private function absoluteUrl(string $base_url, string $path): string {
    if (preg_match('@^https://@i', $path)) {
      $parts = parse_url($path);
      if (!filter_var($path, FILTER_VALIDATE_URL)
        || !is_array($parts)
        || isset($parts['user'])
        || isset($parts['pass'])) {
        throw new \InvalidArgumentException('Configured trust page URLs must be public HTTPS URLs without credentials.');
      }
      return $path;
    }
    if (str_starts_with($path, '//') || preg_match('@^[a-z][a-z0-9+.-]*://@i', $path)) {
      throw new \InvalidArgumentException('Configured trust pages must use an internal path or an absolute HTTPS URL.');
    }
    return $base_url . '/' . ltrim($path, '/');
  }

}
