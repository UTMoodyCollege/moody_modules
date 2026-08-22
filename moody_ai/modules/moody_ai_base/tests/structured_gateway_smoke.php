<?php

declare(strict_types=1);

use Drupal\moody_ai_base\AiGenerationService;
use Drupal\moody_ai_base\HtmlSanitizer;
use Drupal\moody_ai_base\PromptContext;
use Drupal\moody_ai_base\SecretResolver;
use Drupal\moody_ai_assistant\Service\AssistantPlanner;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

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
  new Response(200, [], json_encode([
    'output_text' => '{"action":"guide","guide":{"topic":"menus","summary":"Open the menu administration page."}}',
    'usage' => [
      'input_tokens' => 18,
      'output_tokens' => 8,
      'total_tokens' => 26,
    ],
  ], JSON_THROW_ON_ERROR)),
]));
$stack->push(Middleware::history($history));
putenv('MOODY_AI_OPENAI_API_KEY=dummy');

try {
  $service = new AiGenerationService(
    \Drupal::configFactory(),
    new Client(['handler' => $stack]),
    new SecretResolver(),
    new PromptContext(),
    new HtmlSanitizer(),
    \Drupal::service('logger.factory'),
  );
  $result = $service->generateStructured([
    ['role' => 'system', 'content' => 'Return a supported action as JSON.'],
    ['role' => 'user', 'content' => 'Where do I edit menus?'],
  ]);
  $planner = new AssistantPlanner($service, \Drupal::service('logger.factory'));
  $plan = $planner->planTopLevelAction(
    'Where do I edit menus?',
    ['entity_id' => 7268],
    [],
  );
}
finally {
  putenv('MOODY_AI_OPENAI_API_KEY');
}

$payload = json_decode((string) $history[0]['request']->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
if (
  ($result['data']['action'] ?? '') !== 'guide'
  || ($result['usage']['total_tokens'] ?? 0) !== 16
  || ($payload['store'] ?? TRUE) !== FALSE
  || ($payload['text']['format']['type'] ?? '') !== 'json_object'
  || !str_contains($payload['instructions'] ?? '', 'Drupal must recheck access')
  || ($plan['action'] ?? '') !== 'guide'
  || ($plan['guide']['topic'] ?? '') !== 'menus'
) {
  throw new RuntimeException('Structured gateway assertion failed.');
}
print "Structured gateway mock passed.\n";
