<?php

namespace Drupal\moody_impact_facts\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;

/**
 * Plugin implementation of the 'moody_impact_facts_widget' widget.
 *
 * @FieldWidget(
 *   id = "moody_impact_facts_widget",
 *   module = "moody_impact_facts",
 *   label = @Translation("Moody Impact Facts Widget"),
 *   field_types = {
 *     "moody_impact_facts"
 *   }
 * )
 */
class MoodyImpactFactsWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $element['headline'] = [
      '#title' => $this->t('Headline'),
      '#type' => 'textfield',
      '#default_value' => $items[$delta]->headline ?? NULL,
      '#size' => '60',
      '#placeholder' => '',
      '#maxlength' => 255,
    ];
    $element['style'] = [
      '#title' => $this->t('Style'),
      '#type' => 'radios',
      '#options' => [
        'orange-headline' => 'Orange headlines with gray subheadlines',
        'grey-headline' => 'Gray headlines with orange subheadlines',
      ],
      '#default_value' => $items[$delta]->style ?? 'orange-headline',
    ];
    $element['col_number'] = [
      '#title' => $this->t('Items per row'),
      '#type' => 'radios',
      '#options' => [
        'two-per-row' => 'Two items per row',
        'three-per-row' => 'Three item sper row',
        'four-per-row' => 'Four items per row',
      ],
      '#default_value' => $items[$delta]->col_number ?? 'three-per-row',
    ];

    // Gather the number of items in the Moody Impact Facts.
    $items = !empty($items[$delta]->impact_items) ? unserialize($items[$delta]->impact_items) : [];
    // Ensure item keys are consecutive.
    $items = array_values($items);
    // Retrieve the form element that is using this widget.
    $parents = [$field_name, 'widget'];
    $widget_state = static::getWidgetState($parents, $field_name, $form_state);
    // This value is defined/leveraged by ::utexasAddMoreSubmit().
    $item_count = isset($widget_state[$field_name][$delta]["counter"]) ? $widget_state[$field_name][$delta]["counter"] : NULL;
    // We have to ensure that there is at least one link field.
    if ($item_count === NULL) {
      if (empty($items)) {
        $item_count = 1;
      }
      else {
        $item_count = count($items);
      }
      $widget_state[$field_name][$delta]["counter"] = $item_count;
      static::setWidgetState($parents, $field_name, $form_state, $widget_state);
    }

    $element['impact_items'] = $this->buildDraggableItems($items, $item_count);
    $wrapper_id = Html::getUniqueId('ajax-wrapper');
    $element['impact_items']['#prefix'] = '<div id="' . $wrapper_id . '">';
    $element['impact_items']['#suffix'] = '</div>';
    $element['impact_items']['actions']['add'] = [
      '#type' => 'submit',
      '#name' => $field_name . $delta,
      '#value' => $this->t('Add Impact Fact'),
      '#submit' => [[get_class($this), 'utexasAddMoreSubmit']],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => [get_class($this), 'utexasAddMoreAjax'],
        'wrapper' => $wrapper_id,
      ],
    ];

    return $element;
  }

  /**
   * Create a tabledrag container for all Impact Fact Areas items.
   *
   * @param array $items
   *   Any stored Impact Fact Areas items.
   * @param int $item_count
   *   Items to be populated. Will change on ajax submit for add more.
   *
   * @return array
   *   A render array of a draggable table of items.
   */
  protected function buildDraggableItems(array $items, $item_count) {
    $group_class = 'group-order-weight';
    // Build table.
    $form['items'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Impact Fact items'),
        $this->t('Weight'),
      ],
      '#empty' => $this->t('No items.'),
      '#tableselect' => FALSE,
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => $group_class,
        ],
      ],
    ];
    // Build rows.
    for ($i = 0; $i < $item_count; $i++) {
      $form['items'][$i]['#attributes']['class'][] = 'draggable';
      $form['items'][$i]['#weight'] = $i;
      // Label column.
      $form['items'][$i]['details'] = [
        '#type' => 'details',
        '#title' => $this->t('Impact Fact item %number %headline', [
          '%number' => $i + 1,
          '%headline' => isset($items[$i]['item']['headline']) ? '(' . $items[$i]['item']['headline'] . ')' : '',
        ]),
      ];
      $form['items'][$i]['details']['item'] = [
        '#type' => 'moody_impact_facts',
        '#default_value' => [
          'headline' => $items[$i]['item']['headline'] ?? '',
          'subheadline' => $items[$i]['item']['subheadline'] ?? '',
        ],
      ];
      // Weight column.
      $form['items'][$i]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight for Impact Fact item @key', ['@key' => $i]),
        '#title_display' => 'invisible',
        '#default_value' => $i,
        '#attributes' => ['class' => [$group_class]],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $storage = [];
    // Loop through field deltas.
    foreach ($values as $delta => &$field) {
      if (isset($field['headline'])) {
        // The overall group style.
        $storage[$delta]['headline'] = $field['headline'];
      }
      if (isset($field['style'])) {
        // The items title.
        $storage[$delta]['style'] = $field['style'];
      }
      if (isset($field['col_number'])) {
        // The number of items per row.
        $storage[$delta]['col_number'] = $field['col_number'];
      }
      if (isset($field['impact_items'])) {
        // Re-sort by the order provided by tabledrag.
        usort($field['impact_items']['items'], function ($item1, $item2) {
          return $item1['weight'] <=> $item2['weight'];
        });
        foreach ($field['impact_items']['items'] as $weight => $item) {
          $elements = $item['details']['item'];
          $storage[$delta]['impact_items'][$weight]['item'] = [];
          if (!empty($elements['headline'])) {
            $storage[$delta]['impact_items'][$weight]['item']['headline'] = $elements['headline'];
          }
          if (!empty($elements['subheadline'])) {
            $storage[$delta]['impact_items'][$weight]['item']['subheadline'] = $elements['subheadline'];
          }
          // Remove empty items
          // (i.e., user has manually emptied the field contents).
          if (empty($storage[$delta]['impact_items'][$weight]['item'])) {
            unset($storage[$delta]['impact_items'][$weight]);
          }
        }
      }
      // If no Moody Impact Fact Areas items have been added, remove the empty array.
      if (empty($storage[$delta]['impact_items'])) {
        unset($storage[$delta]['impact_items']);
      }
      else {
        // Moody Impact Fact items are stored in a serialized array,
        // with consecutive keys.
        $storage[$delta]['impact_items'] = serialize(array_values($storage[$delta]['impact_items']));
      }
    }
    return $storage;
  }

  /**
   * Helper function to extract the add more parent element.
   */
  public static function retrieveAddMoreElement($form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $parents = array_slice($triggering_element['#array_parents'], 0, -2);
    return NestedArray::getValue($form, $parents);
  }

  /**
   * Submission handler for the "Add another item" button.
   */
  public static function utexasAddMoreSubmit(array $form, FormStateInterface $form_state) {
    $element = self::retrieveAddMoreElement($form, $form_state);
    array_pop($element['#parents']);
    // The field_delta will be the last (nearest) element in the #parents array.
    $field_delta = array_pop($element['#parents']);
    // The field_name will be the penultimate element in the #parents array.
    $field_name = array_pop($element['#parents']);
    $parents = [$field_name, 'widget'];

    // Increment the items count.
    $widget_state = static::getWidgetState($parents, $field_name, $form_state);
    $widget_state[$field_name][$field_delta]["counter"]++;
    static::setWidgetState($parents, $field_name, $form_state, $widget_state);
    $form_state
      ->setRebuild();
  }

  /**
   * Callback for ajax-enabled buttons.
   *
   * Selects and returns the fieldset with the items in it.
   */
  public static function utexasAddMoreAjax(array &$form, FormStateInterface $form_state) {
    return self::retrieveAddMoreElement($form, $form_state);
  }

}
