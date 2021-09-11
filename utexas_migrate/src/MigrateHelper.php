<?php

namespace Drupal\utexas_migrate;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\utexas_migrate\CustomWidgets\BasicBlock;
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
use Drupal\media\Entity\Media;
use Drupal\Core\Language\Language;

/**
 * Helper functions for migration.
 */
class MigrateHelper {

  /**
   * Retrieve a media entity ID for an equivalent D7 file from migration map.
   *
   * @param int $fid
   *   The file ID from the D7 site.
   *
   * @return int
   *   Returns the matching media entity ID imported to the D8 site.
   */
  public static function getMediaIdFromFid($fid) {
    $mid = 0;
    $mid = \Drupal::database()->select('migrate_map_utexas_media_image')
      ->fields('migrate_map_utexas_media_image', ['destid1'])
      ->condition('sourceid1', $fid, '=')
      ->execute()
      ->fetchField();
    // Try the video map.
    if (!$mid) {
      $mid = \Drupal::database()->select('migrate_map_utexas_media_video')
        ->fields('migrate_map_utexas_media_video', ['destid1'])
        ->condition('sourceid1', $fid, '=')
        ->execute()
        ->fetchField();
    }
    // Try the document map.
    if (!$mid) {
      $mid = \Drupal::database()->select('migrate_map_utexas_media_document')
        ->fields('migrate_map_utexas_media_document', ['destid1'])
        ->condition('sourceid1', $fid, '=')
        ->execute()
        ->fetchField();
    }
    // Try the audio map.
    if (!$mid) {
      $mid = \Drupal::database()->select('migrate_map_utexas_media_audio')
        ->fields('migrate_map_utexas_media_audio', ['destid1'])
        ->condition('sourceid1', $fid, '=')
        ->execute()
        ->fetchField();
    }
    return $mid;
  }

  /**
   * Retrieve a media entity ID for an equivalent D7 file from migration map.
   *
   * @param int $fid
   *   The file ID from the D7 site.
   *
   * @return int
   *   Returns the matching media entity ID imported to the D8 site.
   */
  public static function getDocumentMediaIdFromFid($fid) {
    $mid = 0;
    // Try the document map.
    $mid = \Drupal::database()->select('migrate_map_utexas_media_document')
      ->fields('migrate_map_utexas_media_document', ['destid1'])
      ->condition('sourceid1', $fid, '=')
      ->execute()
      ->fetchField();
    return $mid;
  }

  /**
   * Retrieve a media entity ID for an equivalent D7 file from migration map.
   *
   * @param int $fid
   *   The file ID from the D7 site.
   *
   * @return int
   *   Returns the matching media entity ID imported to the D8 site.
   */
  public static function getAudioMediaIdFromFid($fid) {
    $mid = 0;
    // Try the audio map.
    if (!$mid) {
      $mid = \Drupal::database()->select('migrate_map_utexas_media_audio')
        ->fields('migrate_map_utexas_media_audio', ['destid1'])
        ->condition('sourceid1', $fid, '=')
        ->execute()
        ->fetchField();
    }
    return $mid;
  }

  /**
   * Retrieve a media entity ID for an equivalent D7 file from migration map.
   *
   * @param int $fid
   *   The file ID from the D7 site.
   *
   * @return int
   *   Returns the matching media entity ID imported to the D8 site.
   */
  public static function getAnyMediaIdFromFid($fid) {
    $mid = 0;
    $mid = \Drupal::database()->select('migrate_map_utexas_media_image')
      ->fields('migrate_map_utexas_media_image', ['destid1'])
      ->condition('sourceid1', $fid, '=')
      ->execute()
      ->fetchField();
    // Try the video map.
    if (!$mid) {
      $mid = \Drupal::database()->select('migrate_map_utexas_media_video')
        ->fields('migrate_map_utexas_media_video', ['destid1'])
        ->condition('sourceid1', $fid, '=')
        ->execute()
        ->fetchField();
    }
    // Try the document map.
    if (!$mid) {
      $mid = \Drupal::database()->select('migrate_map_utexas_media_document')
        ->fields('migrate_map_utexas_media_document', ['destid1'])
        ->condition('sourceid1', $fid, '=')
        ->execute()
        ->fetchField();
    }
    // Try the audio map.
    if (!$mid) {
      $mid = \Drupal::database()->select('migrate_map_utexas_media_audio')
        ->fields('migrate_map_utexas_media_audio', ['destid1'])
        ->condition('sourceid1', $fid, '=')
        ->execute()
        ->fetchField();
    }
    return $mid;
  }

