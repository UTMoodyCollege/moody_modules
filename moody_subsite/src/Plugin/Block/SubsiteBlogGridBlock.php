<?php

namespace Drupal\moody_subsite\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a subsite blog grid block.
 *
 * @Block(
 *   id = "moody_subsite_subsite_blog_grid",
 *   admin_label = @Translation("Subsite Blog Grid"),
 *   category = @Translation("Moody Subsites")
 * )
 */
class SubsiteBlogGridBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new SubsiteBlogGridBlock instance.
   *
   * @param array $configuration
   *   The plugin configuration, i.e. an array with configuration values keyed
   *   by configuration option name. The special key 'context' may be used to
   *   initialize the defined contexts by setting it to an array of context
   *   values keyed by context names.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'subsite_to_show' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    // Load all subsite entities.
    $subsites = $this->entityTypeManager->getStorage('moody_subsite')->loadMultiple();
    // Get all subsite directory_structure data into an array.
    $subsite_directory_structure_tids = [];
    foreach ($subsites as $subsite) {
      $subsite_directory_structure_tids[] = $subsite->get('directory_structure')->target_id;
    }
    // Load all directory_structure entities.
    $directory_structures = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($subsite_directory_structure_tids);
    // Get all directory_structure titles into an array.
    $directory_structure_titles = [];
    foreach ($directory_structures as $directory_structure) {
      $directory_structure_titles[$directory_structure->id()] = $directory_structure->label();
    }

    $form['subsite_to_show'] = [
      '#type' => 'select',
      '#title' => $this->t('Subsite to show'),
      '#options' => $directory_structure_titles,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['subsite_to_show'] = $form_state->getValue('subsite_to_show');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];
    $subsite_to_show = $this->configuration['subsite_to_show'];
    $build['content']['view'] = [
      '#type' => 'view',
      '#name' => 'subsite_blogs',
      '#display_id' => 'block_1',
      '#arguments' => [
        $subsite_to_show,
      ],
    ];

    return $build;
  }

}
