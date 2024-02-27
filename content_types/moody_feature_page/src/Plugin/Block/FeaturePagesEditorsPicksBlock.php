<?php

declare(strict_types=1);

namespace Drupal\moody_feature_page\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
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
      $options[$node->id()] = $node->getTitle();
    }

    $form['selected_nodes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Select Nodes'),
      '#options' => $options,
      '#default_value' => $this->configuration['selected_nodes'],
    ];
    return $form;
  }



  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void
  {
    $this->configuration['selected_nodes'] = $form_state->getValue('selected_nodes');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array
  {
    // Transform the $this->configuration['selected_nodes'] into a comma separated list of nids
    $nids = implode(',', $this->configuration['selected_nodes']);
    // We get a value like "6641,6640,6610,6603,6602,6594,6580,6542,6532,6518,6502,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0"
    // Remove any values that are just "0"
    $nids = preg_replace('/,0/', '', $nids);
    $build['content'] = [
      '#type' => 'view',
      '#name' => 'news_filtered',
      '#display_id' => 'block_filtered',
      // We need to add selected_nids as a query parm programmaticallyt
      '#arguments' => [$nids],
    ];

    return $build;
  }
}