  /**
   * Given a source nid, return a destination nid if there is one.
   *
   * @param int $source_nid
   *   The NID from the D7 site.
   *
   * @return mixed
   *   Returns the node ID or FALSE
   */
  public static function getDestinationNid($source_nid) {
    // Each node type migration must be queried individually,
    // since they have no relational shared field for joining.
    $tables_to_query = [
      'migrate_map_utexas_landing_page',
      'migrate_map_utexas_standard_page',
      'migrate_map_utexas_basic_page',
      'migrate_map_utexas_article',
      'migrate_map_utevent_nodes',
      'migrate_map_utprof_nodes',
      'migrate_map_utnews_nodes',
      'migrate_map_moody_standard_page',
      'migrate_map_moody_landing_page',
      'migrate_map_moody_subsite_page',
      'migrate_map_moody_media_page',
    ];
    $connection = \Drupal::database();
    foreach ($tables_to_query as $table) {
      if ($connection->schema()->tableExists($table)) {
        $destination_nid = \Drupal::database()->select($table, 'n')
          ->fields('n', ['destid1'])
          ->condition('n.sourceid1', $source_nid)
          ->execute()
          ->fetchField();
        if ($destination_nid) {
          return $destination_nid;
        }
      }
    }
    return FALSE;
  }

  /**
   * Given an source text format, return an available format.
   *
   * @param string $text_format
   *   The source format (e.g., 'filtered_html')
   *
   * @return string
   *   The destination format (e.g., 'flex_html')
   */
  public function getDestinationTextFormat($text_format) {
    // As much as possible, we want to map the set text formats to their
    // respective D8 equivalents. If a D8 equivalent doesn't exist, fall back
    // to 'flex_html'.
    $destination_text_formats = [
      'flex_html',
      'basic_html',
      'full_html',
      'restricted_html',
      'plain_text',
    ];
    if (in_array($text_format, $destination_text_formats)) {
      return $text_format;
    }
    else {
      return 'flex_html';
    }
  }

  /**
   * Receive a Drupal 7 link & format it for Drupal 8.
   *
   * @param string $link
   *   A link, in string format.
   * @param string $source_path
   *   The source path that referenced this link.
   *
   * @return string
   *   The appropriate link for D8.
   */
  public static function prepareLink($link, $source_path = '') {
    // Check for node/ links.
    // @todo: check for taxonomy/term/, file/, and other internal links (e.g., Views routes)
    if (strpos($link, 'node/') === 0) {
      $source_nid = substr($link, 5);
      if ($destination_nid = self::getDestinationNid($source_nid)) {
        return('internal:/node/' . $destination_nid);
      }
      // The destination NID doesn't exist. Print a warning message.
      \Drupal::logger('utexas_migrate')->warning('* Source node %source contained link "@link". No equivalent destination node was found. Link replaced with link to homepage.', [
        '@link' => $link,
        '%source' => $source_path,
      ]);
      return 'internal:/';
    }
    if ($link == 'faculty') {
      return 'internal:/faculty';
    }
    if ($link == 'news') {
      return 'internal:/news';
    }
    if ($link == 'innovation') {
      return 'internal:/innovation';
    }
    if ($link == 'graduate') {
      return 'internal:/graduate';
    }
    if ($link == 'graduate/students') {
      return 'internal:/graduate/students';
    }
    // Handle <front>.
    if ($link == '<front>') {
      return 'internal:/';
    }
    if ($link == '<nolink>') {
      return 'internal:##';
    }
    return $link;
  }

  /**
   * Prepare a D7 text format for usage in D8.
   *
   * In D7, we had "Filtered HTML", "Filtered HTML for blocks",
   * and "Full HTML".
   * In D8, we only have "Flex HTML", and the Drupal provided "Restricted HTML"
   * and "Full HTML".
   */
  public static function prepareTextFormat($d7_format) {
    switch ($d7_format) {
      case 'filtered_html':
      case 'filtered_html_for_blocks':
        $d8_format = 'flex_html';
        break;

      case 'full_html':
        $d8_format = 'full_html';
        break;

      default:
        $d8_format = 'flex_html';
        break;
    }

    return $d8_format;
  }

