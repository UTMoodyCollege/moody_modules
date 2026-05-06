<?php

declare(strict_types=1);

namespace Drupal\moody_focal_point\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Moody Focal Point block.
 *
 * @Block(
 *   id = "moody_focal_point_block",
 *   admin_label = @Translation("Moody Focal Point"),
 *   category = @Translation("Moody"),
 * )
 */
final class MoodyFocalPointBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Maximum number of focal points allowed per block.
   */
  const MAX_FOCAL_POINTS = 10;

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
      'image' => NULL,
      'focal_points' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    $form['image'] = [
      '#type' => 'media_library',
      '#title' => $this->t('Image'),
      '#description' => $this->t('Select the image to annotate with focal points.'),
      '#allowed_bundles' => ['utexas_image'],
      '#cardinality' => 1,
      '#default_value' => $config['image'] ?? NULL,
      '#required' => TRUE,
    ];

    $form['focal_points'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Focal Points'),
      '#description' => $this->t('Add up to @max focal points. Each point highlights an area on the image and displays a caption as the user scrolls.', ['@max' => self::MAX_FOCAL_POINTS]),
    ];

    for ($i = 0; $i < self::MAX_FOCAL_POINTS; $i++) {
      $point = $config['focal_points'][$i] ?? [];

      $form['focal_points'][$i] = [
        '#type' => 'details',
        '#title' => $this->t('Focal Point @number', ['@number' => $i + 1]),
        '#open' => !empty($point['caption_title']) || !empty($point['caption_body']['value']),
      ];

      $form['focal_points'][$i]['caption_title'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Caption title'),
        '#description' => $this->t('Heading displayed in the caption for this focal point.'),
        '#default_value' => $point['caption_title'] ?? '',
      ];

      $form['focal_points'][$i]['caption_body'] = [
        '#type' => 'text_format',
        '#title' => $this->t('Caption body'),
        '#format' => $point['caption_body']['format'] ?? 'flex_html',
        '#default_value' => $point['caption_body']['value'] ?? '',
      ];

      $form['focal_points'][$i]['x_position'] = [
        '#type' => 'number',
        '#title' => $this->t('Horizontal position (%)'),
        '#description' => $this->t('Left-to-right position of the focal point as a percentage (0–100) of the image width.'),
        '#min' => 0,
        '#max' => 100,
        '#step' => 0.1,
        '#default_value' => $point['x_position'] ?? 50,
      ];

      $form['focal_points'][$i]['y_position'] = [
        '#type' => 'number',
        '#title' => $this->t('Vertical position (%)'),
        '#description' => $this->t('Top-to-bottom position of the focal point as a percentage (0–100) of the image height.'),
        '#min' => 0,
        '#max' => 100,
        '#step' => 0.1,
        '#default_value' => $point['y_position'] ?? 50,
      ];

      $form['focal_points'][$i]['show_square'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show square around focal area'),
        '#default_value' => !empty($point['show_square']),
      ];

      $form['focal_points'][$i]['square_color'] = [
        '#type' => 'color',
        '#title' => $this->t('Square color'),
        '#default_value' => $point['square_color'] ?? '#ffffff',
      ];

      $form['focal_points'][$i]['show_arrow'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show arrow from caption to focal area'),
        '#default_value' => !empty($point['show_arrow']),
      ];

      $form['focal_points'][$i]['caption_border'] = [
        '#type' => 'select',
        '#title' => $this->t('Caption border style'),
        '#options' => [
          'none' => $this->t('None'),
          'thin' => $this->t('Thin'),
          'thick' => $this->t('Thick'),
          'rounded' => $this->t('Rounded'),
          'rounded-thick' => $this->t('Rounded thick'),
        ],
        '#default_value' => $this->normalizeCaptionBorder((string) ($point['caption_border'] ?? 'thin')),
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['image'] = $this->getSubmittedSetting($form_state, 'image', NULL);
    $raw_points = $this->getSubmittedSetting($form_state, 'focal_points', []);
    $points = [];
    for ($i = 0; $i < self::MAX_FOCAL_POINTS; $i++) {
      $point = $raw_points[$i] ?? [];
      $title = trim((string) ($point['caption_title'] ?? ''));
      $body = trim((string) ($point['caption_body']['value'] ?? ''));
      if ($title === '' && $body === '') {
        continue;
      }
      $points[] = [
        'caption_title' => $title,
        'caption_body' => [
          'value' => $body,
          'format' => (string) ($point['caption_body']['format'] ?? 'flex_html'),
        ],
        'x_position' => max(0.0, min(100.0, (float) ($point['x_position'] ?? 50))),
        'y_position' => max(0.0, min(100.0, (float) ($point['y_position'] ?? 50))),
        'show_square' => !empty($point['show_square']),
        'square_color' => (string) ($point['square_color'] ?? '#ffffff'),
        'show_arrow' => !empty($point['show_arrow']),
        'caption_border' => $this->normalizeCaptionBorder((string) ($point['caption_border'] ?? 'thin')),
      ];
    }
    $this->configuration['focal_points'] = $points;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $config = $this->getConfiguration();
    $image_id = $config['image'] ?? NULL;

    if (empty($image_id)) {
      return [];
    }

    $media = $this->entityTypeManager->getStorage('media')->load($image_id);
    if (!$media) {
      return [];
    }

    $image_file = $media->field_utexas_media_image->entity ?? NULL;
    if (!$image_file) {
      return [];
    }

    $image_render = [
      '#theme' => 'image',
      '#uri' => $image_file->getFileUri(),
      '#alt' => $media->field_utexas_media_image->alt ?? '',
      '#attributes' => ['class' => ['moody-focal-point__image']],
    ];

    $focal_points = [];
    foreach (($config['focal_points'] ?? []) as $point) {
      $title = trim((string) ($point['caption_title'] ?? ''));
      $body = trim((string) ($point['caption_body']['value'] ?? ''));
      if ($title === '' && $body === '') {
        continue;
      }
      $focal_points[] = [
        'caption_title' => $title,
        'caption_body' => [
          '#type' => 'processed_text',
          '#text' => $body,
          '#format' => (string) ($point['caption_body']['format'] ?? 'flex_html'),
        ],
        'x_position' => (float) ($point['x_position'] ?? 50),
        'y_position' => (float) ($point['y_position'] ?? 50),
        'show_square' => !empty($point['show_square']),
        'square_color' => (string) ($point['square_color'] ?? '#ffffff'),
        'show_arrow' => !empty($point['show_arrow']),
        'caption_border' => $this->normalizeCaptionBorder((string) ($point['caption_border'] ?? 'thin')),
      ];
    }

    if (empty($focal_points)) {
      return [];
    }

    $block_id = 'moody-focal-point-' . substr(hash('sha256', json_encode($focal_points) . $image_id), 0, 10);

    return [
      '#theme' => 'moody_focal_point',
      '#image' => $image_render,
      '#focal_points' => $focal_points,
      '#block_id' => $block_id,
      '#attached' => [
        'library' => [
          'moody_focal_point/moody_focal_point',
        ],
      ],
    ];
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
   * Normalizes a caption border style value.
   */
  private function normalizeCaptionBorder(string $border): string {
    return in_array($border, ['none', 'thin', 'thick', 'rounded', 'rounded-thick'], TRUE) ? $border : 'thin';
  }

}
