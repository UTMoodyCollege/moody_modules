<?php

declare(strict_types=1);

namespace Drupal\moody_flip_things\Plugin\Block;

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
 *   id = "moody_flip_things_flip_image_grid",
 *   admin_label = @Translation("Moody Flip Image Grid"),
 *   category = @Translation("Moody"),
 * )
 */
final class FlipImageGrid extends BlockBase implements ContainerFactoryPluginInterface
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
    $item_instances = 3;
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
      // LEts store a "Body" that is a text_area with the format choosable and saved
      $form['items'][$i]['body'] = [
        '#type' => 'text_format',
        '#title' => $this->t('Body'),
        '#default_value' => $this->configuration['items'][$i]['body']['value'] ?? '',
        '#format' => $this->configuration['items'][$i]['body']['format'] ?? 'flex_html',
      ];
      // Link url text
      $form['items'][$i]['link_url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Link URL'),
        '#default_value' => $this->configuration['items'][$i]['link_url'] ?? '',
      ];
    }

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
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array
  {
    // Lets unpack our items and then pass that all into the theme function moody_image_grid 
    $items = $this->configuration['items'] ?? [];
    // Lets revise each one so that the $items['image_url'] contains absolute url to the media image referenced
    foreach ($items as $key => $item) {
      $image = $item['image'] ?? FALSE;
      if ($image) {
        $media = $this->entityTypeManager->getStorage('media')->load($image);
        $media_attributes = $media->get('field_utexas_media_image')->getValue();
        $file = $this->entityTypeManager->getStorage('file')->load($media_attributes[0]['target_id']);
        $image_uri = $file->getFileUri();
        $image_url = $this->fileUrlGenerator->generateAbsoluteString($image_uri);
        $items[$key]['image_url'] = $image_url;
      }
      // Lets unpack the body and format
      $items[$key]['body'] = $item['body']['value'] ?? '';
      $items[$key]['body_format'] = $item['body']['format'] ?? 'flex_html';
      
      // LEts render the markup for the body
      $items[$key]['body_rendered'] = [
        '#type' => 'processed_text',
        '#text' => $items[$key]['body'],
        '#format' => $items[$key]['body_format'],
      ];

    }
    return [
      '#theme' => 'moody_flip_things_image_grid',
      '#headline' => $this->configuration['headline'] ?? '',
      '#items' => $items,
      '#attached' => [
        'library' => [
          'moody_flip_things/moody_flip_image_grid',
        ],
      ],
    ];
  }
}
