<?php

declare(strict_types=1);

namespace Drupal\moody_feature_page\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\node\NodeInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a feature pages - editors picks block.
 *
 * @Block(
 *   id = "moody_feature_page_feature_pages_editors_picks",
 *   admin_label = @Translation("Feature Pages - Editors Picks"),
 *   category = @Translation("Custom"),
 * )
 */
final class FeaturePagesEditorsPicksBlock extends BlockBase implements ContainerFactoryPluginInterface
{

  /**
   * Constructs the plugin instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
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
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array
  {
    return [
      'selected_nodes' => [],
      'items' => [],
      'image_style' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array
  {
    $items = $form_state->get('editors_picks_items');
    if (!is_array($items)) {
      $items = $this->configuration['items'] ?? [];
      if (!$items) {
        foreach ($this->configuration['selected_nodes'] ?? [] as $nid) {
          $items[] = ['type' => 'node', 'node' => (string) $nid];
        }
      }
    }

    $wrapper_id = Html::getUniqueId('editors-picks-items-wrapper');
    $group_class = 'editors-picks-order-weight';
    $form['items'] = [
      '#type' => 'fieldset',
      '#title' => $this->t("Editor's Picks items"),
      '#description' => $this->t(
        'Drag items into the desired display order. Existing Feature Pages and custom cards can be mixed freely.'
      ),
      '#prefix' => '<div id="' . $wrapper_id . '">',
      '#suffix' => '</div>',
      '#tree' => TRUE,
    ];
    $form['items']['rows'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Item'),
        $this->t('Weight'),
        $this->t('Operations'),
      ],
      '#empty' => $this->t('No items yet. Add a Feature Page or custom item below.'),
      '#tabledrag' => [[
        'action' => 'order',
        'relationship' => 'sibling',
        'group' => $group_class,
      ]],
    ];

    $node_storage = $this->entityTypeManager->getStorage('node');
    foreach (array_values($items) as $delta => $item) {
      $type = ($item['type'] ?? 'node') === 'custom' ? 'custom' : 'node';
      $nid = (string) ($item['node'] ?? '');
      $node = ctype_digit($nid) ? $node_storage->load($nid) : NULL;
      $label = $type === 'custom'
        ? (string) ($item['title'] ?? $this->t('Custom item'))
        : ($node?->label() ?? (string) $this->t('Feature Page'));
      $type_selector = ':input[name="settings[items][rows][' . $delta . '][details][type]"]';

      $form['items']['rows'][$delta]['#attributes']['class'][] = 'draggable';
      $form['items']['rows'][$delta]['details'] = [
        '#type' => 'details',
        '#title' => $this->t('Item @number: @label', [
          '@number' => $delta + 1,
          '@label' => $label,
        ]),
        '#open' => TRUE,
      ];
      $form['items']['rows'][$delta]['details']['type'] = [
        '#type' => 'select',
        '#title' => $this->t('Item type'),
        '#options' => [
          'node' => $this->t('Existing Feature Page'),
          'custom' => $this->t('Custom item'),
        ],
        '#default_value' => $type,
      ];
      $form['items']['rows'][$delta]['details']['node'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('Feature Page'),
        '#target_type' => 'node',
        '#selection_settings' => [
          'target_bundles' => ['moody_feature_page'],
        ],
        '#default_value' => $node,
        '#states' => [
          'visible' => [$type_selector => ['value' => 'node']],
        ],
      ];
      $form['items']['rows'][$delta]['details']['custom'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Custom card fields'),
        '#states' => [
          'visible' => [$type_selector => ['value' => 'custom']],
        ],
      ];
      $form['items']['rows'][$delta]['details']['custom']['image'] = [
        '#type' => 'media_library',
        '#allowed_bundles' => ['utexas_image'],
        '#title' => $this->t('Image'),
        '#default_value' => $item['image'] ?? '',
        '#cardinality' => 1,
      ];
      $form['items']['rows'][$delta]['details']['custom']['subhead_1'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subhead 1'),
        '#default_value' => $item['subhead_1'] ?? '',
        '#description' => $this->t('Displayed in the orange category style.'),
      ];
      $form['items']['rows'][$delta]['details']['custom']['subhead_2'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subhead 2'),
        '#default_value' => $item['subhead_2'] ?? '',
        '#description' => $this->t('Use for a date, byline, or other secondary line.'),
      ];
      $form['items']['rows'][$delta]['details']['custom']['title'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Title'),
        '#default_value' => $item['title'] ?? '',
      ];
      $form['items']['rows'][$delta]['details']['custom']['body'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Body'),
        '#default_value' => $item['body'] ?? '',
        '#rows' => 3,
      ];
      $form['items']['rows'][$delta]['details']['custom']['url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Link URL'),
        '#default_value' => $item['url'] ?? '',
        '#description' => $this->t(
          'Enter a full external URL or a site-relative path beginning with /. The image and text will use this link.'
        ),
      ];
      $form['items']['rows'][$delta]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight for item @number', ['@number' => $delta + 1]),
        '#title_display' => 'invisible',
        '#default_value' => $delta,
        '#attributes' => ['class' => [$group_class]],
      ];
      $form['items']['rows'][$delta]['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#name' => 'editors_picks_remove_' . $delta,
        '#submit' => [[$this, 'removeItemSubmit']],
        '#ajax' => [
          'callback' => [$this, 'itemsAjaxCallback'],
          'wrapper' => $wrapper_id,
        ],
        '#limit_validation_errors' => [],
        '#item_index' => $delta,
        '#attributes' => ['class' => ['button--danger']],
      ];
    }

    $form['items']['actions'] = ['#type' => 'actions'];
    foreach ([
      'node' => $this->t('Add Feature Page'),
      'custom' => $this->t('Add Custom Item'),
    ] as $type => $label) {
      $form['items']['actions']['add_' . $type] = [
        '#type' => 'submit',
        '#value' => $label,
        '#name' => 'editors_picks_add_' . $type,
        '#submit' => [[$this, 'addItemSubmit']],
        '#ajax' => [
          'callback' => [$this, 'itemsAjaxCallback'],
          'wrapper' => $wrapper_id,
        ],
        '#limit_validation_errors' => [],
        '#item_type' => $type,
      ];
    }

    $image_style_options = [];
    $image_styles = $this->entityTypeManager->getStorage('image_style')->loadMultiple();
    foreach ($image_styles as $image_style) {
      $image_style_options[$image_style->id()] = $image_style->label();
    }
    asort($image_style_options, SORT_NATURAL | SORT_FLAG_CASE);

    $form['image_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Image style override'),
      '#options' => ['' => $this->t('- Use view default -')] + $image_style_options,
      '#default_value' => $this->configuration['image_style'] ?? '',
      '#description' => $this->t('Optionally override the image style used to render article images in this block.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockValidate($form, FormStateInterface $form_state): void
  {
    foreach ($this->submittedRows($form_state) as $delta => $row) {
      $item = _moody_feature_page_normalize_editors_picks_items([$row], TRUE)[0];
      if ($item['type'] === 'node') {
        if ($item['node'] === '') {
          $form_state->setError($form['items']['rows'][$delta]['details']['node'], $this->t('Select a Feature Page.'));
        }
        continue;
      }

      if ($item['title'] === '') {
        $form_state->setError(
          $form['items']['rows'][$delta]['details']['custom']['title'],
          $this->t('Enter a title for the custom item.')
        );
      }
      $url = $item['url'];
      if ($url === '') {
        $form_state->setError(
          $form['items']['rows'][$delta]['details']['custom']['url'],
          $this->t('Enter a link URL for the custom item.')
        );
      }
      elseif (!UrlHelper::isValid($url, TRUE) && (!str_starts_with($url, '/') || str_starts_with($url, '//'))) {
        $form_state->setError(
          $form['items']['rows'][$delta]['details']['custom']['url'],
          $this->t('Enter a valid full URL or a site-relative path beginning with /.')
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void
  {
    $items = _moody_feature_page_normalize_editors_picks_items(
      $this->submittedRows($form_state)
    );
    $this->configuration['items'] = $items;
    $this->configuration['selected_nodes'] = array_values(array_map(
      static fn(array $item): string => $item['node'],
      array_filter($items, static fn(array $item): bool => $item['type'] === 'node')
    ));
    $this->configuration['image_style'] = (string) $form_state->getValue('image_style');
  }

  /**
   * Adds a new Feature Page or custom row.
   */
  public function addItemSubmit(array &$form, FormStateInterface $form_state): void
  {
    $items = _moody_feature_page_normalize_editors_picks_items($this->submittedRows($form_state), TRUE);
    $trigger = $form_state->getTriggeringElement();
    $items[] = ['type' => ($trigger['#item_type'] ?? 'node') === 'custom' ? 'custom' : 'node'];
    $form_state->set('editors_picks_items', $items);
    $form_state->setRebuild();
  }

