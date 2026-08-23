<?php

declare(strict_types=1);

use Drupal\moody_ai_base\Controller\UsageDashboardController;
use Symfony\Component\HttpFoundation\Request;

$database = \Drupal::database();
if (!$database->schema()->fieldExists('moody_ai_assistant_usage_log', 'operation')) {
  throw new RuntimeException('Run database updates before the usage dashboard smoke test.');
}

$transaction = $database->startTransaction('moody_ai_usage_dashboard_smoke');
try {
  $tracker = \Drupal::service('moody_ai_base.usage_tracker');
  $account = \Drupal::currentUser();
  $started = time() - 1;
  $secret_prompt = 'dashboard-smoke-prompt-must-not-export';
  $tracker->recordUsage($account, NULL, $secret_prompt, 100, 'success', NULL, 'dashboard_smoke', 'create_blocks', 2);
  $tracker->recordUsage($account, NULL, $secret_prompt, 50, 'partial', NULL, 'dashboard_smoke', 'edit_blocks', 1);

  $outcome = ['unavailable', 0];
  if (\Drupal::moduleHandler()->moduleExists('moody_ai_assistant')) {
    $thread = \Drupal::entityTypeManager()->getStorage('moody_ai_chat_thread')->create([
      'user_id' => $account->id(),
      'label' => 'Usage reporting smoke',
      'target_entity_type' => 'node',
      'target_entity_id' => 0,
    ]);
    $thread->setMessages([[
      'role' => 'assistant',
      'content' => 'Smoke outcome',
      'metadata' => [
        'created_blocks' => [['uuid' => 'one'], ['uuid' => 'two']],
        'edited_blocks' => [['uuid' => 'three']],
      ],
    ]]);
    $outcome_method = new ReflectionMethod(\Drupal::service('moody_ai_assistant.chat_manager'), 'summarizeUsageOutcome');
    $outcome = $outcome_method->invoke(\Drupal::service('moody_ai_assistant.chat_manager'), $thread);
    if ($outcome !== ['create_and_edit_blocks', 3]) {
      throw new RuntimeException('Assistant block outcomes were not summarized correctly.');
    }
  }

  $controller = UsageDashboardController::create(\Drupal::getContainer());
  $method = new ReflectionMethod($controller, 'loadReport');
  $report = $method->invoke($controller, $started, 50);
  $source = NULL;
  foreach ($report['sources'] as $candidate) {
    if (($candidate['source'] ?? '') === 'dashboard_smoke') {
      $source = $candidate;
      break;
    }
  }
  if (!$source || (int) $source['requests'] !== 2 || (int) $source['items_affected'] !== 3 || (int) $source['tokens_used'] !== 150) {
    throw new RuntimeException('The dashboard did not aggregate the smoke usage outcomes correctly.');
  }

  $response = $controller->export(Request::create('/admin/reports/moody-ai/export', 'GET', ['days' => 'all', 'type' => 'events']));
  $csv_method = new ReflectionMethod($controller, 'writeCsvRow');
  $csv_stream = fopen('php://temp', 'w+');
  $csv_method->invoke($controller, $csv_stream, ['=HYPERLINK("https://example.com")']);
  rewind($csv_stream);
  $safe_csv = (string) stream_get_contents($csv_stream);
  fclose($csv_stream);
  $safe_csv_values = str_getcsv($safe_csv, ',', '"', '');
  if (
    $response->getStatusCode() !== 200
    || !str_contains((string) $response->headers->get('Content-Type'), 'text/csv')
    || str_contains((string) $response->getContent(), $secret_prompt)
    || !str_starts_with((string) ($safe_csv_values[0] ?? ''), "'=")
  ) {
    throw new RuntimeException('The outcome CSV is invalid, unsafe, or exposed prompt content.');
  }

  print json_encode([
    'requests' => (int) $source['requests'],
    'outcomes' => (int) $source['items_affected'],
    'tokens' => (int) $source['tokens_used'],
    'assistant_outcome' => $outcome,
    'csv_formula_protected' => TRUE,
    'prompt_exported' => FALSE,
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
finally {
  $transaction->rollBack();
}
