<?php

namespace Drupal\moody_subsite\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\user\UserInterface;

/**
 * Defines the Moody subsite entity.
 *
 * @ingroup moody_subsite
 *
 * @ContentEntityType(
 *   id = "moody_subsite",
 *   label = @Translation("Moody subsite"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\moody_subsite\MoodySubsiteListBuilder",
 *     "views_data" = "Drupal\moody_subsite\Entity\MoodySubsiteViewsData",
 *     "translation" = "Drupal\moody_subsite\MoodySubsiteTranslationHandler",
 *
 *     "form" = {
 *       "default" = "Drupal\moody_subsite\Form\MoodySubsiteForm",
 *       "add" = "Drupal\moody_subsite\Form\MoodySubsiteForm",
 *       "edit" = "Drupal\moody_subsite\Form\MoodySubsiteForm",
 *       "delete" = "Drupal\moody_subsite\Form\MoodySubsiteDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\moody_subsite\MoodySubsiteHtmlRouteProvider",
 *     },
 *     "access" = "Drupal\moody_subsite\MoodySubsiteAccessControlHandler",
 *   },
 *   base_table = "moody_subsite",
 *   data_table = "moody_subsite_field_data",
 *   translatable = TRUE,
 *   admin_permission = "administer moody subsite entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "name",
 *     "uuid" = "uuid",
 *     "uid" = "user_id",
 *     "langcode" = "langcode",
 *     "published" = "status",
 *   },
 *   links = {
 *     "canonical" = "/admin/structure/moody_subsite/{moody_subsite}",
 *     "add-form" = "/admin/structure/moody_subsite/add",
 *     "edit-form" = "/admin/structure/moody_subsite/{moody_subsite}/edit",
 *     "delete-form" = "/admin/structure/moody_subsite/{moody_subsite}/delete",
 *     "collection" = "/admin/structure/moody_subsite",
 *   },
 *   field_ui_base_route = "moody_subsite.settings"
 * )
 */
class MoodySubsite extends ContentEntityBase implements MoodySubsiteInterface {

