<?php

declare(strict_types=1);

namespace Drupal\moody_special_quote\Plugin\Block;

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
 *   id = "moody_special_quote_special_quote",
 *   admin_label = @Translation("Moody Special Quote"),
 *   category = @Translation("Moody"),
 * )
 */
final class SpecialQuoteBlock extends BlockBase implements ContainerFactoryPluginInterface
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
    // {#                 'headline' => '',
      // 'quote' => '',
      // 'image_url' => '', #}
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();
    // LEts to a headline textfield, quote textarea, and image_Url as media field which we'll convert for template
    $form['headline'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Headline'),
      '#default_value' => $config['headline'] ?? '',
    ];
    $form['quote'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Quote'),
      '#default_value' => $config['quote'] ?? '',
    ];
    $form['quote2'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Quote 2'),
      '#default_value' => $config['quote2'] ?? '',
    ];
    $form['image'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['utexas_image'],
      '#title' => $this->t('Image'),
      '#default_value' => $config['image'] ?? '',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void
  {
    $this->configuration['headline'] = $form_state->getValue('headline');
    $this->configuration['quote'] = $form_state->getValue('quote');
    $this->configuration['quote2'] = $form_state->getValue('quote2');
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
          '#theme' => 'moody_special_quote',
          '#headline' => $config['headline'] ?? '',
          '#quote' => $config['quote'] ?? '',
          '#quote2' => $config['quote2'] ?? '',
          '#image_url' => $image_url,
          '#attached' => [
              'library' => [
                  'moody_special_quote/moody_special_quote',
              ],
          ],
      ];
  }

}
