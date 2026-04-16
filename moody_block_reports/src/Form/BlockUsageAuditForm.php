<?php

namespace Drupal\moody_block_reports\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Utility\TableSort;
use Drupal\Component\Utility\Html;
use Drupal\moody_block_reports\BlockUsageAudit;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form for running Layout Builder block audits.
 */
class BlockUsageAuditForm extends FormBase {

  /**
   * The temp store key for saved results.
   */
  const REPORT_KEY = 'latest_block_usage_report';

  /**
   * Constructs the form.
   */
  public function __construct(
    protected BlockUsageAudit $audit,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected DateFormatterInterface $dateFormatter
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('moody_block_reports.audit'),
      $container->get('tempstore.private'),
      $container->get('date.formatter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'moody_block_reports_block_usage_audit_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'moody_block_reports/admin';

    $form['description'] = [
      '#markup' => '<p>Run a batch audit of Layout Builder block usage across Basic Pages, Moody Standard Pages, Landing Pages, and Feature Pages.</p>',
    ];

    $form['bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types to audit'),
      '#options' => [
        'page' => $this->t('Basic Page'),
        'moody_standard_page' => $this->t('Standard Page'),
        'moody_landing_page' => $this->t('Landing Page'),
        'moody_feature_page' => $this->t('Feature Page'),
      ],
      '#default_value' => [
        'page',
        'moody_standard_page',
        'moody_landing_page',
        'moody_feature_page',
      ],
      '#required' => TRUE,
    ];

    $form['include_unpublished'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include unpublished content'),
      '#default_value' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run block usage audit'),
      '#button_type' => 'primary',
    ];

    $report = $this->getTempStore()->get(static::REPORT_KEY);
    if (!empty($report)) {
      $form['report_filters'] = [
        '#type' => 'details',
        '#title' => $this->t('Report filters'),
        '#open' => TRUE,
      ];

      $form['report_filters']['block_text_contains'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Block text contains'),
        '#default_value' => (string) \Drupal::request()->query->get('block_text_contains', ''),
        '#description' => $this->t('Filter the current report by block label, machine name, plugin ID, or source text.'),
      ];

      $form['report_filters']['actions'] = [
        '#type' => 'actions',
      ];

      $form['report_filters']['actions']['apply_filter'] = [
        '#type' => 'submit',
        '#value' => $this->t('Apply filter'),
        '#submit' => ['::applyReportFilter'],
        '#limit_validation_errors' => [],
      ];

      $form['report_filters']['actions']['clear_filter'] = [
        '#type' => 'submit',
        '#value' => $this->t('Clear filter'),
        '#submit' => ['::clearReportFilter'],
        '#limit_validation_errors' => [],
      ];

      $form['report'] = $this->buildReport($report);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $bundles = array_values(array_filter($form_state->getValue('bundles')));
    if (!$bundles) {
      $form_state->setErrorByName('bundles', $this->t('Select at least one content type to audit.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $bundles = array_values(array_filter($form_state->getValue('bundles')));
    $include_unpublished = (bool) $form_state->getValue('include_unpublished');
    $node_ids = $this->audit->getNodeIds($bundles, $include_unpublished);

    if (!$node_ids) {
      $this->getTempStore()->set(static::REPORT_KEY, [
        'generated' => \Drupal::time()->getRequestTime(),
        'bundles' => $bundles,
        'include_unpublished' => $include_unpublished,
        'audited_nodes' => 0,
        'blocks' => [],
      ]);
      $this->messenger()->addStatus($this->t('No matching nodes were found for the selected audit scope.'));
      $form_state->setRedirect('moody_block_reports.audit_form');
      return;
    }

    $operations = [];
    foreach (array_chunk($node_ids, 25) as $chunk) {
      $operations[] = [
        [static::class, 'processBatchChunk'],
        [$chunk, $bundles, $include_unpublished],
      ];
    }

    $batch = [
      'title' => $this->t('Auditing block usage'),
      'operations' => $operations,
      'finished' => [static::class, 'finishBatch'],
      'init_message' => $this->t('Starting block usage audit.'),
      'progress_message' => $this->t('Processed @current of @total batches.'),
      'error_message' => $this->t('The block usage audit encountered an error.'),
    ];

    $store = $this->getTempStore();
    $store->delete(static::REPORT_KEY);

    batch_set($batch);
  }

  /**
   * Applies report filter query parameters.
   */
  public function applyReportFilter(array &$form, FormStateInterface $form_state) {
    $query = TableSort::getQueryParameters(\Drupal::request());
    $filter = trim((string) $form_state->getValue('block_text_contains', ''));

    if ($filter !== '') {
      $query['block_text_contains'] = $filter;
    }

    $form_state->setRedirect('moody_block_reports.audit_form', [], ['query' => $query]);
  }

  /**
   * Clears report filter query parameters.
   */
  public function clearReportFilter(array &$form, FormStateInterface $form_state) {
    $query = TableSort::getQueryParameters(\Drupal::request());
    unset($query['block_text_contains']);
    $form_state->setRedirect('moody_block_reports.audit_form', [], ['query' => $query]);
  }

  /**
   * Processes a chunk of nodes for the batch.
   *
   * @param int[] $node_ids
   *   The node IDs in this chunk.
   * @param string[] $bundles
   *   The selected node bundles.
   * @param bool $include_unpublished
   *   TRUE when unpublished nodes were included.
   * @param array $context
   *   The batch sandbox context.
   */
  public static function processBatchChunk(array $node_ids, array $bundles, $include_unpublished, array &$context) {
    if (!isset($context['results']['blocks'])) {
      $context['results'] = [
        'bundles' => $bundles,
        'include_unpublished' => (bool) $include_unpublished,
        'audited_nodes' => 0,
        'blocks' => [],
      ];
    }

    $results = &$context['results'];
    $audit = \Drupal::service('moody_block_reports.audit');
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $nodes = $storage->loadMultiple($node_ids);

    foreach ($nodes as $node) {
      $audit->auditNode($node, $results);
      $results['audited_nodes']++;
    }

    $context['message'] = \Drupal::translation()->translate('Audited @count nodes so far.', [
      '@count' => $results['audited_nodes'],
    ]);
  }

  /**
   * Finalizes the batch and stores the report for the current user.
   *
   * @param bool $success
   *   TRUE if the batch completed successfully.
   * @param array $results
   *   The batch results.
   * @param array $operations
   *   Remaining operations, if any.
   */
  public static function finishBatch($success, array $results, array $operations) {
    if (!$success) {
      \Drupal::messenger()->addError(t('The block usage audit did not complete.'));
      return;
    }

    $report = \Drupal::service('moody_block_reports.audit')->finalizeResults($results);
    \Drupal::service('tempstore.private')
      ->get('moody_block_reports')
      ->set(static::REPORT_KEY, $report);

    \Drupal::messenger()->addStatus(t('Block usage audit complete. Found @count distinct blocks.', [
      '@count' => count($report['blocks']),
    ]));
  }

  /**
   * Builds the saved report render array.
   *
   * @param array $report
   *   The saved report data.
   *
   * @return array
   *   A render array.
   */
  protected function buildReport(array $report) {
    $build = [
      '#type' => 'container',
    ];

    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['moody-block-reports-summary']],
    ];

    $bundle_labels = [];
    foreach ($report['bundles'] as $bundle) {
      $bundle_labels[] = match ($bundle) {
        'page' => 'Basic Page',
        'moody_standard_page' => 'Standard Page',
        'moody_landing_page' => 'Landing Page',
        'moody_feature_page' => 'Feature Page',
        default => $bundle,
      };
    }

    $build['summary']['generated'] = [
      '#markup' => '<p><strong>Last run:</strong> ' . $this->dateFormatter->format($report['generated'], 'custom', 'F j, Y g:i a') . '</p>',
    ];
    $build['summary']['scope'] = [
      '#markup' => '<p><strong>Scope:</strong> ' . implode(', ', $bundle_labels) . '</p>',
    ];
    $build['summary']['published'] = [
      '#markup' => '<p><strong>Includes unpublished:</strong> ' . ($report['include_unpublished'] ? 'Yes' : 'No') . '</p>',
    ];
    $build['summary']['audited'] = [
      '#markup' => '<p><strong>Nodes audited:</strong> ' . $report['audited_nodes'] . '</p>',
    ];

    if (empty($report['blocks'])) {
      $build['empty'] = [
        '#markup' => '<p>No Layout Builder block usages were found for the last audit run.</p>',
      ];
      return $build;
    }

    $header = $this->buildReportHeader();
    $blocks = $this->filterAndSortBlocks($report['blocks'] ?? [], $header);

    $rows = [];
    foreach ($blocks as $block) {
      $plugin_id = (string) ($block['plugin_id'] ?? 'unknown');
      $source = (string) ($block['source'] ?? 'Unknown');
      $view_modes = is_array($block['view_modes'] ?? NULL) ? array_values(array_filter($block['view_modes'])) : [];
      $pages_count = (int) ($block['pages_count'] ?? 0);
      $placements = (int) ($block['placements'] ?? 0);
      $usage_items = is_array($block['usage_items'] ?? NULL) ? $block['usage_items'] : [];

      if (!$usage_items && is_array($block['pages'] ?? NULL)) {
        foreach ($block['pages'] as $page) {
          $usage_items[] = [
            'instance_label' => (string) ($block['label'] ?? $plugin_id),
            'view_mode' => '',
            'nid' => (int) ($page['nid'] ?? 0),
            'title' => (string) ($page['title'] ?? 'Untitled'),
            'bundle' => (string) ($page['bundle'] ?? 'Unknown'),
          ];
        }
      }

      $usage_links = [];
      foreach ($usage_items as $item) {
        $instance_label = trim((string) ($item['instance_label'] ?? $plugin_id));
        $view_mode = trim((string) ($item['view_mode'] ?? ''));
        $title = (string) ($item['title'] ?? 'Untitled');
        $nid = (int) ($item['nid'] ?? 0);
        $bundle = (string) ($item['bundle'] ?? 'Unknown');

        $link = $nid > 0
          ? Link::createFromRoute($title, 'entity.node.canonical', ['node' => $nid])->toRenderable()
          : ['#plain_text' => $title];
        $usage_prefix = Html::escape($instance_label);
        if ($view_mode !== '') {
          $usage_prefix .= ' [' . Html::escape($view_mode) . ']';
        }
        $usage_links[] = [
          '#markup' => $usage_prefix . ' - ',
          'link' => $link,
          'bundle' => [
            '#markup' => ' <span>(' . Html::escape($bundle) . ')</span>',
          ],
        ];
      }

      $rows[] = [
        'data' => [
          [
            'data' => [
              '#markup' => '<code>' . Html::escape($plugin_id) . '</code>',
            ],
          ],
          ['data' => ['#plain_text' => $source]],
          ['data' => ['#plain_text' => implode(', ', $view_modes)]],
          ['data' => ['#plain_text' => (string) $pages_count]],
          ['data' => ['#plain_text' => (string) $placements]],
          [
            'data' => [
              '#type' => 'details',
              '#title' => $this->t('@count placements', ['@count' => $placements]),
              '#open' => FALSE,
              'list' => [
                '#theme' => 'item_list',
                '#items' => $usage_links,
              ],
            ],
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No block usage data found for the current filter.'),
      '#attributes' => ['class' => ['moody-block-reports-table']],
    ];

    return $build;
  }

  /**
   * Builds the report table header.
   *
   * @return array
   *   The table header definition.
   */
  protected function buildReportHeader() {
    return [
      $this->t('Block'),
      $this->t('Source'),
      [
        'data' => $this->t('View mode'),
        'field' => 'view_mode',
      ],
      [
        'data' => $this->t('Pages'),
        'field' => 'pages_count',
        'sort' => TableSort::DESC,
      ],
      [
        'data' => $this->t('Placements'),
        'field' => 'placements',
      ],
      $this->t('Usages'),
    ];
  }

  /**
   * Filters and sorts block rows for display.
   *
   * @param array $blocks
   *   The saved report rows.
   * @param array $header
   *   The report table header.
   *
   * @return array
   *   Filtered and sorted rows.
   */
  protected function filterAndSortBlocks(array $blocks, array $header) {
    $blocks = $this->consolidateBlocks($blocks);

    $filter = trim((string) \Drupal::request()->query->get('block_text_contains', ''));
    if ($filter !== '') {
      $blocks = array_values(array_filter($blocks, static function (array $block) use ($filter) {
        $haystack = implode(' ', array_filter([
          (string) ($block['label'] ?? ''),
          (string) ($block['machine_name'] ?? ''),
          (string) ($block['plugin_id'] ?? ''),
          (string) ($block['source'] ?? ''),
          (string) ($block['view_mode'] ?? ''),
        ]));

        return stripos($haystack, $filter) !== FALSE;
      }));
    }

    $order = TableSort::getOrder($header, \Drupal::request());
    $direction = TableSort::getSort($header, \Drupal::request());
    $field = $order['sql'] ?: 'pages_count';

    usort($blocks, static function (array $a, array $b) use ($field, $direction) {
      $comparison = match ($field) {
        'placements', 'pages_count' => ((int) ($a[$field] ?? 0)) <=> ((int) ($b[$field] ?? 0)),
        default => strcasecmp((string) ($a[$field] ?? ''), (string) ($b[$field] ?? '')),
      };

      if ($comparison === 0) {
        $comparison = strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
      }

      return $direction === TableSort::DESC ? -$comparison : $comparison;
    });

    return $blocks;
  }

  /**
   * Consolidates duplicate rows by plugin ID.
   *
   * @param array $blocks
   *   Raw saved report rows.
   *
   * @return array
   *   Consolidated rows keyed by plugin type.
   */
  protected function consolidateBlocks(array $blocks) {
    $merged = [];

    foreach ($blocks as $block) {
      $plugin_id = (string) ($block['plugin_id'] ?? 'unknown');
      if (!isset($merged[$plugin_id])) {
        $merged[$plugin_id] = [
          'plugin_id' => $plugin_id,
          'label' => (string) ($block['label'] ?? $plugin_id),
          'source' => (string) ($block['source'] ?? 'Unknown'),
          'machine_name' => (string) ($block['machine_name'] ?? $plugin_id),
          'view_modes' => array_values(array_filter($block['view_modes'] ?? [])),
          'view_mode' => implode(', ', array_values(array_filter($block['view_modes'] ?? []))),
          'pages_count' => 0,
          'placements' => 0,
          'pages' => [],
          'usage_items' => [],
        ];
      }

      $merged[$plugin_id]['placements'] += (int) ($block['placements'] ?? 0);
      foreach ((array) ($block['view_modes'] ?? []) as $view_mode) {
        $view_mode = trim((string) $view_mode);
        if ($view_mode !== '') {
          $merged[$plugin_id]['view_modes'][] = $view_mode;
        }
      }

      foreach (($block['pages'] ?? []) as $page) {
        $nid = (int) ($page['nid'] ?? 0);
        if ($nid > 0) {
          $merged[$plugin_id]['pages'][$nid] = [
            'nid' => $nid,
            'title' => (string) ($page['title'] ?? 'Untitled'),
            'bundle' => (string) ($page['bundle'] ?? 'Unknown'),
          ];
        }
      }

      foreach (($block['usage_items'] ?? []) as $item) {
        $merged[$plugin_id]['usage_items'][] = [
          'instance_label' => (string) ($item['instance_label'] ?? $merged[$plugin_id]['label']),
          'view_mode' => (string) ($item['view_mode'] ?? ''),
          'nid' => (int) ($item['nid'] ?? 0),
          'title' => (string) ($item['title'] ?? 'Untitled'),
          'bundle' => (string) ($item['bundle'] ?? 'Unknown'),
        ];
      }
    }

    foreach ($merged as &$block) {
      $block['pages'] = array_values($block['pages']);
      $block['view_modes'] = array_values(array_unique(array_filter($block['view_modes'] ?? [])));
      $block['view_mode'] = implode(', ', $block['view_modes']);
      $block['pages_count'] = count($block['pages']);

      usort($block['pages'], static function (array $a, array $b) {
        return strcasecmp($a['title'], $b['title']);
      });

      usort($block['usage_items'], static function (array $a, array $b) {
        return [strcasecmp($a['instance_label'], $b['instance_label']), strcasecmp($a['title'], $b['title'])];
      });
    }
    unset($block);

    return array_values($merged);
  }

  /**
   * Gets the report temp store.
   *
   * @return \Drupal\Core\TempStore\PrivateTempStore
   *   The temp store.
   */
  protected function getTempStore() {
    return $this->tempStoreFactory->get('moody_block_reports');
  }

}
