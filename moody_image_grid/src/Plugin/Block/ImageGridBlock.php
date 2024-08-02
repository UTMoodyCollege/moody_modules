<?php

declare(strict_types=1);

namespace Drupal\moody_image_grid\Plugin\Block;

use Drupal\Core\Block\BlockBase;
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
      'items' => [],
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
    $form['items'] = [
      '#type' => 'fieldset',
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
    $image_style_options = [];
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
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array
  {
    $image_style = $this->configuration['image_style'] ?? 'moody_image_style_560w_x_315h';
    // Unpack our items and then pass them all into the theme function moody_image_grid
    $items = $this->configuration['items'] ?? [];
    // Revise each one so that $items['image_url'] contains the absolute URL to the media image referenced
    foreach ($items as $key => $item) {
      $image = $item['image'] ?? FALSE;
      if ($image) {
        $media = $this->entityTypeManager->getStorage('media')->load($image);
        $media_attributes = $media->get('field_utexas_media_image')->getValue();
        $file = $this->entityTypeManager->getStorage('file')->load($media_attributes[0]['target_id']);
        $image_uri = $file->getFileUri();

        // Get the image style from the image style storage and create the URL
        $image_style = \Drupal::entityTypeManager()->getStorage('image_style')->load('moody_image_style_560w_x_315h');
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
    return [
      '#theme' => 'moody_image_grid',
      '#headline' => $this->configuration['headline'] ?? '',
      '#items' => $items,
      '#attached' => [
        'library' => [
          'moody_image_grid/moody_image_grid',
        ],
      ],
    ];
  }
}
