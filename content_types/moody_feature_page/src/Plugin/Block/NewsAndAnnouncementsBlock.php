<?php

declare(strict_types=1);

namespace Drupal\moody_feature_page\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a News and Announcements block.
 *
 * @Block(
 *   id = "moody_feature_page_news_and_announcements",
 *   admin_label = @Translation("News and Announcements"),
 *   category = @Translation("Moody"),
 * )
 */
final class NewsAndAnnouncementsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'items' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $items_count = $form_state->get('items_count');
    if ($items_count === NULL) {
      $existing_items = $this->configuration['items'] ?? [];
      $items_count = $existing_items ? count($existing_items) : 1;
      $form_state->set('items_count', $items_count);
    }

    $wrapper_id = Html::getUniqueId('news-and-announcements-items-wrapper');

    $form['items'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('News and Announcements'),
      '#prefix' => '<div id="' . $wrapper_id . '">',
      '#suffix' => '</div>',
    ];

    for ($i = 0; $i < $items_count; $i++) {
      $form['items'][$i] = [
        '#type' => 'details',
        '#title' => $this->t('News or announcement @i', ['@i' => $i + 1]),
        '#open' => TRUE,
      ];

      if ($items_count > 1) {
        $form['items'][$i]['remove_item'] = [
          '#type' => 'submit',
          '#value' => $this->t('Remove this item'),
          '#name' => 'remove_item_' . $i,
          '#submit' => [[$this, 'removeItemSubmit']],
          '#ajax' => [
            'callback' => [$this, 'itemsAjaxCallback'],
            'wrapper' => $wrapper_id,
          ],
          '#limit_validation_errors' => [],
          '#attributes' => [
            'class' => ['button--danger'],
          ],
          '#item_index' => $i,
        ];
      }

      $form['items'][$i]['category'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Category'),
        '#default_value' => $this->configuration['items'][$i]['category'] ?? '',
      ];

      $form['items'][$i]['date'] = [
        '#type' => 'date',
        '#title' => $this->t('Date'),
        '#description' => $this->t('Optional. Choose how the date is displayed below.'),
        '#default_value' => $this->configuration['items'][$i]['date'] ?? '',
      ];

      $form['items'][$i]['date_format'] = [
        '#type' => 'select',
        '#title' => $this->t('Date display'),
        '#options' => [
          'month_year' => $this->t('Month and year'),
          'full_date' => $this->t('Month, day, and year'),
        ],
        '#default_value' => $this->configuration['items'][$i]['date_format'] ?? 'full_date',
        '#states' => [
          'visible' => [
            ':input[name="settings[items][' . $i . '][date]"]' => ['filled' => TRUE],
          ],
        ],
      ];

      $body = $this->configuration['items'][$i]['body'] ?? '';
      $form['items'][$i]['body'] = [
        '#type' => 'text_format',
        '#title' => $this->t('Body'),
        '#rows' => 4,
        '#default_value' => is_array($body) ? ($body['value'] ?? '') : $body,
        '#format' => is_array($body) ? ($body['format'] ?? 'flex_html') : 'flex_html',
      ];

      $form['items'][$i]['link'] = [
        '#type' => 'url',
        '#title' => $this->t('Link'),
        '#default_value' => $this->configuration['items'][$i]['link'] ?? '',
      ];

      $form['items'][$i]['link_text'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Link text'),
        '#default_value' => $this->configuration['items'][$i]['link_text'] ?? $this->t('Read More'),
        '#states' => [
          'visible' => [
            ':input[name="settings[items][' . $i . '][link]"]' => ['filled' => TRUE],
          ],
        ],
      ];
    }

    $form['items']['actions'] = [
      '#type' => 'actions',
    ];
    $form['items']['actions']['add_item'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add another item'),
      '#submit' => [[$this, 'addItemSubmit']],
      '#ajax' => [
        'callback' => [$this, 'itemsAjaxCallback'],
        'wrapper' => $wrapper_id,
      ],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $items = $form_state->getValue('items');
    unset($items['actions']);

    foreach ($items as $key => $item) {
      unset($items[$key]['remove_item']);
    }

    $this->configuration['items'] = array_values($items);
  }

  /**
   * Submit handler for the add-item button.
   */
  public function addItemSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->set('items_count', $form_state->get('items_count') + 1);
    $form_state->setRebuild();
  }

  /**
   * Submit handler for the remove-item buttons.
   */
  public function removeItemSubmit(array &$form, FormStateInterface $form_state): void {
    $items_count = $form_state->get('items_count');
    if ($items_count > 1) {
      $triggering_element = $form_state->getTriggeringElement();
      $item_index = $triggering_element['#item_index'];
      $items = $this->configuration['items'] ?? [];
      unset($items[$item_index]);

      $this->configuration['items'] = array_values($items);
      $form_state->set('items_count', $items_count - 1);
    }

    $form_state->setRebuild();
  }

  /**
   * AJAX callback for add/remove item operations.
   */
  public function itemsAjaxCallback(array &$form, FormStateInterface $form_state): array {
    return $form['settings']['items'];
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $items = $this->configuration['items'] ?? [];
    foreach ($items as $key => $item) {
      $body = $item['body'] ?? '';
      $body_value = is_array($body) ? (string) ($body['value'] ?? '') : (string) $body;

      if (empty($item['category']) && trim($body_value) === '' && empty($item['link']) && empty($item['date'])) {
        unset($items[$key]);
        continue;
      }

      $items[$key]['body_rendered'] = $body_value === '' ? [] : [
        '#type' => 'processed_text',
        '#text' => $body_value,
        '#format' => is_array($body) ? ($body['format'] ?? 'flex_html') : 'flex_html',
      ];
    }

    if (!$items) {
      return [];
    }

    return [
      '#theme' => 'moody_news_and_announcements',
      '#items' => array_values($items),
      '#attached' => [
        'library' => ['moody_feature_page/moody_media_mentions'],
      ],
    ];
  }

}
