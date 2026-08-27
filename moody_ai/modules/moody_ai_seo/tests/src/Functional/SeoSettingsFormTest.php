<?php

declare(strict_types=1);

namespace Drupal\Tests\moody_ai_seo\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the per-site agent-readiness dashboard.
 *
 * @group moody_ai_seo
 */
final class SeoSettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['moody_ai_seo'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests access, guidance validation, and per-site configuration storage.
   */
  public function testDashboard(): void {
    $this->drupalGet('/admin/config/services/moody-ai/seo');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['administer moody ai seo']));
    $this->drupalGet('/admin/config/services/moody-ai/seo');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Managed automatically');
    $this->assertSession()->fieldExists('guidance[llms_content]');

    $this->submitForm([
      'guidance[llms_content]' => "# Example College\n\nOfficial information.\n",
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('Agent guidance must include a “When to use this site” section.');

    $guidance = <<<'MARKDOWN'
# Example College

> Official information from Example College.

## When to use this site

Use this site to find official programs, people, research, news, and contact information.
MARKDOWN;
    $this->submitForm([
      'guidance[llms_content]' => $guidance,
      'organization[enabled]' => TRUE,
      'organization[name]' => 'Example College',
      'organization[address][street_address]' => '300 Test Street',
      'organization[address][address_locality]' => 'Austin',
      'organization[address][address_region]' => 'TX',
      'organization[address][postal_code]' => '78712',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $this->assertSame($guidance . "\n", $this->config('llms_txt.settings')->get('content'));
    $this->assertTrue($this->config('moody_ai_seo.settings')->get('organization.enabled'));
    $this->assertSame('300 Test Street', $this->config('moody_ai_seo.settings')->get('organization.street_address'));
  }

}