  /**
   * Removes one item row.
   */
  public function removeItemSubmit(array &$form, FormStateInterface $form_state): void
  {
    $rows = $this->submittedRows($form_state);
    $trigger = $form_state->getTriggeringElement();
    unset($rows[(int) ($trigger['#item_index'] ?? -1)]);
    $form_state->set(
      'editors_picks_items',
      _moody_feature_page_normalize_editors_picks_items($rows, TRUE)
    );
    $form_state->setRebuild();
  }

  /**
   * Returns the rebuilt item fieldset.
   */
  public function itemsAjaxCallback(array &$form, FormStateInterface $form_state): array
  {
    return $form['settings']['items'];
  }

  /**
   * Gets item rows from either a root form callback or scoped plugin form.
   */
  private function submittedRows(FormStateInterface $form_state): array
  {
    $input = $form_state->getUserInput();
    $rows = $input['settings']['items']['rows'] ?? $input['items']['rows'] ?? NULL;
    if (is_array($rows)) {
      return $rows;
    }

    $rows = $form_state->getValue(['settings', 'items', 'rows']);
    if (!is_array($rows)) {
      $rows = $form_state->getValue(['items', 'rows']);
    }
    return is_array($rows) ? $rows : [];
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array
  {
    $items = _moody_feature_page_normalize_editors_picks_items($this->configuration['items'] ?? []);
    if (!$items) {
      return $this->buildLegacyView();
    }

    return $this->buildMixedItems($items);
  }

  /**
   * Preserves the exact legacy selected_nodes rendering path.
   */
  private function buildLegacyView(): array
  {
    $selected_nodes = array_values(array_filter(
      $this->configuration['selected_nodes'] ?? [],
      static function ($nid): bool {
        $nid_string = (string) $nid;
        return $nid_string !== '' && ctype_digit($nid_string) && (int) $nid_string > 0;
      }
    ));
    $view = $this->prepareView($selected_nodes);
    if (!$view) {
      return ['#markup' => $this->t('The view @view was not found.', ['@view' => 'news_filtered'])];
    }

    $build = ['content' => $view->render()];
    $this->attachBlockAssets($build);
    return $build;
  }

  /**
   * Builds an ordered grid from View rows and custom cards.
   */
  private function buildMixedItems(array $items): array
  {
    $node_ids = array_values(array_map(
      static fn(array $item): string => $item['node'],
      array_filter($items, static fn(array $item): bool => $item['type'] === 'node')
    ));
    $node_rows = [];
    $view_build = [];

    if ($node_ids) {
      $view = $this->prepareView($node_ids);
      if (!$view) {
        return ['#markup' => $this->t('The view @view was not found.', ['@view' => 'news_filtered'])];
      }
      $view->execute();
      $positions = array_flip($node_ids);
      $result_node_id = static function ($result): string {
        $entity = $result->_entity ?? NULL;
        return $entity instanceof NodeInterface ? (string) $entity->id() : (string) ($result->nid ?? '');
      };
      usort($view->result, static function ($left, $right) use ($positions, $result_node_id): int {
        $left_id = $result_node_id($left);
        $right_id = $result_node_id($right);
        return ($positions[$left_id] ?? PHP_INT_MAX) <=> ($positions[$right_id] ?? PHP_INT_MAX);
      });
      $view_build = $view->render() ?? [];
      $rendered_rows = $view_build['#rows'][0]['#rows'] ?? [];
      foreach ($view->result as $index => $result) {
        $nid = $result_node_id($result);
        if ($nid !== '' && isset($rendered_rows[$index])) {
          $node_rows[$nid][] = $rendered_rows[$index];
        }
      }
    }

    $rows = [];
    foreach ($items as $item) {
      if ($item['type'] === 'node') {
        if (!empty($node_rows[$item['node']])) {
          $rows[] = array_shift($node_rows[$item['node']]);
        }
        continue;
      }
      $rows[] = $this->buildCustomItem($item);
    }

    if (!$rows) {
      return [];
    }

    $build = [
      'content' => [
        '#theme' => 'moody_feature_page_editors_picks_mixed',
        '#items' => $rows,
        '#attached' => $view_build['#attached'] ?? [],
        '#cache' => $view_build['#cache'] ?? [],
      ],
    ];
    $this->attachBlockAssets($build);
    return $build;
  }

  /**
   * Builds one custom card using the existing View card classes.
   */
  private function buildCustomItem(array $item): array
  {
    $image = [];
    if ($item['image'] !== '') {
      $media = $this->entityTypeManager->getStorage('media')->load($item['image']);
      if (
        $media
        && $media->hasField('field_utexas_media_image')
        && !$media->get('field_utexas_media_image')->isEmpty()
      ) {
        $image = $media->get('field_utexas_media_image')->view([
          'label' => 'hidden',
          'type' => 'image',
          'settings' => [
            'image_style' => (string) (($this->configuration['image_style'] ?? '') ?: 'wide'),
            'image_link' => '',
            'image_loading' => ['attribute' => 'lazy'],
          ],
        ]);
      }
    }

    return [
      '#theme' => 'moody_feature_page_editors_picks_custom',
      '#item' => $item + ['image_render' => $image],
    ];
  }

  /**
   * Creates the existing filtered news View with the requested node IDs.
   */
  private function prepareView(array $selected_nodes): ?ViewExecutable
  {
    $view = Views::getView('news_filtered');
    if (!$view) {
      return NULL;
    }

    $view->setDisplay('block_filtered');
    $view->setArguments([$selected_nodes ? implode(',', $selected_nodes) : '0']);
    $view->initDisplay();
    $image_style = (string) ($this->configuration['image_style'] ?? '');
    if ($image_style !== '') {
      $this->applyImageStyleOverride($view, $image_style);
    }
    return $view;
  }

  /**
   * Attaches the shared card library and optional image-style cache tag.
   */
  private function attachBlockAssets(array &$build): void
  {
    $build['#attached']['library'][] = 'moody_feature_page/moody_feature_editors_picks';
    $image_style = (string) ($this->configuration['image_style'] ?? '');
    if ($image_style !== '') {
      $build['#cache']['tags'][] = 'config:image.style.' . $image_style;
    }
  }

  /**
   * Overrides the image style used by image-like view fields.
   */
  private function applyImageStyleOverride(ViewExecutable $view, string $image_style): void
  {
    if (!$view->display_handler) {
      return;
    }

    $fields = $view->display_handler->getOption('fields') ?? [];
    foreach ($fields as &$field) {
      $field_type = $field['type'] ?? '';
      if (!in_array($field_type, ['media_thumbnail', 'image'], TRUE)) {
        continue;
      }
      if (!isset($field['settings']['image_style'])) {
        continue;
      }
      $field['settings']['image_style'] = $image_style;
    }
    unset($field);

    $view->display_handler->setOption('fields', $fields);
  }
}
