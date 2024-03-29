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
      'event_exclusions' => null,
      'event_host' => null,
    ];
  }

  /**
   * Helper to list out all nodes of type moody_event with node id as key and title as value
   */
  private function getEventsNodeList() {
    $events = [];
    $events_nodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['type' => 'moody_event']);
    foreach ($events_nodes as $event_node) {
      $events[$event_node->id()] = $event_node->getTitle();
    }
    return $events;
  }

  /**
   * Helper to get all taxonomy terms id and label array for the moody_departments vocab.
   */
  private function getDepartmentsTerms() {
    $departments = [];
    $departments_terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree('moody_departments');
    foreach ($departments_terms as $departments_term) {
      $departments[$departments_term->tid] = $departments_term->name;
    }
    return $departments;
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $event_exclusions_default_value = $this->configuration['event_exclusions'];
    $event_exclusions_default_value = explode(',', $event_exclusions_default_value);
    $form['event_exclusions'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#title' => $this->t('Event Tag Exclusion Filter'),
      '#description' => $this->t('Select events to exclude.'),
      '#default_value' => $event_exclusions_default_value,
      '#options' => $this->getEventsNodeList(),
    ];
    $form['event_host'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#title' => $this->t('Event Host Filter'),
      '#description' => $this->t('Select event hosts to include.'),
      '#default_value' => $this->configuration['event_host'],
      '#options' => $this->getDepartmentsTerms(),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    // Create a comma separated list of the terms selected for the event_exclusions.
    $event_exclusions_selected = $form_state->getValue('event_exclusions');
    $imploded_event_exclusions = implode(',', $event_exclusions_selected);
    $this->configuration['event_exclusions'] = $imploded_event_exclusions;
    // Create a comma separated list of the terms selected for the event_host.
    $event_host_selected = $form_state->getValue('event_host');
    $imploded_event_host = implode(',', $event_host_selected);
    $this->configuration['event_host'] = $imploded_event_host;
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $view = Views::getView('moody_events');
    $event_exclusions = $this->configuration['event_exclusions'];
    if (empty($event_exclusions)) {
      // Set it to "0"
      $event_exclusions = '0';
    }
    $event_host = $this->configuration['event_host'] ?? NULL;
    //Build an args array
    $view_args = [
      'event_exclusions' => $event_exclusions,
    ];
    if (!empty($event_host)) {
      $view_args['event_host'] = $event_host;
    }
    $build['moody_events'] = $view->buildRenderable('block_1', $view_args);
    $build['moody_events']['#attributes']['class'][] = 'block-views-blockmoody-events-block-1';
    return $build;
  }

}