  /**
   * Map of fieldblock IDs that should NOT be migrated right now.
   *
   * @var array
   */
  public static $excludedFieldblocks = [
    'fieldblock-bb03b0e9fbf84510ab65cbb066d872fc' => 'Standard Page Twitter Widget',
    'fieldblock-bb03b0e9fbf84510ab65cbb066d872fc' => 'Landing Page Twitter Widget',
    'fieldblock-d83c2a95384186e375ab37cbf1430bf5' => 'Landing Page Contact Info',
    'fieldblock-38205d43426b33bd0fe595ff8ca61ffd' => 'Standard Page Contact Info',
    'fieldblock-d41b4a03ee9d7b1084986f74b617921c' => 'Landing Page UT Newsreel',
    'fieldblock-8e85c2c89f0ccf26e9e4d0378250bf17' => 'Standard Page UT Newsreel',
  ];

  /**
   * Map of fieldblock IDs that SHOULD be migrated right now.
   *
   * @var array
   */
  public static $includedFieldBlocks = [

    // Moody field blocks.
    'fieldblock-9604b012ffab0bf4101338cd3d192880' => 'wysiwyg_a',
    'fieldblock-7feef85d0c580a673562bfcd6f92439c' => 'wysiwyg_a',
    'fieldblock-e3569674b88f0fe2acd2c66bf61b18ee' => 'wysiwyg_a',
    'fieldblock-a0e75cb0a63487edd556d74866b3bd5f' => 'wysiwyg_a',
    'fieldblock-565dc5bc6c9e396224faa42ed9ac142c' => 'wysiwyg_a',
    'fieldblock-6f3b85225f51542463a88e53104f8753' => 'wysiwyg_a',
    'fieldblock-fda604d130a57f15015895c8268f20d2' => 'wysiwyg_a',

    'fieldblock-9479afd0c93dfd33d9623facfc67afd9' => 'wysiwyg_b',
    'fieldblock-f662b31619810e1a4e78217d3c917c74' => 'wysiwyg_b',
    'fieldblock-15783f441af05e3dc5ce5f2716b433cb' => 'wysiwyg_b',
    'fieldblock-d54f95a9778b2bdcd0e67c6de6df4f04' => 'wysiwyg_b',
    'fieldblock-8ece510417a31a83168a5cbb0ac370ef' => 'wysiwyg_b',
    'fieldblock-9a6760fa853859ac84ff3a273ab79869' => 'wysiwyg_b',
    'fieldblock-bf40687156268eaa30437ed84189f13e' => 'wysiwyg_b',

    'fieldblock-7354b57b03e4cc64322413b068171f63' => 'wysiwyg_c',
    'fieldblock-0b17b72a669852889097d3b0eeac9707' => 'wysiwyg_c',
    'fieldblock-2ded99b78ee233aa62ee757b9637439d' => 'wysiwyg_c',
    'fieldblock-b49ce4640664a09edfbdf3bdc86d74ad' => 'wysiwyg_c',
    'fieldblock-b3aa45060b73b2b99a941ebe63c3f323' => 'wysiwyg_c',

    'fieldblock-0288b57bf33f813f13ba814f0a0f65b1' => 'wysiwyg_d',
    'fieldblock-090ebb561d57885ed017b0c040174d5e' => 'wysiwyg_d',
    'fieldblock-3cbfee1e505727b77efd9f968b22de0c' => 'wysiwyg_d',
    'fieldblock-671747ecd7c3d6a2691687588fcf7b0d' => 'wysiwyg_d',

    'fieldblock-0328081a2c353ed0e8f00ff60a1d1a35' => 'flex_content_area_a',
    'fieldblock-636eab25448c88f8580190baa64cb94d' => 'flex_content_area_a',
    'fieldblock-f05003a5283f6cd815e42b6c382acac2' => 'flex_content_area_a',
    'fieldblock-d3bba61650fe0588ec5c8fff52c6b27f' => 'flex_content_area_a',
    'fieldblock-1a9dd8685785a44b58d5e24ed3f8996d' => 'flex_content_area_a',
    'fieldblock-9c079efa827f76dea650869c5d2631e6' => 'flex_content_area_a',

    'fieldblock-7c9e605e101cbd2485a8f65ae7d1c33e' => 'flex_content_area_b',
    'fieldblock-e88b2a536d75d4ac75f1aba9db1cf619' => 'flex_content_area_b',
    'fieldblock-28c5faf3bdda16f11923ddc84bf19913' => 'flex_content_area_b',
    'fieldblock-171f57c2269e221c96b732a464bae2e0' => 'flex_content_area_b',
    'fieldblock-2c880c8461bc3ce5a6ac19b2e7791346' => 'flex_content_area_b',

    'fieldblock-1a037d1f756dc6b4ffc9c7af2c8b2d1c' => 'flex_cta',
    'fieldblock-3632ee532157a1e1edec956556124951' => 'flex_cta',
    'fieldblock-80b5f8f7d1bd5b3a9cf3bdeb6410437f' => 'flex_cta',

    'fieldblock-99ede81a85caed01ad07d54b9642cd6b' => 'full_width_quote',

    'fieldblock-0f768a15f76bd21f343e118706061289' => 'promo_unit',
    'fieldblock-f9842fc07e4abc9a37097a3d37bb28ec' => 'promo_unit',
    'fieldblock-748d1d699ca1b0d8ced1b4ea119243da' => 'promo_unit',
    'fieldblock-9bcf52bbed6b2a3ea84b55a58fdd9c55' => 'promo_unit',
    'fieldblock-208a521aa519bc1ed37d8992aeffae83' => 'promo_unit',

    'fieldblock-13476ddcd6656b8dc4de0ae603aeb39a' => 'photo_content_area',
    'fieldblock-8177c2d0da5a6530f12e8400d7fe8674' => 'photo_content_area',
    'fieldblock-f28dec811f29578f018fae1a8458c9b4' => 'photo_content_area',
    'fieldblock-29dbb1cb2c1033fdddae49c21ad4a9f5' => 'photo_content_area',

    'fieldblock-2ce7441cdce3020875f86758edae956a' => 'hero',
    'fieldblock-8b42a7d369dcd54d7878fb0015188cba' => 'hero',
    'fieldblock-8af3bd2d3cab537c77dbfbb55146ab7b' => 'hero',
    'fieldblock-f4361d99a73eca8a4329c07d0724a554' => 'hero',

    'fieldblock-a4f3cdcfc5ae4018ba11d7543b86ac2a' => 'image_link_a',
    'fieldblock-f2ac49ee699ab099c1c84cccdd110cfa' => 'image_link_a',
    'fieldblock-2d7ba5dd27459d05fbf7ac9e7245457f' => 'image_link_a',
    'fieldblock-05826976d27bc7abbc4f0475ba10cb58' => 'image_link_a',
    'fieldblock-6986914623a8e5646904aca42f9f452e' => 'image_link_a',

    'fieldblock-6e9362df39fb05facc1fabb9df6c6ade' => 'image_link_b',
    'fieldblock-9a73e804c8bdafecee84aeafe80f4c93' => 'image_link_b',
    'fieldblock-d676d13212d535d896a5f26805226c91' => 'image_link_b',
    'fieldblock-21808b5e6c396dac8670f322f5c9e197' => 'image_link_b',
    'fieldblock-738c0498378ce2c32ba571a0a69457dc' => 'image_link_b',

    'fieldblock-11de6fd6d60ca401e906b56943904f5c' => 'quick_links',
    'fieldblock-669a6a1f32566fa73ea7974696027184' => 'quick_links',
    'fieldblock-eab8c417f7d28e9571473905cfebbd5b' => 'quick_links',

    'fieldblock-fd3e8e5cfb332cd9e16fc4f7e434c2d0' => 'promo_list',
    'fieldblock-f7f659f0bcfc7db4944a3dda35986833' => 'promo_list',
    'fieldblock-41cadee78be3c84a6c91f21c6266424e' => 'promo_list',
    'fieldblock-1f11b5247df5b10da980b5681b637d17' => 'promo_list',
    'fieldblock-c4c10ae36665adf0e722e7e3f4be74d4' => 'promo_list',

    'fieldblock-4d72f6d0d1dca617b0da9621ac41c6d7' => 'featured_highlight_a',

    'fieldblock-e848447af81fa75c16e3504ca601b844' => 'featured_highlight_b',

    'fieldblock-4f2d2f2c145d6c8932abd74184a3314f' => 'featured_highlight_c',

    'fieldblock-205723da13bdadd816a716421b436a92' => 'featured_highlight',
    'fieldblock-21a5d45af1d1930a6933599d812eddb5' => 'featured_highlight',
    'fieldblock-553096d7ea242fc7edcddc53f719d074' => 'featured_highlight',
    'fieldblock-fca389bd6ddd81bc40af040ef39fcd38' => 'featured_highlight',

    'fieldblock-08e3ecf290052f1e6a356a0ad1384af3' => 'moody_showcase',
    'fieldblock-917b59c360f2b31644b34bfb8ac6ca5b' => 'moody_showcase',
    'fieldblock-d09cc58da1200889d2f272800ceebb7a' => 'moody_showcase',

    'fieldblock-5290a1ee5fb4416991d1cbdde0dc5d33' => 'moody_featured_highlight',
    'fieldblock-b07e0cf46350e10d5e860d77c3a3fb4c' => 'moody_featured_highlight',

    'fieldblock-035a344f4b0686c6db435e508910e342' => 'resource',
    'fieldblock-750bd3fe3ce39a770569c09d04421b25' => 'resource',
    'fieldblock-71371c39998df192a2374d764119fccd' => 'resource',
    'fieldblock-75a75df6422c87166c75aa079ca98c3c' => 'resource',
    'fieldblock-e01ea87c2dadf3edda4cc61011b33637' => 'resource',

    'fieldblock-101ad2a4e1207ec4c932a07388863699' => 'contact_info',
    'fieldblock-330656a95a6c6ccfae9ca1db78724091' => 'contact_info',
    'fieldblock-38205d43426b33bd0fe595ff8ca61ffd' => 'contact_info',
    'fieldblock-6e7265f8ea5f642ea4ce5b5956661e1b' => 'contact_info',
    'fieldblock-d83c2a95384186e375ab37cbf1430bf5' => 'contact_info',
    'fieldblock-e0dd01c69fe4caeabdcb1ccd00757cae' => 'contact_info',

    'fieldblock-40e2c500ce2cae3e6a532aa7f88163c0' => 'tabs',
    'fieldblock-ec43372734589e7d660a3a37ce6283c7' => 'tabs',
    'fieldblock-218e210461cc656d15fce81c78ec3023' => 'tabs',
    'fieldblock-773968d76396e61a9c57d1678cd9cb2c' => 'tabs',

  ];

