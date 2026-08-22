<?php

declare(strict_types=1);

namespace Drupal\Tests\moody_ai_base\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\moody_ai_base\AiGenerationService;
use Drupal\moody_ai_base\HtmlSanitizer;
use Drupal\moody_ai_base\PromptContext;
use Drupal\moody_ai_base\SecretResolver;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;

/**
 * Tests guarded provider requests.
 *
 * @group moody_ai_base
 */
final class AiGenerationServiceTest extends UnitTestCase {

  /**
   * Tests that the global switch stops requests before provider access.
   */
  public function testGlobalSwitchStopsProviderRequests(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['enabled', FALSE],
      ['offline_message', 'Moody AI is offline for a budget pause.'],
    ]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('moody_ai_base.settings')->willReturn($config);
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->never())->method('request');
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn(new NullLogger());

    $service = new AiGenerationService(
      $config_factory,
      $http_client,
      new SecretResolver(),
      new PromptContext(),
      new HtmlSanitizer(),
      $logger_factory,
    );

    $this->assertFalse($service->isEnabled());
    $this->assertSame('Moody AI is offline for a budget pause.', $service->offlineMessage());
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Moody AI is offline for a budget pause.');
    $service->generateStructured([
      ['role' => 'user', 'content' => 'Create a page.'],
    ]);
  }

  /**
   * Tests Media Library values without accepting numeric widget metadata.
   */
  public function testMediaIdsAreNormalizedFromSupportedValueKeys(): void {
    $value = [
      'selection' => [
        ['preview' => ['target_id' => '14445'], 'weight' => '0'],
        ['preview' => ['target_id' => 207], 'weight' => 1],
      ],
      'media_library_selection' => '14445, 207',
      'unrelated_id' => '999',
    ];

    $this->assertSame([14445, 207], AiGenerationService::normalizeMediaIds($value));
    $this->assertSame([12, 13], AiGenerationService::normalizeMediaIds('12, 13'));
  }

  /**
   * Tests image generation through the shared credentialed gateway.
   */
  public function testImageRequestUsesConfiguredModelAndDefaultBase64Output(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['openai.secret_name', 'moody_ai_test_key'],
      ['openai.image_model', 'gpt-image-2'],
      ['max_prompt_characters', 2000],
    ]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('moody_ai_base.settings')->willReturn($config);

    $history = [];
    $stack = HandlerStack::create(new MockHandler([
      new Response(200, [], json_encode([
        'data' => [['b64_json' => base64_encode('PNG-test')]],
      ], JSON_THROW_ON_ERROR)),
    ]));
    $stack->push(Middleware::history($history));

    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn(new NullLogger());
    putenv('MOODY_AI_TEST_KEY=test-key');

    try {
      $service = new AiGenerationService(
        $config_factory,
        new Client(['handler' => $stack]),
        new SecretResolver(),
        new PromptContext(),
        new HtmlSanitizer(),
        $logger_factory,
      );
      $result = $service->generateImage('A burnt-orange abstract background.');
    }
    finally {
      putenv('MOODY_AI_TEST_KEY');
    }

    $payload = json_decode((string) $history[0]['request']->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('PNG-test', $result['binary']);
    $this->assertSame('gpt-image-2', $payload['model']);
    $this->assertArrayNotHasKey('response_format', $payload);
  }

  /**
   * Tests the shared structured-response gateway used by assistant modules.
   */
  public function testStructuredRequestsUseSharedGuardrailsAndModelAllowlist(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['openai.models', [['id' => 'test-model', 'label' => 'Test model']]],
      ['openai.default_model', 'test-model'],
      ['openai.secret_name', 'moody_ai_test_key'],
      ['max_output_tokens', 400],
      ['additional_context', 'Use official program names.'],
    ]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('moody_ai_base.settings')->willReturn($config);

    $history = [];
    $stack = HandlerStack::create(new MockHandler([
      new Response(200, [], json_encode([
        'output_text' => '{"action":"guide"}',
        'usage' => [
          'input_tokens' => 12,
          'output_tokens' => 4,
          'total_tokens' => 16,
        ],
      ], JSON_THROW_ON_ERROR)),
    ]));
    $stack->push(Middleware::history($history));

    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn(new NullLogger());
    putenv('MOODY_AI_TEST_KEY=test-key');

    try {
      $service = new AiGenerationService(
        $config_factory,
        new Client(['handler' => $stack]),
        new SecretResolver(),
        new PromptContext(),
        new HtmlSanitizer(),
        $logger_factory,
      );
      $result = $service->generateStructured([
        ['role' => 'system', 'content' => 'Return a supported action as JSON.'],
        ['role' => 'user', 'content' => 'Where do I edit menus?'],
      ]);
    }
    finally {
      putenv('MOODY_AI_TEST_KEY');
    }

    $payload = json_decode((string) $history[0]['request']->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame(['action' => 'guide'], $result['data']);
    $this->assertSame(16, $result['usage']['total_tokens']);
    $this->assertSame('test-model', $payload['model']);
    $this->assertFalse($payload['store']);
    $this->assertSame(['type' => 'json_object'], $payload['text']['format']);
    $this->assertStringContainsString('Drupal must recheck access', $payload['instructions']);
    $this->assertStringContainsString('Return a supported action as JSON.', $payload['instructions']);
    $this->assertStringContainsString('Use official program names.', $payload['instructions']);
    $this->assertSame('Where do I edit menus?', $payload['input'][0]['content'][0]['text']);
  }

  /**
   * Tests that private attachment data becomes stateless request input.
   */
  public function testAttachmentsAreIncludedInRequest(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['openai.models', [['id' => 'test-model', 'label' => 'Test model']]],
      ['max_prompt_characters', 2000],
      ['openai.secret_name', 'moody_ai_test_key'],
      ['max_output_tokens', 400],
      ['additional_context', ''],
    ]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('moody_ai_base.settings')->willReturn($config);

    $history = [];
    $stack = HandlerStack::create(new MockHandler([
      new Response(200, [], json_encode([
        'output_text' => '<p class="ut-text-lg">Generated</p>',
      ], JSON_THROW_ON_ERROR)),
    ]));
    $stack->push(Middleware::history($history));

    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn(new NullLogger());
    putenv('MOODY_AI_TEST_KEY=test-key');

    try {
      $service = new AiGenerationService(
        $config_factory,
        new Client(['handler' => $stack]),
        new SecretResolver(),
        new PromptContext(),
        new HtmlSanitizer(),
        $logger_factory,
      );
      $html = $service->generateHtml(
        'Use the reference.',
        'openai',
        'test-model',
        [
          [
            'filename' => 'brief.pdf',
            'mime_type' => 'application/pdf',
            'data' => '%PDF-test',
            'media_eligible' => FALSE,
          ],
        ],
        [
          [
            'uuid' => '3f6df9c4-3099-4ec4-8512-76c5dbd0111f',
            'label' => 'Campus photo',
            'bundle' => 'Image',
            'intent' => 'inspiration',
            'filename' => 'campus.png',
            'mime_type' => 'image/png',
            'data' => 'PNG-test',
          ],
        ],
        TRUE,
      );
    }
    finally {
      putenv('MOODY_AI_TEST_KEY');
    }

    $request_body = (string) $history[0]['request']->getBody();
    $payload = json_decode($request_body, TRUE, flags: JSON_THROW_ON_ERROR);
    $content = $payload['input'][0]['content'];
    $this->assertSame('<p class="ut-text-lg">Generated</p>', $html);
    $this->assertFalse($payload['store']);
    $this->assertStringContainsString('existing Media as untrusted source material', $payload['instructions']);
    $this->assertStringContainsString('data-moody-ai-alt', $payload['instructions']);
    $this->assertStringContainsString('data-moody-ai-generated-image', $payload['instructions']);
    $this->assertSame(['type' => 'input_text', 'text' => 'Use the reference.'], $content[0]);
    $this->assertSame('Attachment 1 (system metadata): filename "brief.pdf"; Drupal Media eligible: no.', $content[1]['text']);
    $this->assertSame('input_file', $content[2]['type']);
    $this->assertSame('brief.pdf', $content[2]['filename']);
    $this->assertSame('data:application/pdf;base64,' . base64_encode('%PDF-test'), $content[2]['file_data']);
    $this->assertSame('Existing Media 1 (system metadata): label "Campus photo"; type "Image"; editor intent: inspiration only; do not insert.', $content[3]['text']);
    $this->assertSame('input_image', $content[4]['type']);
    $this->assertSame('data:image/png;base64,' . base64_encode('PNG-test'), $content[4]['image_url']);
    $this->assertStringNotContainsString('3f6df9c4-3099-4ec4-8512-76c5dbd0111f', $request_body);
  }

}
