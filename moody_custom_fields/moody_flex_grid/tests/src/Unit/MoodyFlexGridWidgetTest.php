<?php

namespace Drupal\Tests\moody_flex_grid\Unit;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormState;
use Drupal\moody_flex_grid\Plugin\Field\FieldWidget\MoodyFlexGridWidget;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the Moody Flex Grid widget.
 *
 * @group moody_flex_grid
 */
class MoodyFlexGridWidgetTest extends UnitTestCase {

  /**
   * Tests bulk removal when the widget is nested in another form.
   */
  public function testRemoveSelectedItemsInNestedForm(): void {
    $element_parents = [
      'settings',
      'block_form',
      'field_block_moody_flex_grid',
      0,
      'flex_grid_items',
    ];
    $value_parents = array_merge($element_parents, ['items']);
    $rows = [
      $this->row('One', 0, FALSE),
      $this->row('Two', 1, TRUE),
      $this->row('Three', 2, FALSE),
    ];

    $form = [];
    NestedArray::setValue($form, $element_parents, [
      '#parents' => $element_parents,
    ]);
    $values = [];
    NestedArray::setValue($values, $value_parents, $rows);

    $form_state = new FormState();
    $form_state->setUserInput($values);
    $form_state->setTriggeringElement([
      '#array_parents' => array_merge($element_parents, [
        'actions',
        'remove_selected',
      ]),
    ]);

    MoodyFlexGridWidget::utexasRemoveSelectedSubmit($form, $form_state);

    $expected = [
      $this->row('One', 0, FALSE),
      $this->row('Three', 1, FALSE),
    ];
    $this->assertSame($expected, $form_state->getValue($value_parents));
    $this->assertSame(
      $expected,
      NestedArray::getValue($form_state->getUserInput(), $value_parents)
    );
    $this->assertTrue($form_state->isRebuilding());
  }

  /**
   * Builds a submitted Flex Grid row.
   */
  private function row(string $headline, int $weight, bool $remove): array {
    return [
      'details' => [
        'item' => [
          'headline' => $headline,
        ],
      ],
      'weight' => $weight,
      'remove' => $remove,
    ];
  }

}
