<?php

declare(strict_types=1);

namespace Drupal\moody_feature_page\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
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
      'image_style' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array
  {
    $options = [];

    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $nids = $query->condition('type', 'moody_feature_page')
      ->sort('created', 'DESC')
      ->accessCheck(FALSE)
      ->range(0, 50)
      ->execute();

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    foreach ($nodes as $node) {
      $options[$node->id()] = $node->label();
    }

    $form['selected_nodes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Select Nodes'),
      '#options' => $options,
      '#default_value' => $this->configuration['selected_nodes'],
    ];

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
  public function blockSubmit($form, FormStateInterface $form_state): void
  {
    $selected_nodes = array_values(array_filter(
      $form_state->getValue('selected_nodes') ?? [],
      static function ($nid): bool {
        $nid_string = (string) $nid;
        return $nid_string !== '' && ctype_digit($nid_string) && (int) $nid_string > 0;
      }
    ));

    $this->configuration['selected_nodes'] = $selected_nodes;
    $this->configuration['image_style'] = (string) $form_state->getValue('image_style');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array
  {
    $selected_nodes = array_values(array_filter(
      $this->configuration['selected_nodes'] ?? [],
      static function ($nid): bool {
        $nid_string = (string) $nid;
        return $nid_string !== '' && ctype_digit($nid_string) && (int) $nid_string > 0;
      }
    ));

    $nids = $selected_nodes ? implode(',', $selected_nodes) : '0';
    $image_style = (string) ($this->configuration['image_style'] ?? '');

    $view = Views::getView('news_filtered');
    if (!$view) {
      return ['#markup' => $this->t('The view @view was not found.', ['@view' => 'news_filtered'])];
    }

    $view->setDisplay('block_filtered');
    $view->setArguments([$nids]);
    $view->initDisplay();

    if ($image_style !== '') {
      $this->applyImageStyleOverride($view, $image_style);
    }

    $build = [];
    $build['content'] = $view->render();

    $build['#attached']['library'][] = 'moody_feature_page/moody_feature_editors_picks';
    if ($image_style !== '') {
      $build['#cache']['tags'][] = 'config:image.style.' . $image_style;
    }

    return $build;
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
      if (!in_array($field_type, ['media_thumbnail', 'image'], true)) {
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
