<?php

namespace Drupal\Tests\moody_page_convert\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Section;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Moody page conversion.
 *
 * @group moody_page_convert
 */
#[RunTestsInSeparateProcesses]
class PageConverterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'layout_discovery',
    'layout_builder',
    'moody_page_convert',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'node']);

    foreach ([
      'moody_standard_page',
      'moody_subsite_page',
      'moody_feature_page',
    ] as $bundle) {
      NodeType::create([
        'type' => $bundle,
        'name' => $bundle,
        'new_revision' => TRUE,
      ])->save();
    }

    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'layout_builder__layout',
      'type' => 'layout_section',
      'cardinality' => 1,
      'translatable' => TRUE,
    ])->save();
    foreach ([
      'moody_standard_page',
      'moody_subsite_page',
      'moody_feature_page',
    ] as $bundle) {
      FieldConfig::create([
        'entity_type' => 'node',
        'field_name' => 'layout_builder__layout',
        'bundle' => $bundle,
        'label' => 'Layout',
        'translatable' => FALSE,
      ])->save();
    }

    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_moody_url_generator',
      'type' => 'string',
    ])->save();
    foreach (['moody_standard_page', 'moody_subsite_page'] as $bundle) {
      FieldConfig::create([
        'entity_type' => 'node',
        'field_name' => 'field_moody_url_generator',
        'bundle' => $bundle,
        'label' => 'Moody URL Generator',
        'required' => TRUE,
      ])->save();
    }
  }

  /**
   * Tests that layout and revision history survive conversion.
   */
  public function testConvertPreservesLayoutAndCreatesRevision(): void {
    $node = Node::create([
      'type' => 'moody_standard_page',
      'title' => 'Convertible page',
      'layout_builder__layout' => [new Section('layout_onecol')],
      'field_moody_url_generator' => 'directory',
    ]);
    $node->save();
    $node_id = $node->id();
    $old_revision_id = $node->getRevisionId();

    $converted = $this->container
      ->get('moody_page_convert.converter')
      ->convert($node, 'moody_subsite_page');

    $this->assertSame($node_id, $converted->id());
    $this->assertSame('moody_subsite_page', $converted->bundle());
    $this->assertNotSame($old_revision_id, $converted->getRevisionId());
    $this->assertSame('layout_onecol', $converted->get('layout_builder__layout')->getSection(0)->getLayoutId());
    $this->assertSame('directory', $converted->get('field_moody_url_generator')->value);

    $old_revision = $this->container
      ->get('entity_type.manager')
      ->getStorage('node')
      ->loadRevision($old_revision_id);
    // Node bundles are not revisionable in Drupal, so prior revisions use the
    // node's current bundle while retaining their revisioned layout values.
    $this->assertSame('moody_subsite_page', $old_revision->bundle());
    $this->assertSame('layout_onecol', $old_revision->get('layout_builder__layout')->getSection(0)->getLayoutId());
  }

  /**
   * Tests that a missing required target field rolls the conversion back.
   */
  public function testMissingRequiredTargetFieldRollsBack(): void {
    $node = Node::create([
      'type' => 'moody_feature_page',
      'title' => 'Unconvertible page',
      'layout_builder__layout' => [new Section('layout_onecol')],
    ]);
    $node->save();
    $revision_id = $node->getRevisionId();

    try {
      $this->container
        ->get('moody_page_convert.converter')
        ->convert($node, 'moody_subsite_page');
      $this->fail('The conversion should reject a missing required field.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertStringContainsString('Moody URL Generator', $exception->getMessage());
    }

    $unchanged = Node::load($node->id());
    $this->assertSame('moody_feature_page', $unchanged->bundle());
    $this->assertSame($revision_id, $unchanged->getRevisionId());
  }

}
