<?php

namespace Drupal\moody_media_remediation\Plugin\Field\FieldFormatter;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Url;
use Drupal\media\Plugin\Field\FieldFormatter\OEmbedFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Keeps failed provider previews visible without repeatedly hammering them. */
class ResilientOEmbedFormatter extends OEmbedFormatter {

  protected CacheBackendInterface $failureCache;
  protected TimeInterface $clock;
  protected string $siteOrigin;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $formatter = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $formatter->failureCache = $container->get('cache.data');
    $formatter->clock = $container->get('datetime.time');
    $formatter->siteOrigin = $container->get('request_stack')->getCurrentRequest()?->getSchemeAndHttpHost() ?? '';
    return $formatter;
  }

  public function viewElements(FieldItemListInterface $items, $langcode) {
    $entity = $items->getEntity();
    $cid = 'moody_media:oembed_failure:' . hash('sha256', serialize([
      $entity->uuid(), $entity instanceof RevisionableInterface ? $entity->getRevisionId() : NULL,
      $entity->label(), $items->getName(), $items->getValue(), $langcode,
      $this->getSettings(), $this->siteOrigin,
    ]));
    if ($cached = $this->failureCache->get($cid)) {
      $elements = $cached->data;
      CacheableMetadata::createFromRenderArray($elements)
        ->setCacheMaxAge(max(0, $cached->expire - $this->clock->getCurrentTime()))
        ->applyTo($elements);
      return $elements;
    }

    // Core retains its provider validation, successful rendering, and first error.
    $elements = parent::viewElements($items, $langcode);
    $hasFallback = FALSE;
    foreach ($items as $delta => $item) {
      if (isset($elements[$delta])) {
        continue;
      }
      $property = $item->getFieldDefinition()->getFieldStorageDefinition()->getMainPropertyName();
      $url = $item->{$property};
      if (!is_string($url) || !self::supportsFallback($url)) {
        continue;
      }
      $hasFallback = TRUE;
      $elements[$delta] = [
        '#type' => 'container',
        'message' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('Video preview is currently unavailable.')],
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Open @title on its original site', ['@title' => $entity->label()]),
          '#url' => Url::fromUri($url),
        ],
      ];
    }
    if ($hasFallback) {
      // Retry after five minutes; media edits and media settings invalidate sooner.
      $metadata = CacheableMetadata::createFromObject($entity)
        ->addCacheableDependency($this->config)
        ->merge(CacheableMetadata::createFromRenderArray($elements))
        ->merge((new CacheableMetadata())->setCacheMaxAge(300));
      foreach ($elements as $delta => $element) {
        if (is_int($delta)) {
          $metadata = $metadata->merge(CacheableMetadata::createFromRenderArray($element));
        }
      }
      $metadata->applyTo($elements);
      $this->failureCache->set($cid, $elements, $this->clock->getCurrentTime() + 300, $metadata->getCacheTags());
    }
    return $elements;
  }

  public static function supportsFallback(string $url): bool {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      return FALSE;
    }
    $parts = parse_url($url);
    return ($parts['scheme'] ?? '') === 'https'
      && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
      && in_array(strtolower($parts['host'] ?? ''), [
        'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be',
        'vimeo.com', 'www.vimeo.com', 'player.vimeo.com',
      ], TRUE);
  }
}
