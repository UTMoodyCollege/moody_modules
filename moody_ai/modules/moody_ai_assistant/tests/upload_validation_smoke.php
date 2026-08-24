<?php

declare(strict_types=1);

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

$account = \Drupal::entityTypeManager()->getStorage('user')->load(1);
if (!$account) {
  throw new RuntimeException('User 1 is required for the upload validation smoke test.');
}
\Drupal::currentUser()->setAccount($account);

$creator = \Drupal::service('moody_ai_assistant.asset_creator');
$validator = new ReflectionMethod($creator, 'assertValidUploadedFile');
$valid_path = tempnam(sys_get_temp_dir(), 'moody-ai-valid-gif-');
$invalid_path = tempnam(sys_get_temp_dir(), 'moody-ai-invalid-gif-');
$summary_file = NULL;
$document_file = NULL;
$summary_media = NULL;

try {
  file_put_contents($valid_path, base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', TRUE));
  file_put_contents($invalid_path, 'not an image');

  $upload = static function (string $path, string $name): UploadedFile {
    return new class($path, $name, 'image/gif', UPLOAD_ERR_OK, TRUE) extends UploadedFile {
      public function getMimeType(): ?string {
        return 'application/octet-stream';
      }
    };
  };

  $validator->invoke($creator, $upload($valid_path, 'valid.gif'));
  $disguised_rejected = FALSE;
  try {
    $validator->invoke($creator, $upload($invalid_path, 'disguised.gif'));
  }
  catch (Throwable $exception) {
    $disguised_rejected = TRUE;
  }
  if (!$disguised_rejected) {
    throw new RuntimeException('A non-image file with a .gif extension was accepted.');
  }

  $directory = 'private://1/' . gmdate('Y-m-d') . '/moody-ai-ckeditor-uploads';
  $file_system = \Drupal::service('file_system');
  $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
  $summary_file = \Drupal::service('file.repository')->writeData(
    base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', TRUE),
    $directory . '/upload-summary-smoke.gif',
    FileSystemInterface::EXISTS_RENAME,
  );
  if (!$summary_file instanceof FileInterface) {
    throw new RuntimeException('The private upload summary fixture could not be created.');
  }
  $summary_file->setOwnerId(1);
  $summary_file->save();
  $summary = $creator->buildPrivateUploadSummary($summary_file);
  if (($summary['id'] ?? 0) !== (int) $summary_file->id() || empty($summary['is_image']) || ($summary['extension'] ?? '') !== 'GIF' || empty($summary['url']) || empty($summary['preview_url'])) {
    throw new RuntimeException('Private upload display metadata was incomplete.');
  }

  $media_type = \Drupal::entityTypeManager()->getStorage('media_type')->load('utexas_image');
  $source_field = $media_type ? ($media_type->getSource()->getSourceFieldDefinition($media_type)->getName() ?? '') : '';
  if (!$media_type || $source_field === '') {
    throw new RuntimeException('The UTexas image Media bundle is required for private upload form validation.');
  }
  $summary_media = \Drupal::entityTypeManager()->getStorage('media')->create([
    'bundle' => 'utexas_image',
    'name' => 'Private upload form smoke media',
    $source_field => [
      'target_id' => $summary_file->id(),
      'alt' => 'One-pixel upload form test image',
    ],
  ]);
  $summary_media->setOwnerId(1);
  $summary_media->save();

  $document_file = \Drupal::service('file.repository')->writeData(
    "%PDF-1.4\n%%EOF\n",
    $directory . '/upload-summary-smoke.pdf',
    FileSystemInterface::EXISTS_RENAME,
  );
  if (!$document_file instanceof FileInterface) {
    throw new RuntimeException('The private document summary fixture could not be created.');
  }
  $document_file->setOwnerId(1);
  $document_file->save();
  $document_summary = $creator->buildPrivateUploadSummary($document_file);
  if (($document_summary['extension'] ?? '') !== 'PDF' || !empty($document_summary['is_image']) || !empty($document_summary['preview_url']) || empty($document_summary['url'])) {
    throw new RuntimeException('Private document display metadata was incomplete.');
  }

  $uploads_form = \Drupal::formBuilder()->getForm(Drupal\moody_ai_assistant\Form\PrivateUploadsForm::class);
  if (!in_array('moody_ai_assistant/private_uploads', $uploads_form['#attached']['library'] ?? [], TRUE)) {
    throw new RuntimeException('The private upload management stylesheet was not attached.');
  }
  if (empty($uploads_form['uploads']['#options'][(int) $summary_file->id()]['#disabled']) || !empty($uploads_form['uploads']['#options'][(int) $document_file->id()]['#disabled']) || empty($uploads_form['uploads']['#js_select']) || !empty($uploads_form['actions']['delete']['#disabled'])) {
    throw new RuntimeException('Private upload removal safeguards were not reflected in the form state.');
  }
  $uploads_html = (string) \Drupal::service('renderer')->renderRoot($uploads_form);
  foreach (['ai-moody-private-uploads__table-wrap', 'ai-moody-private-uploads__preview', 'ai-moody-private-uploads__delete', 'select-all', 'Stored in Media; not placed in content', 'Delete Media'] as $expected) {
    if (!str_contains($uploads_html, $expected)) {
      throw new RuntimeException(sprintf('Private upload management markup did not contain "%s".', $expected));
    }
  }

  echo json_encode([
    'octet_stream_gif_accepted' => TRUE,
    'disguised_gif_rejected' => $disguised_rejected,
    'private_upload_summary' => TRUE,
    'thumbnail_available' => TRUE,
    'document_extension_badge' => TRUE,
    'private_upload_management_form' => TRUE,
  ], JSON_PRETTY_PRINT) . PHP_EOL;
}
finally {
  if ($summary_media) {
    $summary_media->delete();
  }
  if ($summary_file instanceof FileInterface) {
    $summary_file->delete();
  }
  if ($document_file instanceof FileInterface) {
    $document_file->delete();
  }
  @unlink($valid_path);
  @unlink($invalid_path);
}
