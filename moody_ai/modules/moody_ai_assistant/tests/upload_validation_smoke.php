<?php

declare(strict_types=1);

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

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

  $chat_form = \Drupal::formBuilder()->getForm(Drupal\moody_ai_assistant\Form\AIChatBlockForm::class);
  foreach (['help', 'prompts', 'generation_options', 'previous_uploads'] as $dialog_key) {
    if (($chat_form['tool_dialogs'][$dialog_key]['#tag'] ?? '') !== 'dialog') {
      throw new RuntimeException(sprintf('The %s assistant tool did not render as a native dialog.', $dialog_key));
    }
  }
  $media_ajax_element = [
    '#moody_ai_ajax_route_parameters' => [
      'entity_type' => 'node',
      'entity_id' => 1,
    ],
    'open_button' => ['#ajax' => []],
    'update_button' => ['#ajax' => []],
  ];
  $complete_form = [];
  Drupal\moody_ai_assistant\Form\AIChatBlockForm::applyMediaLibraryAjaxUrl($media_ajax_element, new Drupal\Core\Form\FormState(), $complete_form);
  foreach (['open_button', 'update_button'] as $ajax_button) {
    $ajax_url = $media_ajax_element[$ajax_button]['#ajax']['url'] ?? NULL;
    if (!$ajax_url instanceof Drupal\Core\Url || !str_contains($ajax_url->toString(), '/moody-ai/assistant/form/node/1') || ($media_ajax_element[$ajax_button]['#ajax']['options']['query']['ajax_form'] ?? 0) !== 1) {
      throw new RuntimeException('Media Library callbacks were not routed through the stable assistant AJAX form endpoint.');
    }
  }
  $private_upload_settings = $chat_form['#attached']['drupalSettings']['moodyAiAssistant']['privateUploads'] ?? [];
  if (empty($private_upload_settings[(int) $document_file->id()]['removable']) || empty($private_upload_settings[(int) $document_file->id()]['remove_url']) || !empty($private_upload_settings[(int) $summary_file->id()]['removable'])) {
    throw new RuntimeException('The assistant upload dialog did not distinguish removable and in-use files.');
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

  $controller = Drupal\moody_ai_assistant\Controller\PrivateUploadActionController::create(\Drupal::getContainer());
  $invalid_response = $controller->remove((int) $document_file->id(), Request::create('/', 'POST'));
  if ($invalid_response->getStatusCode() !== 403) {
    throw new RuntimeException('Private upload removal accepted a request without a CSRF token.');
  }
  $request = Request::create('/', 'POST', [], [], [], [
    'HTTP_X_CSRF_TOKEN' => \Drupal::service('csrf_token')->get('moody_ai_assistant.private_upload_remove'),
  ]);
  $in_use_response = $controller->remove((int) $summary_file->id(), $request);
  if ($in_use_response->getStatusCode() !== 409) {
    throw new RuntimeException(sprintf('Private upload removal did not protect an in-use file (HTTP %d: %s).', $in_use_response->getStatusCode(), $in_use_response->getContent()));
  }
  $removed_file_id = (int) $document_file->id();
  $remove_response = $controller->remove($removed_file_id, $request);
  if ($remove_response->getStatusCode() !== 200 || \Drupal::entityTypeManager()->getStorage('file')->load($removed_file_id)) {
    throw new RuntimeException('An owned, unused private upload was not removed.');
  }
  $document_file = NULL;

  echo json_encode([
    'octet_stream_gif_accepted' => TRUE,
    'disguised_gif_rejected' => $disguised_rejected,
    'private_upload_summary' => TRUE,
    'thumbnail_available' => TRUE,
    'document_extension_badge' => TRUE,
    'private_upload_management_form' => TRUE,
    'assistant_tool_dialogs' => TRUE,
    'stable_media_library_ajax' => TRUE,
    'secure_inline_upload_removal' => TRUE,
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