  /**
   * Helper method to save the inline block.
   */
  public static function createInlineBlock($component_data) {
    switch ($component_data['field_identifier']) {
      case 'featured_highlight':
      case 'featured_highlight_a':
      case 'featured_highlight_b':
      case 'featured_highlight_c':
        $block_definition = FeaturedHighlight::createBlockDefinition($component_data);
        break;

      case 'flex_content_area_a':
      case 'flex_content_area_b':
        $block_definition = FlexContentArea::createBlockDefinition($component_data);
        break;

      case 'hero':
        $block_definition = Hero::createBlockDefinition($component_data);
        break;

      case 'image_link_a':
      case 'image_link_b':
        $block_definition = ImageLink::createBlockDefinition($component_data);
        break;

      case 'promo_list':
        $block_definition = PromoLists::createBlockDefinition($component_data);
        break;

      case 'promo_unit':
        $block_definition = PromoUnits::createBlockDefinition($component_data);
        break;

      case 'photo_content_area':
        $block_definition = PhotoContentArea::createBlockDefinition($component_data);
        break;

      case 'quick_links':
        $block_definition = QuickLinks::createBlockDefinition($component_data);
        break;

      case 'resource':
        $block_definition = Resource::createBlockDefinition($component_data);
        break;

      case 'social_links':
        $block_definition = SocialLinks::createBlockDefinition($component_data);
        break;

      case 'wysiwyg_a':
      case 'wysiwyg_b':
      case 'wysiwyg_c':
      case 'wysiwyg_d':
        $block_definition = BasicBlock::createBlockDefinition($component_data);
        break;

      case 'moody_showcase':
        $block_definition = MoodyShowcase::createBlockDefinition($component_data);
        break;

      case 'tabs':
        $block_definition = MoodyFlexTabs::createBlockDefinition($component_data);
        break;

    }
    if (!isset($block_definition)) {
      return FALSE;
    }

    // For each block type to migrate, add a callback like the one above.
    try {
      $block = BlockContent::create($block_definition);
      $block->save();
      return $block;
    }
    catch (EntityStorageException $e) {
      \Drupal::logger('utexas_migrate')->warning("Import of :block_type failed: :error - Code: :code", [
        ':block_type' => $component_data['block_type'],
        ':error' => $e->getMessage(),
        ':code' => $e->getCode(),
      ]);
    }
  }

