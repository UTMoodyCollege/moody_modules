<?php

namespace Drupal\utexas_migrate\Plugin\migrate\process;

use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\utexas_migrate\CustomWidgets\BackgroundAccent;
use Drupal\utexas_migrate\CustomWidgets\BasicBlock;
use Drupal\utexas_migrate\MigrateHelper;
use Drupal\utexas_migrate\CustomWidgets\FlexContentArea;
use Drupal\utexas_migrate\CustomWidgets\FeaturedHighlight;
use Drupal\utexas_migrate\CustomWidgets\Hero;
use Drupal\utexas_migrate\CustomWidgets\UtexasHero;
use Drupal\utexas_migrate\CustomWidgets\ImageLink;
use Drupal\utexas_migrate\CustomWidgets\PhotoContentArea;
use Drupal\utexas_migrate\CustomWidgets\PromoLists;
use Drupal\utexas_migrate\CustomWidgets\PromoUnits;
use Drupal\utexas_migrate\CustomWidgets\QuickLinks;
use Drupal\utexas_migrate\CustomWidgets\Resource;
use Drupal\utexas_migrate\CustomWidgets\SocialLinks;
use Drupal\utexas_migrate\CustomWidgets\MoodyShowcase;
use Drupal\utexas_migrate\CustomWidgets\MoodyFlexTabs;
use Drupal\utexas_migrate\CustomWidgets\MoodyFeaturedHighlight;
use Drupal\utexas_migrate\CustomWidgets\CustomBlock;

/**
 * Layouts Processor.
 *
 * This plugin takes care of processing a D7 "Page Layout"
 * into something consumable buy D8 "Layout Builder".
 *
 * @MigrateProcessPlugin(
 *   id = "utexas_process_layout"
 * )
 */
class Layouts extends ProcessPluginBase {

  /**
   * The main function.
   *
   * Given a row that contains a destination node ID and
   * the source context for all of the D7 fields, build an array of inline data,
   * organized by Drupal 8 section components, then save that to the node's
   * layout (node__layout_builder__layout).
   */
  public function transform($layout, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    // 1. Get the template name (e.g., "Featured Highlight")
    // and the destination ID (e.g., "1")
    $template = $row->getSourceProperty('template');
    $nid = $row->getDestinationProperty('temp_nid');

    // This contains all inline field data as well as layout structure,
    // in a single array.
    $section_data = self::buildSectionsArray($layout, $template, $nid, $row);
    // print_r($section_data);
    // @breakpoint recommendation.
    // 2. Put those array elements into D8 section objects.
    $sections = [];
    foreach ($section_data as $section) {
      $d8_components = [];
      if (!empty($section['components'])) {
        foreach ($section['components'] as $component) {
          $d8_component = self::createD8SectionComponent($component);
          if ($d8_component) {
            $d8_components[] = $d8_component;
          }
        }
        if (!empty($d8_components) && !empty($section['layout'])) {
          if (!isset($section['layoutSettings'])) {
            $section['layoutSettings'] = [];
          }
          $section = self::createD8Section($section['layout'], $section['layoutSettings'], $d8_components);
          $sections[] = $section;
        }

      }
    }
    return $sections;
  }

  /**
   * Get layout data into a traversable format.
   *
   * @param string $layout
   *   A serialized array of layout data from the "context" table.
   * @param string $template
   *   The D7 template associated with this page.
   * @param int $nid
   *   The destination NID.
   * @param Drupal\migrate\Row $row
   *   Other entity source data related to this specific entity migration.
   */
  protected static function buildSectionsArray($layout, $template, $nid, Row $row) {
    $layout_data = unserialize($layout);
    // Extract the blocks in the layout from the 'context' table.
    $blocks = isset($layout_data['block']['blocks']) ? $layout_data['block']['blocks'] : '';

    if ($blocks == '') {
      // print_r($layout_data);
      // echo $template;
      // echo $nid;
      // print_r($row);
      return [];
    }

    // Look up presence of "locked" fields & add them programmatically
    // as blocks, potentially adjusting weight of other blocks.
    $blocks = self::addLockedFieldsAsBlocks($blocks, $template, $nid, $row);
    // print_r($blocks);
    // Build up the D8 sections based on known information about the D7 layout:
    $sections = self::getD8SectionsfromD7Layout($template, $nid, $row);
    // print_r($sections);
    // Loop through all known blocks, building the D8 section components.
    foreach ($blocks as $id => $settings) {
      $found = FALSE;
      if (in_array($id, array_keys(MigrateHelper::$excludedFieldblocks))) {
        // Skip "excluded" fieldblocks, like Twitter Widget, Contact Info,
        // since UTDK8 doesn't currently have a location for these.
        continue;
      }
      elseif (in_array($id, array_keys(MigrateHelper::$includedFieldBlocks))) {
        $field_name = MigrateHelper::$includedFieldBlocks[$id];
        $found = TRUE;
      }
      elseif ($settings['region'] == 'social_links') {
        // The above eliminates fieldblocks not yet converted to UUIDs.
        // @todo: look up standard blocks' block UUIDs in FlexPageLayoutsSource.php
        // This code may need to be refactored to further disambiguate.
        // This is not a fieldblock (e.g., Social Links). Use the block ID.
        $field_name = 'social_links';
        $found = TRUE;
      }
      elseif ($settings['module'] == 'block') {
        $field_name = 'custom_block';
        $found = TRUE;
      }

      if ($found) {
        // @todo: Revise the placeFieldinSection() method to use inline blocks.
        // Now that we know we have a field, check for a D7 display setting,
        // and if so, pass an equivalent view_mode to the D8 field formatter.
        $field_data = self::retrieveFieldData($field_name, $row);
        $sections = self::placeFieldinSection($sections, $field_data, $settings, $template);
      }
    }
    return $sections;
  }

