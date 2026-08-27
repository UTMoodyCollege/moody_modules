<?php

declare(strict_types=1);

namespace Drupal\Tests\moody_ai_seo\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\moody_ai_seo\OrganizationSchema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests organization structured-data construction.
 *
 * @group moody_ai_seo
 */
final class OrganizationSchemaTest extends TestCase {

  /**
   * Tests configured values and existing site defaults are combined safely.
   */
  public function testBuildsCompleteOrganization(): void {
    $seo = $this->createMock(ImmutableConfig::class);
    $seo->method('get')->willReturnMap([
      ['organization.enabled', TRUE],
      ['organization.url', ''],
      ['organization.name', ''],
      ['organization.description', ''],
      ['organization.email', ''],
      ['organization.type', 'CollegeOrUniversity'],
      ['organization.logo', 'https://example.edu/logo.svg'],
      ['organization.contact_type', 'general inquiries'],
      ['organization.telephone', '+1-512-555-0100'],
      ['organization.street_address', '300 Test Street'],
      ['organization.address_locality', 'Austin'],
      ['organization.address_region', 'TX'],
      ['organization.postal_code', '78712'],
      ['organization.address_country', 'US'],
      ['organization.same_as', "https://www.linkedin.com/school/example\nhttps://www.instagram.com/example"],
      ['organization.parent_name', 'The University of Texas at Austin'],
      ['organization.parent_url', 'https://www.utexas.edu'],
    ]);
    $site = $this->createMock(ImmutableConfig::class);
    $site->method('get')->willReturnMap([
      ['name', 'Example College'],
      ['mail', 'webmaster@example.edu'],
    ]);
    $metatag = $this->createMock(ImmutableConfig::class);
    $metatag->method('get')->with('tags.description')->willReturn('Official college description.');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnMap([
      ['moody_ai_seo.settings', $seo],
      ['system.site', $site],
      ['metatag.metatag_defaults.global', $metatag],
    ]);
    $stack = new RequestStack();
    $stack->push(Request::create('https://example.edu/'));

    $schema = (new OrganizationSchema($factory, $stack))->build();

    $this->assertSame('CollegeOrUniversity', $schema['@type']);
    $this->assertSame('Example College', $schema['name']);
    $this->assertSame('webmaster@example.edu', $schema['contactPoint']['email']);
    $this->assertSame('Austin', $schema['address']['addressLocality']);
    $this->assertCount(2, $schema['sameAs']);
    $this->assertSame('The University of Texas at Austin', $schema['parentOrganization']['name']);
  }

  /**
   * Tests the feature is inert by default.
   */
  public function testDisabledSchemaIsEmpty(): void {
    $seo = $this->createMock(ImmutableConfig::class);
    $seo->method('get')->with('organization.enabled')->willReturn(FALSE);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('moody_ai_seo.settings')->willReturn($seo);

    $this->assertSame([], (new OrganizationSchema($factory, new RequestStack()))->build());
  }

  /**
   * Tests imported configuration cannot publish non-HTTPS identity links.
   */
  public function testUnsafeIdentityUrlsAreOmitted(): void {
    $seo = $this->createMock(ImmutableConfig::class);
    $seo->method('get')->willReturnMap([
      ['organization.enabled', TRUE],
      ['organization.url', 'javascript:alert(1)'],
      ['organization.name', 'Example College'],
      ['organization.description', ''],
      ['organization.email', ''],
      ['organization.type', 'Organization'],
      ['organization.logo', 'http://example.edu/logo.svg'],
      ['organization.contact_type', 'general inquiries'],
      ['organization.telephone', ''],
      ['organization.street_address', ''],
      ['organization.address_locality', ''],
      ['organization.address_region', ''],
      ['organization.postal_code', ''],
      ['organization.address_country', ''],
      ['organization.same_as', "javascript:alert(1)\nhttps://example.edu/profile"],
      ['organization.parent_name', 'Parent'],
      ['organization.parent_url', 'http://example.edu'],
    ]);
    $site = $this->createMock(ImmutableConfig::class);
    $site->method('get')->willReturnMap([
      ['name', 'Example College'],
      ['mail', ''],
    ]);
    $metatag = $this->createMock(ImmutableConfig::class);
    $metatag->method('get')->with('tags.description')->willReturn('');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnMap([
      ['moody_ai_seo.settings', $seo],
      ['system.site', $site],
      ['metatag.metatag_defaults.global', $metatag],
    ]);
    $stack = new RequestStack();
    $stack->push(Request::create('https://example.edu/'));

    $schema = (new OrganizationSchema($factory, $stack))->build();

    $this->assertSame('https://example.edu/', $schema['url']);
    $this->assertArrayNotHasKey('logo', $schema);
    $this->assertSame(['https://example.edu/profile'], $schema['sameAs']);
    $this->assertArrayNotHasKey('url', $schema['parentOrganization']);
  }

}
