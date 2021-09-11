<?php

namespace Drupal\utprof_migrate\Plugin\migrate\destination;

use Drupal\Core\Database\Database;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\migrate\Plugin\migrate\destination\EntityContentBase;
use Drupal\migrate\Plugin\MigrateDestinationInterface;
use Drupal\migrate\Row;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Provides the 'utprof:node' destination plugin.
 *
 * @MigrateDestination(
 *   id = "utprof:node"
 * )
 */
class Profile extends EntityContentBase implements MigrateDestinationInterface {

  /**
   * Import function that runs on each row.
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    // Convert D7 textarea field structure to fit Horizontal tabs field.
    $body = $row->getDestinationProperty('field_utprof_content');
    $row->setDestinationProperty('field_utprof_content', [
      'header' => '',
      'body_value' => $body[0]['value'],
      'body_format' => MigrateHelper::getDestinationTextFormat($body[0]['format']),
    ]);

    // Convert D7 link value to D8 true/false.
    $link_behavior = $row->getDestinationProperty('field_utprof_listing_link');
    $d8_link = $link_behavior[0]['value'] === 'linked' ? TRUE : FALSE;
    $row->setDestinationProperty('field_utprof_listing_link', $d8_link);


    // Convert 'Additional basic info' from Quick Links'. The header, copy &
    // links will populate the v3 WYSIWYG something like this:
    // <h3>Lorem Ipsum</h3><p>Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam.</p><p><a href="http://stanford.edu">JD Stanford</a></p><p><a href="http://wesleyan.edu">BA Wesleyan University</a></p>
    $add_basic_info = $row->getDestinationProperty('field_utprof_add_basic_info');
    $basic_info_string = '';
    if (!empty($add_basic_info[0]['headline'])) {
      $basic_info_string .= '<h3>' . $add_basic_info[0]['headline'] . '</h3>';
    }
    if (!empty($add_basic_info[0]['copy_value'])) {
      $basic_info_string .= '<p>' . $add_basic_info[0]['copy_value'] . '</p>';
    }
    if (!empty($add_basic_info[0]['links'])) {
      $links = unserialize($add_basic_info[0]['links']);
      if (!empty($links)) {
        foreach ($links as $link) {
          $link_uri = MigrateHelper::prepareLink($link['link_url']);
          $title = $link['link_title'] ?? $link_uri;
          $basic_info_string .= '<p>' . Link::fromTextAndUrl($title, Url::fromUri($link_uri))->toString() . '</p>';
        }
      }
    }
    $basic_info = [
      'value' => $basic_info_string,
      'format' => MigrateHelper::getDestinationTextFormat($add_basic_info[0]['copy_value']),
    ];
    $row->setDestinationProperty('field_utprof_add_basic_info', $basic_info);

    // Retrieve contact info entity value from source; place values into fields.
    $contact_info_source = $row->getDestinationProperty('field_utprof_add_contact_info');
    if (!empty($contact_info_source[0]['target_id'])) {
      $contact_info = $this->getContactInfoData($contact_info_source[0]['target_id']);
      $row->setDestinationProperty('field_utprof_website_link', $contact_info[0]->field_url);
      $row->setDestinationProperty('field_utprof_fax_number', $contact_info[0]->field_fax);
      $row->setDestinationProperty('field_utprof_phone_number', $contact_info[0]->field_phone);
      $row->setDestinationProperty('field_utprof_email_address', $contact_info[0]->field_email);
      $addresses = '';
      $l1 = '';
      if (!empty($contact_info[0]->field_location_1)) {
        $l1 .= $contact_info[0]->field_location_1 . '<br />';
      }
      if (!empty($contact_info[0]->field_location_2)) {
        $l1 .= $contact_info[0]->field_location_2 . '<br />';
      }
      if (!empty($contact_info[0]->field_location_3)) {
        $l1 .= $contact_info[0]->field_location_3 . '<br />';
      }
      if (!empty($contact_info[0]->field_location_city)) {
        $l1 .= $contact_info[0]->field_location_city . ', ';
      }
      if (!empty($contact_info[0]->field_location_state)) {
        $l1 .= $contact_info[0]->field_location_state . ' ';
      }
      if (!empty($contact_info[0]->field_location_zip)) {
        $l1 .= $contact_info[0]->field_location_zip;
      }
      if (!empty($l1)) {
        $addresses .= '<strong>Location:</strong><p>' . $l1 . '</p>';
      }
      $l2 = '';
      if (!empty($contact_info[0]->field_address_1)) {
        $l2 .= $contact_info[0]->field_address_1 . '<br />';
      }
      if (!empty($contact_info[0]->field_address_2)) {
        $l2 .= $contact_info[0]->field_address_2 . '<br />';
      }
      if (!empty($contact_info[0]->field_address_3)) {
        $l2 .= $contact_info[0]->field_address_3 . '<br />';
      }
      if (!empty($contact_info[0]->field_address_city)) {
        $l2 .= $contact_info[0]->field_address_city . ', ';
      }
      if (!empty($contact_info[0]->field_address_state)) {
        $l2 .= $contact_info[0]->field_address_state . ' ';
      }
      if (!empty($contact_info[0]->field_address_zip)) {
        $l2 .= $contact_info[0]->field_address_zip;
      }
      if (!empty($l2)) {
        $addresses .= '<strong>Mailing address:</strong><p>' . $l2 . '</p>';
      }
      $row->setDestinationProperty('field_utprof_add_contact_info', [
        'value' => $addresses,
        'format' => 'flex_html'
      ]);
    }

    return parent::import($row, $old_destination_id_values);
  }

  /**
   * Query the source database for data.
   *
   * @param int $id
   *   The contact info ID from the source data.
   *
   * @return array
   *   Returns an array of the widget data.
   */
  public function getContactInfoData($id) {
    // Get all instances from the legacy DB.
    Database::setActiveConnection('utexas_migrate');
    $source_data = Database::getConnection()->select('utexas_contact_info', 'c')
      ->fields('c')
      ->condition('id', $id)
      ->execute()
      ->fetchAll();
    Database::setActiveConnection('default');
    return $source_data;
  }
}
