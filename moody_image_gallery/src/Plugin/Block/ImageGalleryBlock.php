<?php

declare(strict_types=1);

namespace Drupal\moody_image_gallery\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Moody Image Gallery block.
 *
 * @Block(
 *   id = "moody_image_gallery_block",
 *   admin_label = @Translation("Moody Image Gallery"),
 *   category = @Translation("Moody"),
 * )
 */
final class ImageGalleryBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the block.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'headline' => '',
      'gutter' => '1.25',
      'items' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();
    $form['#attached']['library'][] = 'moody_image_gallery/admin';

    $form['headline'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Headline'),
      '#default_value' => $config['headline'] ?? '',
    ];

    $form['gutter'] = [
      '#type' => 'select',
      '#title' => $this->t('Gutter size'),
      '#options' => [
        '0.75' => $this->t('Tight'),
        '1.25' => $this->t('Standard'),
        '1.75' => $this->t('Large'),
      ],
      '#default_value' => $config['gutter'] ?? '1.25',
    ];

    $form['items'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Gallery Images'),
      '#description' => $this->t('Add up to 12 images. The layout repeats in a 60/40/full-width pattern on larger screens. Use the horizontal and vertical focus controls to choose the crop anchor for each image. Leave unused slots empty.'),
    ];

    for ($i = 0; $i < 12; $i++) {
      $form['items'][$i] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Image @number', ['@number' => $i + 1]),
      ];
      $form['items'][$i]['media'] = [
        '#type' => 'media_library',
        '#allowed_bundles' => ['utexas_image'],
        '#title' => $this->t('Image'),
        '#cardinality' => 1,
        '#default_value' => $config['items'][$i]['media'] ?? NULL,
      ];
      $preview_url = $this->getPreviewImageUrl($config['items'][$i]['media'] ?? NULL);
      $focus_parts = $this->getFocusParts($config['items'][$i] ?? []);
      $form['items'][$i]['focus_preview'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['moody-image-gallery-focus-preview'],
          'style' => $preview_url ? 'background-image:url(' . $preview_url . ');background-position:' . $focus_parts['x'] . ' ' . $focus_parts['y'] . ';' : 'background-image:none;',
        ],
      ];
      $form['items'][$i]['focus_x'] = [
        '#type' => 'radios',
        '#title' => $this->t('Horizontal focus'),
        '#default_value' => $focus_parts['x'],
        '#options' => [
          '0%' => $this->t('Far left'),
          '25%' => $this->t('Left'),
          '50%' => $this->t('Center'),
          '75%' => $this->t('Right'),
          '100%' => $this->t('Far right'),
        ],
        '#attributes' => [
          'class' => ['moody-image-gallery-focus-radios'],
        ],
      ];
      $form['items'][$i]['focus_y'] = [
        '#type' => 'radios',
        '#title' => $this->t('Vertical focus'),
        '#default_value' => $focus_parts['y'],
        '#options' => [
          '0%' => $this->t('Top'),
          '25%' => $this->t('Upper'),
          '50%' => $this->t('Center'),
          '75%' => $this->t('Lower'),
          '100%' => $this->t('Bottom'),
        ],
        '#attributes' => [
          'class' => ['moody-image-gallery-focus-radios'],
        ],
      ];
      $form['items'][$i]['caption'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Optional caption'),
        '#default_value' => $config['items'][$i]['caption'] ?? '',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['headline'] = $form_state->getValue('headline');
    $this->configuration['gutter'] = $form_state->getValue('gutter');
    $this->configuration['items'] = $form_state->getValue('items');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $config = $this->getConfiguration();
    $items = [];

    foreach (($config['items'] ?? []) as $delta => $item) {
      $media_id = $item['media'] ?? NULL;
      if (empty($media_id)) {
        continue;
      }

      $media = $this->entityTypeManager->getStorage('media')->load($media_id);
      if (!$media || $media->bundle() !== 'utexas_image' || $media->get('field_utexas_media_image')->isEmpty()) {
        continue;
      }

      $image_field = $media->get('field_utexas_media_image')->first();
      if (!$image_field || empty($image_field->entity)) {
        continue;
      }

      $file = $image_field->entity;
      $caption = trim((string) ($item['caption'] ?? ''));
      $alt = (string) ($image_field->alt ?? '');

      $items[] = [
        'id' => 'moody-image-gallery-item-' . $delta,
        'uri' => $file->getFileUri(),
        'thumbnail_uri' => $file->getFileUri(),
        'alt' => $alt,
        'caption' => $caption,
        'focus_position' => $this->getFocusParts($item)['x'] . ' ' . $this->getFocusParts($item)['y'],
        'index' => count($items),
      ];
    }

    if ($items === []) {
      return [];
    }

    $gallery_id = 'moody-image-gallery-' . $this->getPluginId() . '-' . substr(hash('sha256', serialize($items)), 0, 8);

    return [
      '#theme' => 'moody_image_gallery',
      '#headline' => $config['headline'] ?? '',
      '#items' => $items,
      '#gallery_id' => $gallery_id,
      '#gutter' => $config['gutter'] ?? '1.25',
      '#attached' => [
        'library' => [
          'moody_image_gallery/moody_image_gallery',
        ],
      ],
    ];
  }

  /**
   * Returns a preview image URL for the admin focus selector.
   */
  private function getPreviewImageUrl($media_id): string {
    if (empty($media_id)) {
      return '';
    }

    $media = $this->entityTypeManager->getStorage('media')->load($media_id);
    if (!$media || $media->bundle() !== 'utexas_image' || $media->get('field_utexas_media_image')->isEmpty()) {
      return '';
    }

    $image_field = $media->get('field_utexas_media_image')->first();
    if (!$image_field || empty($image_field->entity)) {
      return '';
    }

    return $this->fileUrlGenerator->generateString($image_field->entity->getFileUri());
  }

  /**
   * Returns horizontal and vertical focus values from item configuration.
   */
  private function getFocusParts(array $item): array {
    if (!empty($item['focus_x']) || !empty($item['focus_y'])) {
      return [
        'x' => $item['focus_x'] ?? '50%',
        'y' => $item['focus_y'] ?? '50%',
      ];
    }

    if (!empty($item['focus_position'])) {
      $parts = preg_split('/\s+/', trim((string) $item['focus_position']));
      return [
        'x' => match ($parts[0] ?? 'center') {
          'left' => '0%',
          'center' => '50%',
          'right' => '100%',
          default => $parts[0] ?? '50%',
        },
        'y' => match ($parts[1] ?? 'center') {
          'top' => '0%',
          'center' => '50%',
          'bottom' => '100%',
          default => $parts[1] ?? '50%',
        },
      ];
    }

    return [
      'x' => '50%',
      'y' => '50%',
    ];
  }

}
