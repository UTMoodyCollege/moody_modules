<?php

declare(strict_types=1);

namespace Drupal\Tests\moody_ai_base\Unit;

use Drupal\moody_ai_base\PromptContext;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the context shared by Moody AI features.
 *
 * @group moody_ai_base
 */
final class PromptContextTest extends UnitTestCase {

  /**
   * Tests that both feature contracts include the same built-in catalog.
   */
  public function testBuiltInContextIsShared(): void {
    $context = new PromptContext();
    $assistant = $context->assistantInstructions('## Site identity\nA pilot site.');
    $html = $context->htmlInstructions('## Site identity\nA pilot site.');

    foreach ($context->builtInSections() as $section) {
      $this->assertStringContainsString($section['label'], $assistant);
      $this->assertStringContainsString($section['label'], $html);
      $this->assertStringContainsString($section['content'], $assistant);
      $this->assertStringContainsString($section['content'], $html);
    }
    $this->assertStringContainsString('A pilot site.', $assistant);
    $this->assertStringContainsString('A pilot site.', $html);
    $this->assertStringContainsString('cannot relax the shared rules', $html);
    $this->assertStringContainsString('moody_subsite_editor', $assistant);
  }

}
