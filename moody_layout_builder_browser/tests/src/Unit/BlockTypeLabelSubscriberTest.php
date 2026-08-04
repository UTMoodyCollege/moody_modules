<?php

namespace Drupal\Tests\moody_layout_builder_browser\Unit;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\layout_builder\Event\SectionComponentBuildRenderArrayEvent;
use Drupal\moody_layout_builder_browser\EventSubscriber\BlockTypeLabelSubscriber;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Layout Builder block type labels.
 */
#[Group('moody_layout_builder_browser')]
class BlockTypeLabelSubscriberTest extends UnitTestCase {

  /**
   * Tests the checked-by-default Layout Builder form toggle.
   */
  public function testAddsBlockTypeLabelToggle(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(42);
    $container = new ContainerBuilder();
    $container->set('current_user', $account);
    \Drupal::setContainer($container);
    require_once dirname(__DIR__, 3) . '/moody_layout_builder_browser.module';

    $form = [
      'actions' => [
        'preview_toggle' => [],
        'revert' => [],
      ],
    ];
    moody_layout_builder_browser_form_alter(
      $form,
      $this->createMock(FormStateInterface::class),
      'layout_builder_form',
    );

    $toggle = $form['actions']['block_type_labels_toggle'];
    $this->assertTrue($toggle['toggle_block_type_labels']['#value']);
    $this->assertSame(
      'Drupal.layout_builder.block_type_labels.42',
      $toggle['toggle_block_type_labels']['#attributes']['data-block-type-labels-id'],
    );
    $this->assertSame(10, $form['actions']['preview_toggle']['#weight']);
    $this->assertSame(11, $toggle['#weight']);
    $this->assertSame(20, $form['actions']['revert']['#weight']);
    $this->assertSame(
      ['moody_layout_builder_browser/block_type_labels'],
      $form['#attached']['library'],
    );
  }

  /**
   * Tests that a block type label preserves preview content metadata.
   */
  public function testAddsLabelToPreviewOnly(): void {
    $plugin = $this->createMock(BlockPluginInterface::class);
    $plugin->method('getPluginDefinition')->willReturn([
      'admin_label' => 'Basic block',
    ]);

    $event = $this->createMock(SectionComponentBuildRenderArrayEvent::class);
    $event->method('inPreview')->willReturn(TRUE);
    $event->method('getPlugin')->willReturn($plugin);
    $event->method('getBuild')->willReturn([
      'content' => [
        '#block_content' => 'Block metadata',
        'field' => ['#markup' => 'Block content'],
      ],
    ]);
    $event->expects($this->once())
      ->method('setBuild')
      ->with($this->callback(function (array $build): bool {
        return $build['content']['moody_block_type_label']['label']['#value'] === 'Basic block'
          && $build['content']['#block_content'] === 'Block metadata'
          && $build['content']['field']['#markup'] === 'Block content'
          && $build['#attached']['library'] === [
            'moody_layout_builder_browser/block_type_labels',
          ];
      }));

    (new BlockTypeLabelSubscriber())->addBlockTypeLabel($event);

    $non_preview = $this->createMock(SectionComponentBuildRenderArrayEvent::class);
    $non_preview->method('inPreview')->willReturn(FALSE);
    $non_preview->method('getPlugin')->willReturn($plugin);
    $non_preview->expects($this->never())->method('setBuild');

    (new BlockTypeLabelSubscriber())->addBlockTypeLabel($non_preview);
  }

}
