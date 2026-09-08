<?php

declare(strict_types=1);

namespace Drupal\moody_hero_builder\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\moody_hero_builder\HeroConfiguration;

/**
 * Provides a visual hero composer; legacy hero fields are not changed.
 *
 * @Block(
 *   id = "moody_hero_builder",
 *   admin_label = @Translation("Moody Hero Builder"),
 *   category = @Translation("Moody")
 * )
 */
final class MoodyHeroBuilderBlock extends BlockBase {

  public function defaultConfiguration(): array {
    return ['image' => 0, 'hero' => HeroConfiguration::defaults()] + parent::defaultConfiguration();
  }

  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);
    // Drupal deep-merges numeric arrays by appending. A saved composition is
    // authoritative: never append the starter elements when reopening a block.
    if (array_key_exists('hero', $configuration)) {
      $this->configuration['hero'] = $configuration['hero'];
    }
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);
    $form['hero_builder'] = [
      '#type' => 'container', '#tree' => TRUE,
      '#attributes' => ['data-moody-hero-builder' => '', 'class' => ['mhb-builder']],
      '#attached' => ['library' => ['moody_hero_builder/builder']],
    ];
    $form['hero_builder']['source'] = [
      '#type' => 'textarea', '#title' => $this->t('Hero configuration'),
      '#description' => $this->t('The visual editor updates this data. With JavaScript unavailable, you can edit the JSON here; the same validation applies.'),
      '#default_value' => json_encode($this->configuration['hero'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      '#attributes' => ['data-hero-source' => '', 'spellcheck' => 'false'],
      '#rows' => 12,
    ];
    $form['hero_builder']['image'] = [
      '#type' => 'media_library', '#title' => $this->t('Hero image'),
      '#description' => $this->t('Choose an image from Media Library. A wide, high-resolution image works best. Set its focal point and description in the builder.'),
      '#allowed_bundles' => ['utexas_image'], '#cardinality' => 1,
      '#default_value' => $this->configuration['image'] ?: NULL,
      '#after_build' => [[self::class, 'imagePreview']],
    ];
    return $form;
  }

  /**
   * Exposes the selected, access-checked image after every Media Library AJAX.
   */
  public static function imagePreview(array $element, FormStateInterface $form_state): array {
    $id = self::mediaId($element['#value'] ?? 0);
    $cache = new CacheableMetadata();
    $image = self::imageData($id, $cache);
    $element['#attributes']['data-hero-media'] = '';
    $element['#attributes']['data-hero-image-url'] = $image['url'] ?? '';
    $element['#attributes']['data-hero-image-alt'] = $image['alt'] ?? '';
    return $element;
  }

  private static function mediaId(mixed $value): int {
    if (is_array($value)) {
      $value = $value['media_library_selection'] ?? $value['media_selection_id'] ?? 0;
    }
    return is_scalar($value) && ctype_digit((string) $value) ? (int) $value : 0;
  }

  public function blockValidate($form, FormStateInterface $form_state): void {
    try {
      HeroConfiguration::decode((string) $form_state->getValue(['hero_builder', 'source'], ''));
      $image_id = self::mediaId($form_state->getValue(['hero_builder', 'image'], 0));
      $cache = new CacheableMetadata();
      if ($image_id && !self::imageData($image_id, $cache)) {
        $form_state->setError($form['hero_builder']['image'], $this->t('Choose an accessible image from the Media Library, or remove the unavailable image.'));
      }
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setError($form['hero_builder']['source'], $e->getMessage());
    }
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['hero'] = HeroConfiguration::decode((string) $form_state->getValue(['hero_builder', 'source'], ''));
    $this->configuration['image'] = self::mediaId($form_state->getValue(['hero_builder', 'image'], 0));
  }

  public function build(): array {
    try {
      if (!is_array($this->configuration['hero'])) {
        throw new \InvalidArgumentException('Invalid hero configuration.');
      }
      $hero = HeroConfiguration::validate($this->configuration['hero']);
    }
    catch (\InvalidArgumentException $e) {
      // A malformed imported config must never become executable markup.
      return ['#cache' => ['max-age' => 0]];
    }
    $cache = new CacheableMetadata();
    $image = self::imageData((int) $this->configuration['image'], $cache);
    $build = [
      '#theme' => 'moody_hero_builder', '#hero' => $hero,
      '#image' => $image, '#heading_tag' => $hero['heading_level'],
      '#attached' => ['library' => ['moody_hero_builder/hero']],
    ];
    $cache->applyTo($build);
    return $build;
  }

  /**
   * Resolves media through its source field and retains access/cache metadata.
   */
  private static function imageData(int $id, CacheableMetadata $cache): array {
    if (!$id) {
      return [];
    }
    $manager = \Drupal::entityTypeManager();
    $media = $manager->getStorage('media')->load($id);
    $cache->addCacheTags(['media:' . $id]);
    if (!$media || $media->bundle() !== 'utexas_image') {
      return [];
    }
    $access = $media->access('view', NULL, TRUE);
    $cache->addCacheableDependency($media)->addCacheableDependency($access);
    if (!$access->isAllowed()) {
      return [];
    }
    $field = $media->getSource()->getConfiguration()['source_field'] ?? '';
    if (!$field || !$media->hasField($field) || $media->get($field)->isEmpty()) {
      return [];
    }
    $item = $media->get($field)->first();
    $file = $item->entity;
    if (!$file) {
      return [];
    }
    $cache->addCacheableDependency($file);
    $uri = $file->getFileUri();
    $url = \Drupal::service('file_url_generator')->generateString($uri);
    $sources = [];
    // Width-only styles preserve the entire image so CSS focal points match the
    // editor. Pre-cropped derivatives would discard the author's chosen area.
    foreach (['utexas_image_style_1000w', 'utexas_image_style_1600w', 'utexas_image_style_2000w'] as $name) {
      $style = $manager->getStorage('image_style')->load($name);
      $cache->addCacheTags(['config:image.style.' . $name]);
      if ($style) {
        $cache->addCacheableDependency($style);
        $dimensions = ['width' => $item->width, 'height' => $item->height];
        $style->transformDimensions($dimensions, $uri);
        if (!empty($dimensions['width'])) {
          $sources[$dimensions['width']] = $style->buildUrl($uri) . ' ' . $dimensions['width'] . 'w';
        }
      }
    }
    return ['url' => $url, 'srcset' => implode(', ', $sources), 'alt' => $item->alt ?? '', 'width' => $item->width ?: 2280, 'height' => $item->height ?: 1232];
  }

}
