<?php

declare(strict_types=1);

namespace Drupal\moody_interactive_featurette\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a moody interactive featurette block.
 *
 * @Block(
 *   id = "moody_interactive_featurette_moody_interactive_featurette",
 *   admin_label = @Translation("Moody Interactive Featurette"),
 *   category = @Translation("Custom"),
 * )
 */
final class MoodyInteractiveFeaturetteBlock extends BlockBase implements ContainerFactoryPluginInterface
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
      'example' => $this->t('Hello world!'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array
  {
    // TODO: Allow configuring up to 3 image or solid colors backgrounds.
    for ($i = 1; $i <= 3; $i++) {
      $form['image_' . $i] = [
        '#type' => 'media_library',
        '#title' => $this->t('Image @i', ['@i' => $i]),
        '#description' => $this->t('Upload an image for the background.'),
        '#allowed_bundles' => ['utexas_image'],
        '#cardinality' => 1,
        '#default_value' => $this->configuration['image_' . $i] ?? NULL,
        '#states' => [
          'visible' => [
            ':input[name="image_type"]' => ['value' => 'image'],
          ],
        ],
      ];
      $form['image_' . $i . '_color'] = [
        '#type' => 'color',
        '#title' => $this->t('Image @i Color', ['@i' => $i]),
        '#description' => $this->t('Enter the color for the background.'),
        '#default_value' => $this->configuration['image_' . $i . '_color'] ?? NULL,
        '#states' => [
          'visible' => [
            ':input[name="image_type"]' => ['value' => 'color'],
          ],
        ],
      ];
    }

    // Heading
    $form['heading'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Heading'),
      '#description' => $this->t('Enter the heading for the featurette.'),
      '#default_value' => $this->configuration['heading'] ?? NULL,
    ];

    // Text
    $form['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Text'),
      '#description' => $this->t('Enter the text for the featurette.'),
      '#default_value' => $this->configuration['text'] ?? NULL,
    ];

    // Link
    $form['link'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link'),
      '#description' => $this->t('Enter the link for the featurette.'),
      '#default_value' => $this->configuration['link'] ?? NULL,
    ];

    // Link Text
    $form['link_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link Text'),
      '#description' => $this->t('Enter the link text for the featurette.'),
      '#default_value' => $this->configuration['link_text'],
    ];



    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void
  {
    // Setup all the config
    for ($i = 1; $i <= 3; $i++) {
      $this->configuration['image_' . $i] = $form_state->getValue('image_' . $i);
      $this->configuration['image_' . $i . '_color'] = $form_state->getValue('image_' . $i . '_color');
    }
    $this->configuration['heading'] = $form_state->getValue('heading');
    $this->configuration['text'] = $form_state->getValue('text');
    $this->configuration['link'] = $form_state->getValue('link');
    $this->configuration['link_text'] = $form_state->getValue('link_text');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array
  {
    $heading = $this->configuration['heading'] ?? NULL;
    $text = $this->configuration['text'] ?? NULL;
    $link = $this->configuration['link'] ?? NULL;
    $link_text = $this->configuration['link_text'] ?? NULL;
    

    // Inside your build() method.
    $image_render_arrays = [];
    for ($i = 1; $i <= 3; $i++) {
      $image_id = $this->configuration['image_' . $i] ?? NULL;
      if ($image_id) {
        $image_entity = $this->entityTypeManager->getStorage('media')->load($image_id);
        $image_file = $image_entity->field_utexas_media_image->entity;
        if ($image_file) {
          // If the image is the first one, its moody_image_style_560w_x_315h image style. Otherwise its moody_image_style_150w_x_188h.
          // $image_style = $i === 0 ? 'moody_image_style_325w_x_350h' : 
          // 'utexas_image_style_150w_188h';
          // utexas_image_style_280w_280h
          switch ($i) {
            case 1:
              $image_style = 'moody_image_style_800w_1140h';
              break;
            case 2:
              $image_style = 'utexas_image_style_280w_280h';
              break;
            case 3:
              $image_style = 'utexas_image_style_250w_150h';
              break;
          }
          $image_render_arrays[] = [
            '#theme' => 'image_style',
            '#style_name' => $image_style,
            '#uri' => $image_file->getFileUri(),
            '#alt' => $this->configuration['image_' . $i . '_alt'],
            '#attributes' => ['class' => ['featurette-image-' . $i]], // Optional, if you want to add custom classes.
          ];
        }
      }
      else {
        // We can check for the $image_$i_color value and use that as a background color.
        $image_render_arrays[] = [
          '#theme' => 'moody_interactive_featurette_color',
          '#color' => $this->configuration['image_' . $i . '_color'],
        ];
      }
    }

    $data = [
      'image_1' => $image_render_arrays[0],
      'image_2' => $image_render_arrays[1],
      'image_3' => $image_render_arrays[2],
      'heading' => $heading,
      'text' => $text,
      'link' => $link,
      'link_text' => $link_text,
    ];

    $output = [
      '#theme' => 'moody_interactive_featurette',
      '#data' => $data,
    ];

    // Lets include the moody_interactive_featurette/interactive_featurette lib.
    $output['#attached']['library'][] = 'moody_interactive_featurette/interactive_featurette';
    return $output;
  }
}
