<?php

namespace Drupal\moody_flex_grid\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;

/**
 * Plugin implementation of the 'moody_flex_grid_widget' widget.
 *
 * @FieldWidget(
 *   id = "moody_flex_grid_widget",
 *   module = "moody_flex_grid",
 *   label = @Translation("Moody Flex Grid Widget"),
 *   field_types = {
 *     "moody_flex_grid"
 *   }
 * )
 */
class MoodyFlexGridWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $element['headline'] = [
      '#title' => $this->t('Headline'),
      '#type' => 'textfield',
      '#default_value' => isset($items[$delta]->headline) ? $items[$delta]->headline : NULL,
    ];
    $element['style'] = [
      '#title' => $this->t('Style Options'),
      '#type' => 'radios',
      '#options' => [
        'one' => 'One item per row',
        'two' => 'Two items per row',
        'three' => 'Three items per row',
        'four' => 'Four items per row',
        'five' => 'Five items per row',
        'six' => 'Six items per row',
      ],
      '#default_value' => isset($items[$delta]->style) ? $items[$delta]->style : 'three',
    ];
    // Gather the number of items in the Moody Flex Grid.
    $items = !empty($items[$delta]->flex_grid_items) ? unserialize($items[$delta]->flex_grid_items) : [];
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

    $element['flex_grid_items'] = $this->buildDraggableItems($items, $item_count);
    $wrapper_id = Html::getUniqueId('ajax-wrapper');
    $element['flex_grid_items']['#prefix'] = '<div id="' . $wrapper_id . '">';
    $element['flex_grid_items']['#suffix'] = '</div>';
    $element['flex_grid_items']['actions']['add'] = [
      '#type' => 'submit',
      '#name' => $field_name . $delta,
      '#value' => $this->t('Add Flex Grid item'),
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
   * Create a tabledrag container for all Flex Grid items.
   *
   * @param array $items
   *   Any stored Flex Grid items.
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
        $this->t('Flex Grid items'),
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
        '#title' => $this->t('Flex Grid item %number %headline', [
          '%number' => $i + 1,
          '%headline' => isset($items[$i]['item']['headline']) ? '(' . $items[$i]['item']['headline'] . ')' : '',
        ]),
      ];
      $form['items'][$i]['details']['item'] = [
        '#type' => 'moody_flex_grid',
        '#default_value' => [
          'image' => $items[$i]['item']['image'] ?? '',
          'headline' => $items[$i]['item']['headline'] ?? '',
          'headline_alignment' => $items[$i]['item']['headline_alignment'] ?? '',
          'copy' => $items[$i]['item']['copy'] ?? '',
          'link' => $items[$i]['item']['link'] ?? '',
        ],
      ];
      // Weight column.
      $form['items'][$i]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight for Flex Grid item @key', ['@key' => $i]),
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
        // The overall group headline.
        $storage[$delta]['headline'] = $field['headline'];
      }
      if (isset($field['style'])) {
        // The overall group style.
        $storage[$delta]['style'] = $field['style'];
      }
      if (isset($field['flex_grid_items'])) {
        // Re-sort by the order provided by tabledrag.
        usort($field['flex_grid_items']['items'], function ($item1, $item2) {
          return $item1['weight'] <=> $item2['weight'];
        });
        foreach ($field['flex_grid_items']['items'] as $weight => $item) {
          $elements = $item['details']['item'];
          $storage[$delta]['flex_grid_items'][$weight]['item'] = [];
          if (!empty($elements['image'])) {
            $storage[$delta]['flex_grid_items'][$weight]['item']['image'] = $elements['image'];
          }
          if (!empty($elements['headline'])) {
            $storage[$delta]['flex_grid_items'][$weight]['item']['headline'] = $elements['headline'];
          }
          if (!empty($elements['copy'])) {
            $storage[$delta]['flex_grid_items'][$weight]['item']['copy'] = $elements['copy'];
          }
          if (!empty($elements['headline_alignment'])) {
            $storage[$delta]['flex_grid_items'][$weight]['item']['headline_alignment'] = $elements['headline_alignment'];
          }
          if (!empty($elements['link']['uri'])) {
            $storage[$delta]['flex_grid_items'][$weight]['item']['link']['uri'] = $elements['link']['uri'];
            $storage[$delta]['flex_grid_items'][$weight]['item']['link']['title'] = $elements['link']['title'];
            $storage[$delta]['flex_grid_items'][$weight]['item']['link']['options'] = $elements['link']['options'];
          }
          // Remove empty items
          // (i.e., user has manually emptied the field contents).
          if (empty($storage[$delta]['flex_grid_items'][$weight]['item'])) {
            unset($storage[$delta]['flex_grid_items'][$weight]);
          }
          // If we *only* have the "headline_alignment" and EVERYTHING ELSE is empty, its a removal... so in othe words, if at this point we have an empty image, headline, copy, and link[uri], we should remove the item.
          if (empty($elements['image']) && empty($elements['headline']) && empty($elements['copy']) && empty($elements['link']['uri'])) {
            unset($storage[$delta]['flex_grid_items'][$weight]);
          }
        }
      }
      // If no Moody Flex Grid items have been added, remove the empty array.
      if (empty($storage[$delta]['flex_grid_items'])) {
        unset($storage[$delta]['flex_grid_items']);
      }
      else {
        // Moody Flex Grid items are stored in a serialized array,
        // with consecutive keys.
        $storage[$delta]['flex_grid_items'] = serialize(array_values($storage[$delta]['flex_grid_items']));
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
