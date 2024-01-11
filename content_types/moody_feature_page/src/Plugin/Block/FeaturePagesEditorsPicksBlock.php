<?php declare(strict_types = 1);

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
final class FeaturePagesEditorsPicksBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
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
  public function defaultConfiguration(): array {
    return [
      'selected_nodes' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
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
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['selected_nodes'] = $form_state->getValue('selected_nodes');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $articles = [];
  
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($this->configuration['selected_nodes']);
    
    foreach ($nodes as $node) {
      $url = $node->toUrl()->toString();
      $summary = $node->body->summary;
      // Get the category
      $category = $node->get('field_news_categories')->referencedEntities();
      $author = 'BY ' . strtoupper($node->get('field_feature_page_author')->getValue()[0]['first_name'] . ' ' . $node->get('field_feature_page_author')->getValue()[0]['last_name']);
      $article_date = $node->created->value;
      $category = NULL;
      if (!empty($category[0])) {
        $category = $category[0]->getName();
      }

      $articles[] = [
        'title' => $node->getTitle(),
        'summary' => $summary,
        'url' => $url,
        'category' => $category,
        'author' => $author,
        'article_date' => $article_date,
      ];
    }
  
    $build = [
      '#theme' => 'moody_feature_page_editors_picks',
      '#title' => $this->t('Editor\'s Picks'),
      '#articles' => $articles,
    ];

    // Attach the moody_feature_page/moody_feature_editors_picks library.
    $build['#attached']['library'][] = 'moody_feature_page/moody_feature_editors_picks';
  
    return $build;
  }
  

}