  /**
   * Get Drupal 7 layout data into a traversable format.
   *
   * @param string $field_name
   *   The Drupal 8 field name (e.g., field_flex_page_fh).
   * @param Drupal\migrate\Row $row
   *   Other entity source data related to this specific entity migration.
   */
  protected static function retrieveFieldData($field_name, Row $row) {
    $nid = $row->getSourceProperty('nid');
    $page_type = $row->getSourceProperty('type');
    $formatter = [
      'label' => 'hidden',
    ];
    switch ($field_name) {
      case 'featured_highlight':
        $block_type = 'utexas_featured_highlight';
        $source = FeaturedHighlight::getFromNid($nid);
        break;

      case 'featured_highlight_a':
        $block_type = 'utexas_featured_highlight';
        $source = MoodyFeaturedHighlight::getFromNid($nid);
        break;

      case 'flex_content_area_a':
      case 'flex_content_area_b':
        $block_type = 'utexas_flex_content_area';
        $source = FlexContentArea::getFromNid($field_name, $nid);
        break;

      case 'hero':
        $block_type = 'moody_hero';
        if ($page_type == 'moody_landing_page') {
          $source = Hero::getFromNid('hero_photo', $nid);
        }
        else {
          $source = UtexasHero::getFromNid('hero_photo', $nid);
        }
        break;

      case 'image_link_a':
      case 'image_link_b':
        $block_type = 'utexas_image_link';
        $source = ImageLink::getFromNid($field_name, $nid);
        break;

      case 'photo_content_area':
        $block_type = 'utexas_photo_content_area';
        $source = PhotoContentArea::getFromNid($nid);
        break;

      case 'promo_unit':
        $block_type = 'utexas_promo_unit';
        $source = PromoUnits::getFromNid($nid);
        break;

      case 'promo_list':
        $block_type = 'utexas_promo_list';
        $source = PromoLists::getFromNid($nid);
        break;

      case 'quick_links':
        $block_type = 'utexas_quick_links';
        $source = QuickLinks::getFromNid($nid);
        break;

      case 'resource':
        $source = Resource::getFromNid($nid);
        $block_type = 'utexas_resources';
        break;

      case 'social_links':
        $source = SocialLinks::getFromNid($nid);
        $block_type = 'social_links';
        break;

      case 'moody_showcase':
        $source = MoodyShowcase::getFromNid($field_name, $nid);
        $block_type = 'moody_showcase';
        break;

      case 'tabs':
        $source = MoodyFlexTabs::getFromNid($field_name, $nid);
        $block_type = 'moody_flex_tabs';
        break;

      case 'wysiwyg_a':
      case 'wysiwyg_b':
      case 'wysiwyg_c':
      case 'wysiwyg_d':
        $block_type = 'basic';
        $source = BasicBlock::getFromNid($field_name, $nid);
        break;

      case 'custom_block':
        $block_type = $source['block_type'];
        $source = CustomBlock::getBlockData($field_name, $row->delta);
        break;

    }

    return [
      'field_name' => $field_name,
      'block_type' => $block_type,
      'data' => $source,
      'format' => $formatter,
    ];
  }

