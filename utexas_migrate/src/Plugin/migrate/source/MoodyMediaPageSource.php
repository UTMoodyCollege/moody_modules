<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Provides a 'moody_media_page_source' migrate source.
 *
 * This provides a base source plugin for migrating
 * article type nodes from Drupal 7.
 *
 * @MigrateSource(
 *  id = "moody_media_page_source",
 *  source_module = "utexas_migrate"
 * )
 */
class MoodyMediaPageSource extends NodeSource {

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {

    // URL generator.
    $url_generator = $this->select('field_data_field_moody_url_generator', 't')
      ->fields('t', ['field_moody_url_generator_tid'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    if ($url_generator != '') {
      $row->setSourceProperty('url_generator', $url_generator);
    }

    // EAS category.
    $eas_category = $this->select('field_data_field_eas_category', 't')
      ->fields('t', ['field_eas_category_tid'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    if ($eas_category != '') {
      $row->setSourceProperty('eas_category', $eas_category);
    }

    // Tags.
    $tags = $this->select('field_data_field_tags', 't')
      ->fields('t', ['field_tags_tid'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    if ($tags != '') {
      $row->setSourceProperty('field_tags', $tags);
    }

    // Copy.
    $copy = $this->select('field_data_field_wysiwyg_a', 't')
      ->fields('t', ['field_wysiwyg_a_value', 'field_wysiwyg_a_format'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    if ($copy != '') {
      $row->setSourceProperty('copy', [
        'value' => self::cleanWysiwygValue($copy['field_wysiwyg_a_value']),
        'format' => 'flex_html',
      ]);
    }

    // Video external URL.
    $video = $this->select('field_data_field_featured_video', 't')
      ->fields('t', ['field_featured_video_video_url'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    if ($video != '') {
      $row->setSourceProperty('video', $video['field_featured_video_video_url']);
    }

    // Thumbnail image.
    $thumbnail = $this->select('field_data_field_featured_video_thumbnail', 't')
      ->fields('t', ['field_featured_video_thumbnail_fid'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    if ($thumbnail != '') {
      $fid = MigrateHelper::getMediaIdFromFid($thumbnail);
      $row->setSourceProperty('thumbnail', $fid);
    }

    // Description.
    $description = $this->select('field_data_field_description', 't')
      ->fields('t', ['field_description_value', 'field_description_format'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    if ($description != '') {
      $row->setSourceProperty('description', [
        'value' => self::cleanWysiwygValue($description['field_description_value']),
        'format' => 'flex_html',
      ]);
    }

    // Director sources.
    $sources = $this->select('field_data_field_sources', 't')
      ->fields('t', ['field_sources_directors'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    $source = unserialize($sources['field_sources_directors']);
    $row->setSourceProperty('source', $source);

    // Audio files.
    $audio_file = $this->select('field_data_field_featured_audio', 'i')
      ->fields('i', ['field_featured_audio_fid', 'entity_id'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    if ($audio_file['field_featured_audio_fid'] != 0) {
      $mid = MigrateHelper::getMediaIdFromFid($audio_file['field_featured_audio_fid']);
      $row->setSourceProperty('audio_file', [
        'target_id' => $mid,
      ]);
    }

    // People sources.
    $people = $this->select('field_data_field_people', 't')
      ->fields('t', ['field_people_first_name', 'field_people_last_name', 'field_people_copy', 'field_people_persons_title', 'entity_id'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    $row->setSourceProperty('people', [
      'first_name' => $people['field_people_first_name'],
      'last_name' => $people['field_people_last_name'],
      'title' => $people['field_people_persons_title'],
      'body' => unserialize($people['field_people_copy']),
    ]);

    // Location.
    $location = $this->select('field_data_field_moody_media_location', 't')
      ->fields('t', ['field_moody_media_location_value'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    if ($location != '') {
      $row->setSourceProperty('location', $location);
    }

    // Directors text.
    $directors_text = $this->select('field_data_field_sources_text', 't')
      ->fields('t', ['field_sources_text_value'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    if ($directors_text != '') {
      $row->setSourceProperty('directors_text', $directors_text);
    }
  }

  /**
   * Transform file entity into media embed and update classes.
   *
   * @param string $wysiwyg_value
   *   A fid for file entity.
   *
   * @return string
   *   A string with media embed markup.
   */
  protected static function cleanWysiwygValue(string $wysiwyg_value) {
    // Send to helper function to update standard classes.
    $source = MigrateHelper::wysiwygTransformCssClasses($wysiwyg_value);
    // Regex to find file entities. Returns array of file entities.
    preg_match_all('/\[\[{"fid"(.*)}}\]\]/', $source, $matches);
    foreach ($matches[0] as $key => $value) {
      $media_embed = MigrateHelper::transformMediaEmbed($value);
      $updated_source = str_replace($value, $media_embed, $source);
      $source = $updated_source;
    }
    // Regex to find videos embedd with video_filter.
    preg_match_all('/\[video:(.*)\]/', $source, $matches);
    foreach ($matches[0] as $key => $value) {
      $media_embed = MigrateHelper::transformVideoFilterEmbed($value);
      $updated_source = str_replace($value, $media_embed, $source);
      $source = $updated_source;
    }
    return ($source);
  }

}