  use EntityChangedTrait;
  use EntityPublishedTrait;

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage_controller, array &$values) {
    parent::preCreate($storage_controller, $values);
    $values += [
      'user_id' => \Drupal::currentUser()->id(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return $this->get('name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setName($name) {
    $this->set('name', $name);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime($timestamp) {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwner() {
    return $this->get('user_id')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnerId() {
    return $this->get('user_id')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwnerId($uid) {
    $this->set('user_id', $uid);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwner(UserInterface $account) {
    $this->set('user_id', $account->id());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Add the published field.
    $fields += static::publishedBaseFieldDefinitions($entity_type);

    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Authored by'))
      ->setDescription(t('The user ID of author of the Moody subsite entity.'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setTranslatable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'author',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 0,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'autocomplete_type' => 'tags',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setDescription(t('The administrative name of the Moody subsite entity. This name will only be seen on administrative forms.'))
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -4,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setRequired(TRUE);

    $fields['status']->setDescription(t('A boolean indicating whether the Moody subsite is published.'))
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 1,
      ]);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the entity was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the entity was last edited.'));

    // *** Custom fields ***
    // Subsite navigation.
    $fields['subsite_nav'] = BaseFieldDefinition::create('moody_subsite_menu')
      ->setLabel(t('Subsite Navigation'))
      ->setCardinality('-1')
      ->setDisplayOptions('form', [
        'type' => 'moody_subsite_menu_widget',
        'weight' => 10,
      ])
      ->setDisplayOptions('view', [
        'type' => 'moody_subsite_menu_formatter',
        'label' => 'hidden',
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->addConstraint('subsite_menu_validation')
      ->setRequired(FALSE);

    // Subsite display name.
    $fields['display_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Display Name'))
      ->setDescription(t('The display name of the Moody subsite entity. This is the title that will be displayed to site visitors.'))
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayOptions('form', [
        'label' => 'above',
        'type' => 'string_textfield',
        'weight' => 12,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setRequired(TRUE);

    // Subsite home link.
    $fields['base_url'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Homepage URL'))
      ->setDescription(t('The URL of the Moody subsite homepage. Only add the URL arguments preceded by a slash. For instance, if the homepage is "https://moody.utexas.edu/mycenter" enter "/mycenter".'))
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -6,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 14,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->addConstraint('subsite_url_constraint')
      ->setRequired(TRUE);

    // Page title style option list.
    $fields['title_display_option'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Page Title Style'))
      ->setDescription(t('Select how the page title should be displayed.'))
      ->setDefaultValue('1')
      ->setSettings([
        'allowed_values' => [
          '1' => 'Page title only',
          '2' => 'No page or subsite titles',
          '3' => 'Subsite name prepended to page title',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => '16',
      ])
      ->setDisplayOptions('view', [
        'type' => 'list_default',
        'label' => 'hidden',
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setRequired(TRUE);

    // Subsite footer textarea.
    $fields['subsite_footer_text'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Subsite Footer Text'))
      ->setDescription(t('Optionally, enter text to be displayed in the left portion of the footer.'))
      ->setDefaultValue('')
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 18,
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
        'label' => 'hidden',
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setRequired(FALSE);

    // Custom GIVE link.
    $fields['give_link'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Give link'))
      ->setDescription(t('Optionally, enter URL for GIVE button in the header.'))
      ->setDefaultValue('')
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 20,
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
        'label' => 'hidden',
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setRequired(FALSE);


    // Subsite hero image.
    $fields['hero'] = BaseFieldDefinition::create('moody_subsite_hero')
      ->setLabel(t('Default Hero Image'))
      ->setDescription(t('The subsite default hero image.'))
      ->setDisplayOptions('form', [
        'type' => 'moody_subsite_hero',
        'weight' => 22,
      ])
      ->setDisplayOptions('view', [
        'type' => 'moody_subsite_hero',
        'weight' => 5,
        'label' => 'hidden',
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setRequired(TRUE);

    // Subsite custom homepage hero checkbox.
    $fields['subsite_home_hero'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Special styling on subsite homepage hero image.'))
      ->setDescription(t('Check this box to display custom styling on subsite homepage hero. The subsite homepage is set in the Homepage URL configuration option.'))
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 23,
      ]);

    // Custom logo field.
    $fields['custom_logo'] = BaseFieldDefinition::create('subsite_custom_logo')
      ->setLabel(t('Custom Logo'))
      ->setDescription(t('The subsite custom logo - to be used only for centers and insitutes.'))
      ->setDisplayOptions('form', [
        'type' => 'subsite_custom_logo_widget',
        'weight' => 24,
      ])
      ->setDisplayOptions('view', [
        'type' => 'subsite_custom_logo_formatter',
        'weight' => 5,
        'label' => 'hidden',
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setRequired(TRUE);

    // Subsite info bars.
    $fields['subsite_info_bars'] = BaseFieldDefinition::create('moody_info_bars')
      ->setLabel(t('Info Bars: custom text/links to display above the hero image.'))
      ->setCardinality('4')
      ->setDisplayOptions('form', [
        'type' => 'moody_info_bars_widget',
        'weight' => 26,
      ])
      ->setDisplayOptions('view', [
        'type' => 'moody_info_bars_formatter',
        'label' => 'hidden',
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->addConstraint('subsite_infobars_constraint')
      ->setRequired(FALSE);

    // Subsite social links.
    $fields['subsite_social_links'] = BaseFieldDefinition::create('moody_social_accounts')
      ->setLabel(t('Subsite social links'))
      ->setDescription(t('Optionally, enter custom social links to display in lieu of the main site social accounts in the header.'))
      ->setDisplayOptions('form', [
        'type' => 'moody_social_accounts_widget',
        'weight' => '28',
      ])
      ->setDisplayOptions('view', [
        'type' => 'moody_social_accounts_formatter',
        'label' => 'hidden',
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setRequired(FALSE);

    // Hide all social links in the subsite header.
    $fields['hide_all_social_accounts'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Hide all social accounts'))
      ->setDescription(t('Check this box to hide all social account links in the subsite header, including sitewide defaults and subsite overrides.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 29,
      ]);

    // Taxonomy term selector.
    $fields['directory_structure'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Directory Structure term reference'))
      ->setDescription(t('Select the taxonomy term to associate this subsite with.'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler_settings', ['target_bundles' => ['taxonomy_term' => 'directory_structure']])
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'entity_reference_entity_id',
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 50,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setRequired(TRUE);

    return $fields;
  }

}