  /**
   * Add Drupal 7 "locked" fields to D7 data.
   *
   * @param array $blocks
   *   The D7 block data for this given node.
   * @param string $template
   *   The D7 template associated with this page.
   * @param int $nid
   *   The destination NID.
   * @param Drupal\migrate\Row $row
   *   Other entity source data related to this specific entity migration.
   */
  protected static function addLockedFieldsAsBlocks(array $blocks, $template, $nid, Row $row) {
    $node = Node::load($nid);
    // Check if a social link exists on the source node.
    if ($social_link = SocialLinks::getRawSourceData($row->getSourceProperty('nid'))) {
      // Make a fake D7 block ID that can be identified later on.
      $blocks['inline_social_links'] = [
        'type' => 'social_links',
        'region' => 'social_links',
        'weight' => '-1',
      ];
    }
    if ($hir = UtexasHero::getRawSourceData('hero_photo', $row->getSourceProperty('nid'))) {
      $region = FALSE;
      switch ($template) {
        case 'Moody Hero Image & Sidebars':
        case 'Moody Promotional Page & Sidebar':
        case 'Moody Layout 1':
        case 'Moody Layout 2':
        case 'Moody Layout 3':
        case 'Moody Layout 4':
        case 'Moody Layout 5':
          $region = 'hero_image';
          $id = 'fieldblock-8b42a7d369dcd54d7878fb0015188cba';
          break;
      }
      if ($region) {
        // Enforce that hero image is above other content.
        $blocks[$id] = [
          'region' => $region,
          'weight' => '-1',
        ];
      }
    }

    if ($hi = Hero::getRawSourceData('hero_photo', $row->getSourceProperty('nid'))) {
      $region = FALSE;
      switch ($template) {
        case 'Moody Landing Page Template 1':
        case 'Moody Landing Page Template 2':
        case 'Moody Landing Page Template 3':
        case 'Moody Landing Page Template 4':
          $region = 'hero_image';
          $id = 'fieldblock-8b42a7d369dcd54d7878fb0015188cba';
          break;
      }
      if ($region) {
        // Enforce that hero image is above other content.
        $blocks[$id] = [
          'region' => $region,
          'weight' => '-1',
        ];
      }
    }

    if ($mfh = MoodyFeaturedHighlight::getRawSourceData($row->getSourceProperty('nid'))) {
      $region = FALSE;
      switch ($template) {
        case 'Moody Featured Highlight':
          $region = 'featured_highlight';
          $id = 'fieldblock-4d72f6d0d1dca617b0da9621ac41c6d7';
          break;

        case 'Moody Landing Page Template 1':
        case 'Moody Landing Page Template 2':
        case 'Moody Landing Page Template 3':
          $region = 'featured_highlight';
          $id = 'fieldblock-4d72f6d0d1dca617b0da9621ac41c6d7';
          break;
      }
      if ($region) {
        // Enforce that this locked field is above other content,
        // and is above Quick Links, if present.
        $blocks[$id] = [
          'region' => $region,
          'weight' => '-2',
        ];
      }
    }

    if ($fh = FeaturedHighlight::getRawSourceData($row->getSourceProperty('nid'))) {
      $region = FALSE;
      switch ($template) {
        case 'Moody Featured Highlight':
          $region = 'featured_highlight';
          $id = 'fieldblock-553096d7ea242fc7edcddc53f719d074';
          break;

        case 'Moody Landing Page Template 1':
        case 'Moody Landing Page Template 2':
        case 'Moody Landing Page Template 3':
          $region = 'featured_highlight';
          $id = 'fieldblock-205723da13bdadd816a716421b436a92';
          break;

        case 'Moody Landing Page Template 3':
          $region = 'moody_featured_highlight_a';
          $id = 'fieldblock-205723da13bdadd816a716421b436a92';
          break;
      }
      if ($region) {
        // Enforce that this locked field is above other content,
        // and is above Quick Links, if present.
        $blocks[$id] = [
          'region' => $region,
          'weight' => '-2',
        ];
      }
    }

    if ($ql = QuickLinks::getRawSourceData($row->getSourceProperty('nid'))) {
      $region = FALSE;
      switch ($template) {
        case 'Moody Landing Page Template 2':
          $region = 'quick_links';
          $id = 'fieldblock-669a6a1f32566fa73ea7974696027184';
          break;
      }
      if ($region) {
        // Enforce that this locked field is above other content.
        $blocks[$id] = [
          'region' => $region,
          'weight' => '-1',
        ];
      }
    }

    if ($w = BasicBlock::getFromNid('wysiwyg_a', $nid)) {
      $region = FALSE;
      switch ($template) {
        case 'Moody Open Text Page':
        case 'Moody Multimedia Page':
          $region = 'wysiwyg_a';
          break;
      }
      if ($region) {
        // Enforce that this locked field is above other content.
        $blocks['fieldblock-e3569674b88f0fe2acd2c66bf61b18ee'] = [
          'region' => $region,
          'weight' => '-1',
        ];
        $blocks['fieldblock-9604b012ffab0bf4101338cd3d192880'] = [
          'region' => $region,
          'weight' => '-1',
        ];
        $blocks['fieldblock-7feef85d0c580a673562bfcd6f92439c'] = [
          'region' => $region,
          'weight' => '-1',
        ];
        $blocks['fieldblock-a0e75cb0a63487edd556d74866b3bd5f'] = [
          'region' => $region,
          'weight' => '-1',
        ];
        $blocks['fieldblock-565dc5bc6c9e396224faa42ed9ac142c'] = [
          'region' => $region,
          'weight' => '-1',
        ];
        $blocks['fieldblock-6f3b85225f51542463a88e53104f8753'] = [
          'region' => $region,
          'weight' => '-1',
        ];
        $blocks['fieldblock-fda604d130a57f15015895c8268f20d2'] = [
          'region' => $region,
          'weight' => '-1',
        ];
      }
    }

    if ($ms = MoodyShowcase::getFromNid('moody_showcase', $nid)) {
      $region = FALSE;
      switch ($template) {
        case 'Moody Landing Page Template 1':
        case 'Moody Landing Page Template 2':
        case 'Moody Landing Page Template 3':
        case 'Moody Landing Page Template 4':
          $region = 'moody_showcase';
          break;
      }
      if ($region) {
        // Enforce that this locked field is above other content.
        $blocks['fieldblock-08e3ecf290052f1e6a356a0ad1384af3'] = [
          'region' => $region,
          'weight' => '-1',
        ];
        $blocks['fieldblock-917b59c360f2b31644b34bfb8ac6ca5b'] = [
          'region' => $region,
          'weight' => '-1',
        ];
        $blocks['fieldblock-d09cc58da1200889d2f272800ceebb7a'] = [
          'region' => $region,
          'weight' => '-1',
        ];
      }
    }
    return $blocks;
  }

