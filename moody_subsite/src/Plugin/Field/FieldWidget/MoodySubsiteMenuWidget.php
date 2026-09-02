<?php

namespace Drupal\moody_subsite\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_subsite_menu_widget' widget.
 *
 * @FieldWidget(
 *   id = "moody_subsite_menu_widget",
 *   module = "moody_subsite",
 *   label = @Translation("Moody subsite menu widget"),
 *   field_types = {
 *     "moody_subsite_menu"
 *   }
 * )
 */
class MoodySubsiteMenuWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {

    $parent_id = '';
    for ($previous = $delta - 1; $previous >= 0; $previous--) {
      if (empty($items[$previous]->is_child)) {
        $parent_id = 'subsite-menu-item-' . $previous;
        break;
      }
    }

    $element['#type'] = 'container';
    $element['#attributes']['class'][] = 'moody-subsite-menu-fields';
    $element['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => isset($items[$delta]->title) ? $items[$delta]->title : NULL,
    ];
    $element['link'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL'),
      '#description' => $this->t('Use /path for this site or a complete https:// URL for another site.'),
      '#default_value' => isset($items[$delta]->link) ? $items[$delta]->link : NULL,
      '#maxlength' => 256,
    ];
    $element['is_child'] = [
      '#type' => 'select',
      '#title' => $this->t('Level'),
      '#options' => [
        0 => $this->t('Top level'),
        1 => $this->t('Submenu'),
      ],
      '#default_value' => empty($items[$delta]->is_child) ? 0 : 1,
      '#attributes' => ['class' => ['subsite-menu-depth']],
    ];
    $element['_menu_id'] = [
      '#type' => 'hidden',
      '#default_value' => 'subsite-menu-item-' . $delta,
      '#attributes' => ['class' => ['subsite-menu-id']],
    ];
    $element['_menu_parent'] = [
      '#type' => 'hidden',
      '#default_value' => empty($items[$delta]->is_child) ? '' : $parent_id,
      '#attributes' => ['class' => ['subsite-menu-parent']],
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      $value['is_child'] = empty($value['is_child']) ? 0 : 1;
      unset($value['_menu_id'], $value['_menu_parent']);
    }
    unset($value);
    return $values;
  }

}
