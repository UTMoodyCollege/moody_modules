<?php

declare(strict_types=1);

namespace Drupal\moody_broken_links\Form;

use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\moody_broken_links\BrokenLinksManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Broken-link scan and remediation dashboard.
 */
final class DashboardForm extends FormBase {

  public function __construct(
    private readonly BrokenLinksManager $manager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('moody_broken_links.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'moody_broken_links_dashboard';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $scan = $this->manager->getLatestScan();
    $bundle_options = $this->manager->getBundleOptions();

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Scan selected content types or one specific page for HTTP links in fields and Layout Builder inline blocks. Queue several fixes for a page and apply them in one revision. Fixes stop if the source changed after the scan.') . '</p>',
    ];
    $form['scan'] = [
      '#type' => 'details',
      '#title' => $this->t('Run a scan'),
      '#open' => !$scan,
    ];
    $form['scan']['scan_scope'] = [
      '#type' => 'radios',
      '#title' => $this->t('Scan'),
      '#options' => [
        'bundles' => $this->t('Content types'),
        'node' => $this->t('One specific page'),
      ],
      '#default_value' => 'bundles',
    ];
    $form['scan']['all_bundles'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('All content types'),
      '#default_value' => FALSE,
      '#states' => [
        'visible' => [':input[name="scan_scope"]' => ['value' => 'bundles']],
      ],
    ];
    $form['scan']['bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Selected content types'),
      '#options' => $bundle_options,
      '#default_value' => $scan['bundles'] ?? [],
      '#description' => $this->t('Current revisions and translations are scanned. Per-page Layout Builder overrides are included automatically.'),
      '#states' => [
        'visible' => [
          ':input[name="scan_scope"]' => ['value' => 'bundles'],
          ':input[name="all_bundles"]' => ['checked' => FALSE],
        ],
      ],
    ];
    $form['scan']['node'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Page'),
      '#target_type' => 'node',
      '#description' => $this->t('Choose one page to scan, including its Layout Builder override.'),
      '#states' => [
        'visible' => [':input[name="scan_scope"]' => ['value' => 'node']],
        'required' => [':input[name="scan_scope"]' => ['value' => 'node']],
      ],
    ];
    $form['scan']['actions'] = ['#type' => 'actions'];
    $form['scan']['actions']['start'] = [
      '#type' => 'submit',
      '#value' => $scan ? $this->t('Run a new scan') : $this->t('Run broken-link scan'),
      '#button_type' => 'primary',
      '#validate' => ['::validateScanSelection'],
      '#submit' => ['::startScan'],
      '#disabled' => $scan && $scan['status'] === 'running',
    ];

    if (!$scan) {
      $form['empty'] = ['#markup' => '<p>' . $this->t('No broken-link scan has been run yet.') . '</p>'];
      return $form;
    }

