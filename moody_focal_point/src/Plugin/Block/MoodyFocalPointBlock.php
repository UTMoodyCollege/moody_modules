<?php

declare(strict_types=1);

namespace Drupal\moody_focal_point\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
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
   * Default width of a focal area, in percent.
   */
  const DEFAULT_AREA_WIDTH = 24.0;

  /**
   * Default height of a focal area, in percent.
   */
  const DEFAULT_AREA_HEIGHT = 24.0;

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
      'image' => NULL,
      'show_slide_counter' => FALSE,
      'focal_points' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();
    $selected_image = $this->getSelectedImageData(isset($config['image']) ? (int) $config['image'] : NULL);

    $form['image'] = [
      '#type' => 'media_library',
      '#title' => $this->t('Image'),
      '#description' => $this->t('Select the image to annotate with focal points.'),
      '#allowed_bundles' => ['utexas_image'],
      '#cardinality' => 1,
      '#default_value' => $config['image'] ?? NULL,
      '#required' => TRUE,
    ];

    $form['show_slide_counter'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show slide counter'),
      '#description' => $this->t('Display the current slide number, for example 1 / 3.'),
      '#default_value' => !empty($config['show_slide_counter']),
    ];

    $form['focal_points'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Focal Points'),
      '#description' => $this->t('Add up to @max focal points. Drag over the selected image to define the zoomed area that should be framed for each step.', ['@max' => self::MAX_FOCAL_POINTS]),
    ];

    $form['#attached']['library'][] = 'moody_focal_point/moody_focal_point.admin';

    for ($i = 0; $i < self::MAX_FOCAL_POINTS; $i++) {
      $point = $this->normalizeFocalPoint($config['focal_points'][$i] ?? []);

      $form['focal_points'][$i] = [
        '#type' => 'details',
        '#title' => $this->t('Focal Point @number', ['@number' => $i + 1]),
        '#open' => !empty($point['caption_title']) || !empty($point['caption_body']['value']),
      ];

      $form['focal_points'][$i]['editor'] = [
        '#type' => 'markup',
        '#markup' => $this->buildEditorMarkup($selected_image, $point, $i),
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
        '#type' => 'hidden',
        '#default_value' => $point['x_position'] ?? 50,
        '#attributes' => ['data-focal-input' => 'x'],
      ];

      $form['focal_points'][$i]['y_position'] = [
        '#type' => 'hidden',
        '#default_value' => $point['y_position'] ?? 50,
        '#attributes' => ['data-focal-input' => 'y'],
      ];

      $form['focal_points'][$i]['area_width'] = [
        '#type' => 'hidden',
        '#default_value' => $point['area_width'],
        '#attributes' => ['data-focal-input' => 'width'],
      ];

      $form['focal_points'][$i]['area_height'] = [
        '#type' => 'hidden',
        '#default_value' => $point['area_height'],
        '#attributes' => ['data-focal-input' => 'height'],
      ];

      $form['focal_points'][$i]['caption_x_position'] = [
        '#type' => 'hidden',
        '#default_value' => $point['caption_x_position'],
        '#attributes' => ['data-focal-input' => 'caption-x'],
      ];

      $form['focal_points'][$i]['caption_y_position'] = [
        '#type' => 'hidden',
        '#default_value' => $point['caption_y_position'],
        '#attributes' => ['data-focal-input' => 'caption-y'],
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
    $this->configuration['show_slide_counter'] = !empty($this->getSubmittedSetting($form_state, 'show_slide_counter', FALSE));
    $raw_points = $this->getSubmittedSetting($form_state, 'focal_points', []);
    $points = [];
    for ($i = 0; $i < self::MAX_FOCAL_POINTS; $i++) {
      $point = $this->normalizeFocalPoint($raw_points[$i] ?? []);
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
        'x_position' => $point['x_position'],
        'y_position' => $point['y_position'],
        'area_width' => $point['area_width'],
        'area_height' => $point['area_height'],
        'caption_x_position' => $point['caption_x_position'],
        'caption_y_position' => $point['caption_y_position'],
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
      $point = $this->normalizeFocalPoint($point);
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
        'x_position' => $point['x_position'],
        'y_position' => $point['y_position'],
        'area_width' => $point['area_width'],
        'area_height' => $point['area_height'],
        'caption_x_position' => $point['caption_x_position'],
        'caption_y_position' => $point['caption_y_position'],
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
      '#show_slide_counter' => !empty($config['show_slide_counter']),
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

  /**
   * Normalizes focal-point geometry values.
   */
  private function normalizeFocalPoint(array $point): array {
    $x_position = $this->normalizePercentage((float) ($point['x_position'] ?? 50));
    $y_position = $this->normalizePercentage((float) ($point['y_position'] ?? 50));
    $area_width = $this->normalizeAreaDimension((float) ($point['area_width'] ?? self::DEFAULT_AREA_WIDTH));
    $area_height = $this->normalizeAreaDimension((float) ($point['area_height'] ?? self::DEFAULT_AREA_HEIGHT));
    $caption_x_position = $this->normalizePercentage((float) ($point['caption_x_position'] ?? 50));
    $caption_y_position = $this->normalizePercentage((float) ($point['caption_y_position'] ?? 82));

    $x_position = max($area_width / 2, min(100.0 - ($area_width / 2), $x_position));
    $y_position = max($area_height / 2, min(100.0 - ($area_height / 2), $y_position));

    return [
      'caption_title' => (string) ($point['caption_title'] ?? ''),
      'caption_body' => [
        'value' => (string) ($point['caption_body']['value'] ?? ''),
        'format' => (string) ($point['caption_body']['format'] ?? 'flex_html'),
      ],
      'x_position' => $x_position,
      'y_position' => $y_position,
      'area_width' => $area_width,
      'area_height' => $area_height,
      'caption_x_position' => $caption_x_position,
      'caption_y_position' => $caption_y_position,
      'show_square' => !empty($point['show_square']),
      'square_color' => (string) ($point['square_color'] ?? '#ffffff'),
      'show_arrow' => !empty($point['show_arrow']),
      'caption_border' => $this->normalizeCaptionBorder((string) ($point['caption_border'] ?? 'thin')),
    ];
  }

  /**
   * Normalizes percentage values to a 0-100 range.
   */
  private function normalizePercentage(float $value): float {
    return max(0.0, min(100.0, $value));
  }

  /**
   * Normalizes box dimensions to a usable range.
   */
  private function normalizeAreaDimension(float $value): float {
    return max(5.0, min(100.0, $value));
  }

  /**
   * Returns the selected image URL and alt text for the admin annotator.
   */
  private function getSelectedImageData(?int $media_id): ?array {
    if (empty($media_id)) {
      return NULL;
    }

    $media = $this->entityTypeManager->getStorage('media')->load($media_id);
    if (!$media) {
      return NULL;
    }

    $image_file = $media->field_utexas_media_image->entity ?? NULL;
    if (!$image_file) {
      return NULL;
    }

    return [
      'url' => $this->fileUrlGenerator->generateString($image_file->getFileUri()),
      'alt' => (string) ($media->field_utexas_media_image->alt ?? ''),
    ];
  }

  /**
   * Builds the admin annotator markup for one focal point.
   */
  private function buildEditorMarkup(?array $selected_image, array $point, int $index): string {
    $image_url = Html::escape((string) ($selected_image['url'] ?? ''));
    $image_alt = Html::escape((string) ($selected_image['alt'] ?? ''));
    $empty_class = $image_url === '' ? ' is-empty' : '';

    return '<div class="moody-focal-point-admin' . $empty_class . '" data-focal-admin-item data-focal-index="' . $index . '">'
      . '<div class="moody-focal-point-admin__toolbar">'
      . '<span class="moody-focal-point-admin__mode is-active" data-focal-admin-mode="focus" role="button" tabindex="0">' . $this->t('Draw focus area') . '</span>'
      . '<span class="moody-focal-point-admin__mode" data-focal-admin-mode="caption" role="button" tabindex="0">' . $this->t('Place text') . '</span>'
      . '</div>'
      . '<div class="moody-focal-point-admin__stage" data-focal-admin-stage data-image-url="' . $image_url . '" data-image-alt="' . $image_alt . '">'
      . '<img class="moody-focal-point-admin__image" data-focal-admin-image alt="' . $image_alt . '"' . ($image_url !== '' ? ' src="' . $image_url . '"' : '') . ' />'
      . '<div class="moody-focal-point-admin__shade" data-focal-admin-shade></div>'
      . '<div class="moody-focal-point-admin__hitarea" data-focal-admin-hitarea></div>'
      . '<div class="moody-focal-point-admin__selection" data-focal-admin-selection><span class="moody-focal-point-admin__selection-label">' . $this->t('Focus area') . '</span></div>'
      . '<span class="moody-focal-point-admin__caption-pin" data-focal-admin-caption-pin role="presentation" aria-label="' . $this->t('Caption position') . '"><span>' . $this->t('Text') . '</span></span>'
      . '</div>'
      . '<p class="moody-focal-point-admin__help" data-focal-admin-help>' . $this->t('Draw focus area mode: drag over the actual image to set what the viewport frames. Place text mode: click where the caption should appear over the final image.') . '</p>'
      . '<p class="moody-focal-point-admin__empty">' . $this->t('Select an image above to enable the focal-point editor.') . '</p>'
      . '</div>';
  }

}
