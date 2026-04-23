<?php

declare(strict_types=1);

namespace Drupal\moody_scroll_reveal_media\Plugin\Block;

use Drupal\Component\Utility\UrlHelper;
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
      'animation_style' => 'fade',
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

    $form['animation_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Animation style'),
      '#description' => $this->t('Choose whether the next slide fades in while moving, or slides in at full opacity.'),
      '#options' => [
        'fade' => $this->t('Fade'),
        'slide' => $this->t('Slide'),
      ],
      '#default_value' => $this->normalizeAnimationStyle($config['animation_style'] ?? 'fade'),
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
        '#description' => $this->t('Choose the media shown alongside this reveal step. This is ignored when a Vimeo URL is provided below.'),
        '#allowed_bundles' => ['utexas_image', 'utexas_video_external'],
        '#cardinality' => 1,
        '#default_value' => $slide['media'] ?? NULL,
      ];

      $form['slides'][$i]['video_url'] = [
        '#type' => 'url',
        '#title' => $this->t('Vimeo video URL'),
        '#description' => $this->t('Optional Vimeo URL to embed in place of the selected media for this slide.'),
        '#default_value' => $slide['video_url'] ?? '',
        '#placeholder' => 'https://vimeo.com/123456789',
      ];

      $form['slides'][$i]['video_autoplay'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Autoplay Vimeo video'),
        '#description' => $this->t('When enabled, the video plays automatically while this slide is active.'),
        '#default_value' => !empty($slide['video_autoplay']),
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

      $form['slides'][$i]['title_display'] = [
        '#type' => 'select',
        '#title' => $this->t('Title display'),
        '#options' => [
          'inline' => $this->t('Inline'),
          'overlay' => $this->t('Overlay'),
        ],
        '#default_value' => $this->normalizeTitleDisplay((string) ($slide['title_display'] ?? 'inline')),
      ];

      $form['slides'][$i]['title_position'] = [
        '#type' => 'select',
        '#title' => $this->t('Overlay position'),
        '#description' => $this->t('Used when the title display is set to Overlay.'),
        '#options' => [
          'top-left' => $this->t('Top left'),
          'top-center' => $this->t('Top center'),
          'top-right' => $this->t('Top right'),
          'center-left' => $this->t('Center left'),
          'center' => $this->t('Center'),
          'center-right' => $this->t('Center right'),
          'bottom-left' => $this->t('Bottom left'),
          'bottom-center' => $this->t('Bottom center'),
          'bottom-right' => $this->t('Bottom right'),
        ],
        '#default_value' => $this->normalizeTitlePosition((string) ($slide['title_position'] ?? 'center')),
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
    $this->configuration['headline'] = (string) $this->getSubmittedSetting($form_state, 'headline', '');
    $this->configuration['animation_style'] = $this->normalizeAnimationStyle((string) $this->getSubmittedSetting($form_state, 'animation_style', 'fade'));
    $this->configuration['slides'] = $this->getSubmittedSetting($form_state, 'slides', []);
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
      $video_url = trim((string) ($slide['video_url'] ?? ''));
      $title_display = $this->normalizeTitleDisplay((string) ($slide['title_display'] ?? 'inline'));
      $title_position = $this->normalizeTitlePosition((string) ($slide['title_position'] ?? 'center'));
      $has_media_source = !empty($media_id) || $video_url !== '';
      $video = $this->buildVideoSource($video_url, !empty($slide['video_autoplay']), $delta);
      $has_overlay_title = $title !== '' && $title_display === 'overlay';
      $has_inline_content = ($eyebrow !== '' || $body_value !== '' || ($title !== '' && !$has_overlay_title));

      if (!$has_media_source && $eyebrow === '' && $title === '' && $body_value === '') {
        continue;
      }

      $media_render = [];
      if ($video === [] && !empty($media_id)) {
        $media = $this->entityTypeManager->getStorage('media')->load($media_id);
        if ($media) {
          $media_render = $media_view_builder->view($media, 'default');
        }
      }

      $slides[] = [
        'delta' => $delta,
        'eyebrow' => $eyebrow,
        'title' => $title,
        'has_content' => $has_inline_content,
        'has_overlay_title' => $has_overlay_title,
        'body' => [
          '#type' => 'processed_text',
          '#text' => $slide['body']['value'] ?? '',
          '#format' => $slide['body']['format'] ?? 'flex_html',
        ],
        'direction' => $this->normalizeDirection($slide['direction'] ?? 'right'),
        'title_display' => $title_display,
        'title_position' => $title_position,
        'media' => $media_render,
        'video' => $video,
      ];
    }

    if (count($slides) < 1) {
      return [];
    }

    $animation_style = $this->normalizeAnimationStyle($config['animation_style'] ?? 'fade');
    $block_id = 'moody-scroll-reveal-media-' . substr(hash('sha256', serialize($slides)), 0, 10);

    return [
      '#theme' => 'moody_scroll_reveal_media',
      '#headline' => $config['headline'] ?? '',
      '#animation_style' => $animation_style,
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

  /**
   * Retrieves a submitted block setting from the block form state.
   */
  private function getSubmittedSetting(FormStateInterface $form_state, string $key, mixed $default = NULL): mixed {
    $settings_value = $form_state->getValue(['settings', $key]);
    if ($settings_value !== NULL) {
      return $settings_value;
    }

    $value = $form_state->getValue($key);
    return $value !== NULL ? $value : $default;
  }

  /**
   * Normalizes an animation style value.
   */
  private function normalizeAnimationStyle(string $animation_style): string {
    return in_array($animation_style, ['fade', 'slide'], TRUE) ? $animation_style : 'fade';
  }

  /**
   * Normalizes a title display value.
   */
  private function normalizeTitleDisplay(string $title_display): string {
    return in_array($title_display, ['inline', 'overlay'], TRUE) ? $title_display : 'inline';
  }

  /**
   * Normalizes an overlay title position value.
   */
  private function normalizeTitlePosition(string $title_position): string {
    return in_array($title_position, [
      'top-left',
      'top-center',
      'top-right',
      'center-left',
      'center',
      'center-right',
      'bottom-left',
      'bottom-center',
      'bottom-right',
    ], TRUE) ? $title_position : 'center';
  }

  /**
   * Builds Vimeo embed data for a slide.
   */
  private function buildVideoSource(string $video_url, bool $autoplay, int $delta): array {
    if ($video_url === '' || !UrlHelper::isValid($video_url, TRUE)) {
      return [];
    }

    if ($this->isDirectVideoUrl($video_url)) {
      return [
        'autoplay' => $autoplay,
        'kind' => 'file',
        'player_id' => 'moody-scroll-reveal-media-video-' . $delta,
        'src' => $video_url,
        'title' => $this->t('Embedded video for slide @number', ['@number' => $delta + 1]),
      ];
    }

    $video_id = $this->extractVimeoVideoId($video_url);
    if ($video_id === NULL) {
      return [];
    }

    $query = [
      'api' => '1',
      'autopause' => '0',
      'byline' => '0',
      'controls' => $autoplay ? '0' : '1',
      'dnt' => '1',
      'loop' => $autoplay ? '1' : '0',
      'muted' => $autoplay ? '1' : '0',
      'portrait' => '0',
      'title' => '0',
    ];

    if ($autoplay) {
      $query['autoplay'] = '1';
      $query['background'] = '1';
    }

    return [
      'autoplay' => $autoplay,
      'kind' => 'vimeo',
      'player_id' => 'moody-scroll-reveal-media-video-' . $delta,
      'src' => 'https://player.vimeo.com/video/' . $video_id . '?' . http_build_query($query),
      'title' => $this->t('Embedded Vimeo video for slide @number', ['@number' => $delta + 1]),
    ];
  }

  /**
   * Determines whether a URL points directly to a video file.
   */
  private function isDirectVideoUrl(string $video_url): bool {
    $path = strtolower((string) parse_url($video_url, PHP_URL_PATH));

    return preg_match('/\.(mp4|m4v|webm|ogv|ogg|mov)$/', $path) === 1;
  }

  /**
   * Extracts a Vimeo video id from a supported Vimeo URL.
   */
  private function extractVimeoVideoId(string $video_url): ?string {
    $parts = parse_url($video_url);
    $host = strtolower((string) ($parts['host'] ?? ''));

    if ($host === '') {
      return NULL;
    }

    if ($host !== 'vimeo.com' && !str_ends_with($host, '.vimeo.com')) {
      return NULL;
    }

    $path = trim((string) ($parts['path'] ?? ''), '/');
    if ($path === '') {
      return NULL;
    }

    $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));

    foreach (array_reverse($segments) as $segment) {
      if (ctype_digit($segment)) {
        return $segment;
      }
    }

    return NULL;
  }

}