    $form['summary'] = [
      '#type' => 'details',
      '#title' => $this->t('Latest scan'),
      '#open' => TRUE,
    ];
    $form['summary']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Status'),
        $this->t('Started'),
        $this->t('Completed'),
        $this->t('Pages'),
        $this->t('Links'),
        $this->t('Broken'),
        $this->t('Warnings'),
      ],
      '#rows' => [[
        ucfirst((string) $scan['status']),
        $this->dateFormatter->format((int) $scan['started'], 'short'),
        (int) $scan['completed'] ? $this->dateFormatter->format((int) $scan['completed'], 'short') : $this->t('Not yet'),
        $scan['processed_nodes'] . ' / ' . $scan['total_nodes'],
        $scan['total_links'],
        $scan['broken_links'],
        $scan['warning_links'],
      ]],
    ];
    if ($scan['status'] !== 'complete') {
      return $form;
    }

    $request = \Drupal::request();
    $status = (string) $request->query->get('status', 'needs_attention');
    if (!in_array($status, ['needs_attention', 'broken', 'warning', 'ok', 'all'], TRUE)) {
      $status = 'needs_attention';
    }
    $search = trim((string) $request->query->get('search', ''));
    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];
    $form['filters']['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Show'),
      '#options' => [
        'needs_attention' => $this->t('Broken and warnings'),
        'broken' => $this->t('Broken'),
        'warning' => $this->t('Warnings'),
        'ok' => $this->t('Working'),
        'all' => $this->t('All checked links'),
      ],
      '#default_value' => $status,
    ];
    $form['filters']['search'] = [
      '#type' => 'search',
      '#title' => $this->t('Page, field, or URL'),
      '#default_value' => $search,
      '#size' => 36,
    ];
    $form['filters']['apply'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply'),
      '#submit' => ['::applyFilters'],
    ];
    if ($status !== 'needs_attention' || $search !== '') {
      $form['filters']['clear'] = [
        '#type' => 'link',
        '#title' => $this->t('Clear'),
        '#url' => Url::fromRoute('moody_broken_links.dashboard'),
        '#attributes' => ['class' => ['button']],
      ];
    }

    $query = $this->manager->resultQuery((int) $scan['scan_id'], $status, $search)
      ->extend(PagerSelectExtender::class)
      ->limit(50);
    $rows = [];
    foreach ($query->execute() as $result) {
      $page = Link::fromTextAndUrl(
        (string) $result->title ?: $this->t('Node @nid', ['@nid' => $result->nid]),
        Url::fromRoute('entity.node.canonical', ['node' => $result->nid], [
          'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
        ]),
      )->toRenderable();
      $destination = Link::fromTextAndUrl(
        (string) $result->href,
        Url::fromUri((string) $result->absolute_url, [
          'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
        ]),
      )->toRenderable();

      if ((int) $result->remediated) {
        $actions = ['#plain_text' => ucfirst((string) $result->action) . ' — new scan required'];
      }
      else {
        $actions = [
          '#type' => 'container',
          'page' => Link::fromTextAndUrl($this->t('Queue page fixes'), Url::fromRoute('moody_broken_links.page', [
            'scan_id' => $result->scan_id,
            'nid' => $result->nid,
          ]))->toRenderable(),
          'separator_page' => ['#markup' => ' &nbsp; '],
          'revise' => Link::fromTextAndUrl($this->t('Revise link'), Url::fromRoute('moody_broken_links.revise', [
            'result_id' => $result->result_id,
          ]))->toRenderable(),
          'separator' => ['#markup' => ' &nbsp; '],
          'remove' => Link::fromTextAndUrl($this->t('Remove link'), Url::fromRoute('moody_broken_links.remove', [
            'result_id' => $result->result_id,
          ]))->toRenderable(),
        ];
      }
      $status_label = ucfirst((string) $result->result_status);
      if ((int) $result->http_code) {
        $status_label .= ' (' . $result->http_code . ')';
      }
      $link_text = trim((string) $result->link_text);

      $rows[] = [
        'page' => ['data' => $page],
        'source' => ['data' => ['#plain_text' => (string) $result->source_label]],
        'text' => ['data' => ['#plain_text' => $link_text !== '' ? $link_text : '—']],
        'url' => ['data' => $destination],
        'status' => ['data' => ['#plain_text' => $status_label]],
        'message' => ['data' => ['#plain_text' => (string) $result->message]],
        'actions' => ['data' => $actions],
      ];
    }

    $form['results'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Page'),
        $this->t('Source'),
        $this->t('Link text'),
        $this->t('URL'),
        $this->t('Check'),
        $this->t('Details'),
        $this->t('Actions'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No links matched these filters.'),
    ];
    $form['pager'] = ['#type' => 'pager'];
    return $form;
  }

  /**
   * Starts the selected content-type or single-page scan.
   */
  public function startScan(array &$form, FormStateInterface $form_state): void {
    $scope = (string) $form_state->getValue('scan_scope', 'bundles');
    $node_id = $scope === 'node' ? (int) $form_state->getValue('node') : NULL;
    $bundles = (bool) $form_state->getValue('all_bundles')
      ? array_keys($this->manager->getBundleOptions())
      : array_values(array_filter((array) $form_state->getValue('bundles', [])));
    try {
      $scan = $this->manager->prepareScan($bundles, (int) $this->currentUser()->id(), $node_id);
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($exception->getMessage());
      return;
    }

    if (!$scan['node_ids']) {
      $this->manager->finishScan((int) $scan['scan_id']);
      $this->messenger()->addStatus($this->t('The selected content types contain no pages.'));
      return;
    }
    $site_base_url = \Drupal::request()->getSchemeAndHttpHost();
    $operations = [];
    foreach (array_chunk($scan['node_ids'], 5) as $node_ids) {
      $operations[] = [[static::class, 'processNodes'], [$scan['scan_id'], $node_ids, $site_base_url]];
    }
    batch_set([
      'title' => $this->t('Checking content links'),
      'operations' => $operations,
      'finished' => [static::class, 'scanFinished'],
      'init_message' => $this->t('Starting broken-link scan.'),
      'progress_message' => $this->t('Completed @current of @total page batches.'),
      'error_message' => $this->t('The broken-link scan encountered an error.'),
    ]);
  }

  /**
   * Requires either one page or at least one content type.
   */
  public function validateScanSelection(array &$form, FormStateInterface $form_state): void {
    $scope = (string) $form_state->getValue('scan_scope', 'bundles');
    if ($scope === 'node') {
      if (!(int) $form_state->getValue('node')) {
        $form_state->setErrorByName('node', $this->t('Select a page to scan.'));
      }
      return;
    }
    $bundles = array_filter((array) $form_state->getValue('bundles', []));
    if (!(bool) $form_state->getValue('all_bundles') && !$bundles) {
      $form_state->setErrorByName('bundles', $this->t('Select at least one content type or choose all content types.'));
    }
  }

  /**
   * Applies dashboard filters through query parameters.
   */
  public function applyFilters(array &$form, FormStateInterface $form_state): void {
    $query = ['status' => (string) $form_state->getValue('status', 'needs_attention')];
    $search = trim((string) $form_state->getValue('search', ''));
    if ($search !== '') {
      $query['search'] = $search;
    }
    $form_state->setRedirect('moody_broken_links.dashboard', [], ['query' => $query]);
  }

  /**
   * Batch callback for one node chunk.
   */
  public static function processNodes(int $scan_id, array $node_ids, string $site_base_url, array &$context): void {
    \Drupal::service('moody_broken_links.manager')->scanNodes($scan_id, $node_ids, $site_base_url, $context);
  }

  /**
   * Batch completion callback.
   */
  public static function scanFinished(bool $success, array $results, array $operations): void {
    $scan_id = (int) ($results['scan_id'] ?? 0);
    if (!$scan_id) {
      \Drupal::messenger()->addError(t('The scan did not return an ID.'));
      return;
    }
    $manager = \Drupal::service('moody_broken_links.manager');
    if (!$success) {
      $manager->markScanFailed($scan_id);
      \Drupal::messenger()->addError(t('The broken-link scan did not complete.'));
      return;
    }
    $summary = $manager->finishScan($scan_id);
    \Drupal::messenger()->addStatus(t('Broken-link scan complete: @broken broken links and @warnings warnings.', [
      '@broken' => $summary['broken_links'],
      '@warnings' => $summary['warning_links'],
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

}
