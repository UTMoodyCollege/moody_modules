<?php

declare(strict_types=1);

namespace Drupal\moody_ai_assistant\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
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
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('file.usage'),
      $container->get('date.formatter'),
    );
  }

  public function getFormId(): string {
    return 'moody_ai_assistant_private_uploads';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $rows = [];
    $has_removable = FALSE;
    foreach ($this->loadUploads() as $file) {
      $in_use = $this->fileUsage->listUsage($file) !== [];
      $has_removable = $has_removable || !$in_use;
      $rows[(int) $file->id()] = [
        '#disabled' => $in_use,
        'file' => $file->getFilename(),
        'uploaded' => $this->dateFormatter->format((int) $file->getCreatedTime(), 'short'),
        'size' => $this->formatFileSize((int) $file->getSize()),
        'status' => $in_use ? $this->t('In use; manage through Media or content') : $this->t('Available for reuse or removal'),
      ];
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('These private files were attached to Moody AI by your account. Select them again from the Assistant, or remove files that Drupal content and Media are not using.') . '</p>',
    ];
    $form['uploads'] = [
      '#type' => 'tableselect',
      '#header' => [
        'file' => $this->t('File'),
        'uploaded' => $this->t('Uploaded'),
        'size' => $this->t('Size'),
        'status' => $this->t('Status'),
      ],
      '#options' => $rows,
      '#empty' => $this->t('You have no Moody AI private uploads.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['delete'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove selected unused uploads'),
      '#button_type' => 'danger',
      '#disabled' => !$has_removable,
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

  private function formatFileSize(int $bytes): string {
    return $bytes >= 1048576
      ? rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB'
      : max(1, (int) round($bytes / 1024)) . ' KB';
  }

}
