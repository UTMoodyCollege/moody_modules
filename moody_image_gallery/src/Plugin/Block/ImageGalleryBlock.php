<?php

declare(strict_types=1);

namespace Drupal\moody_image_gallery\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
      '#description' => $this->t('Add up to 12 images. The layout repeats in a 75/25/full-width pattern on larger screens. Leave unused slots empty.'),
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

}
