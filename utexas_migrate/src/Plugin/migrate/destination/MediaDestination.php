<?php

namespace Drupal\utexas_migrate\Plugin\migrate\destination;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\media\Entity\Media;
use Drupal\migrate\Plugin\migrate\destination\DestinationBase;
use Drupal\migrate\Plugin\MigrateDestinationInterface;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\Core\Site\Settings;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Utility\Html;
use Drupal\Core\File\FileSystemInterface;

/**
 * Provides a 'utexas_media_destination' destination plugin.
 *
 * This is a base class for Media migrations.
 */
abstract class MediaDestination extends DestinationBase implements MigrateDestinationInterface {

  public $migrationSourceBasePath;
  public $migrationSourceBaseUrl;
  public $migrationSourcePrivateFilePath;
  public $migrationSourcePublicFilePath;
  public $mediaElements = [];
  public $importedFile;

  /**
   * Constructor method.
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, MigrationInterface $migration) {
    $this->migrationSourceBasePath = '/' . trim(Settings::get('migration_source_base_path'), '/') . '/';
    $this->migrationSourceBaseUrl = trim(Settings::get('migration_source_base_url'), '/') . '/';
    $this->migrationSourcePublicFilePath = trim(Settings::get('migration_source_public_file_path'), '/') . '/';
    $this->migrationSourcePrivateFilePath = trim(Settings::get('migration_source_private_file_path'), '/') . '/';
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
  }

  /**
   * Import function that runs on each row.
   *
   * This import method will return a managed file entity.
   * It is up to implementations to use the file in whatever
   * form of Media entity is needed.
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    // The managed file needs to be saved, first,
    // before the media entity can be created.
    $file_uri = $row->getSourceProperty('uri');
    $filepath = str_replace(['public://', 'private://'], ['', ''], $file_uri);
    $filepath = str_replace("%2F", "/", urlencode($filepath));
    $filepath = str_replace("+", "%20", $filepath);
    $filepath = str_replace("%252C", ",", $filepath);
    $filepath = str_replace("%2522", '"', $filepath);
    $filepath = str_replace("%2526", '&', $filepath);
    $filepath = str_replace("%252B", '+', $filepath);
    $filepath = str_replace("%253A", ':', $filepath);
    $filepath = str_replace("%2527", "'", $filepath);
    $filepath = str_replace("%255B", '[', $filepath);
    $filepath = str_replace("%255D", ']', $filepath);
    $filepath = str_replace("%2520", ' ', $filepath);
    $filepath = str_replace("%2528", '(', $filepath);
    $filepath = str_replace("%2529", ')', $filepath);
    $filepath = str_replace("%2521", '!', $filepath);
    $filepath = str_replace("%2593", ')', $filepath);
    $filepath = str_replace("%25E2", 'â', $filepath);
    $filepath = str_replace("%2540", '@', $filepath);

    if (strpos($file_uri, 'public://') !== FALSE) {
      $location_path = $this->migrationSourcePublicFilePath . $filepath;
      // Public files.
      $path_to_file = $this->migrationSourceBaseUrl . $location_path;
    }
    else {
      // Private files.
      $location_path = $this->migrationSourcePrivateFilePath . $filepath;
      $path_to_file = $this->migrationSourceBasePath . $location_path;
    }

    try {
      // This saves a new Managed File.
      $file_data = file_get_contents($path_to_file);
      $dirname = dirname($file_uri);
      // Prepare subdirectories of the filesystem.
      if (!in_array($dirname, ['public:', 'private:'])) {
        \Drupal::service('file_system')->prepareDirectory($dirname, FileSystemInterface::CREATE_DIRECTORY);
      }
      $this->importedFile = \Drupal::service('file.repository')->writeData($file_data, $file_uri, FileSystemInterface::EXISTS_REPLACE);
      if ($this->importedFile) {
        $this->mediaElements['name'] = $this->importedFile->getFilename();
        $this->mediaElements['uid'] = $row->getDestinationProperty('uid');
        // File "status" in Drupal 7 is present, but non-functional.
        // Nevertheless, migrate the value ("1") from the source system.
        $this->mediaElements['status'] = $row->getSourceProperty('status');
        // Drupal 7 file entities only have the "timestamp" timestamp, so
        // migrate that as both 'created' & 'changed' into Drupal 8.
        $this->mediaElements['created'] = $row->getSourceProperty('timestamp');
        $this->mediaElements['changed'] = $row->getSourceProperty('timestamp');
      }
    }
    catch (EntityStorageException $e) {
      \Drupal::logger('utexas_migrate')->warning("Import of file failed: :code, :error", [
        ':error' => $e->getMessage(),
        ':code' => $e->getCode(),
      ]);
    }
  }

  /**
   * Helper function that actually saves the media entity.
   *
   * This MUST be called in classes that extend MediaDestination
   * as the last element in those extending classes' import() method.
   */
  protected function saveImportData() {
    // Before trying to save the media entity, check if the file was saved.
    if ($this->importedFile->id() != '0') {
      try {
        $imported_media = Media::create($this->mediaElements);
        $imported_media->save();
        return [$imported_media->id()];
      }
      catch (EntityStorageException $e) {
        \Drupal::logger('utexas_migrate')->warning("Import of node failed: :error - Code: :code", [
          ':error' => $e->getMessage(),
          ':code' => $e->getCode(),
        ]);
      }
    }

  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'id' => [
        'type' => 'integer',
        'unsigned' => FALSE,
        'size' => 'big',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    // TODO: Implement calculateDependencies() method.
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    try {
      $entity = Media::load($destination_identifier['id']);
      if ($entity != NULL) {
        $entity->delete();
      }
    }
    catch (EntityStorageException $e) {
      \Drupal::logger('utexas_migrate')->warning("Rollback of node with nid of :nid failed: :error - Code: :code", [
        ':nid' => $destination_identifier['id'],
        ':error' => $e->getMessage(),
        ':code' => $e->getCode(),
      ]);
    }
    // Delete the actual managed files, as well.
    $query = \Drupal::database()->select('file_managed')
      ->fields('file_managed', ['fid']);
    $result = array_keys($query->execute()->fetchAllAssoc('fid'));
    if (!empty($result)) {
      $file_storage = \Drupal::entityTypeManager()->getStorage('file');
      foreach ($result as $fid) {
        $file = $file_storage->load($fid);
        if ($file) {
          \Drupal::service('file.repository')->delete($file);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function supportsRollback() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function rollbackAction() {
    return MigrateIdMapInterface::ROLLBACK_DELETE;
  }

  /**
   * {@inheritdoc}
   */
  public function fields(MigrationInterface $migration = NULL) {
    // Not needed; must be implemented to respect MigrateDestinationInterface.
  }

}