  /**
   * Build the sections that will comprise this page's layout.
   *
   * @param string $template
   *   The D7 template associated with this page.
   * @param int $nid
   *   The destination NID.
   * @param Drupal\migrate\Row $row
   *   Other entity source data related to this specific entity migration.
   */
  protected static function getD8SectionsfromD7Layout($template, $nid, Row $row) {
    $sections = [];
    $onecol = [
      'layout' => 'layout_utexas_onecol',
      'layoutSettings' => [
        'section_width' => 'container',
      ],
    ];
    // $onecol_full_width = [
    //   'layout' => 'layout_utexas_onecol',
    //   'layoutSettings' => [
    //     'layout_builder_styles_style' => [
    //       'utexas_no_padding',
    //     ],
    //   ],
    // ];
    $onecol_container_width = [
      'layout' => 'layout_utexas_onecol',
      'layoutSettings' => [
        'section_width' => 'container',
      ],
    ];
    $onecol_full_width = [
      'layout' => 'layout_utexas_onecol',
      'layoutSettings' => [
        'layout_builder_styles_style' => [
          'utexas_no_padding',
        ],
        'section_width' => 'container-fluid',
      ],
    ];
    $fifty_fifty = [
      'layout' => 'layout_utexas_twocol',
      'layoutSettings' => [
        'column_widths' => '50-50',
      ],
    ];
    $sixty_six_thirty_three = [
      'layout' => 'layout_utexas_twocol',
      'layoutSettings' => [
        'column_widths' => '67-33',
      ],
    ];
    switch ($template) {
      case 'Moody Hero Image & Sidebars':
      case 'Moody Header with Content & Sidebars';
        $sections[0] = $sixty_six_thirty_three;
        $sections[1] = $sixty_six_thirty_three;
        break;

      case 'Moody Promotional Page & Sidebar';
      case 'Moody Full Content Page & Sidebar':
        $sections[0] = $sixty_six_thirty_three;
        break;

      case 'Moody Featured Highlight':
        $sections[0] = $fifty_fifty;
        $sections[1] = $onecol_full_width;
        $sections[2] = $sixty_six_thirty_three;
        break;

      case 'Moody Full Width Content Page & Title':
      case 'Moody Full Width Content Page':
        $sections[0] = $onecol;
        $sections[1] = $onecol;
        break;

      case 'Moody Layout 2';
      case 'Moody Open Text Page':
        $sections[0] = $onecol;
        break;

      case 'Moody Layout 1';
        $sections[0] = $onecol;
        $sections[1] = $sixty_six_thirty_three;
        break;

      case 'Moody Layout 3';
        $sections[0] = $onecol;
        $sections[1] = $sixty_six_thirty_three;
        $sections[2] = $onecol_full_width;
        $sections[3] = $sixty_six_thirty_three;
        break;

      case 'Moody Layout 4';
        $sections[0] = $onecol;
        $sections[1] = $onecol_full_width;
        $sections[2] = $sixty_six_thirty_three;
        $sections[3] = $onecol_full_width;
        break;

      case 'Moody Layout 5';
        $sections[0] = $onecol;
        $sections[1] = $onecol_full_width;
        $sections[2] = $onecol;
        $sections[3] = $onecol_full_width;
        break;

      case 'Moody Landing Page Template 1':
        // First section is always hero photo.
        $sections[0] = $onecol_full_width;
        $sections[1] = $sixty_six_thirty_three;
        // Third section is always Featured Highlight.
        $sections[2] = $onecol_full_width;
        $sections[3] = $sixty_six_thirty_three;
        break;

      case 'Moody Landing Page Template 2':
        // First section is always hero photo.
        $sections[0] = $onecol_full_width;
        $sections[1] = $onecol;
        // Third section is always Featured Highlight + Quick Links.
        $sections[2] = $onecol_full_width;
        $sections[3] = $onecol;
        break;

      case 'Moody Landing Page Template 3':
        // First section is always hero photo.
        $sections[0] = $onecol_full_width;
        $sections[1] = $onecol;
        // Third section is always Featured Highlight.
        $sections[2] = $onecol_full_width;
        $sections[3] = $sixty_six_thirty_three;
        break;

      case 'Moody Landing Page Template 4':
        // First section is always hero photo.
        $sections[0] = $onecol_full_width;
        $sections[1] = $onecol;
        $sections[2] = $sixty_six_thirty_three;
        $sections[3] = $onecol_full_width;
        $sections[4] = $onecol;
        $sections[5] = $onecol_full_width;
        break;

      case 'Moody Multimedia Page':
        $sections[0] = $fifty_fifty;
        $sections[1] = $sixty_six_thirty_three;
        $sections[2] = $onecol;
    }

    switch ($template) {
      case 'Moody Landing Page Template 1':
      case 'Moody Landing Page Template 2':
      case 'Moody Landing Page Template 3':
      case 'Moody Landing Page Template 3':
        // Add a background accent, if present.
        $background_accent = BackgroundAccent::getFromNid($row->getSourceProperty('nid'));
        if (!empty($background_accent)) {
          $sections[2]['layoutSettings']['blur'] = $background_accent['blur'];
          $sections[2]['layoutSettings']['background-accent'] = $background_accent['image'];
        }
        break;
    }

    return $sections;
  }

