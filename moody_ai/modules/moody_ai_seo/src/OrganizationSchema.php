<?php

declare(strict_types=1);

namespace Drupal\moody_ai_seo;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the site's organization JSON-LD from reviewed configuration.
 */
final class OrganizationSchema {

  private const ALLOWED_TYPES = [
    'Organization',
    'EducationalOrganization',
    'CollegeOrUniversity',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Builds a Schema.org organization record, or nothing when disabled.
   */
  public function build(): array {
    $config = $this->configFactory->get('moody_ai_seo.settings');
    if (!$config->get('organization.enabled')) {
      return [];
    }

    $site = $this->configFactory->get('system.site');
    $metatag = $this->configFactory->get('metatag.metatag_defaults.global');
    $request = $this->requestStack->getCurrentRequest();
    $base_url = $request ? $request->getSchemeAndHttpHost() . '/' : '';
    $url = $this->httpsUrl($config->get('organization.url')) ?: $this->httpsUrl($base_url);
    $name = $this->value($config->get('organization.name'), $site->get('name'));
    $description = $this->value(
      $config->get('organization.description'),
      $metatag->get('tags.description'),
    );
    $email = $this->value($config->get('organization.email'), $site->get('mail'));
    $type = (string) $config->get('organization.type');
    if (!in_array($type, self::ALLOWED_TYPES, TRUE)) {
      $type = 'Organization';
    }

    if ($name === '' || $url === '') {
      return [];
    }

    $schema = [
      '@context' => 'https://schema.org',
      '@type' => $type,
      '@id' => rtrim($url, '/') . '/#organization',
      'name' => $name,
      'url' => $url,
    ];
    $this->add($schema, 'description', $description);
    $this->add($schema, 'logo', $this->httpsUrl($config->get('organization.logo')));

    $contact = [
      '@type' => 'ContactPoint',
      'contactType' => $this->value($config->get('organization.contact_type'), 'general inquiries'),
    ];
    $this->add($contact, 'email', $email);
    $this->add($contact, 'telephone', $config->get('organization.telephone'));
    if (count($contact) > 2) {
      $schema['contactPoint'] = $contact;
    }

    $address = ['@type' => 'PostalAddress'];
    foreach ([
      'streetAddress' => 'street_address',
      'addressLocality' => 'address_locality',
      'addressRegion' => 'address_region',
      'postalCode' => 'postal_code',
      'addressCountry' => 'address_country',
    ] as $property => $key) {
      $this->add($address, $property, $config->get("organization.$key"));
    }
    if (count($address) > 1) {
      $schema['address'] = $address;
    }

    $same_as = array_values(array_filter(array_map(
      $this->httpsUrl(...),
      preg_split('/\R/', (string) $config->get('organization.same_as')) ?: [],
    )));
    if ($same_as !== []) {
      $schema['sameAs'] = $same_as;
    }

    $parent_name = trim((string) $config->get('organization.parent_name'));
    $parent_url = $this->httpsUrl($config->get('organization.parent_url'));
    if ($parent_name !== '' || $parent_url !== '') {
      $schema['parentOrganization'] = ['@type' => 'Organization'];
      $this->add($schema['parentOrganization'], 'name', $parent_name);
      $this->add($schema['parentOrganization'], 'url', $parent_url);
    }

    return $schema;
  }

  /**
   * Adds a trimmed nonempty scalar value.
   */
  private function add(array &$target, string $key, mixed $value): void {
    if (is_scalar($value) && trim((string) $value) !== '') {
      $target[$key] = trim((string) $value);
    }
  }

  /**
   * Returns a configured value or its fallback.
   */
  private function value(mixed $value, mixed $fallback): string {
    $value = is_scalar($value) ? trim((string) $value) : '';
    $fallback = is_scalar($fallback) ? trim((string) $fallback) : '';
    return $value !== '' ? $value : $fallback;
  }

  /**
   * Returns an absolute HTTPS URL or an empty string.
   */
  private function httpsUrl(mixed $value): string {
    $value = is_scalar($value) ? trim((string) $value) : '';
    $parts = parse_url($value);
    return filter_var($value, FILTER_VALIDATE_URL)
      && is_array($parts)
      && ($parts['scheme'] ?? '') === 'https'
      && !isset($parts['user'])
      && !isset($parts['pass'])
        ? $value
        : '';
  }

}
