<?php

namespace Drupal\utexas_migrate\Plugin\migrate\destination;

use Drupal\migrate\Plugin\MigrateDestinationInterface;
use Drupal\migrate\Row;

/**
 * Provides a 'utexas_media_image_destination' destination plugin.
 *
 * @MigrateDestination(
 *   id = "utexas_media_image_destination"
 * )
 */
class ImageDestination extends MediaDestination implements MigrateDestinationInterface {

  /**
   * Import function that runs on each row.
   *
   * Calling the parent import(), the returned file is used
   * for a new utexas_image media entity to complete the import.
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    // The parent MediaDestination class is providing
    // standard media properties, like author, created, status etc.,
    // to the $media_properties array, referenced in saveImportData().
    // The parent import() is also responsible for actually saving the file
    // (not to be confused with the media entity) in Drupal.
    parent::import($row, $old_destination_id_values);

    if ($this->importedFile) {
      // Define the media bundle for this entity type.
      $this->mediaElements['bundle'] = 'utexas_image';

      // Add image-specific elements to the media object.
      $this->mediaElements['field_utexas_media_image'] = [
        'target_id' => $this->importedFile->id(),
        'alt' => $row->getSourceProperty('field_file_image_alt_text_value'),
        'title' => $row->getSourceProperty('field_file_image_title_text_value'),
      ];

      // @see MediaDestination.
      return $this->saveImportData();
    }
    return FALSE;
  }

}
