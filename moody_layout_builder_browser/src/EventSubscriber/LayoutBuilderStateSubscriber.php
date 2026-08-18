<?php

declare(strict_types=1);

namespace Drupal\moody_layout_builder_browser\EventSubscriber;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\layout_builder\Event\PrepareLayoutEvent;
use Drupal\layout_builder\LayoutBuilderEvents;
use Drupal\layout_builder\OverridesSectionStorageInterface;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionListInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Exposes whether the working layout differs from persisted sections.
 */
final class LayoutBuilderStateSubscriber implements EventSubscriberInterface {

  /**
   * Request attribute containing the current Layout Builder dirty state.
   */
  public const REQUEST_ATTRIBUTE = '_moody_layout_builder_unsaved';

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run after Layout Builder has prepared a first-time override.
    return [LayoutBuilderEvents::PREPARE_LAYOUT => ['onPrepareLayout', 0]];
  }

  /**
   * Compares the working layout with the version stored by Drupal.
   */
  public function onPrepareLayout(PrepareLayoutEvent $event): void {
    $section_storage = $event->getSectionStorage();
    $saved_sections = $this->getSavedSections($section_storage);
    $request = $this->requestStack->getCurrentRequest();

    if ($request && $saved_sections !== NULL) {
      $request->attributes->set(
        self::REQUEST_ATTRIBUTE,
        $this->normalize($section_storage->getSections()) !== $saved_sections,
      );
    }
  }

  /**
   * Loads the persisted sections represented by a section storage plugin.
   */
  private function getSavedSections(SectionStorageInterface $section_storage): ?array {
    $context_name = match ($section_storage->getStorageType()) {
      'defaults' => 'display',
      'overrides' => 'entity',
      default => NULL,
    };
    if ($context_name === NULL) {
      return NULL;
    }

    $edited_entity = $section_storage->getContextValue($context_name);
    if (!$edited_entity instanceof EntityInterface || $edited_entity->id() === NULL) {
      return NULL;
    }

    $persisted_entity = $this->entityTypeManager
      ->getStorage($edited_entity->getEntityTypeId())
      ->loadUnchanged($edited_entity->id());
    if (!$persisted_entity instanceof EntityInterface) {
      return NULL;
    }

    if ($edited_entity instanceof TranslatableInterface && $persisted_entity instanceof TranslatableInterface) {
      $langcode = $edited_entity->language()->getId();
      if ($persisted_entity->hasTranslation($langcode)) {
        $persisted_entity = $persisted_entity->getTranslation($langcode);
      }
    }

    if ($section_storage->getStorageType() === 'defaults') {
      return $persisted_entity instanceof SectionListInterface
        ? $this->normalize($persisted_entity->getSections())
        : NULL;
    }

    if (!$persisted_entity instanceof FieldableEntityInterface || !$persisted_entity->hasField(OverridesSectionStorage::FIELD_NAME)) {
      return NULL;
    }

    $section_list = $persisted_entity->get(OverridesSectionStorage::FIELD_NAME);
    if (!$section_list instanceof SectionListInterface) {
      return NULL;
    }

    $saved_sections = $section_list->getSections();
    if ($saved_sections === [] && $section_storage instanceof OverridesSectionStorageInterface) {
      $saved_sections = $section_storage->getDefaultSectionStorage()->getSections();
    }
    return $this->normalize($saved_sections);
  }

  /**
   * Converts section value objects into stable arrays for comparison.
   *
   * @param \Drupal\layout_builder\Section[] $sections
   *   The sections to normalize.
   */
  private function normalize(array $sections): array {
    return array_map(
      function (Section $section): array {
        $value = $section->toArray();
        $components = [];
        foreach ($value['components'] ?? [] as $component) {
          // Layout Builder may regenerate a component UUID without changing
          // the component editors see. Compare the meaningful values instead.
          unset($component['uuid']);
          $components[] = $this->normalizeValue($component);
        }
        usort(
          $components,
          static fn(array $a, array $b): int => serialize($a) <=> serialize($b),
        );
        $value['components'] = $components;
        return $this->normalizeValue($value);
      },
      $sections,
    );
  }

  /**
   * Sorts associative configuration keys while preserving meaningful lists.
   */
  private function normalizeValue(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }

    if (!array_is_list($value)) {
      ksort($value);
    }
    return array_map([$this, 'normalizeValue'], $value);
  }

}
