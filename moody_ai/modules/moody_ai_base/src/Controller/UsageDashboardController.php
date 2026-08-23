<?php

namespace Drupal\moody_ai_base\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Shows factual site-local Moody AI adoption, outcomes, and CSV exports.
 */
class UsageDashboardController extends ControllerBase {

  /** Supported reporting windows. */
  const PERIODS = [
    '7' => 'Last 7 days',
    '30' => 'Last 30 days',
    '90' => 'Last 90 days',
    '365' => 'Last year',
    'all' => 'All time',
  ];

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityManager,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the site-local reporting dashboard.
   */
  public function dashboard(Request $request) {
    $period = $this->resolvePeriod($request);
    $since = $period === 'all' ? 0 : time() - ((int) $period * 86400);
    $report = $this->loadReport($since, 50);
    $summary = $report['summary'];
    $requests = max(0, (int) $summary['requests']);
    $completion_rate = $requests ? round(((int) $summary['successes'] / $requests) * 100, 1) : 0;

    $build = [
      '#attached' => ['library' => ['moody_ai_base/dashboard']],
      'intro' => [
        '#markup' => '<p>' . $this->t('This report uses site-local Moody AI request records. Prompts and generated content are never displayed or exported.') . '</p>',
      ],
      'periods' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['moody-ai-report__periods'], 'aria-label' => $this->t('Reporting period')],
      ],
    ];
    foreach (self::PERIODS as $value => $label) {
      $build['periods'][$value] = [
        '#type' => 'link',
        '#title' => $this->t($label),
        '#url' => Url::fromRoute('moody_ai_base.usage_report', [], ['query' => ['days' => $value]]),
        '#attributes' => ['class' => ['button', $value === $period ? 'button--primary' : 'button--small']],
      ];
    }

    $build['stats'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['moody-ai-report__stats']],
    ];
    $cards = [
      'users' => [$summary['users'], $this->t('People supported')],
      'requests' => [$requests, $this->t('AI requests')],
      'outcomes' => [$summary['items_affected'], $this->t('AI outcomes delivered')],
      'pages' => [$summary['targets'], $this->t('Content items reached')],
      'completion' => [$completion_rate . '%', $this->t('Successful requests')],
      'tokens' => [number_format((int) $summary['tokens_used']), $this->t('Tracked tokens')],
      'review' => [$summary['needs_review'], $this->t('Requests needing review')],
    ];
    foreach ($cards as $key => [$value, $label]) {
      $build['stats'][$key] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['moody-ai-report__stat']],
        'value' => ['#markup' => '<strong>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</strong>'],
        'label' => ['#markup' => '<span>' . $label . '</span>'],
      ];
    }

    $build['exports'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['moody-ai-report__exports']],
      'label' => ['#markup' => '<strong>' . $this->t('Export evidence:') . '</strong> '],
      'users' => [
        '#type' => 'link',
        '#title' => $this->t('User statistics CSV'),
        '#url' => Url::fromRoute('moody_ai_base.usage_export', [], ['query' => ['days' => $period, 'type' => 'users']]),
      ],
      'events' => [
        '#type' => 'link',
        '#title' => $this->t('Request outcomes CSV'),
        '#url' => Url::fromRoute('moody_ai_base.usage_export', [], ['query' => ['days' => $period, 'type' => 'events']]),
      ],
    ];

    $build['sources'] = [
      '#type' => 'table',
      '#caption' => $this->t('Value by AI tool'),
      '#header' => [$this->t('Tool'), $this->t('Requests'), $this->t('Successful'), $this->t('Outcomes'), $this->t('Tokens')],
      '#rows' => array_map(function (array $row) {
        return [
          $this->sourceLabel($row['source']),
          number_format((int) $row['requests']),
          number_format((int) $row['successes']),
          number_format((int) $row['items_affected']),
          number_format((int) $row['tokens_used']),
        ];
      }, $report['sources']),
      '#empty' => $this->t('No Moody AI usage has been recorded for this period.'),
    ];

    $build['users'] = [
      '#type' => 'table',
      '#caption' => $this->t('User adoption and outcomes'),
      '#header' => [$this->t('User'), $this->t('Roles'), $this->t('Requests'), $this->t('Successful'), $this->t('Partial'), $this->t('Errors'), $this->t('Outcomes'), $this->t('Tokens'), $this->t('Last used')],
      '#rows' => array_map(function (array $row) {
        return [
          $row['user'],
          implode(', ', $row['roles']),
          number_format((int) $row['requests']),
          number_format((int) $row['successes']),
          number_format((int) $row['partials']),
          number_format((int) $row['errors']),
          number_format((int) $row['items_affected']),
          number_format((int) $row['tokens_used']),
          $this->dateFormatter->format((int) $row['last_used'], 'short'),
        ];
      }, $report['users']),
      '#empty' => $this->t('No users have recorded Moody AI activity for this period.'),
    ];

    $build['events'] = [
      '#type' => 'table',
      '#caption' => $this->t('Latest request outcomes'),
      '#header' => [$this->t('Date'), $this->t('User'), $this->t('Tool'), $this->t('Operation'), $this->t('Target'), $this->t('Status'), $this->t('Outcomes'), $this->t('Tokens')],
      '#rows' => array_map(function (array $row) {
        return [
          $this->dateFormatter->format((int) $row['created'], 'short'),
          $row['user'],
          $this->sourceLabel($row['source']),
          $this->machineLabel($row['operation']),
          $row['target_entity_label'] ?: $this->t('Not tied to content'),
          $this->machineLabel($row['status']),
          number_format((int) $row['items_affected']),
          number_format((int) $row['tokens_used']),
        ];
      }, $report['events']),
      '#empty' => $this->t('No request outcomes have been recorded for this period.'),
    ];

    return $build;
  }

  /**
   * Exports user statistics or request outcomes without prompts.
   */
  public function export(Request $request) {
    $period = $this->resolvePeriod($request);
    $since = $period === 'all' ? 0 : time() - ((int) $period * 86400);
    $type = $request->query->get('type') === 'events' ? 'events' : 'users';
    $report = $this->loadReport($since, $type === 'events' ? NULL : 0);
    $stream = fopen('php://temp', 'w+');

    if ($type === 'events') {
      $this->writeCsvRow($stream, ['Date', 'User ID', 'User', 'Tool', 'Operation', 'Target type', 'Target ID', 'Target label', 'Status', 'Outcomes', 'Tokens', 'Review flags']);
      foreach ($report['events'] as $row) {
        $this->writeCsvRow($stream, [
          gmdate('c', (int) $row['created']),
          $row['uid'],
          $row['user'],
          $this->sourceLabel($row['source']),
          $this->machineLabel($row['operation']),
          $row['target_entity_type'],
          $row['target_entity_id'],
          $row['target_entity_label'],
          $row['status'],
          $row['items_affected'],
          $row['tokens_used'],
          $row['review_flags'],
        ]);
      }
    }
    else {
      $this->writeCsvRow($stream, ['User ID', 'User', 'Roles', 'Requests', 'Successful', 'Partial', 'Errors', 'Outcomes', 'Tokens', 'Last used']);
      foreach ($report['users'] as $row) {
        $this->writeCsvRow($stream, [
          $row['uid'],
          $row['user'],
          implode('; ', $row['roles']),
          $row['requests'],
          $row['successes'],
          $row['partials'],
          $row['errors'],
          $row['items_affected'],
          $row['tokens_used'],
          gmdate('c', (int) $row['last_used']),
        ]);
      }
    }

    rewind($stream);
    $response = new Response((string) stream_get_contents($stream));
    fclose($stream);
    $filename = sprintf('moody-ai-%s-%s.csv', $type, gmdate('Y-m-d'));
    $disposition = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', $disposition);
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

  /**
   * Writes one spreadsheet-safe CSV row.
   */
  protected function writeCsvRow($stream, array $values) {
    $values = array_map(static function ($value) {
      if (is_string($value) && preg_match('/^[\t\r ]*[=+\-@]/u', $value)) {
        return "'" . $value;
      }
      return $value;
    }, $values);
    fputcsv($stream, $values, ',', '"', '');
  }

  /**
   * Loads summary, source, user, and event rows for one period.
   */
  protected function loadReport($since, $event_limit = 50) {
    if (!$this->database->schema()->tableExists('moody_ai_assistant_usage_log')) {
      return [
        'summary' => ['requests' => 0, 'users' => 0, 'successes' => 0, 'items_affected' => 0, 'tokens_used' => 0, 'targets' => 0, 'needs_review' => 0],
        'sources' => [],
        'users' => [],
        'events' => [],
      ];
    }

    $has_outcomes = $this->database->schema()->fieldExists('moody_ai_assistant_usage_log', 'operation');
    $summary_query = $this->database->select('moody_ai_assistant_usage_log', 'l');
    $this->applySince($summary_query, $since);
    $summary_query->addExpression('COUNT(*)', 'requests');
    $summary_query->addExpression('COUNT(DISTINCT uid)', 'users');
    $summary_query->addExpression("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END)", 'successes');
    $summary_query->addExpression($has_outcomes ? 'COALESCE(SUM(items_affected), 0)' : '0', 'items_affected');
    $summary_query->addExpression('COALESCE(SUM(tokens_used), 0)', 'tokens_used');
    $summary_query->addExpression('COALESCE(SUM(needs_review), 0)', 'needs_review');
    $summary = $summary_query->execute()->fetchAssoc() ?: [];

    $target_query = $this->database->select('moody_ai_assistant_usage_log', 'l');
    $this->applySince($target_query, $since);
    $target_query->fields('l', ['target_entity_type', 'target_entity_id']);
    $target_query->condition('target_entity_id', 0, '>');
    $target_query->groupBy('target_entity_type');
    $target_query->groupBy('target_entity_id');
    $summary['targets'] = count($target_query->execute()->fetchAll());

    $source_query = $this->database->select('moody_ai_assistant_usage_log', 'l');
    $this->applySince($source_query, $since);
    $source_query->fields('l', ['source']);
    $source_query->addExpression('COUNT(*)', 'requests');
    $source_query->addExpression("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END)", 'successes');
    $source_query->addExpression($has_outcomes ? 'COALESCE(SUM(items_affected), 0)' : '0', 'items_affected');
    $source_query->addExpression('COALESCE(SUM(tokens_used), 0)', 'tokens_used');
    $source_query->groupBy('source');
    $source_query->orderBy('requests', 'DESC');
    $sources = array_map('get_object_vars', $source_query->execute()->fetchAll());

    $user_query = $this->database->select('moody_ai_assistant_usage_log', 'l');
    $this->applySince($user_query, $since);
    $user_query->fields('l', ['uid']);
    $user_query->addExpression('COUNT(*)', 'requests');
    $user_query->addExpression("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END)", 'successes');
    $user_query->addExpression("SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END)", 'partials');
    $user_query->addExpression("SUM(CASE WHEN status NOT IN ('success', 'partial') THEN 1 ELSE 0 END)", 'errors');
    $user_query->addExpression($has_outcomes ? 'COALESCE(SUM(items_affected), 0)' : '0', 'items_affected');
    $user_query->addExpression('COALESCE(SUM(tokens_used), 0)', 'tokens_used');
    $user_query->addExpression('MAX(created)', 'last_used');
    $user_query->groupBy('uid');
    $user_query->orderBy('requests', 'DESC');
    $users = array_map('get_object_vars', $user_query->execute()->fetchAll());
    $accounts = $this->entityManager->getStorage('user')->loadMultiple(array_column($users, 'uid'));
    $role_entities = $this->entityManager->getStorage('user_role')->loadMultiple();
    foreach ($users as &$row) {
      $account = $accounts[$row['uid']] ?? NULL;
      $row['user'] = $account ? $account->getDisplayName() : $this->t('Deleted user @uid', ['@uid' => $row['uid']]);
      $row['roles'] = [];
      foreach ($account ? $account->getRoles(TRUE) : [] as $role_id) {
        if (isset($role_entities[$role_id])) {
          $row['roles'][] = $role_entities[$role_id]->label();
        }
      }
      $row['roles'] = $row['roles'] ?: [(string) $this->t('Authenticated user')];
    }
    unset($row);

    $events = [];
    if ($event_limit !== 0) {
      $event_query = $this->database->select('moody_ai_assistant_usage_log', 'l');
      $this->applySince($event_query, $since);
      $event_fields = ['id', 'uid', 'target_entity_type', 'target_entity_id', 'target_entity_label', 'tokens_used', 'status', 'source', 'review_flags', 'created'];
      if ($has_outcomes) {
        $event_fields[] = 'operation';
        $event_fields[] = 'items_affected';
      }
      $event_query->fields('l', $event_fields);
      $event_query->orderBy('created', 'DESC');
      if (is_int($event_limit) && $event_limit > 0) {
        $event_query->range(0, $event_limit);
      }
      $events = array_map('get_object_vars', $event_query->execute()->fetchAll());
      $event_accounts = $this->entityManager->getStorage('user')->loadMultiple(array_unique(array_column($events, 'uid')));
      foreach ($events as &$row) {
        $row += ['operation' => '', 'items_affected' => 0];
        $row['user'] = isset($event_accounts[$row['uid']]) ? $event_accounts[$row['uid']]->getDisplayName() : $this->t('Deleted user @uid', ['@uid' => $row['uid']]);
      }
      unset($row);
    }

    return [
      'summary' => $summary + ['requests' => 0, 'users' => 0, 'successes' => 0, 'items_affected' => 0, 'tokens_used' => 0, 'targets' => 0, 'needs_review' => 0],
      'sources' => $sources,
      'users' => $users,
      'events' => $events,
    ];
  }

  /**
   * Applies the optional reporting start timestamp.
   */
  protected function applySince($query, $since) {
    if ($since > 0) {
      $query->condition('created', (int) $since, '>=');
    }
  }

  /**
   * Resolves the allowlisted reporting period.
   */
  protected function resolvePeriod(Request $request) {
    $period = (string) $request->query->get('days', '30');
    return isset(self::PERIODS[$period]) ? $period : '30';
  }

  /**
   * Formats one source machine name for people.
   */
  protected function sourceLabel($source) {
    return match ((string) $source) {
      'ckeditor' => (string) $this->t('CKEditor assistant'),
      'assistant', 'widget' => (string) $this->t('Layout Builder assistant'),
      default => $this->machineLabel($source),
    };
  }

  /**
   * Formats a normalized machine name for people.
   */
  protected function machineLabel($value) {
    $value = trim(str_replace('_', ' ', (string) $value));
    return $value === '' ? (string) $this->t('General assistance') : ucfirst($value);
  }

}