  /**
   * Given a D7 field setting & template, place it in the equivalent D8 section.
   *
   * @param array $sections
   *   The sections as defined in the D8 equivalent layout from D7..
   * @param string $field_data
   *   The field data.
   * @param array $settings
   *   Field settings, namely region & weight.
   * @param string $template
   *   The D7 template name.
   */
  protected static function placeFieldinSection(array $sections, $field_data, array $settings, $template) {
    // In D7, many sidebar regions apply a border w/ background style to blocks.
    $layout_builder_styles_border_with_background = [
      'layout_builder_styles_style' => [
        [],
      ],
    ];
    $d8_field = $field_data['field_name'];
    $sidebar_flag = FALSE;
    switch ($template) {
      // Moody Standard Pages.
      // $sections[0] = $sixty_six_thirty_three;
      // $sections[1] = $sixty_six_thirty_three;
      case 'Moody Hero Image & Sidebars':
        switch ($settings['region']) {
          case 'hero_image':
            $delta = 0;
            $region = 'first';
            break;

          case 'content_top_right':
            $delta = 0;
            $region = 'second';
            // $view_mode = (in_array($d8_field, ['resource'])) ? 'utexas_resources_2' : 'default';
            $sidebar_flag = TRUE;
            break;

          case 'content':
            $delta = 1;
            $region = 'first';
            break;

          case 'social_links':
          case 'sidebar_second':
          case 'contact_info':
            $delta = 1;
            $region = 'second';
            $additional = $layout_builder_styles_border_with_background;
            // $view_mode = (in_array($d8_field, ['resource'])) ? 'utexas_resources_2' : 'default';
            $sidebar_flag = TRUE;
            break;
        }
        break;

      // $sections[0] = $sixty_six_thirty_three;
      // $sections[1] = $sixty_six_thirty_three;
      case 'Moody Header with Content & Sidebars':
        switch ($settings['region']) {
          case 'content_top_left':
            $delta = 0;
            $region = 'first';
            break;

          case 'content_top_right':
            $delta = 0;
            $region = 'second';
            $sidebar_flag = TRUE;
            break;

          case 'content_bottom':
            $delta = 1;
            $region = 'first';
            break;

          case 'social_links':
          case 'sidebar_second':
          case 'contact_info':
            $delta = 1;
            $region = 'second';
            $additional = $layout_builder_styles_border_with_background;
            $sidebar_flag = TRUE;
            break;
        }
        break;

      // $sections[0] = $sixty_six_thirty_three;
      case 'Moody Promotional Page & Sidebar':
        switch ($settings['region']) {
          case 'hero_image':
          case 'content':
            $delta = 0;
            $region = 'first';
            break;

          case 'social_links':
          case 'sidebar_second':
          case 'contact_info':
            $delta = 0;
            $region = 'second';
            $additional = $layout_builder_styles_border_with_background;
            $sidebar_flag = TRUE;
            break;
        }
        break;

      // $sections[0] = $sixty_six_thirty_three;
      case 'Moody Full Content Page & Sidebar':
        switch ($settings['region']) {
          case 'content':
            $delta = 0;
            $region = 'first';
            break;

          case 'social_links':
          case 'sidebar_second':
          case 'contact_info':
            $delta = 0;
            $region = 'second';
            $additional = $layout_builder_styles_border_with_background;
            $sidebar_flag = TRUE;
            break;
        }
        break;

      // $sections[0] = $fifty_fifty;
      // $sections[1] = $onecol_full_width;
      // $sections[2] = $sixty_six_thirty_three;
      case 'Moody Featured Highlight':
        switch ($settings['region']) {
          case 'main_content_top_left':
            $delta = 0;
            $region = 'first';
            break;

          case 'main_content_top_right':
            $delta = 0;
            $region = 'second';
            $sidebar_flag = TRUE;
            break;

          case 'featured_highlight':
            $delta = 1;
            $region = 'main';
            break;

          case 'content_bottom':
            $delta = 2;
            $region = 'first';
            break;

          case 'social_links':
          case 'sidebar_second':
          case 'contact_info':
            $delta = 2;
            $region = 'second';
            $additional = $layout_builder_styles_border_with_background;
            $sidebar_flag = TRUE;
            break;
        }
        break;

      // $sections[0] = $onecol;
      // $sections[1] = $onecol;
      case 'Moody Full Width Content Page & Title':
      case 'Moody Full Width Content Page':
        switch ($settings['region']) {
          case 'content_top':
            $delta = 0;
            $region = 'main';
            break;

          case 'content_bottom':
            $delta = 1;
            $region = 'main';
            break;
        }
        break;

      // $sections[0] = $onecol;
      case 'Moody Open Text Page':
        switch ($settings['region']) {
          case 'wysiwyg_a':
          case 'content_bottom':
            $delta = 0;
            $region = 'main';
            break;
        }
        break;

      // $sections[0] = $onecol;
      // $sections[1] = $sixty_six_thirty_three;
      case 'Moody Layout 1':
        switch ($settings['region']) {
          // case 'hero_image':
          //   $delta = 0;
          //   $region = 'main';
          //   break;

          case 'content':
            $delta = 1;
            $region = 'first';
            break;

          case 'social_links':
          case 'sidebar_second':
          case 'contact_info':
            $delta = 1;
            $region = 'second';
            $sidebar_flag = TRUE;
            break;
        }
        break;

      // $sections[0] = $onecol;
      case 'Moody Layout 2':
        switch ($settings['region']) {
          // case 'hero_image':
          case 'content_top':
            $delta = 0;
            $region = 'main';
            break;
        }
        break;

      // $sections[0] = $onecol;
      // $sections[1] = $sixty_six_thirty_three;
      // $sections[2] = $onecol_full_width;
      // $sections[3] = $sixty_six_thirty_three;
      case 'Moody Layout 3':
        switch ($settings['region']) {
          // case 'hero_image':
          //   $delta = 0;
          //   $region = 'main';
          //   break;

          case 'main_content_top_left':
            $delta = 1;
            $region = 'first';
            break;

          case 'main_content_top_right':
            $delta = 1;
            $region = 'second';
            $sidebar_flag = TRUE;
            break;

          case 'featured_highlight':
            $delta = 2;
            $region = 'main';
            break;

          case 'content_bottom':
            $delta = 3;
            $region = 'first';
            break;

          case 'social_links':
          case 'sidebar_second':
          case 'contact_info':
            $delta = 3;
            $region = 'second';
            $sidebar_flag = TRUE;
            break;
        }
        break;

      // $sections[0] = $onecol;
      // $sections[1] = $onecol_full_width;
      // $sections[2] = $sixty_six_thirty_three;
      // $sections[3] = $onecol_full_width;
      case 'Moody Layout 4':
        switch ($settings['region']) {
          // case 'hero_image':
          case 'main_content_top_left':
            $delta = 0;
            $region = 'main';
            break;

          case 'featured_highlight':
            $delta = 1;
            $region = 'main';
            break;

          case 'content_bottom':
            $delta = 2;
            $region = 'first';
            break;

          case 'social_links':
          case 'sidebar_second':
          case 'contact_info':
            $delta = 2;
            $region = 'second';
            $sidebar_flag = TRUE;
            break;

          case 'flex_cta':
            $delta = 3;
            $region = 'main';
            break;
        }
        break;

      // $sections[0] = $onecol;
      // $sections[1] = $onecol_full_width;
      // $sections[2] = $onecol;
      // $sections[3] = $onecol_full_width;
      case 'Moody Layout 5':
        switch ($settings['region']) {
          // case 'hero_image':
          case 'main_content_top_left':
            $delta = 0;
            $region = 'main';
            break;

          case 'featured_highlight':
          case 'moody_showcase':
            $delta = 1;
            $region = 'main';
            break;

          case 'content_bottom':
            $delta = 2;
            $region = 'main';
            break;

          case 'flex_cta':
            $delta = 3;
            $region = 'main';
            break;
        }
        break;

      case 'Moody Layout 6':
        switch ($settings['region']) {
          case 'content_top_three_pillars':
            $delta = 0;
            $region = 'main';
            break;

          case 'content_top_four_pillars':
            $delta = 0;
            $region = 'main';
            break;

          case 'content_top':
            $delta = 0;
            $region = 'main';
            break;

          case 'main_content_top_left':
            $delta = 1;
            $region = 'first';
            break;

          case 'main_content_top_right':
            $delta = 1;
            $region = 'second';
            break;

          case 'featured_highlight':
            $delta = 2;
            $region = 'main';
            break;

          case 'content_bottom':
            $delta = 3;
            $region = 'first';
            break;

          case 'sidebar_second':
            $delta = 3;
            $region = 'second';
            break;
        }
        break;

      // $sections[0] = $onecol_full_width;
      // $sections[1] = $sixty_six_thirty_three;
      // $sections[2] = $onecol_full_width;
      // $sections[3] = $sixty_six_thirty_three;
      case 'Moody Landing Page Template 1':
        switch ($settings['region']) {
          case 'hero_image':
            $delta = 0;
            $region = 'main';
            $view_mode = isset($field_data['data'][0]['view_mode']) ? $field_data['data'][0]['view_mode'] : 'moody_hero_2';
            break;

          case 'content_top_left':
            $delta = 1;
            $region = 'first';
            break;

          case 'content_top_right':
            $delta = 1;
            $region = 'second';
            $sidebar_flag = TRUE;
            break;

          case 'featured_highlight':
          case 'moody_showcase':
          case 'flex_cta':
            $delta = 2;
            $region = 'main';
            break;

          case 'content_bottom':
            $delta = 3;
            $region = 'first';
            break;

          case 'social_links':
          case 'sidebar_second':
            $delta = 3;
            $region = 'second';
            $sidebar_flag = TRUE;
            break;
        }
        break;

      // $sections[0] = $onecol_full_width;
      // $sections[1] = $onecol;
      // $sections[2] = $onecol_full_width;
      // $sections[3] = $onecol;
      case 'Moody Landing Page Template 2':
        switch ($settings['region']) {
          case 'hero_image':
            $delta = 0;
            $region = 'main';
            $view_mode = isset($field_data['data'][0]['view_mode']) ? $field_data['data'][0]['view_mode'] : 'moody_hero_2';
            break;

          case 'content_top_three_pillars':
            if (in_array($d8_field, ['flex_content_area_a', 'flex_content_area_b'])) {
              // Special case: FCA in content_top_three_pillars is 3-columns.
              $view_mode = 'utexas_flex_content_area_3';
              $additional = [
                'layout_builder_styles_style' => [
                  'utexas_threecol' => 'utexas_threecol',
                ],
              ];
            }
            $delta = 1;
            $region = 'main';
            break;

          case 'quick_links':
          case 'moody_showcase':
            $delta = 2;
            $region = 'main';
            break;

          case 'content_bottom':
          case 'flex_cta':
            $delta = 3;
            $region = 'main';
            break;
        }
        break;

      // $sections[0] = $onecol_full_width;
      // $sections[1] = $onecol;
      // $sections[2] = $onecol_full_width;
      // $sections[3] = $sixty_six_thirty_three;
      case 'Moody Landing Page Template 3':
        switch ($settings['region']) {
          case 'hero_image':
            $delta = 0;
            $region = 'main';
            $view_mode = isset($field_data['data'][0]['view_mode']) ? $field_data['data'][0]['view_mode'] : 'moody_hero_2';
            break;

          case 'content_top_four_pillars':
            if (in_array($d8_field, ['flex_content_area_a', 'flex_content_area_b'])) {
              // Special case: FCA in content_top_four_pillars 4-columns.
              $view_mode = 'utexas_flex_content_area_4';
              $additional = [
                'layout_builder_styles_style' => [
                  'utexas_fourcol' => 'utexas_fourcol',
                ],
              ];
            }
            $delta = 1;
            $region = 'main';
            break;

          case 'featured_highlight':
          case 'moody_showcase':
          case 'flex_cta':
            $delta = 2;
            $region = 'main';
            break;

          case 'content_bottom':
            $delta = 3;
            $region = 'first';
            break;

          case 'social_links':
          case 'sidebar_second':
          case 'contact_info':
            $delta = 3;
            $region = 'second';
            $sidebar_flag = TRUE;
            break;

        }
        break;

      // $sections[0] = $onecol_full_width;
      // $sections[1] = $onecol;
      // $sections[2] = $sixty_six_thirty_three;
      // $sections[3] = $onecol_full_width;
      // $sections[4] = $onecol;
      // $sections[5] = $onecol_full_width;
      case 'Moody Landing Page Template 4':
        switch ($settings['region']) {
          case 'hero_image':
            $delta = 0;
            $region = 'main';
            $view_mode = isset($field_data['data'][0]['view_mode']) ? $field_data['data'][0]['view_mode'] : 'moody_hero_2';
            break;

          case 'content_top':
          case 'content_top_middle':
            $delta = 1;
            $region = 'main';
            break;

          case 'content_middle_left':
            $delta = 2;
            $region = 'first';
            break;

          case 'content_middle_right':
            $delta = 2;
            $region = 'second';
            $sidebar_flag = TRUE;
            break;

          case 'moody_showcase':
          case 'featured_highlight':
            $delta = 3;
            $region = 'main';
            break;

          case 'content_bottom':
            $delta = 4;
            $region = 'main';
            break;

          case 'flex_cta':
            $delta = 5;
            $region = 'main';
            break;

        }
        break;
    }

    // Set stacked formatters for content placed in sidebars.
    $field_view_mode_map = [
      'utexas_resources_2' => 'resource',
      'utexas_promo_unit_4' => 'promo_unit',
      'utexas_flex_content_area_1' => 'flex_content_area_a',
      'utexas_flex_content_area_1' => 'flex_content_area_b',
    ];
    if ($sidebar_flag) {
      $key = array_search($d8_field, $field_view_mode_map);
      if ($key) {
        $view_mode = $key;
      }
    }
    else {
      if ($d8_field == 'promo_unit') {
        $additional = [
          'layout_builder_styles_style' => [
            'utexas_onecol' => 'utexas_onecol',
          ],
        ];
      }
      if ($d8_field == 'promo_list') {
        $vm = $field_data['data'][0]['view_mode'];
        $view_mode = $vm;
      }
      if ($d8_field == 'resource') {
        $additional = [
          'layout_builder_styles_style' => [
            'utexas_onecol' => 'utexas_onecol',
          ],
        ];
      }
      if ($d8_field == 'flex_content_area_a' or $d8_field == 'flex_content_area_b') {
        if (!isset($additional['layout_builder_styles_style'])) {
          $additional = [
            'layout_builder_styles_style' => [
              'utexas_twocol' => 'utexas_twocol',
            ],
          ];
        }
      }
    }

    // if (!isset($delta)) {
    //   echo '----------------------------';
    //   echo 'field: ' . $d8_field . '::';
    //   echo 'template: ' . $template . '##';
    //   print_r($sections);
    //   print_r($settings['region']);
    //   print_r($field_data);
    //   echo "----------------------------";
    // }

    // print_r('CUCKOOBANANPANTSCUCKOOBANANPANTS' . PHP_EOL);
    // print_r($d8_field . PHP_EOL);
    // print_r('CUCKOOBANANPANTSCUCKOOBANANPANTS' . PHP_EOL);

    // Guarantee featured highlights are set to blue.
    if ($d8_field == 'featured_highlight' || $d8_field == 'featured_highlight_a') {
      $view_mode = 'utexas_featured_highlight_2';
    }

    $sections[$delta]['components'][$d8_field] = [
      'field_identifier' => $d8_field,
      'block_data' => $field_data['data'],
      'block_type' => $field_data['block_type'],
      'block_format' => $field_data['format'],
      'region' => isset($region) ? $region : 'main',
      'additional' => $additional ?? [],
      'weight' => $settings['weight'],
      'view_mode' => isset($view_mode) ? $view_mode : 'default',
    ];

    return $sections;
  }

