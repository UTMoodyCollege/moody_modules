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
        'preview_toggle' => [
          '#attributes' => [],
        ],
        'revert' => [],
        'submit' => [],
        'discard_changes' => [],
        'rebuild-layout' => [],
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
    $this->assertSame(21, $form['actions']['submit']['#weight']);
    $this->assertSame(22, $form['actions']['discard_changes']['#weight']);
    $this->assertSame(23, $form['actions']['rebuild-layout']['#weight']);
    $this->assertContains(
      'moody-layout-builder-toolbar',
      $form['actions']['#attributes']['class'],
    );
    $this->assertSame(-40, $form['actions']['mobile_toggle']['#weight']);
    $this->assertSame(
      'button',
      $form['actions']['mobile_toggle']['#attributes']['type'],
    );
    $this->assertTrue($form['actions']['mobile_toggle']['#attributes']['hidden']);
    $this->assertSame(
      'true',
      $form['actions']['mobile_toggle']['#attributes']['aria-expanded'],
    );
    $this->assertTrue($form['actions']['unsaved_changes']['#attributes']['hidden']);
    $this->assertSame(
      'status',
      $form['actions']['unsaved_changes']['#attributes']['role'],
    );
    $this->assertContains(
      'moody-layout-builder-toolbar__button--danger',
      $form['actions']['discard_changes']['#attributes']['class'],
    );
    $this->assertSame(
      [
        'moody_layout_builder_browser/block_type_labels',
        'moody_layout_builder_browser/editor_toolbar',
      ],
      $form['#attached']['library'],
    );
  }

  /**
   * Tests the accessible live preview added to new block forms.
   */
  public function testAddsBlockLivePreview(): void {
    require_once dirname(__DIR__, 3) . '/moody_layout_builder_browser.module';

    $form = [
      'settings' => [
        'admin_label' => [],
        'label' => [],
        'label_display' => [],
      ],
      'layout_builder_style_utexas_items_per_row' => [
        '#type' => 'select',
      ],
      'layout_builder_style_utexas_borders' => [
        '#type' => 'checkboxes',
      ],
    ];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getTriggeringElement')->willReturn(NULL);

    moody_layout_builder_browser_form_alter(
      $form,
      $form_state,
      'layout_builder_add_block',
    );

    $preview = $form['settings']['moody_live_preview'];
    $this->assertSame('moody-block-live-preview', $preview['#attributes']['id']);
    $this->assertContains('is-collapsed', $preview['#attributes']['class']);
    $this->assertSame('waiting', $preview['#attributes']['data-state']);
    $this->assertSame('status', $preview['body']['status']['#attributes']['role']);
    $this->assertSame(
      'moody-block-live-preview-body',
      $preview['body']['#attributes']['id'],
    );
    $this->assertSame(
      'moody-block-live-preview-body',
      $preview['header']['actions']['toggle']['#attributes']['aria-controls'],
    );
    $this->assertSame(
      'false',
      $preview['header']['actions']['toggle']['#attributes']['aria-expanded'],
    );
    $this->assertSame(
      'Expand preview',
      (string) $preview['header']['actions']['toggle']['#attributes']['aria-label'],
    );
    $this->assertSame(
      'moody_layout_builder_browser_live_preview_ajax',
      $preview['header']['actions']['refresh']['#ajax']['callback'],
    );
    $this->assertSame(
      [['settings']],
      $preview['header']['actions']['refresh']['#limit_validation_errors'],
    );
    $this->assertSame(-90, $form['settings']['label']['#weight']);
    $this->assertSame(-80, $form['settings']['label_display']['#weight']);
    $this->assertSame(-70, $preview['#weight']);
    $this->assertArrayHasKey('layout_builder_style_utexas_items_per_row', $form);
    $this->assertArrayHasKey('layout_builder_style_utexas_borders', $form);
    $this->assertSame('select', $form['layout_builder_style_utexas_items_per_row']['#type']);
    $this->assertSame('container', $form['layout_builder_style_utexas_borders']['#type']);
    $this->assertFalse($form['layout_builder_style_utexas_borders']['#access']);
    $this->assertSame(
      ['layout_builder_style_utexas_borders'],
      $form['moody_block_styles']['styles']['layout_builder_style_utexas_borders']['#parents'],
    );
    $this->assertContains(
      'moody_layout_builder_browser/editor_toolbar',
      $form['#attached']['library'],
    );
  }

  /**
   * Tests rendered preview content cannot receive user interaction.
   */
  public function testBuildsProtectedBlockPreview(): void {
    require_once dirname(__DIR__, 3) . '/moody_layout_builder_browser.module';

    $preview = _moody_layout_builder_browser_build_protected_preview([
      '#markup' => '<a href="/leave">Leave</a>',
    ]);

    $this->assertTrue($preview['viewport']['#attributes']['inert']);
    $this->assertSame(
      'true',
      $preview['viewport']['#attributes']['aria-hidden'],
    );
    $this->assertSame(
      'note',
      $preview['notice']['#attributes']['role'],
    );
  }

  /**
   * Tests preview normalization for the composite Media Library form element.
   */
  public function testNormalizesMediaLibraryPreviewValue(): void {
    require_once dirname(__DIR__, 3) . '/moody_layout_builder_browser.module';

    $form_state = new \Drupal\Core\Form\FormState();
    $parents = ['settings', 'block_form', 'image'];
    $form_state->setValue($parents, [
      'media_library_selection' => '42',
      'media_library_open_button' => 'Add media',
    ]);
    $elements = [
      'image' => [
        '#type' => 'media_library',
        '#parents' => $parents,
      ],
    ];

    _moody_layout_builder_browser_normalize_preview_values($elements, $form_state);

    $this->assertSame('42', $form_state->getValue($parents));
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
