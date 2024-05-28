<?php

namespace Drupal\moody_events\Commands;

use Drush\Commands\DrushCommands;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;

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

        // Check if filename contains '?'
        if (strpos($filename, '?') !== FALSE) {
          $new_filename = strtok($filename, '?');
          $new_uri = str_replace($filename, $new_filename, $uri);

          // Rename the file on disk.
          if (file_exists($uri)) {
            rename($uri, $new_uri);
          }

          // Update the file entity.
          $file->setFileUri($new_uri);
          $file->setFilename($new_filename);
          $file->save();

          // Update the media entity.
          $media->set('field_utexas_media_image', $file->id());
          $media->save();

          $this->output()->writeln("Updated: {$filename} to {$new_filename}");
        }
      }
    }

    $this->output()->writeln("Filename fixing process completed.");
  }
}
