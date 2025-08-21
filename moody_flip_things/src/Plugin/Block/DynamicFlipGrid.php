<?php

declare(strict_types=1);

namespace Drupal\moody_flip_things\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\utexas_form_elements\UtexasLinkOptionsHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a dynamic flip grid block.
 *
 * @Block(
 *   id = "moody_flip_things_dynamic_flip_grid",
 *   admin_label = @Translation("Moody Dynamic Flip Grid"),
 *   category = @Translation("Moody"),
 * )
 */
final class DynamicFlipGrid extends BlockBase implements ContainerFactoryPluginInterface
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
      'headline' => '',
      'items' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array
  {
    // Overall headline for the grid
    $form['headline'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Headline'),
      '#default_value' => $this->configuration['headline'] ?? '',
    ];

    // Position options for content zones
    $position_options = [
      'top-left' => $this->t('Top Left'),
      'top-center' => $this->t('Top Center'),
      'top-right' => $this->t('Top Right'),
      'center-left' => $this->t('Center Left'),
      'center' => $this->t('Center'),
      'center-right' => $this->t('Center Right'),
      'bottom-left' => $this->t('Bottom Left'),
      'bottom-center' => $this->t('Bottom Center'),
      'bottom-right' => $this->t('Bottom Right'),
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

      // Front side configuration
      $form['items'][$i]['front'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Front Side'),
        '#collapsible' => TRUE,
        '#collapsed' => FALSE,
      ];

      $form['items'][$i]['front']['media'] = [
        '#type' => 'media_library',
        '#allowed_bundles' => ['utexas_image'],
        '#title' => $this->t('Media'),
        '#default_value' => $this->configuration['items'][$i]['front']['media'] ?? '',
        '#cardinality' => 1,
      ];

      $form['items'][$i]['front']['headline'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Headline'),
        '#default_value' => $this->configuration['items'][$i]['front']['headline'] ?? '',
      ];

      $form['items'][$i]['front']['body'] = [
        '#type' => 'text_format',
        '#title' => $this->t('Body'),
        '#default_value' => $this->configuration['items'][$i]['front']['body']['value'] ?? '',
        '#format' => $this->configuration['items'][$i]['front']['body']['format'] ?? 'flex_html',
      ];

      $form['items'][$i]['front']['cta'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Call to Action'),
      ];

      $form['items'][$i]['front']['cta']['link'] = [
        '#type' => 'utexas_link_options_element',
        '#title' => $this->t('Link'),
        '#default_value' => [
          'uri' => $this->configuration['items'][$i]['front']['cta']['link']['uri'] ?? '',
          'title' => $this->configuration['items'][$i]['front']['cta']['link']['title'] ?? '',
          'options' => $this->configuration['items'][$i]['front']['cta']['link']['options'] ?? [],
        ],
      ];

      $form['items'][$i]['front']['position'] = [
        '#type' => 'select',
        '#title' => $this->t('Content Position'),
        '#options' => $position_options,
        '#default_value' => $this->configuration['items'][$i]['front']['position'] ?? 'center',
      ];

      // Back side configuration
      $form['items'][$i]['back'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Back Side'),
        '#collapsible' => TRUE,
        '#collapsed' => FALSE,
      ];

      $form['items'][$i]['back']['media'] = [
        '#type' => 'media_library',
        '#allowed_bundles' => ['utexas_image'],
        '#title' => $this->t('Media'),
        '#default_value' => $this->configuration['items'][$i]['back']['media'] ?? '',
        '#cardinality' => 1,
      ];

      $form['items'][$i]['back']['headline'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Headline'),
        '#default_value' => $this->configuration['items'][$i]['back']['headline'] ?? '',
      ];

      $form['items'][$i]['back']['body'] = [
        '#type' => 'text_format',
        '#title' => $this->t('Body'),
        '#default_value' => $this->configuration['items'][$i]['back']['body']['value'] ?? '',
        '#format' => $this->configuration['items'][$i]['back']['body']['format'] ?? 'flex_html',
      ];

      $form['items'][$i]['back']['cta'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Call to Action'),
      ];

      $form['items'][$i]['back']['cta']['link'] = [
        '#type' => 'utexas_link_options_element',
        '#title' => $this->t('Link'),
        '#default_value' => [
          'uri' => $this->configuration['items'][$i]['back']['cta']['link']['uri'] ?? '',
          'title' => $this->configuration['items'][$i]['back']['cta']['link']['title'] ?? '',
          'options' => $this->configuration['items'][$i]['back']['cta']['link']['options'] ?? [],
        ],
      ];

      $form['items'][$i]['back']['position'] = [
        '#type' => 'select',
        '#title' => $this->t('Content Position'),
        '#options' => $position_options,
        '#default_value' => $this->configuration['items'][$i]['back']['position'] ?? 'center',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void
  {
    $this->configuration['headline'] = $form_state->getValue('headline');
    $this->configuration['items'] = $form_state->getValue('items');
  }

  /**
   * Process item side data to prepare for rendering.
   */
  private function processSideData($side_data) {
    $processed = $side_data;
    
    // Process media
    if (!empty($side_data['media'])) {
      $media = $this->entityTypeManager->getStorage('media')->load($side_data['media']);
      if ($media) {
        $media_attributes = $media->get('field_utexas_media_image')->getValue();
        if (!empty($media_attributes[0]['target_id'])) {
          $file = $this->entityTypeManager->getStorage('file')->load($media_attributes[0]['target_id']);
          if ($file) {
            $image_uri = $file->getFileUri();
            $processed['media_url'] = $this->fileUrlGenerator->generateAbsoluteString($image_uri);
            $processed['media_alt'] = $media_attributes[0]['alt'] ?? '';
          }
        }
      }
    }

    // Process body content
    if (!empty($side_data['body']['value'])) {
      $processed['body_rendered'] = [
        '#type' => 'processed_text',
        '#text' => $side_data['body']['value'],
        '#format' => $side_data['body']['format'] ?? 'flex_html',
      ];
    }

    // Process CTA
    if (!empty($side_data['cta']['link']['uri'])) {
      $cta_item['link']['uri'] = $side_data['cta']['link']['uri'];
      $cta_item['link']['title'] = $side_data['cta']['link']['title'] ?? '';
      $cta_item['link']['options'] = $side_data['cta']['link']['options'] ?? [];
      $processed['cta_render'] = UtexasLinkOptionsHelper::buildLink($cta_item, ['btn', 'btn-primary']);
    }

    return $processed;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array
  {
    $items = $this->configuration['items'] ?? [];
    $processed_items = [];

    // Process each item
    foreach ($items as $key => $item) {
      $processed_items[$key] = [
        'front' => $this->processSideData($item['front'] ?? []),
        'back' => $this->processSideData($item['back'] ?? []),
      ];
    }

    // Get chevron images for navigation
    $module_path = \Drupal::service('extension.list.module')->getPath('moody_flip_things');
    $chevron_left = '';
    $chevron_right = '';

    $chevron_left_path = $module_path . '/images/white-chevron-left.svg';
    if (file_exists($chevron_left_path)) {
      $chevron_left = file_get_contents($chevron_left_path);
    }

    $chevron_right_path = $module_path . '/images/white-chevron-right.svg';
    if (file_exists($chevron_right_path)) {
      $chevron_right = file_get_contents($chevron_right_path);
    }

    return [
      '#theme' => 'moody_dynamic_flip_grid',
      '#headline' => $this->configuration['headline'] ?? '',
      '#items' => $processed_items,
      '#chevron_left' => $chevron_left,
      '#chevron_right' => $chevron_right,
      '#attached' => [
        'library' => [
          'moody_flip_things/moody_dynamic_flip_grid',
        ],
      ],
    ];
  }
}