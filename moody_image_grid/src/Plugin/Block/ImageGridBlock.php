<?php

declare(strict_types=1);

namespace Drupal\moody_image_grid\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a moody image grid block.
 *
 * @Block(
 *   id = "moody_image_grid_image_grid",
 *   admin_label = @Translation("Moody Image Grid"),
 *   category = @Translation("Moody"),
 * )
 */
final class ImageGridBlock extends BlockBase implements ContainerFactoryPluginInterface
{

  /**
   * Constructs the plugin instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    // Inject an entity type manager
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self
  {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('file_url_generator'),
      // Load an instance of the entity type manager
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array
  {
    return [
      'headline' => '',
      'items' => [],
      'image_style' => 'moody_image_style_560w_x_315h',
      'layout' => 'legacy',
      'gap' => 'small',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array
  {
    // We want to ultimately store up to 6 items. Each item should have a media library referenced image media, and an optional headline and link text fields.
    // Add\ a headl;ine field
    $form['headline'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Headline'),
      '#default_value' => $this->configuration['headline'] ?? '',
    ];
    $item_instances = 6;
    $form['layout'] = [
      '#type' => 'select',
      '#title' => $this->t('Grid layout'),
      '#options' => ['legacy' => $this->t('Original bordered cards'), 'mosaic' => $this->t('Borderless photo mosaic')],
      '#default_value' => $this->configuration['layout'] ?? 'legacy',
      '#description' => $this->t('Mosaic supports individual photo sizes and stacks in reading order on mobile.'),
    ];
    $form['gap'] = [
      '#type' => 'select',
      '#title' => $this->t('Mosaic spacing'),
      '#options' => ['none' => $this->t('No gap'), 'small' => $this->t('Small gap'), 'medium' => $this->t('Medium gap')],
      '#default_value' => $this->configuration['gap'] ?? 'small',
    ];
    $form['items'] = [
      '#type' => 'fieldset',
      '#tree' => TRUE,
      '#title' => $this->t('Items'),
    ];
    for ($i = 0; $i < $item_instances; $i++) {
      $form['items'][$i] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Item @i', ['@i' => $i + 1]),
      ];
      $form['items'][$i]['image'] = [
        '#type' => 'media_library',
        '#allowed_bundles' => ['utexas_image'],
        '#title' => $this->t('Image'),
        '#default_value' => $this->configuration['items'][$i]['image'] ?? '',
      ];
      $form['items'][$i]['headline'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Headline'),
        '#default_value' => $this->configuration['items'][$i]['headline'] ?? '',
      ];
      $form['items'][$i]['size'] = [
        '#type' => 'select',
        '#title' => $this->t('Mosaic photo size'),
        '#options' => ['small' => $this->t('Small — one tile'), 'medium' => $this->t('Medium — one column, double height'), 'large' => $this->t('Large — two columns, double height')],
        '#default_value' => $this->configuration['items'][$i]['size'] ?? 'small',
        '#description' => $this->t('For a three-photo composition, use one large and two small photos. Photos crop to fill each tile.'),
      ];
      // Link url text
      $form['items'][$i]['link_url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Link URL'),
        '#default_value' => $this->configuration['items'][$i]['link_url'] ?? '',
      ];
    }

    // Lets add an image style selection that alows choosing
    // from the available image styles
    $all_image_styles = \Drupal::entityTypeManager()->getStorage('image_style')->loadMultiple();
    $image_style_options = ['original' => $this->t('Original image — recommended for mosaic')];
    foreach ($all_image_styles as $image_style) {
      $image_style_options[$image_style->id()] = $image_style->label();
    }
    $form['image_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Image Style'),
      '#options' => $image_style_options,
      '#default_value' => $this->configuration['image_style'] ?? 'moody_image_style_560w_x_315h',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void
  {
    // Lets serialize up the data and then render it out in the build method.
    $this->configuration['items'] = $form_state->getValue('items');
    $this->configuration['headline'] = $form_state->getValue('headline');
    $this->configuration['image_style'] = $form_state->getValue('image_style');
    $this->configuration['layout'] = $form_state->getValue('layout');
    $this->configuration['gap'] = $form_state->getValue('gap');
  }

  /**
   * Reject unsafe links instead of allowing active URLs in saved content.
   */
  public function blockValidate($form, FormStateInterface $form_state): void {
    foreach ($form_state->getValue('items', []) as $key => $item) {
      if (!self::safeLink($item['link_url'] ?? '')) {
        $form_state->setError($form['items'][$key]['link_url'], $this->t('Use a site-relative /path or a complete https:// URL.'));
      }
    }
  }

