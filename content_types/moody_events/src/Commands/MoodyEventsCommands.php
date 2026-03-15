<?php

namespace Drupal\moody_events\Commands;

use Drush\Commands\DrushCommands;

class MoodyEventsCommands extends DrushCommands {

  /**
   * Fix bad filenames in utexas_image media entities.
   *
   * @command moody_events:fix-filenames
   * @aliases fix-filenames
   */
  public function fixFilenames() {
    $media_storage = \Drupal::entityTypeManager()->getStorage('media');
    $file_storage = \Drupal::entityTypeManager()->getStorage('file');
    $file_system = \Drupal::service('file_system');

    // Load all utexas_image media entities.
    $mids = $media_storage->getQuery()
      ->condition('bundle', 'utexas_image')
      ->accessCheck(FALSE)
      ->execute();

    $medias = $media_storage->loadMultiple($mids);

    foreach ($medias as $media) {
      $fid = $media->get('field_utexas_media_image')->target_id;
      $file = $file_storage->load($fid);

      if ($file) {
        $uri = $file->getFileUri();
        $filename = basename($uri);
        $normalized_filename = rawurldecode(strtok($filename, '?'));

        if ($normalized_filename !== $filename) {
          $new_uri = str_replace($filename, $normalized_filename, $uri);
          $old_real_path = $file_system->realpath($uri);
          $new_real_path = $old_real_path ? dirname($old_real_path) . '/' . $normalized_filename : FALSE;

          // Rename the file on disk.
          if ($old_real_path && $new_real_path && file_exists($old_real_path) && $old_real_path !== $new_real_path) {
            rename($old_real_path, $new_real_path);
          }

          // Update the file entity.
          $file->setFileUri($new_uri);
          $file->setFilename($normalized_filename);
          $file->save();

          // Update the media entity.
          $media->set('field_utexas_media_image', $file->id());
          $media->save();

          $this->output()->writeln("Updated: {$filename} to {$normalized_filename}");
        }
      }
    }

    $this->output()->writeln("Filename fixing process completed.");
  }
}
