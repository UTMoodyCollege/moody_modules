<?php

declare(strict_types=1);

namespace Drupal\moody_media_remediation\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\moody_media_remediation\MediaRemediationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reviews and consolidates one exact duplicate group.
 */
final class DuplicateGroupForm extends FormBase {

  private int $scanId;
  private string $sha256;

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
    return 'moody_media_remediation_duplicate_group';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?string $scan_id = NULL,
    ?string $sha256 = NULL,
  ): array {
    $this->scanId = (int) $scan_id;
    $this->sha256 = (string) $sha256;
    $group = $this->manager->getGroup($this->scanId, $this->sha256);
    if (count($group) < 2) {
      throw new NotFoundHttpException('This exact duplicate group is no longer available.');
    }

    $form['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to media remediation'),
      '#url' => Url::fromRoute('moody_media_remediation.dashboard'),
    ];
    $form['explanation'] = [
      '#markup' => '<p>' . $this->t('Every file below had the same SHA-256 during the scan. Consolidation rechecks the bytes, rewrites only current managed file/image fields, creates content revisions when supported, and retains every original file and binary.') . '</p>',
    ];

    $options = [];
    $rows = [];
    $all_exist = TRUE;
    foreach ($group as $fid => $item) {
      $usage_data = json_decode((string) $item['usage_data'], TRUE) ?: ['core' => [], 'tracked' => []];
      $usage_lines = [];
      foreach ($usage_data['core'] ?? [] as $usage) {
        $usage_lines[] = sprintf(
          '%s:%s:%s (%d)',
          $usage['module'],
          $usage['source_type'],
          $usage['source_id'],
          $usage['count'],
        );
      }
      foreach ($usage_data['tracked'] ?? [] as $usage) {
        $usage_lines[] = sprintf(
          '%s:%s:%s:%s (%d)',
          $usage['method'],
          $usage['source_type'],
          $usage['source_id'],
          $usage['field_name'],
          $usage['count'],
        );
      }

      $file_link = ['#plain_text' => $item['filename']];
      if ((int) $item['file_exists']) {
        try {
          $file_link = Link::fromTextAndUrl(
            $item['filename'],
            Url::fromUri($this->fileUrlGenerator->generateAbsoluteString($item['uri']), [
              'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
            ]),
          )->toRenderable();
        }
        catch (\Throwable) {
          // Leave invalid stream URIs as plain text.
        }
      }
      else {
        $all_exist = FALSE;
      }

      $options[$fid] = $this->t('@name (file @fid, @uses detected uses)', [
        '@name' => $item['filename'],
        '@fid' => $fid,
        '@uses' => (int) $item['core_usage'] + (int) $item['tracked_usage'],
      ]);
      $rows[] = [
        'fid' => $fid,
        'file' => ['data' => $file_link],
        'uri' => $item['uri'],
        'size' => ByteSizeMarkup::create((int) $item['filesize']),
        'changed' => $this->dateFormatter->format((int) $item['changed'], 'short'),
        'usage' => $usage_lines ? implode("\n", $usage_lines) : $this->t('No tracked usage'),
        'exists' => (int) $item['file_exists'] ? $this->t('Yes') : $this->t('No'),
      ];
    }

    $form['files'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('File ID'),
        $this->t('File'),
        $this->t('URI'),
        $this->t('Size'),
        $this->t('Changed'),
        $this->t('Known usage'),
        $this->t('Exists'),
      ],
      '#rows' => $rows,
    ];

    $default_canonical = (int) array_key_first($group);
    $form['canonical_fid'] = [
      '#type' => 'radios',
      '#title' => $this->t('Canonical file to keep using'),
      '#options' => $options,
      '#default_value' => $default_canonical,
      '#required' => TRUE,
    ];
    $form['duplicate_fids'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Files whose managed references should be consolidated'),
      '#options' => $options,
      '#default_value' => array_map('intval', array_keys($group)),
      '#description' => $this->t('The selected canonical file is automatically excluded. Uncheck any other file you do not want to process.'),
    ];
    $form['acknowledge'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand that this changes current managed references but does not delete files.'),
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Consolidate selected references'),
      '#button_type' => 'primary',
      '#disabled' => !$all_exist,
    ];
    if (!$all_exist) {
      $form['warning'] = [
        '#type' => 'status_messages',
        '#message_list' => [
          'warning' => [$this->t('One or more binaries are unavailable. Consolidation is disabled until a new scan can hash every file.')],
        ],
      ];
    }

    $operations = $this->manager->getOperations(10, $this->scanId, $this->sha256);
    if ($operations) {
      $operation_rows = [];
      foreach ($operations as $operation) {
        $action = $operation['status'] === 'applied'
          ? Link::fromTextAndUrl($this->t('Undo'), Url::fromRoute('moody_media_remediation.undo', [
            'operation_id' => $operation['operation_id'],
          ]))
          : '—';
        $operation_rows[] = [
          $operation['operation_id'],
          $this->dateFormatter->format((int) $operation['created'], 'short'),
          $operation['canonical_fid'],
          implode(', ', json_decode((string) $operation['duplicate_fids'], TRUE) ?: []),
          ucfirst((string) $operation['status']),
          ['data' => $action],
        ];
      }
      $form['operations'] = [
        '#type' => 'table',
        '#caption' => $this->t('Operations for this exact group'),
        '#header' => [
          $this->t('Operation'),
          $this->t('Date'),
          $this->t('Canonical'),
          $this->t('Consolidated'),
          $this->t('Status'),
          $this->t('Action'),
        ],
        '#rows' => $operation_rows,
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $canonical_fid = (int) $form_state->getValue('canonical_fid');
    $duplicate_fids = array_values(array_filter(array_map(
      'intval',
      (array) $form_state->getValue('duplicate_fids', []),
    )));
    $duplicate_fids = array_values(array_diff($duplicate_fids, [$canonical_fid]));
    if (!$duplicate_fids) {
      $form_state->setErrorByName('duplicate_fids', $this->t('Select at least one non-canonical file.'));
    }
    $form_state->setValue('duplicate_fids', $duplicate_fids);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $result = $this->manager->consolidateGroup(
        $this->scanId,
        $this->sha256,
        (int) $form_state->getValue('canonical_fid'),
        (array) $form_state->getValue('duplicate_fids'),
      );
      if (!$result['operation_id']) {
        $this->messenger()->addWarning($this->t('No current managed references pointed at the selected duplicate files. Nothing changed.'));
      }
      else {
        $this->messenger()->addStatus($this->t('Operation @operation updated @fields fields on @entities entities. Every file and binary was retained.', [
          '@operation' => $result['operation_id'],
          '@fields' => $result['changed_fields'],
          '@entities' => $result['changed_entities'],
        ]));
      }
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($exception->getMessage());
    }
    $form_state->setRedirect('moody_media_remediation.group', [
      'scan_id' => $this->scanId,
      'sha256' => $this->sha256,
    ]);
  }

}
