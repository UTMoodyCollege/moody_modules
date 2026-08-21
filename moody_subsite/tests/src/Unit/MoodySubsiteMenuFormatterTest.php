<?php

namespace Drupal\Tests\moody_subsite\Unit;

use Drupal\moody_subsite\Plugin\Field\FieldFormatter\MoodySubsiteMenuFormatter;
use Drupal\Tests\UnitTestCase;

/**
 * Tests grouping flat subsite navigation items into one submenu level.
 *
 * @group moody_subsite
 */
class MoodySubsiteMenuFormatterTest extends UnitTestCase {

  /**
   * Tests flat-menu compatibility and child grouping.
   */
  public function testBuildMenuTree() {
    $items = [
      ['title' => 'Home', 'link' => '/home', 'is_child' => FALSE],
      ['title' => 'About', 'link' => '/about', 'is_child' => FALSE],
      ['title' => 'People', 'link' => '/about/people', 'is_child' => TRUE],
      ['title' => 'Contact', 'link' => '/about/contact', 'is_child' => TRUE],
    ];

    $tree = TestableMoodySubsiteMenuFormatter::buildTree($items);

    $this->assertSame([], $tree[0]['children']);
    $this->assertSame([$items[2], $items[3]], $tree[1]['children']);
  }

}

/**
 * Exposes the pure tree builder for testing.
 */
class TestableMoodySubsiteMenuFormatter extends MoodySubsiteMenuFormatter {

  /**
   * Builds a menu tree for the test.
   */
  public static function buildTree(array $items) {
    return parent::buildMenuTree($items);
  }

}
