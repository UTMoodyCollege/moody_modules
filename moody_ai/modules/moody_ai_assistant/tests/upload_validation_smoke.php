<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\File\UploadedFile;

$creator = \Drupal::service('moody_ai_assistant.asset_creator');
$validator = new ReflectionMethod($creator, 'assertValidUploadedFile');
$valid_path = tempnam(sys_get_temp_dir(), 'moody-ai-valid-gif-');
$invalid_path = tempnam(sys_get_temp_dir(), 'moody-ai-invalid-gif-');

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

  echo json_encode([
    'octet_stream_gif_accepted' => TRUE,
    'disguised_gif_rejected' => $disguised_rejected,
  ], JSON_PRETTY_PRINT) . PHP_EOL;
}
finally {
  @unlink($valid_path);
  @unlink($invalid_path);
}
