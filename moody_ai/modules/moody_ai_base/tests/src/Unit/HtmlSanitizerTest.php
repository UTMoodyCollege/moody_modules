<?php

declare(strict_types=1);

namespace Drupal\Tests\moody_ai_base\Unit;

use Drupal\moody_ai_base\HtmlSanitizer;
use Drupal\Tests\UnitTestCase;

/**
 * Tests generated HTML sanitization at the AI trust boundary.
 *
 * @group moody_ai_base
 */
final class HtmlSanitizerTest extends UnitTestCase {

  /**
   * Tests unsafe elements, attributes, heading levels, and classes.
   */
  public function testUnsafeMarkupIsRemoved(): void {
    $sanitizer = new HtmlSanitizer();
    $html = '<section class="ut-p-4 md:ut-p-8 invented" onclick="bad()"><h1>Wrong level</h1><p class="ut-text-lg">Hello <a href="javascript:alert(1)" target="popup">there</a><script>alert(1)</script></p></section>';

    $this->assertSame(
      '<section class="ut-p-4 md:ut-p-8">Wrong level<p class="ut-text-lg">Hello <a>there</a></p></section>',
      $sanitizer->sanitize($html),
    );
  }

  /**
   * Tests the additional published design-system utilities.
   */
  public function testPublishedDesignSystemClassesAreAllowed(): void {
    $sanitizer = new HtmlSanitizer();
    $html = '<section class="border-ut-bluebonnet ut-border-width-thin md:ut-border-radius-lg fake-card"><h2 class="ut-headline--underline lg:ut-text-3xl">Programs</h2><p class="ut-copy dont-break-out">Explore our work.</p><a class="ut-cta-link--darker" href="/programs">View programs</a><table class="ut-fit-table ut-50-50-table"><caption>Programs</caption></table></section>';

    $this->assertSame(
      '<section class="border-ut-bluebonnet ut-border-width-thin md:ut-border-radius-lg"><h2 class="ut-headline--underline lg:ut-text-3xl">Programs</h2><p class="ut-copy dont-break-out">Explore our work.</p><a class="ut-cta-link--darker" href="/programs">View programs</a><table class="ut-fit-table ut-50-50-table"><caption>Programs</caption></table></section>',
      $sanitizer->sanitize($html),
    );
  }

  /**
   * Tests safe links and new-window protections.
   */
  public function testSafeLinksAreNormalized(): void {
    $sanitizer = new HtmlSanitizer();
    $html = '<p><a href="https://example.com/path" target="_blank" rel="opener nofollow">Example</a> <a href="/relative">Relative</a></p>';

    $this->assertSame(
      '<p><a href="https://example.com/path" target="_blank" rel="nofollow noopener noreferrer">Example</a> <a href="/relative">Relative</a></p>',
      $sanitizer->sanitize($html),
    );
  }

  /**
   * Tests table accessibility attributes and invalid numeric values.
   */
  public function testTableAttributesAreRestricted(): void {
    $sanitizer = new HtmlSanitizer();
    $html = '<table><caption>Data</caption><thead><tr><th scope="col" colspan="2">Name</th></tr></thead><tbody><tr><td rowspan="all">One</td></tr></tbody></table>';

    $this->assertSame(
      '<table><caption>Data</caption><thead><tr><th scope="col" colspan="2">Name</th></tr></thead><tbody><tr><td>One</td></tr></tbody></table>',
      $sanitizer->sanitize($html),
    );
  }

  /**
   * Tests that only internal Media placeholders survive generation.
   */
  public function testMediaPlaceholdersAreRestricted(): void {
    $sanitizer = new HtmlSanitizer();
    $html = '<drupal-media data-entity-uuid="invented" data-moody-ai-attachment="1" data-moody-ai-alt=" Students at work " data-moody-ai-align="center"><script>bad()</script></drupal-media><drupal-media data-moody-ai-media="2" data-moody-ai-alt="Invented"></drupal-media><drupal-media data-moody-ai-generated-image="1" data-moody-ai-image-prompt=" Editorial students collaborating " data-moody-ai-alt="Students collaborate around a table"></drupal-media><drupal-media data-moody-ai-generated-image="1" data-moody-ai-image-prompt=""></drupal-media><drupal-media data-moody-ai-attachment="x" data-moody-ai-alt="Bad"></drupal-media>';

    $this->assertSame(
      '<drupal-media data-moody-ai-attachment="1" data-moody-ai-alt="Students at work" data-moody-ai-align="center"></drupal-media><drupal-media data-moody-ai-media="2"></drupal-media><drupal-media data-moody-ai-generated-image="1" data-moody-ai-image-prompt="Editorial students collaborating" data-moody-ai-alt="Students collaborate around a table"></drupal-media>',
      $sanitizer->sanitize($html),
    );
  }

}