  public static function safeLink(string $url): bool {
    return $url === '' || (!preg_match('/[\x00-\x20\\\\]/', $url) && ((str_starts_with($url, '/') && !str_starts_with($url, '//')) || (preg_match('@^https?://@i', $url) && UrlHelper::isValid($url, TRUE))));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array
  {
    $style_id = $this->configuration['image_style'] ?? 'moody_image_style_560w_x_315h';
    $image_style = $style_id === 'original' ? NULL : $this->entityTypeManager->getStorage('image_style')->load($style_id);
    $cache = new CacheableMetadata();
    if ($image_style) {
      $cache->addCacheableDependency($image_style);
    }
    // Unpack our items and then pass them all into the theme function moody_image_grid
    $items = $this->configuration['items'] ?? [];
    // Revise each one so that $items['image_url'] contains the absolute URL to the media image referenced
    foreach ($items as $key => $item) {
      unset($items[$key]);
      $image = $item['image'] ?? FALSE;
      if ($image) {
        $media = $this->entityTypeManager->getStorage('media')->load($image);
        if (!$media) {
          $cache->addCacheTags($this->entityTypeManager->getDefinition('media')->getListCacheTags());
          continue;
        }
        $access = $media->access('view', NULL, TRUE);
        $cache->addCacheableDependency($media)->addCacheableDependency($access);
        if (!$access->isAllowed() || !$media->hasField('field_utexas_media_image') || $media->get('field_utexas_media_image')->isEmpty()) {
          continue;
        }
        $media_attributes = $media->get('field_utexas_media_image')->getValue();
        $file = $this->entityTypeManager->getStorage('file')->load($media_attributes[0]['target_id']);
        if (!$file) {
          continue;
        }
        $cache->addCacheableDependency($file);
        $items[$key] = $item;
        $items[$key]['alt'] = $media_attributes[0]['alt'] ?? '';
        $items[$key]['size'] = in_array($item['size'] ?? '', ['small', 'medium', 'large'], TRUE) ? $item['size'] : 'small';
        $items[$key]['link_url'] = self::safeLink($item['link_url'] ?? '') ? ($item['link_url'] ?? '') : '';
        $image_uri = $file->getFileUri();

        // Get the image style from the image style storage and create the URL
        if ($image_style) {
          $styled_image_url = $image_style->buildUrl($image_uri);
          $items[$key]['image_url'] = $styled_image_url;
        } else {
          // Fallback to original image URL if the style is not found
          $file_url_generator = \Drupal::service('file_url_generator');
          $items[$key]['image_url'] = $file_url_generator->generateAbsoluteString($image_uri);
        }

      }
    }
    $build = [
      '#theme' => 'moody_image_grid',
      '#headline' => $this->configuration['headline'] ?? '',
      '#items' => $items,
      '#layout' => ($this->configuration['layout'] ?? 'legacy') === 'mosaic' ? 'mosaic' : 'legacy',
      '#gap' => in_array($this->configuration['gap'] ?? '', ['none', 'small', 'medium'], TRUE) ? $this->configuration['gap'] : 'small',
      '#attached' => [
        'library' => [
          'moody_image_grid/moody_image_grid',
        ],
      ],
    ];
    $cache->applyTo($build);
    return $build;
  }
}
