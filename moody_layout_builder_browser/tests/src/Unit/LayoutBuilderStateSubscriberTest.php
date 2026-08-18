<?php

declare(strict_types=1);

namespace Drupal\Tests\moody_layout_builder_browser\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\layout_builder\Event\PrepareLayoutEvent;
use Drupal\layout_builder\Field\LayoutSectionItemList;
use Drupal\layout_builder\OverridesSectionStorageInterface;
use Drupal\layout_builder\Section;
use Drupal\moody_layout_builder_browser\EventSubscriber\LayoutBuilderStateSubscriber;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the persisted Layout Builder comparison.
 */
#[Group('moody_layout_builder_browser')]
class LayoutBuilderStateSubscriberTest extends UnitTestCase {

  /**
   * Tests that generated UUID differences remain clean.
   */
  public function testMatchingSectionsAreClean(): void {
    $working = $this->section([
      'layout_id' => 'layout_onecol',
      'components' => [
        'working-uuid' => [
          'uuid' => 'working-uuid',
          'region' => 'content',
          'weight' => 0,
        ],
      ],
    ]);
    $saved = $this->section([
      'components' => [
        'saved-uuid' => [
          'weight' => 0,
          'region' => 'content',
          'uuid' => 'saved-uuid',
        ],
      ],
      'layout_id' => 'layout_onecol',
    ]);

    $this->assertFalse($this->detect([$working], [$saved]));
  }

  /**
   * Tests that a changed working section is marked unsaved.
   */
  public function testChangedSectionsAreUnsaved(): void {
    $working = $this->section(['layout_id' => 'layout_twocol']);
    $saved = $this->section(['layout_id' => 'layout_onecol']);

    $this->assertTrue($this->detect([$working], [$saved]));
  }

  /**
   * Runs the subscriber with mocked working and persisted sections.
   *
   * @param \Drupal\layout_builder\Section[] $working
   *   Working sections.
   * @param \Drupal\layout_builder\Section[] $saved
   *   Persisted sections.
   */
  private function detect(array $working, array $saved): bool {
    $edited_entity = $this->createMock(EntityInterface::class);
    $edited_entity->method('id')->willReturn(1);
    $edited_entity->method('getEntityTypeId')->willReturn('node');

    $section_list = $this->createMock(LayoutSectionItemList::class);
    $section_list->method('getSections')->willReturn($saved);

    $persisted_entity = $this->createMock(FieldableEntityInterface::class);
    $persisted_entity->method('hasField')->willReturn(TRUE);
    $persisted_entity->method('get')->willReturn($section_list);

    $entity_storage = $this->createMock(EntityStorageInterface::class);
    $entity_storage->method('loadUnchanged')->willReturn($persisted_entity);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturn($entity_storage);

    $section_storage = $this->createMock(OverridesSectionStorageInterface::class);
    $section_storage->method('getStorageType')->willReturn('overrides');
    $section_storage->method('getContextValue')->willReturn($edited_entity);
    $section_storage->method('getSections')->willReturn($working);

    $request = new Request();
    $request_stack = new RequestStack();
    $request_stack->push($request);
    $subscriber = new LayoutBuilderStateSubscriber(
      $entity_type_manager,
      $request_stack,
    );
    $subscriber->onPrepareLayout(new PrepareLayoutEvent($section_storage));

    return $request->attributes->get(
      LayoutBuilderStateSubscriber::REQUEST_ATTRIBUTE,
    );
  }

  /**
   * Creates a section mock with a stable serialized value.
   */
  private function section(array $value): Section {
    $section = $this->createMock(Section::class);
    $section->method('toArray')->willReturn($value);
    return $section;
  }

}
