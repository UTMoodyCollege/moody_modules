<?php

declare(strict_types=1);

namespace Drupal\moody_ai_assistant\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\entity_usage\EntityUsageInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\moody_ai_assistant\Service\AIAssetCreator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists and removes private Moody AI uploads owned by the current user.
 */
final class PrivateUploadsForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly FileUsageInterface $fileUsage,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly AIAssetCreator $assetCreator,
    private readonly ?EntityUsageInterface $entityUsage,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('file.usage'),
      $container->get('date.formatter'),
      $container->get('moody_ai_assistant.asset_creator'),
      $container->has('entity_usage.usage') ? $container->get('entity_usage.usage') : NULL,
    );
  }

  public function getFormId(): string {
    return 'moody_ai_assistant_private_uploads';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $rows = [];
    $has_removable = FALSE;
    foreach ($this->loadUploads() as $file) {
      $summary = $this->assetCreator->buildPrivateUploadSummary($file);
      $usage = $this->buildUsage($file);
      $in_use = $usage['in_use'];
      $has_removable = $has_removable || !$in_use;
      $rows[(int) $file->id()] = [
        '#disabled' => $in_use,
        '#attributes' => [
          'class' => [$in_use ? 'is-in-use' : 'is-removable'],
        ],
        'preview' => [
          'data' => $this->buildPreview($summary),
          'class' => ['ai-moody-private-uploads__preview-cell'],
        ],
        'file' => [
          'data' => $this->buildFileDetails($summary),
          'class' => ['ai-moody-private-uploads__file-cell'],
        ],
        'uploaded' => $this->dateFormatter->format((int) $file->getCreatedTime(), 'short'),
        'size' => $this->formatFileSize((int) $file->getSize()),
        'usage' => [
          'data' => $usage['build'],
          'class' => ['ai-moody-private-uploads__usage-cell'],
        ],
      ];
    }

    $form['#attributes']['class'][] = 'ai-moody-private-uploads';
    $form['#attached']['library'][] = 'moody_ai_assistant/private_uploads';
    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-moody-private-uploads__intro']],
      'copy' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('These private files were attached to Moody AI by your account. Preview or open them here, see the Media and content that use them, or remove files that Drupal is no longer using.'),
      ],
      'help' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('To remove an in-use upload, remove it from the linked content first, then delete its Media item. The file will become selectable here.'),
      ],
    ];
    $form['uploads'] = [
      '#type' => 'tableselect',
      '#header' => [
        'preview' => $this->t('Preview'),
        'file' => $this->t('File'),
        'uploaded' => $this->t('Uploaded'),
        'size' => $this->t('Size'),
        'usage' => $this->t('Usage and actions'),
      ],
      '#options' => $rows,
      '#empty' => $this->t('You have no Moody AI private uploads.'),
      '#js_select' => $has_removable,
      '#prefix' => '<div class="ai-moody-private-uploads__table-wrap">',
      '#suffix' => '</div>',
      '#attributes' => [
        'class' => ['ai-moody-private-uploads__table'],
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['delete'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove selected unused uploads'),
      '#button_type' => 'danger',
      '#disabled' => !$has_removable,
      '#attributes' => [
        'class' => ['ai-moody-private-uploads__delete'],
      ],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $selected = array_values(array_filter(array_map('intval', $form_state->getValue('uploads', []))));
    if ($selected === []) {
      $form_state->setErrorByName('uploads', $this->t('Select at least one unused upload to remove.'));
      return;
    }

    $available = $this->loadUploads();
    foreach ($selected as $file_id) {
      $file = $available[$file_id] ?? NULL;
      if (!$file instanceof FileInterface || $this->fileUsage->listUsage($file) !== []) {
        $form_state->setErrorByName('uploads', $this->t('One or more selected files are in use or unavailable and cannot be removed here.'));
        return;
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $selected = array_values(array_filter(array_map('intval', $form_state->getValue('uploads', []))));
    $available = $this->loadUploads();
    $removed = 0;

    foreach ($selected as $file_id) {
      $file = $available[$file_id] ?? NULL;
      if ($file instanceof FileInterface && $this->fileUsage->listUsage($file) === []) {
        $file->delete();
        $removed++;
      }
    }

    $this->messenger()->addStatus($this->formatPlural($removed, 'Removed one private upload.', 'Removed @count private uploads.'));
    $form_state->setRebuild();
  }

  /**
   * Loads only Moody AI private uploads owned by the current user.
   *
   * @return \Drupal\file\FileInterface[]
   *   Files keyed by file ID.
   */
  private function loadUploads(): array {
    $uid = (int) $this->currentUser->id();
    if ($uid < 1) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('file');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('uri', 'private://' . $uid . '/', 'STARTS_WITH')
      ->sort('created', 'DESC')
      ->execute();

    $uploads = [];
    foreach ($storage->loadMultiple($ids) as $file) {
      if ($file instanceof FileInterface && preg_match('#^private://' . $uid . '/\d{4}-\d{2}-\d{2}/moody-ai-ckeditor-uploads/[^/]+$#D', $file->getFileUri())) {
        $uploads[(int) $file->id()] = $file;
      }
    }
    return $uploads;
  }

  /**
   * Builds preview markup equivalent to the Assistant upload picker.
   */
  private function buildPreview(array $summary): array {
    $title = !empty($summary['is_image']) && !empty($summary['preview_url'])
      ? [
        '#theme' => 'image',
        '#uri' => $summary['preview_url'],
        '#alt' => $this->t('Preview of @file', ['@file' => $summary['name']]),
        '#attributes' => ['loading' => 'lazy'],
      ]
      : [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $summary['extension'] ?? 'FILE',
        '#attributes' => ['class' => ['ai-moody-private-uploads__extension']],
      ];

    return [
      '#type' => 'link',
      '#title' => $title,
      '#url' => $this->fileUrl((string) $summary['url']),
      '#attributes' => [
        'class' => ['ai-moody-private-uploads__preview'],
        'target' => '_blank',
        'rel' => 'noopener',
        'aria-label' => $this->t('Open @file in a new tab', ['@file' => $summary['name']]),
      ],
    ];
  }

  /**
   * Builds the primary filename and file type display.
   */
  private function buildFileDetails(array $summary): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-moody-private-uploads__file']],
      'name' => [
        '#type' => 'link',
        '#title' => $summary['name'],
        '#url' => $this->fileUrl((string) $summary['url']),
        '#attributes' => [
          'target' => '_blank',
          'rel' => 'noopener',
        ],
      ],
      'type' => [
        '#type' => 'html_tag',
        '#tag' => 'small',
        '#value' => $this->t('@extension file', ['@extension' => $summary['extension'] ?? 'FILE']),
      ],
    ];
  }

  /**
   * Resolves file, Media, and accessible content usage for one upload.
   */
  private function buildUsage(FileInterface $file): array {
    $raw_usage = $this->fileUsage->listUsage($file);
    if ($raw_usage === []) {
      return [
        'in_use' => FALSE,
        'build' => $this->buildUsageDisplay('removable', $this->t('Unused'), [], []),
      ];
    }

    $media = $this->loadFileUsageEntities($raw_usage, 'media');
    $sources = $this->loadUsageSources($media);
    $status = $sources
      ? $this->formatPlural(count($sources), 'Used in one content item', 'Used in @count content items')
      : ($media
        ? ($this->entityUsage ? $this->t('Stored in Media; not placed in content') : $this->t('Stored in Media; content usage details unavailable'))
        : $this->t('Referenced by Drupal'));

    return [
      'in_use' => TRUE,
      'build' => $this->buildUsageDisplay($sources ? 'used' : 'media', $status, $media, $sources),
    ];
  }

  /**
   * Loads entities of one type from Drupal file usage records.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Entities keyed by entity ID.
   */
  private function loadFileUsageEntities(array $usage, string $entity_type_id): array {
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return [];
    }

    $ids = [];
    foreach ($usage as $module_usage) {
      foreach (array_keys($module_usage[$entity_type_id] ?? []) as $id) {
        $ids[(string) $id] = $id;
      }
    }
    return $ids ? $this->entityTypeManager->getStorage($entity_type_id)->loadMultiple($ids) : [];
  }

  /**
   * Finds accessible content that directly or indirectly uses Media.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $media
   *   Media entities associated with the file.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Accessible usage sources keyed by entity type and ID.
   */
  private function loadUsageSources(array $media): array {
    if (!$this->entityUsage) {
      return [];
    }

    $sources = [];
    $seen = [];
    $queue = array_map(static fn(EntityInterface $entity): array => [$entity, 0], array_values($media));
    while ($queue && count($seen) < 50) {
      [$target, $depth] = array_shift($queue);
      if ($depth >= 3) {
        continue;
      }

      foreach ($this->entityUsage->listSources($target, FALSE, 50) as $record) {
        $type = (string) ($record['source_type'] ?? '');
        $id = (string) ($record['source_id'] ?? '');
        $key = $type . ':' . $id;
        if ($type === '' || $id === '' || isset($seen[$key]) || !$this->entityTypeManager->hasDefinition($type)) {
          continue;
        }
        $seen[$key] = TRUE;
        $source = $this->entityTypeManager->getStorage($type)->load($id);
        if (!$source instanceof EntityInterface) {
          continue;
        }
        if ($source->access('view', $this->currentUser) || $source->access('update', $this->currentUser)) {
          $sources[$key] = $source;
        }
        $queue[] = [$source, $depth + 1];
      }
    }

    return $sources;
  }

  /**
   * Builds the usage badge and actionable entity links.
   */
  private function buildUsageDisplay(string $state, $status, array $media, array $sources): array {
    $items = [];
    foreach ($media as $entity) {
      $items[] = $this->buildEntityLink($entity, $this->t('Edit Media: @label', ['@label' => $entity->label()]));
      if (!$sources && $entity->access('delete', $this->currentUser) && $entity->hasLinkTemplate('delete-form')) {
        $items[] = [
          '#type' => 'link',
          '#title' => $this->t('Delete Media'),
          '#url' => $entity->toUrl('delete-form'),
          '#attributes' => ['class' => ['ai-moody-private-uploads__media-delete']],
        ];
      }
    }
    foreach ($sources as $entity) {
      $type = $this->entityTypeManager->getDefinition($entity->getEntityTypeId())->getSingularLabel();
      $items[] = $this->buildEntityLink($entity, $this->t('@type: @label', [
        '@type' => ucfirst((string) $type),
        '@label' => $entity->label(),
      ]));
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-moody-private-uploads__usage', 'is-' . $state]],
      'status' => [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => $status,
        '#attributes' => ['class' => ['ai-moody-private-uploads__status']],
      ],
    ];
    if ($items) {
      $build['items'] = [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => ['class' => ['ai-moody-private-uploads__usage-links']],
      ];
    }
    return $build;
  }

  /**
   * Builds the best accessible management link for an entity.
   */
  private function buildEntityLink(EntityInterface $entity, $title): array {
    if ($entity->access('update', $this->currentUser) && $entity->hasLinkTemplate('edit-form')) {
      return ['#type' => 'link', '#title' => $title, '#url' => $entity->toUrl('edit-form')];
    }
    if ($entity->access('view', $this->currentUser) && $entity->hasLinkTemplate('canonical')) {
      return ['#type' => 'link', '#title' => $title, '#url' => $entity->toUrl('canonical')];
    }
    $type = $this->entityTypeManager->getDefinition($entity->getEntityTypeId())->getSingularLabel();
    return ['#plain_text' => $this->t('@type item (access restricted)', ['@type' => $type])];
  }

  /**
   * Converts a generated private file URL into a Drupal URL object.
   */
  private function fileUrl(string $url): Url {
    return str_starts_with($url, '/') ? Url::fromUserInput($url) : Url::fromUri($url);
  }

  private function formatFileSize(int $bytes): string {
    return $bytes >= 1048576
      ? rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB'
      : max(1, (int) round($bytes / 1024)) . ' KB';
  }

}
