<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\utexas_migrate\MigrateHelper;
use Drupal\media\Entity\Media;
use Drupal\Component\Utility\Html;

/**
 * Provides a 'moody_landing_page_source' migrate source.
 *
 * This provides a base source plugin for migrating
 * article type nodes from Drupal 7.
 *
 * @MigrateSource(
 *  id = "moody_feature_page_source",
 *  source_module = "utexas_migrate"
 * )
 */
class MoodyFeaturePageSource extends NodeSource {

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

    // Subtitle.
    $field_subtitle = $this->select('field_data_field_subtitle', 't')
      ->fields('t', ['field_subtitle_value'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    $row->setSourceProperty('field_subtitle', $field_subtitle);

    // // WYSIWYG A.
    $field_body = $this->select('field_data_field_wysiwyg_a', 'body')
      ->fields('body', ['field_wysiwyg_a_value', 'field_wysiwyg_a_format'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    $body = ($field_body['field_wysiwyg_a_value']) ? self::cleanWysiwygValue($field_body['field_wysiwyg_a_value']) : '';
    $row->setSourceProperty('field_body', [
      'value' => $body,
      'format' => 'flex_html',
    ]);

    // Image.
    $field_moody_feature_page_thumbnail = $this->select('field_data_field_credit', 'i')
      ->fields('i', ['field_credit_thumbnail'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    $destination_fid = MigrateHelper::getMediaIdFromFid($field_moody_feature_page_thumbnail['field_credit_thumbnail']);
    $row->setSourceProperty('field_moody_feature_page_thumbnail', [
      'target_id' => $destination_fid,
    ]);

    // Author paragraph.
    $field_credit = $this->select('field_data_field_credit', 't')
      ->fields('t', ['field_credit_credits'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    $credit = unserialize($field_credit['field_credit_credits']);
    $row->setSourceProperty('credit', $credit);

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