  /**
   * Given a source path, provide a destination path.
   *
   * @param string $source
   *   A source path, such as `node/4` or `file/2`.
   *
   * @return mixed
   *   A corresponding destination path, such as `node/8` or `media/6` or FALSE.
   */
  public static function getDestinationFromSource($source) {
    // @todo: Add coverage for taxonomy ID mapping.
    if (strpos($source, 'node/') === 0) {
      $source_nid = substr($source, 5);
      $destination_nid = self::getDestinationNid($source_nid);
      if ($destination_nid) {
        return '/node/' . $destination_nid;
      }
    }
    elseif (strpos($source, 'file/') === 0) {
      $source_fid = substr($source, 5);
      $destination_fid = self::getDestinationMid($source_fid);
      if ($destination_fid) {
        return '/media/' . $destination_fid;
      }
    }
    elseif (strpos($source, 'user/') === 0) {
      $source_uid = substr($source, 5);
      $destination_uid = self::getDestinationUid($source_uid);
      if ($destination_uid) {
        return '/user/' . $destination_uid;
      }
    }
    return $source;
  }

  /**
   * Transform file entity into media embed.
   *
   * @param string $fid
   *   A fid for file entity.
   *
   * @return string
   *   A string with media embed markup.
   */
  public static function transformMediaEmbed(string $media_entity) {
    // Decode media entity.
    $json = json_decode($media_entity);
    // Get the file id and alignment.
    $fid = $json[0][0]->fid;
    $alignment = ($json[0][0]->fields->alignment) ? $json[0][0]->fields->alignment : 'center';
    print_r($alignment);
    // Get media id for images and documents.
    $mid = MigrateHelper::getAnyMediaIdFromFid($fid);
    $media = Media::load($mid);
    $uuid = ($media) ? $media->uuid() : NULL;
    if ($uuid) {
      $markup = '<drupal-media data-align="' . $alignment . '" data-entity-type="media" data-entity-uuid="' . $uuid . '"></drupal-media>';
      return $markup;
    }
    return;
  }

