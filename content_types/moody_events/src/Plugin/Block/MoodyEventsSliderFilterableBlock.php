<?php

namespace Drupal\moody_events\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\views\Views;

/**
 * Provides a moody events slider - filterable block.
 *
 * @Block(
 *   id = "moody_events_moody_events_slider_filterable",
 *   admin_label = @Translation("Moody Events Slider - Filterable"),
 *   category = @Translation("Custom")
 * )
 */
class MoodyEventsSliderFilterableBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new MoodyEventsSliderFilterableBlock instance.
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
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, RequestStack $request_stack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'event_filter' => null,
    ];
  }

  /**
   * Helper to get all event tags from event_tag taxonomy array with key as tid and name as value.
   */
  private function getEventTags() {
    $event_tags = [];
    $event_tags_taxonomy = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree('moody_event_tags');
    foreach ($event_tags_taxonomy as $event_tag) {
      $event_tags[$event_tag->tid] = $event_tag->name;
    }
    return $event_tags;
  }
  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    // Get the current configured event_filter config.
    $event_filter = $this->configuration['event_filter'];
    // Since these are stored as comma separated, explode to array.
    $event_filter = explode(',', $event_filter);
    
    $form['event_filter'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#title' => $this->t('Event Tag Filter'),
      '#description' => $this->t('Select event tags to filter by.'),
      '#default_value' => $event_filter,
      '#options' => $this->getEventTags(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    // Create a comma separated list of the terms selected.
    $event_filters_selected = $form_state->getValue('event_filter');
    $imploded_event_filters = implode(',', $event_filters_selected);
    $this->configuration['event_filter'] = $imploded_event_filters;
    
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $view = Views::getView('moody_events');
    // Load the configured event filter.
    $event_filter = $this->configuration['event_filter'];
    
    $build['moody_events'] = $view->buildRenderable('block_1', [$event_filter]);
    $build['moody_events']['#attributes']['class'][] = 'block-views-blockmoody-events-block-1';

    return $build;
  }

}
