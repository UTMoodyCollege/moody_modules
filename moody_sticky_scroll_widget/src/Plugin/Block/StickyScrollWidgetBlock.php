<?php

declare(strict_types=1);

namespace Drupal\moody_sticky_scroll_widget\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a moody sticky scroll widget block.
 *
 * @Block(
 *   id = "moody_sticky_scroll_widget_block",
 *   admin_label = @Translation("Moody Sticky Scroll Widget"),
 *   category = @Translation("Moody"),
 * )
 */
final class StickyScrollWidgetBlock extends BlockBase implements ContainerFactoryPluginInterface
{

  /**
   * Constructs the plugin instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
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
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array
  {
    return [
      'text_content' => '',
      'image' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array
  {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    $form['text_content'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Text Content'),
      '#default_value' => $config['text_content']['value'] ?? '',
      '#format' => $config['text_content']['format'] ?? 'full_html',
      '#description' => $this->t('Enter the text content that will scroll while the image stays fixed.'),
    ];

    $form['image'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['utexas_image'],
      '#title' => $this->t('Fixed Image'),
      '#default_value' => $config['image'] ?? '',
      '#description' => $this->t('Select an image that will stay fixed on the left while scrolling.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void
  {
    $this->configuration['text_content'] = $form_state->getValue('text_content');
    $this->configuration['image'] = $form_state->getValue('image');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array
  {
      $config = $this->getConfiguration();

      // Initialize the image_url as an empty string.
      $image_url = '';

      // Check if 'image' is set in the configuration and a media entity exists.
      if (!empty($config['image'])) {
          $media = $this->entityTypeManager->getStorage('media')->load($config['image']);
          if ($media && !$media->get('field_utexas_media_image')->isEmpty()) {
              $file_entity = $media->get('field_utexas_media_image')->entity;
              if ($file_entity) {
                  $image_url = $this->fileUrlGenerator->generate($file_entity->getFileUri());
              }
          }
      }

      return [
          '#theme' => 'moody_sticky_scroll_widget',
          '#text_content' => $config['text_content']['value'] ?? '',
          '#image_url' => $image_url,
          '#attached' => [
              'library' => [
                  'moody_sticky_scroll_widget/moody_sticky_scroll_widget',
              ],
          ],
      ];
  }

}
