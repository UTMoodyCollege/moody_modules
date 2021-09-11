<?php

namespace Drupal\moody_focus_areas\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_focus_areas_widget' widget.
 *
 * @FieldWidget(
 *   id = "moody_focus_areas_widget",
 *   module = "moody_focus_areas",
 *   label = @Translation("Moody Focus Areas Widget"),
 *   field_types = {
 *     "moody_focus_areas"
 *   }
 * )
 */
class MoodyFocusAreasWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();

    $element['items_title'] = [
      '#title' => 'Focus Areas Items Title',
      '#type' => 'textfield',
      '#default_value' => isset($items[$delta]->items_title) ? $items[$delta]->items_title : NULL,
    ];

    $element['items_style'] = [
      '#title' => $this->t('Style Options'),
      '#type' => 'radios',
      '#options' => [
        'two-per-row' => 'Two items per row',
        'three-per-row' => 'Three items per row',
        'four-per-row' => 'Four items per row',
      ],
      '#default_value' => isset($items[$delta]->items_style) ? $items[$delta]->items_style : 'three-per-row',
    ];

    $element['cta'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Focus Areas Call to Action'),
    ];

    $element['cta']['link'] = [
      '#type' => 'utexas_link_options_element',
      '#default_value' => [
        'uri' => $items[$delta]->link_uri ?? '',
        'title' => $items[$delta]->link_title ?? '',
        'options' => isset($items[$delta]->link_options) ? $items[$delta]->link_options : [],
      ],
    ];

    // Gather the number of items in the Moody Focus Areas.
    $items = !empty($items[$delta]->focus_areas_items) ? unserialize($items[$delta]->focus_areas_items) : [];
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

    $element['focus_areas_items'] = $this->buildDraggableItems($items, $item_count);
    $wrapper_id = Html::getUniqueId('ajax-wrapper');
    $element['focus_areas_items']['#prefix'] = '<div id="' . $wrapper_id . '">';
    $element['focus_areas_items']['#suffix'] = '</div>';
    $element['focus_areas_items']['actions']['add'] = [
      '#type' => 'submit',
      '#name' => $field_name . $delta,
      '#value' => $this->t('Add Focus Areas item'),
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
   * Create a tabledrag container for all Focus Areas items.
   *
   * @param array $items
   *   Any stored Focus Areas items.
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
        $this->t('Focus Areas items'),
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
        '#title' => $this->t('Focus Areas item %number %headline', [
          '%number' => $i + 1,
          '%headline' => isset($items[$i]['item']['headline']) ? '(' . $items[$i]['item']['headline'] . ')' : '',
        ]),
      ];
      $form['items'][$i]['details']['item'] = [
        '#type' => 'moody_focus_areas',
        '#default_value' => [
          'headline' => $items[$i]['item']['headline'] ?? '',
          'image' => $items[$i]['item']['image'] ?? '',
          'copy_value' => $items[$i]['item']['copy']['value'] ?? '',
          'copy_format' => $items[$i]['item']['copy']['format'] ?? 'restricted_html',
          'link' => $items[$i]['item']['link'] ?? '',
        ],
      ];
      // Weight column.
      $form['items'][$i]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight for Focus Areas item @key', ['@key' => $i]),
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
      if (isset($field['cta']['link']['uri'])) {
        // The main CTA button.
        $storage[$delta]['link_uri'] = $field['cta']['link']['uri'] ?? '';
        $storage[$delta]['link_title'] = $field['cta']['link']['title'] ?? '';
        $storage[$delta]['link_options'] = $field['cta']['link']['options'] ?? [];
      }
      if (isset($field['items_style'])) {
        // The overall group style.
        $storage[$delta]['items_style'] = $field['items_style'];
      }
      if (isset($field['items_title'])) {
        // The items title.
        $storage[$delta]['items_title'] = $field['items_title'];
      }
      if (isset($field['focus_areas_items'])) {
        // Re-sort by the order provided by tabledrag.
        usort($field['focus_areas_items']['items'], function ($item1, $item2) {
          return $item1['weight'] <=> $item2['weight'];
        });
        foreach ($field['focus_areas_items']['items'] as $weight => $item) {
          $elements = $item['details']['item'];
          $storage[$delta]['focus_areas_items'][$weight]['item'] = [];
          if (!empty($elements['headline'])) {
            $storage[$delta]['focus_areas_items'][$weight]['item']['headline'] = $elements['headline'];
          }
          if (!empty($elements['image'])) {
            $storage[$delta]['focus_areas_items'][$weight]['item']['image'] = $elements['image'];
          }
          if (!empty($elements['copy']['value'])) {
            $storage[$delta]['focus_areas_items'][$weight]['item']['copy'] = $elements['copy'];
          }
          if (!empty($elements['link']['uri'])) {
            $storage[$delta]['focus_areas_items'][$weight]['item']['link']['uri'] = $elements['link']['uri'];
            $storage[$delta]['focus_areas_items'][$weight]['item']['link']['title'] = $elements['link']['title'];
            $storage[$delta]['focus_areas_items'][$weight]['item']['link']['options'] = $elements['link']['options'];
          }
          // Remove empty items
          // (i.e., user has manually emptied the field contents).
          if (empty($storage[$delta]['focus_areas_items'][$weight]['item'])) {
            unset($storage[$delta]['focus_areas_items'][$weight]);
          }
        }
      }
      // If no Moody Focus Areas items have been added, remove the empty array.
      if (empty($storage[$delta]['focus_areas_items'])) {
        unset($storage[$delta]['focus_areas_items']);
      }
      else {
        // Moody Focus Areas items are stored in a serialized array,
        // with consecutive keys.
        $storage[$delta]['focus_areas_items'] = serialize(array_values($storage[$delta]['focus_areas_items']));
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
