<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\utexas_migrate\MigrateHelper;
use Drupal\media\Entity\Media;

/**
 * Provides a 'utexas_faculty_bio_source' migrate source.
 *
 * This provides a base source plugin for migrating
 * article type nodes from Drupal 7.
 *
 * @MigrateSource(
 *  id = "utexas_faculty_bio_source",
 *  source_module = "utexas_migrate"
 * )
 */
class FacultyBioSource extends NodeSource {

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    // Query for faculty-bio-specific fields.

    // First name.
    $field_first_name = $this->select('field_data_field_first_name', 't')
      ->fields('t', ['field_first_name_value'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    $row->setSourceProperty('field_first_name', $field_first_name);

    // Middle name.
    $field_middle_name = $this->select('field_data_field_middle_name', 't')
      ->fields('t', ['field_middle_name_value'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    if ($field_middle_name != '') {
      $row->setSourceProperty('field_middle_name', $field_middle_name);
    }

    // Last name.
    $field_last_name = $this->select('field_data_field_last_name', 't')
      ->fields('t', ['field_last_name_value'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    $row->setSourceProperty('field_last_name', $field_last_name);

    // Office number.
    $field_office_number = $this->select('field_data_field_office_number', 't')
      ->fields('t', ['field_office_number_value'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    if ($field_office_number != '') {
      $row->setSourceProperty('field_office_number', $field_office_number);
    }

    // Phone number.
    $field_phone_number = $this->select('field_data_field_phone', 't')
      ->fields('t', ['field_phone_value'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    if ($field_phone_number != '') {
      $row->setSourceProperty('field_phone', $field_phone_number);
    }

    // EID.
    $field_ut_eid = $this->select('field_data_field_ut_eid', 't')
      ->fields('t', ['field_ut_eid_value'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    if ($field_ut_eid != '') {
      $row->setSourceProperty('field_ut_eid', $field_ut_eid);
    }

    // Show EID boolean.
    $field_show_eid_on_profile = $this->select('field_data_field_show_eid_on_profile', 't')
      ->fields('t', ['field_show_eid_on_profile_value'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    $row->setSourceProperty('field_show_eid_on_profile', $field_show_eid_on_profile);

    // Position.
    $field_position = $this->select('field_data_field_position', 't')
      ->fields('t', ['field_position_tid'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    if ($field_position != '') {
      $row->setSourceProperty('field_position', $field_position);
    }

    // Headshot.
    $faculty_headshot = $this->select('field_data_field_moody_profile_picture', 'fi')
      ->fields('fi', ['field_moody_profile_picture_fid', 'field_moody_profile_picture_alt'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    $headshot_fid = $faculty_headshot['field_moody_profile_picture_fid'];
    $media_id = MigrateHelper::getMediaIdFromFid($headshot_fid);
    $media = Media::load($media_id);
    $alt = $faculty_headshot['field_moody_profile_picture_alt'];
    if ($alt == '' && isset($field_first_name) && isset($field_last_name)) {
      $alt = $field_first_name[0] . ' ' . $field_last_name[0] . ' profile picture';
    }
    $row->setSourceProperty('headshot_new', [
      'target_id' => $media_id,
      'alt' => $alt,
    ]);

    // PDF-CV.
    $field_moody_pdf_cv = $this->select('field_data_field_ut_cv', 'i')
      ->fields('i', ['field_ut_cv_fid', 'entity_id'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    if ($field_moody_pdf_cv['field_ut_cv_fid'] != 0) {
      $mid = MigrateHelper::getDocumentMediaIdFromFid($field_moody_pdf_cv['field_ut_cv_fid']);
      // Add 1 because the desired fid is one more than the mid.
      $mid = $mid + 1;
      $row->setSourceProperty('field_ut_cv_pdf', [
        'target_id' => $mid,
      ]);
    }

    // Department.
    $field_field_moody_fac_bio_dept = $this->select('field_data_field_field_moody_fac_bio_dept', 't')
      ->fields('t', ['field_field_moody_fac_bio_dept_tid'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    if ($field_field_moody_fac_bio_dept != '') {
      $row->setSourceProperty('field_field_moody_fac_bio_dept', $field_field_moody_fac_bio_dept);
    }

    // Custom tab title.
    $field_custom_tab_title = $this->select('field_revision_field_custom_tab_title', 't')
      ->fields('t', ['field_custom_tab_title_value'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    if ($field_custom_tab_title != '') {
      $row->setSourceProperty('field_custom_tab_title', $field_custom_tab_title);
    }

    // Custom tab content.
    $field_custom_tab_body = $this->select('field_data_field_custom_tab_body', 'body')
      ->fields('body', ['field_custom_tab_body_value', 'field_custom_tab_body_format'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    $body = ($field_custom_tab_body['field_custom_tab_body_value']) ? self::cleanWysiwygValue($field_custom_tab_body['field_custom_tab_body_value']) : '';
    $row->setSourceProperty('field_custom_tab_body', [
      'value' => $body,
      'format' => 'flex_html',
    ]);

    // Biography.
    $field_biography = $this->select('field_data_field_biography', 'body')
      ->fields('body', ['field_biography_value', 'field_biography_format'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    $body = ($field_biography['field_biography_value']) ? self::cleanWysiwygValue($field_biography['field_biography_value']) : '';
    $row->setSourceProperty('field_biography', [
      'value' => $body,
      'format' => 'flex_html',
    ]);

    // Email address.
    $field_email = $this->select('field_data_field_email', 't')
      ->fields('t', ['field_email_value'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    if ($field_email != '') {
      $row->setSourceProperty('field_email', $field_email);
    }

    // Degrees.
    $field_degrees = $this->select('field_data_field_degrees', 't')
      ->fields('t', ['field_degrees_degrees'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    if (count($field_degrees) != '0') {
      $output = '';
      foreach ($field_degrees as $value) {
        $degrees = unserialize($value);
        if (isset($degrees[0])) {
          foreach ($degrees as $j) {
            $output .= $j['type'] . ' ';
            $output .= $j['degree'] . ' ';
            $output .= $j['year_attended'] . ' ';
            $output .= $j['received_from'] . ' ';
            $output .= $j['location'] . '<br>';
          }
        }
      }
      $row->setSourceProperty('field_degrees', [
        'value' => '<p>' . $output . '</p>',
        'format' => 'flex_html',
      ]);
    }

    // Courses.
    $field_courses = $this->select('field_data_field_courses', 't')
      ->fields('t', ['field_courses_courses'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    if (count($field_courses) != '0') {
      $output = '';
      foreach ($field_courses as $value) {
        $courses = unserialize($value);
        if (is_array($courses)) {
          foreach ($courses as $j) {
            $output .= $j['department'] . ' - ';
            $output .= $j['course'] . '<br>';
          }
        }
      }
      $row->setSourceProperty('field_courses', [
        'value' => '<p>' . $output . '</p>',
        'format' => 'flex_html',
      ]);
    }

    // Affiliations.
    $field_affiliations = $this->select('field_data_field_affiliations', 'body')
      ->fields('body', ['field_affiliations_value', 'field_affiliations_format'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    $body = ($field_affiliations['field_affiliations_value']) ? self::cleanWysiwygValue($field_affiliations['field_affiliations_value']) : '';
    $row->setSourceProperty('field_affiliations', [
      'value' => $body,
      'format' => 'flex_html',
    ]);

    // Personal link title (label).
    $personal_link_title = $this->select('field_data_field_personal_link_text', 't')
      ->fields('t', ['field_personal_link_text_value'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    foreach ($personal_link_title as $value) {
      $title = $value;
    }
    $row->setSourceProperty('personal_link_title', $title);

    // Personal link URL.
    $personal_link_url = $this->select('field_data_field_personal_link_url', 't')
      ->fields('t', ['field_personal_link_url_value'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    foreach ($personal_link_url as $value) {
      $url = $value;
      if ($url != '') {
        $row->setSourceProperty('personal_link_url', $url);
      }
    }

    // Expertise.
    $field_expertise = $this->select('field_data_field_expertise', 't')
      ->fields('t', ['field_expertise_tid'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetchCol();
    if ($field_expertise != '') {
      $row->setSourceProperty('field_expertise', $field_expertise);
    }

    // Social Accounts
    $faculty_social = $this->select('field_data_field_utexas_social_links', 'sl')
      ->fields('sl', ['field_utexas_social_links_links'])
      ->condition('entity_id', $row->getSourceProperty('nid'))
      ->execute()
      ->fetch();
    $accounts = unserialize($faculty_social['field_utexas_social_links_links']);
    $to_migrate = [];
    foreach ($accounts as $key => $value) {
      if (!str_contains($key, '_weight')) {
        $to_migrate[strtolower($key)] = $value;
      }
    }
    if ($to_migrate != '') {
      $row->setSourceProperty('faculty_social_links', serialize($to_migrate));
    }
  }

  /**
   * Transform file entity into media embed and update classes.
   *
   * @param string $wysiwyg_value
   *   WYSIWYG source code.
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
