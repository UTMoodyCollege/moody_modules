<?php

declare(strict_types=1);

namespace Drupal\moody_feature_page\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a moody media mentions block.
 *
 * @Block(
 *   id = "moody_feature_page_media_mentions",
 *   admin_label = @Translation("Moody Media Mentions"),
 *   category = @Translation("Moody"),
 * )
 */
final class MoodyMediaMentionsBlock extends BlockBase implements ContainerFactoryPluginInterface
{

  /**
   * Constructs the plugin instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self
  {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array
  {
    return [
      'items' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array
  {
    // Get the number of items from form state or default to existing items
    $items_count = $form_state->get('items_count');
    if ($items_count === NULL) {
      $existing_items = $this->configuration['items'] ?? [];
      $items_count = !empty($existing_items) ? count($existing_items) : 1;
      $form_state->set('items_count', $items_count);
    }

    // Create a wrapper for AJAX updates
    $wrapper_id = Html::getUniqueId('media-mentions-items-wrapper');
    
    $form['items'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Media Mentions'),
      '#prefix' => '<div id="' . $wrapper_id . '">',
      '#suffix' => '</div>',
    ];

    for ($i = 0; $i < $items_count; $i++) {
      $form['items'][$i] = [
        '#type' => 'details',
        '#title' => $this->t('Media Mention @i', ['@i' => $i + 1]),
        '#open' => TRUE,
      ];

      // Add a remove button for each item (except if there's only one item)
      if ($items_count > 1) {
        $form['items'][$i]['remove_item'] = [
          '#type' => 'submit',
          '#value' => $this->t('Remove this mention'),
          '#name' => 'remove_item_' . $i,
          '#submit' => [[ $this, 'removeItemSubmit' ]],
          '#ajax' => [
            'callback' => [ $this, 'itemsAjaxCallback' ],
            'wrapper' => $wrapper_id,
          ],
          '#limit_validation_errors' => [],
          '#attributes' => [
            'class' => ['button--danger'],
          ],
          '#item_index' => $i,
        ];
      }

      // Media Source Name
      $form['items'][$i]['media_source'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Media Source Name'),
        '#default_value' => $this->configuration['items'][$i]['media_source'] ?? '',
        '#required' => FALSE,
      ];

      // Headline
      $form['items'][$i]['headline'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Headline'),
        '#default_value' => $this->configuration['items'][$i]['headline'] ?? '',
        '#required' => FALSE,
      ];

      // Link
      $form['items'][$i]['link'] = [
        '#type' => 'url',
        '#title' => $this->t('Link'),
        '#default_value' => $this->configuration['items'][$i]['link'] ?? '',
        '#required' => FALSE,
      ];

      // Date
      $form['items'][$i]['date'] = [
        '#type' => 'date',
        '#title' => $this->t('Date'),
        '#default_value' => $this->configuration['items'][$i]['date'] ?? '',
        '#required' => FALSE,
      ];
    }

    // Add another item button
    $form['items']['actions'] = [
      '#type' => 'actions',
    ];
    $form['items']['actions']['add_item'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add another mention'),
      '#submit' => [[ $this, 'addItemSubmit' ]],
      '#ajax' => [
        'callback' => [ $this, 'itemsAjaxCallback' ],
        'wrapper' => $wrapper_id,
      ],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void
  {
    $items = $form_state->getValue('items');
    
    // Remove the actions element from items
    unset($items['actions']);
    
    // Clean up any items that might have remove buttons
    foreach ($items as $key => $item) {
      if (isset($item['remove_item'])) {
        unset($items[$key]['remove_item']);
      }
    }
    
    $this->configuration['items'] = $items;
  }

  /**
   * Submit handler for the "Add another item" button.
   */
  public function addItemSubmit(array &$form, FormStateInterface $form_state): void
  {
    $items_count = $form_state->get('items_count');
    $items_count++;
    $form_state->set('items_count', $items_count);
    $form_state->setRebuild();
  }

  /**
   * Submit handler for the "Remove item" buttons.
   */
  public function removeItemSubmit(array &$form, FormStateInterface $form_state): void
  {
    $triggering_element = $form_state->getTriggeringElement();
    $item_index = $triggering_element['#item_index'];
    
    $items_count = $form_state->get('items_count');
    
    // Don't allow removing the last item
    if ($items_count > 1) {
      $items_count--;
      $form_state->set('items_count', $items_count);
      
      // Remove the item from configuration
      $items = $this->configuration['items'] ?? [];
      unset($items[$item_index]);
      
      // Re-index the array to avoid gaps
      $items = array_values($items);
      $this->configuration['items'] = $items;
    }
    
    $form_state->setRebuild();
  }

  /**
   * AJAX callback for add/remove item operations.
   */
  public function itemsAjaxCallback(array &$form, FormStateInterface $form_state): array
  {
    return $form['settings']['items'];
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array
  {
    $items = $this->configuration['items'] ?? [];
    
    // Filter out empty items
    $items = array_filter($items, function($item) {
      return !empty($item['media_source']) || !empty($item['headline']) || !empty($item['link']) || !empty($item['date']);
    });

    if (empty($items)) {
      return [];
    }

    $build = [
      '#theme' => 'moody_media_mentions',
      '#items' => $items,
    ];

    // Attach the library
    $build['#attached']['library'][] = 'moody_feature_page/moody_media_mentions';

    return $build;
  }
}