  /**
   * Helper function to create a section.
   *
   * @param string $layout
   *   The D8 machine name of the layout to be used.
   * @param array $layout_settings
   *   Any layout-level settings (full width, percentages, etc.).
   * @param array $components
   *   An array of sectionComponents (i.e., fields)
   */
  protected static function createD8Section($layout, array $layout_settings, array $components) {
    // Each section is stored in its own array.
    $section = new Section($layout, $layout_settings, $components);
    return $section;
  }

  /**
   * Helper method to take field data & create a SectionComponent object.
   *
   * @param array $component_data
   *   The data/context of the component (e.g., region, weight, view_mode)
   *
   * @return mixed
   *   The component object or FALSE.
   */
  protected function createD8SectionComponent(array $component_data) {
    // print_r($component_data);
    if ($block = MigrateHelper::createInlineBlock($component_data)) {
      // Important: the 'id' value must be "inline_block:" + a valid block type.
      $component_view_mode = $component_data['block_data'][0]['view_mode'] ?? 'full';
      $component = new SectionComponent(md5($component_data['field_identifier']), $component_data['region'], [
        'id' => 'inline_block:' . $component_data['block_type'],
        'label' => $component_data['field_identifier'],
        'provider' => 'layout_builder',
        'label_display' => 0,
        'view_mode' => $component_data['view_mode'] ?? $component_view_mode,
        'block_revision_id' => $block->id(),
      ]);
      $component->setWeight($component_data['weight']);
      // Add additional component styles, like Layout Builder Styles settings.
      $default_sidebar_styles = [
        'layout_builder_styles_style' => [
          'utexas_border_without_background' => 'utexas_border_without_background',
        ],
      ];
      switch ($component_data['block_type']) {
        case 'utexas_quick_links':
          if (!empty($component_data['additional'])) {
            $component->set('additional', $component_data['additional']);
          }
          else {
            // Quick Links always at least have the border w/o background.
            $component->set('additional', $default_sidebar_styles);
          }
          break;

        case 'social_links':
          // Social links always displays border w/o background.
          $component->set('additional', $default_sidebar_styles);
          break;

      }
    }

    if (isset($component)) {
      // Add additional component styles, like Layout Builder Styles settings.
      // Ensure data type of array passed to component's `additional` element.
      $existing_additional = is_array($component_data['additional']) ? $component_data['additional'] : [];
      $component->set('additional', $existing_additional);
      return $component;
    }
    return FALSE;
  }

}
