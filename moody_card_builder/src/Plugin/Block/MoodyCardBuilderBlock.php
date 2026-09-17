<?php

declare(strict_types=1);

namespace Drupal\moody_card_builder\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\moody_card_builder\CardConfiguration;
use Drupal\moody_hero_builder\Plugin\Block\MoodyHeroBuilderBlock;

/**
 * @Block(
 *   id = "moody_card_builder",
 *   admin_label = @Translation("Moody Card Builder"),
 *   category = @Translation("Specialty Blocks")
 * )
 */
final class MoodyCardBuilderBlock extends BlockBase {

  public function defaultConfiguration(): array {
    return ['collection' => CardConfiguration::defaults(), 'images' => []] + parent::defaultConfiguration();
  }

  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);
    // Saved ordered lists replace defaults, never append starter cards.
    foreach (['collection', 'images'] as $key) {
      if (array_key_exists($key, $configuration)) { $this->configuration[$key] = $configuration[$key]; }
    }
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);
    $form['card_builder'] = ['#type' => 'container', '#tree' => TRUE, '#attributes' => ['data-moody-card-builder' => '', 'class' => ['mcb-builder', 'mhb-builder']], '#attached' => ['library' => ['moody_card_builder/builder']]];
    $form['card_builder']['source'] = ['#type' => 'textarea', '#title' => $this->t('Card configuration'), '#default_value' => json_encode($this->configuration['collection'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '#attributes' => ['data-card-source' => '', 'spellcheck' => 'false'], '#description' => $this->t('The visual builder updates this JSON. Without JavaScript you can edit it here; the same validation applies.')];
    $form['card_builder']['images'] = ['#type' => 'media_library', '#title' => $this->t('Card image library'), '#description' => $this->t('Add images here, then assign an image to each card in its Image settings. Images may be reused across cards.'), '#allowed_bundles' => ['utexas_image'], '#cardinality' => 12, '#default_value' => implode(',', $this->configuration['images']), '#after_build' => [[self::class, 'imagePreview']]];
    return $form;
  }

  public static function imageIds(mixed $value): array {
    if (is_array($value)) { $value = $value['media_library_selection'] ?? ''; }
    if ($value === '' || $value === NULL) { return []; }
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*){0,11}$/D', (string) $value)) { throw new \InvalidArgumentException('Choose up to 12 images from Media Library.'); }
    return array_values(array_unique(array_map('intval', explode(',', (string) $value))));
  }

  public static function imagePreview(array $element, FormStateInterface $form_state): array {
    $images = []; $cache = new CacheableMetadata();
    try {
      foreach (self::imageIds($element['#value'] ?? '') as $id) {
        $image = MoodyHeroBuilderBlock::imageData($id, $cache);
        if ($image) { $images[$id] = $image; }
      }
    }
    catch (\InvalidArgumentException $e) { /* Form validation reports invalid input. */ }
    $element['#attributes']['data-card-media'] = json_encode($images, JSON_THROW_ON_ERROR);
    return $element;
  }

  public function blockValidate($form, FormStateInterface $form_state): void {
    try {
      $collection = CardConfiguration::decode((string) $form_state->getValue(['card_builder', 'source'], ''));
      $ids = self::imageIds($form_state->getValue(['card_builder', 'images'], ''));
      $cache = new CacheableMetadata();
      foreach ($collection['cards'] as $card) {
        if ($card['image'] && (!in_array($card['image'], $ids, TRUE) || !MoodyHeroBuilderBlock::imageData($card['image'], $cache))) { throw new \InvalidArgumentException('A card image is unavailable. Choose an accessible image from the card image library, or select No image.'); }
      }
    }
    catch (\InvalidArgumentException $e) { $form_state->setError($form['card_builder']['source'], $e->getMessage()); }
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['collection'] = CardConfiguration::decode((string) $form_state->getValue(['card_builder', 'source'], ''));
    $this->configuration['images'] = self::imageIds($form_state->getValue(['card_builder', 'images'], ''));
  }

  public function build(): array {
    try { $collection = CardConfiguration::decode(json_encode($this->configuration['collection'], JSON_THROW_ON_ERROR)); }
    catch (\InvalidArgumentException | \JsonException $e) { return ['#cache' => ['max-age' => 0]]; }
    $images = []; $cache = new CacheableMetadata();
    foreach ($collection['cards'] as $card) {
      if ($card['image'] && !array_key_exists($card['image'], $images)) { $images[$card['image']] = MoodyHeroBuilderBlock::imageData($card['image'], $cache); }
    }
    $build = ['#theme' => 'moody_card_builder', '#collection' => $collection, '#images' => $images, '#attached' => ['library' => ['moody_card_builder/cards']]];
    $cache->applyTo($build);
    return $build;
  }
}
