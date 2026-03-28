<?php

declare(strict_types=1);

namespace Drupal\moody_scroll_reveal_media\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Moody Scroll Reveal Media block.
 *
 * @Block(
 *   id = "moody_scroll_reveal_media_block",
 *   admin_label = @Translation("Moody Scroll Reveal Media"),
 *   category = @Translation("Moody"),
 * )
 */
final class ScrollRevealMediaBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
      'slides' => [],
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
      '#title' => $this->t('Section headline'),
      '#description' => $this->t('Optional heading displayed above the pinned reveal block.'),
      '#default_value' => $config['headline'] ?? '',
    ];

    $form['slides'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Reveal slides'),
      '#description' => $this->t('Add up to 6 slides. When the block reaches the viewport, it pins while each next slide reveals as the user scrolls.'),
    ];

    for ($i = 0; $i < 6; $i++) {
      $slide = $config['slides'][$i] ?? [];

      $form['slides'][$i] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Slide @number', ['@number' => $i + 1]),
      ];

      $form['slides'][$i]['media'] = [
        '#type' => 'media_library',
        '#title' => $this->t('Media'),
        '#description' => $this->t('Choose the media shown alongside this reveal step.'),
        '#allowed_bundles' => ['utexas_image', 'utexas_video_external'],
        '#cardinality' => 1,
        '#default_value' => $slide['media'] ?? NULL,
      ];

      $form['slides'][$i]['eyebrow'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Eyebrow'),
        '#default_value' => $slide['eyebrow'] ?? '',
      ];

      $form['slides'][$i]['title'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Title'),
        '#default_value' => $slide['title'] ?? '',
      ];

      $form['slides'][$i]['body'] = [
        '#type' => 'text_format',
        '#title' => $this->t('Body'),
        '#format' => $slide['body']['format'] ?? 'flex_html',
        '#default_value' => $slide['body']['value'] ?? '',
      ];

      $form['slides'][$i]['direction'] = [
        '#type' => 'select',
        '#title' => $this->t('Reveal direction'),
        '#description' => $this->t('Controls how this slide enters when it becomes active.'),
        '#options' => [
          'top' => $this->t('From top'),
          'right' => $this->t('From right'),
          'bottom' => $this->t('From bottom'),
          'left' => $this->t('From left'),
        ],
        '#default_value' => $slide['direction'] ?? ($i === 0 ? 'top' : 'right'),
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['headline'] = $form_state->getValue('headline');
    $this->configuration['slides'] = $form_state->getValue('slides');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $config = $this->getConfiguration();
    $slides = [];
    $media_view_builder = $this->entityTypeManager->getViewBuilder('media');

    foreach (($config['slides'] ?? []) as $delta => $slide) {
      $media_id = $slide['media'] ?? NULL;
      $eyebrow = trim((string) ($slide['eyebrow'] ?? ''));
      $title = trim((string) ($slide['title'] ?? ''));
      $body_value = trim((string) ($slide['body']['value'] ?? ''));

      if (!$media_id && $eyebrow === '' && $title === '' && $body_value === '') {
        continue;
      }

      $media_render = [];
      if (!empty($media_id)) {
        $media = $this->entityTypeManager->getStorage('media')->load($media_id);
        if ($media) {
          $media_render = $media_view_builder->view($media, 'default');
        }
      }

      $slides[] = [
        'delta' => $delta,
        'eyebrow' => $eyebrow,
        'title' => $title,
        'has_content' => ($eyebrow !== '' || $title !== '' || $body_value !== ''),
        'body' => [
          '#type' => 'processed_text',
          '#text' => $slide['body']['value'] ?? '',
          '#format' => $slide['body']['format'] ?? 'flex_html',
        ],
        'direction' => $this->normalizeDirection($slide['direction'] ?? 'right'),
        'media' => $media_render,
      ];
    }

    if (count($slides) < 2) {
      return [];
    }

    $block_id = 'moody-scroll-reveal-media-' . substr(hash('sha256', serialize($slides)), 0, 10);

    return [
      '#theme' => 'moody_scroll_reveal_media',
      '#headline' => $config['headline'] ?? '',
      '#slides' => $slides,
      '#block_id' => $block_id,
      '#attached' => [
        'library' => [
          'moody_scroll_reveal_media/moody_scroll_reveal_media',
        ],
      ],
    ];
  }

  /**
   * Normalizes a reveal direction value.
   */
  private function normalizeDirection(string $direction): string {
    return in_array($direction, ['top', 'right', 'bottom', 'left'], TRUE) ? $direction : 'right';
  }

}