  /**
   * Transform video_filter embeds into media embed.
   *
   * @param string $shortcode
   *   A WYSWIYG shortcode for video_filter embeds.
   *
   * @return string
   *   A string with media embed markup.
   */
  public static function transformVideoFilterEmbed(string $shortcode) {
    preg_match_all('#\bhttps?://[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $shortcode, $url_match);
    $video_url = $url_match[0][0];
    preg_match_all('/title:"(.*)"/', $shortcode, $title_match);
    $video_title = $title_match;
    $video_media = Media::create([
      'name' => ($video_title) ? $video_title : $video_url,
      'bundle' => 'utexas_video_external',
      'uid' => '1',
      'langcode' => Language::LANGCODE_NOT_SPECIFIED,
      'status' => '1',
      'field_media_oembed_video' => [
        'value' => $video_url,
      ],
    ]);
    $video_media->save();
    $uuid = ($video_media) ? $video_media->uuid() : NULL;
    if ($uuid) {
      $markup = '<drupal-media data-align="center" data-entity-type="media" data-entity-uuid="' . $uuid . '"></drupal-media>';
      return $markup;
    }
    return;
  }

  /**
   * Transform video_filter embeds into media embed.
   *
   * @param string $markup
   *   A block of WYSWIYG markup.
   *
   * @return string
   *   A string of wqysiwyg markup with transformed classes.
   */
  public static function wysiwygTransformCssClasses(string $markup) {
    // Replace foundation grid classes with bootstrap classes.
    $markup = preg_replace('/small-(\d{1,2})/', 'col-sm-$1', $markup);
    $markup = preg_replace('/medium-(\d{1,2})/', 'col-md-$1', $markup);
    $markup = preg_replace('/large-(\d{1,2})/', 'col-lg-$1', $markup);

    // Remove data-equalizer attributes.
    $markup = preg_replace('/data-equalizer/', '', $markup);
    $markup = preg_replace('/data-equalizer-watch/', '', $markup);

    // Remove flex-video from classes.
    $markup = preg_replace('/flex-video/', '', $markup);

    // Update .button to .ut-btn class.
    // $markup = preg_replace('/button/', 'ut-btn', $markup);

    // Text alignment classes.
    $markup = preg_replace('/rtecenter/', 'text-align-center', $markup);

    // Remove strong tags from h2s and h1s.
    $markup = preg_replace('/<h1><strong>(.*)<\/strong><\/h1>/', '<h1>$1</h1>', $markup);
    $markup = preg_replace('/<h2><strong>(.*)<\/strong><\/h2>/', '<h2>$1</h2>', $markup);
    $markup = preg_replace('/<h1 class="text-align-center"><strong>(.*)<\/strong><\/h1>/', '<h1 class="text-align-center">$1</h1>', $markup);
    $markup = preg_replace('/<h2 class="text-align-center"><strong>(.*)<\/strong><\/h2>/', '<h2 class="text-align-center">$1</h2>', $markup);

    return $markup;
  }

}
