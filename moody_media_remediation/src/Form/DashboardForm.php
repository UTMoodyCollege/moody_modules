<?php

declare(strict_types=1);

namespace Drupal\moody_media_remediation\Form;

use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\moody_media_remediation\MediaRemediationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Media remediation scan and candidate dashboard.
 */
final class DashboardForm extends FormBase {

  public function __construct(
    private readonly MediaRemediationManager $manager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('moody_media_remediation.manager'),
      $container->get('date.formatter'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'moody_media_remediation_dashboard';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $scan = $this->manager->getLatestScan();

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Scan this site for missing, unused, and byte-identical managed files. Exact duplicate consolidation changes only known current Drupal references. Redundant file entities and binaries can optionally be deleted after a guarded review.') . '</p>',
    ];
    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => -20,
    ];
    $form['actions']['scan'] = [
      '#type' => 'submit',
      '#value' => $scan ? $this->t('Run a new scan') : $this->t('Run media scan'),
      '#button_type' => 'primary',
      '#submit' => ['::startScan'],
      '#disabled' => $scan && $scan['status'] === 'running',
    ];

    if (!$scan) {
      $form['empty'] = [
        '#markup' => '<p>' . $this->t('No scan has been run yet.') . '</p>',
      ];
      return $form;
    }

    $status = ucfirst((string) $scan['status']);
    $started = $this->dateFormatter->format((int) $scan['started'], 'short');
    $completed = (int) $scan['completed']
      ? $this->dateFormatter->format((int) $scan['completed'], 'short')
      : $this->t('Not yet');
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
        $this->t('Processed'),
        $this->t('Existing'),
        $this->t('Exact groups'),
        $this->t('Duplicate files'),
        $this->t('Unused candidates'),
        $this->t('Missing'),
      ],
      '#rows' => [[
        $status,
        $started,
        $completed,
        $scan['processed_files'] . ' / ' . $scan['total_files'],
        $scan['existing_files'],
        $scan['duplicate_groups'],
        $scan['duplicate_files'],
        $scan['unused_files'],
        $scan['missing_files'],
      ]],
    ];

    if ($scan['status'] !== 'complete') {
      return $form;
    }

    $request = \Drupal::request();
    $mode = (string) $request->query->get('mode', 'duplicates');
    if (!in_array($mode, ['duplicates', 'unused', 'missing', 'all'], TRUE)) {
      $mode = 'duplicates';
    }
    $search = trim((string) $request->query->get('search', ''));

    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];
    $form['filters']['mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Show'),
      '#options' => [
        'duplicates' => $this->t('Exact duplicates'),
        'unused' => $this->t('Unused candidates'),
        'missing' => $this->t('Missing files'),
        'all' => $this->t('All managed files'),
      ],
      '#default_value' => $mode,
    ];
    $form['filters']['search'] = [
      '#type' => 'search',
      '#title' => $this->t('Filename or URI'),
      '#default_value' => $search,
      '#size' => 32,
    ];
    $form['filters']['apply'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply'),
      '#submit' => ['::applyFilters'],
    ];
    if ($mode !== 'duplicates' || $search !== '') {
      $form['filters']['clear'] = [
        '#type' => 'link',
        '#title' => $this->t('Clear'),
        '#url' => Url::fromRoute('moody_media_remediation.dashboard'),
        '#attributes' => ['class' => ['button']],
      ];
    }

    $query = $this->manager->candidateQuery((int) $scan['scan_id'], $mode, $search)
      ->extend(PagerSelectExtender::class)
      ->limit(50);
    $rows = [];
    foreach ($query->execute() as $item) {
      $preview = ['#markup' => '—'];
      if ((int) $item->file_exists && str_starts_with((string) $item->mime_type, 'image/')) {
        $preview = [
          '#theme' => 'image',
          '#uri' => (string) $item->uri,
          '#alt' => (string) $item->filename,
          '#attributes' => [
            'loading' => 'lazy',
            'style' => 'max-height:72px;max-width:96px;width:auto;',
          ],
        ];
      }

      $filename = ['#plain_text' => (string) $item->filename];
      if ((int) $item->file_exists) {
        try {
          $filename = Link::fromTextAndUrl(
            (string) $item->filename,
            Url::fromUri($this->fileUrlGenerator->generateAbsoluteString((string) $item->uri), [
              'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
            ]),
          )->toRenderable();
        }
        catch (\Throwable) {
          // An invalid stream wrapper remains visible as plain text.
        }
      }

      $group = '—';
      if ((int) $item->group_size > 1) {
        $group = Link::fromTextAndUrl(
          $this->t('Review @count', ['@count' => $item->group_size]),
          Url::fromRoute('moody_media_remediation.group', [
            'scan_id' => $scan['scan_id'],
            'sha256' => $item->sha256,
          ]),
        );
      }

      $rows[] = [
        'preview' => ['data' => $preview],
        'fid' => $item->fid,
        'file' => ['data' => $filename],
        'size' => ByteSizeMarkup::create((int) $item->filesize),
        'changed' => $this->dateFormatter->format((int) $item->changed, 'short'),
        'usage' => $this->t('Core @core / tracked @tracked', [
          '@core' => $item->core_usage,
          '@tracked' => $item->tracked_usage,
        ]),
        'exists' => (int) $item->file_exists ? $this->t('Yes') : $this->t('No'),
        'group' => ['data' => $group],
      ];
    }

    $form['candidates'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Preview'),
        $this->t('File ID'),
        $this->t('File'),
        $this->t('Size'),
        $this->t('Changed'),
        $this->t('Detected usage'),
        $this->t('Exists'),
        $this->t('Exact group'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No files matched these filters.'),
    ];
    $form['pager'] = ['#type' => 'pager'];

    $operations = $this->manager->getOperations();
    if ($operations) {
      $operation_rows = [];
      foreach ($operations as $operation) {
        $duplicate_fids = json_decode((string) $operation['duplicate_fids'], TRUE, 512, JSON_THROW_ON_ERROR);
        $action = $operation['status'] === 'applied'
          ? Link::fromTextAndUrl($this->t('Undo'), Url::fromRoute('moody_media_remediation.undo', [
            'operation_id' => $operation['operation_id'],
          ]))
          : '—';
        $operation_rows[] = [
          $operation['operation_id'],
          $this->dateFormatter->format((int) $operation['created'], 'short'),
          $operation['canonical_fid'],
          implode(', ', $duplicate_fids),
          ucfirst((string) $operation['status']),
          ['data' => $action],
        ];
      }
      $form['operations'] = [
        '#type' => 'details',
        '#title' => $this->t('Recent operations'),
        '#open' => TRUE,
      ];
      $form['operations']['table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Operation'),
          $this->t('Date'),
          $this->t('Canonical file'),
          $this->t('Consolidated files'),
          $this->t('Status'),
          $this->t('Action'),
        ],
        '#rows' => $operation_rows,
      ];
    }

    return $form;
  }

  /**
   * Starts the metadata and hash batch passes.
   */
  public function startScan(array &$form, FormStateInterface $form_state): void {
    try {
      $scan = $this->manager->prepareScan((int) $this->currentUser()->id());
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($exception->getMessage());
      return;
    }

    $operations = [];
    foreach (array_chunk($scan['file_ids'], 250) as $file_ids) {
      $operations[] = [[static::class, 'processMetadata'], [$scan['scan_id'], $file_ids]];
    }
    foreach (array_chunk($scan['hash_file_ids'], 50) as $file_ids) {
      $operations[] = [[static::class, 'processHashes'], [$scan['scan_id'], $file_ids]];
    }
    $batch = [
      'title' => $this->t('Scanning managed files'),
      'operations' => $operations,
      'finished' => [static::class, 'scanFinished'],
      'init_message' => $this->t('Starting media scan.'),
      'progress_message' => $this->t('Completed @current of @total scan batches.'),
      'error_message' => $this->t('The media scan encountered an error.'),
    ];
    batch_set($batch);
  }

  /**
   * Applies dashboard filters through query parameters.
   */
  public function applyFilters(array &$form, FormStateInterface $form_state): void {
    $query = ['mode' => (string) $form_state->getValue('mode', 'duplicates')];
    $search = trim((string) $form_state->getValue('search', ''));
    if ($search !== '') {
      $query['search'] = $search;
    }
    $form_state->setRedirect('moody_media_remediation.dashboard', [], ['query' => $query]);
  }

  /**
   * Batch metadata callback.
   */
  public static function processMetadata(int $scan_id, array $file_ids, array &$context): void {
    \Drupal::service('moody_media_remediation.manager')->scanMetadata($scan_id, $file_ids, $context);
  }

  /**
   * Batch hash callback.
   */
  public static function processHashes(int $scan_id, array $file_ids, array &$context): void {
    \Drupal::service('moody_media_remediation.manager')->scanHashes($scan_id, $file_ids, $context);
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
    $manager = \Drupal::service('moody_media_remediation.manager');
    if (!$success) {
      $manager->markScanFailed($scan_id);
      \Drupal::messenger()->addError(t('The media scan did not complete.'));
      return;
    }
    $summary = $manager->finishScan($scan_id);
    \Drupal::messenger()->addStatus(t('Media scan complete: @groups exact duplicate groups and @unused unused candidates.', [
      '@groups' => $summary['duplicate_groups'],
      '@unused' => $summary['unused_files'],
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

}
