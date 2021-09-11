<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;
use Drupal\migrate_drupal\Plugin\migrate\source\d7\FieldableEntity;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Provides a 'moody_subsite_source' migrate source.
 *
 * This provides a base source plugin for migrating
 * entity content from UTDK 7.
 *
 * @MigrateSource(
 *  id = "moody_subsite_source",
 *  source_module = "utexas_migrate"
 * )
 */
class MoodySubsiteSource extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    return $this->select('centers_nav', 'c')
      ->fields('c', [
        'id',
        'reference',
        'links',
        'overall_center',
        'default_hero_photo',
        'info_bar_data',
        'corresponding_url_generator_tids',
        'overall_center_link',
        'subsite_logo',
        'subsite_social_accounts',
        'subsite_hero_display',
        'subsite_title_display',
        'subsite_footer_info',
        'subsite_title_style',
        'subsite_give_link',
      ]);
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [];
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'id' => [
        'type' => 'integer',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    // Subsite hero image.
    $field_hero = $this->select('centers_nav', 'hp')
      ->fields('hp', ['default_hero_photo'])
      ->condition('id', $row->getSourceProperty('id'))
      ->execute()
      ->fetch();
    $hero = unserialize($field_hero['default_hero_photo']);
    $hero_fid = $hero['hero_image'];
    $media_id = MigrateHelper::getMediaIdFromFid($hero_fid);
    $row->setSourceProperty('hero_mid', $media_id);

    // Subsite custom logo.
    $field_logo = $this->select('centers_nav', 'hp')
      ->fields('hp', ['subsite_logo'])
      ->condition('id', $row->getSourceProperty('id'))
      ->execute()
      ->fetch();
    $logo = unserialize($field_logo['subsite_logo']);
    $logo_fid = $logo['subsite_logo_image'];
    $logo_style = $logo['logo_style'];
    $row->setSourceProperty('logo_style', $logo_style);
    if ($logo_fid != '0') {
      $logo_media_id = MigrateHelper::getMediaIdFromFid($logo_fid);
      $row->setSourceProperty('logo_mid', $logo_media_id);
    }

    // Subsite directory structure taxonomy term.
    $ds_term = $this->select('centers_nav', 'hp')
      ->fields('hp', ['corresponding_url_generator_tids'])
      ->condition('id', $row->getSourceProperty('id'))
      ->execute()
      ->fetch();
    $tmp_term = unserialize($ds_term['corresponding_url_generator_tids']);
    $term = $tmp_term['0'];
    $row->setSourceProperty('tid', $term);

    // Subsite info bars.
    $infobars = $this->select('centers_nav', 'hp')
      ->fields('hp', ['info_bar_data'])
      ->condition('id', $row->getSourceProperty('id'))
      ->execute()
      ->fetch();
    $tmp_info = unserialize($infobars['info_bar_data']);
    $info = array_values($tmp_info);
    $row->setSourceProperty('infobars', $info);

    // Subsite meu links.
    $subsite_nav = $this->select('centers_nav', 'hp')
      ->fields('hp', ['links'])
      ->condition('id', $row->getSourceProperty('id'))
      ->execute()
      ->fetch();
    $tmp_nav = unserialize($subsite_nav['links']);
    $nav = array_values($tmp_nav);
    $row->setSourceProperty('subsite_nav', $nav);

    // Subsite social accounts.
    $subsite_social = $this->select('centers_nav', 'ss')
      ->fields('ss', ['subsite_social_accounts'])
      ->condition('id', $row->getSourceProperty('id'))
      ->execute()
      ->fetch();
    $accounts = unserialize($subsite_social['subsite_social_accounts']);
    $to_migrate = FALSE;
    foreach ($accounts as $key => $value) {
      if ($value != '') {
        $to_migrate = TRUE;
      }
    }
    if ($to_migrate == TRUE) {
      $row->setSourceProperty('social_links', $subsite_social['subsite_social_accounts']);
    }

    return parent::prepareRow($row);
  }

}
