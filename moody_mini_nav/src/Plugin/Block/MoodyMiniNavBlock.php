<?php

declare(strict_types=1);

namespace Drupal\moody_mini_nav\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\moody_mini_nav\MiniNavAnchorTargetManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Moody Mini Nav block.
 *
 * @Block(
 *   id = "moody_mini_nav",
 *   admin_label = @Translation("Moody Mini Nav"),
 *   category = @Translation("Moody")
 * )
 */
final class MoodyMiniNavBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Maximum configurable links.
   */
  private const ITEM_LIMIT = 8;

  /**
   * Constructs the block.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly MiniNavAnchorTargetManager $anchorTargetManager,
    private readonly UuidInterface $uuidGenerator,
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
      $container->get('moody_mini_nav.anchor_target_manager'),
      $container->get('uuid'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'instance_uuid' => '',
      'items' => [],
      'text_color' => 'burnt-orange',
      'background_color' => 'white',
      'font_size' => 'md',
      'font_weight' => 'black',
      'mobile_label' => 'Menu',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $anchor_options = $this->anchorTargetManager->getAnchorTargetOptions();
    $items = $this->configuration['items'] ?? [];

    $form['display'] = [
      '#type' => 'details',
      '#title' => $this->t('Mini Nav styling'),
      '#open' => TRUE,
    ];
    $form['display']['text_color'] = [
      '#type' => 'select',
      '#title' => $this->t('Text color'),
      '#options' => $this->getTextColorOptions(),
      '#default_value' => $this->configuration['text_color'] ?? 'burnt-orange',
    ];
    $form['display']['background_color'] = [
      '#type' => 'select',
      '#title' => $this->t('Background color'),
      '#options' => $this->getBackgroundColorOptions(),
      '#default_value' => $this->configuration['background_color'] ?? 'white',
    ];
    $form['display']['font_size'] = [
      '#type' => 'select',
      '#title' => $this->t('Font size'),
      '#options' => [
        'sm' => $this->t('Small'),
        'md' => $this->t('Medium'),
        'lg' => $this->t('Large'),
        'xl' => $this->t('Extra large'),
      ],
      '#default_value' => $this->configuration['font_size'] ?? 'md',
    ];
    $form['display']['font_weight'] = [
      '#type' => 'select',
      '#title' => $this->t('Font weight'),
      '#options' => [
        'medium' => $this->t('Medium'),
        'bold' => $this->t('Bold'),
        'black' => $this->t('Black'),
      ],
      '#default_value' => $this->configuration['font_weight'] ?? 'black',
    ];
    $form['display']['mobile_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mobile toggle label'),
      '#default_value' => $this->configuration['mobile_label'] ?? 'Menu',
      '#maxlength' => 24,
    ];

    $form['items'] = [
      '#type' => 'details',
      '#title' => $this->t('Menu items'),
      '#description' => $this->t('Configure up to @count items. Leave unused items empty. "Anchor to block" targets come from the current page Layout Builder structure when available.', ['@count' => self::ITEM_LIMIT]),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    for ($i = 0; $i < self::ITEM_LIMIT; $i++) {
      $item = $items[$i] ?? [];
      $item_key = 'item_' . $i;

      $form['items'][$item_key] = [
        '#type' => 'details',
        '#title' => $this->t('Item @number', ['@number' => $i + 1]),
        '#open' => $i < 2,
      ];
      $form['items'][$item_key]['label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Label'),
        '#default_value' => $item['label'] ?? '',
      ];
      $form['items'][$item_key]['link_type'] = [
        '#type' => 'radios',
        '#title' => $this->t('Link type'),
        '#options' => [
          'url' => $this->t('URL'),
          'anchor' => $this->t('Anchor to block'),
        ],
        '#default_value' => $item['link_type'] ?? 'url',
      ];
      $form['items'][$item_key]['url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('URL'),
        '#description' => $this->t('Accepts internal paths like <code>/about</code>, <code>&lt;front&gt;</code>, or full external URLs like <code>https://example.com</code>.'),
        '#default_value' => $item['url'] ?? '',
        '#states' => [
          'visible' => [
            ':input[name="settings[items][' . $item_key . '][link_type]"]' => ['value' => 'url'],
          ],
        ],
      ];
      $form['items'][$item_key]['anchor_target'] = [
        '#type' => 'select',
        '#title' => $this->t('Anchor target'),
        '#options' => ['' => $this->t('- Select a block target -')] + $anchor_options,
        '#default_value' => $item['anchor_target'] ?? '',
        '#empty_value' => '',
        '#description' => $anchor_options === []
          ? $this->t('No Layout Builder block targets were detected in the current page context.')
          : $this->t('Scroll to the selected block on the current page.'),
        '#states' => [
          'visible' => [
            ':input[name="settings[items][' . $item_key . '][link_type]"]' => ['value' => 'anchor'],
          ],
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $submitted_items = $form_state->getValue('items') ?? [];
    $items = [];

    foreach ($submitted_items as $item) {
      $label = trim((string) ($item['label'] ?? ''));
      $link_type = (string) ($item['link_type'] ?? 'url');
      $url = trim((string) ($item['url'] ?? ''));
      $anchor_target = trim((string) ($item['anchor_target'] ?? ''));

      if ($label === '') {
        continue;
      }
      if ($link_type === 'anchor' && $anchor_target === '') {
        continue;
      }
      if ($link_type !== 'anchor' && $url === '') {
        continue;
      }

      $items[] = [
        'label' => $label,
        'link_type' => $link_type === 'anchor' ? 'anchor' : 'url',
        'url' => $url,
        'anchor_target' => $anchor_target,
      ];
    }

    $this->configuration['instance_uuid'] = $this->configuration['instance_uuid'] ?: $this->uuidGenerator->generate();
    $this->configuration['items'] = $items;
    $this->configuration['text_color'] = $form_state->getValue(['display', 'text_color']);
    $this->configuration['background_color'] = $form_state->getValue(['display', 'background_color']);
    $this->configuration['font_size'] = $form_state->getValue(['display', 'font_size']);
    $this->configuration['font_weight'] = $form_state->getValue(['display', 'font_weight']);
    $this->configuration['mobile_label'] = trim((string) $form_state->getValue(['display', 'mobile_label']));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $items = [];
    foreach ($this->configuration['items'] ?? [] as $item) {
      $label = trim((string) ($item['label'] ?? ''));
      if ($label === '') {
        continue;
      }

      if (($item['link_type'] ?? 'url') === 'anchor') {
        $target = trim((string) ($item['anchor_target'] ?? ''));
        if ($target === '') {
          continue;
        }

        $items[] = [
          'label' => $label,
          'href' => '#',
          'type' => 'anchor',
          'target_id' => 'moody-mini-nav-target-' . Html::getId($target),
          'target_uuid' => $target,
        ];
        continue;
      }

      $href = $this->normalizeUrl((string) ($item['url'] ?? ''));
      if ($href === NULL) {
        continue;
      }

      $items[] = [
        'label' => $label,
        'href' => $href,
        'type' => 'url',
        'target_id' => '',
        'target_uuid' => '',
      ];
    }

    if ($items === []) {
      return [];
    }

    $instance_uuid = (string) ($this->configuration['instance_uuid'] ?? '');
    if ($instance_uuid === '') {
      $instance_uuid = $this->uuidGenerator->generate();
    }

    return [
      '#theme' => 'moody_mini_nav',
      '#instance_id' => 'moody-mini-nav-' . Html::getId($instance_uuid),
      '#items' => $items,
      '#text_color' => $this->configuration['text_color'] ?? 'burnt-orange',
      '#background_color' => $this->configuration['background_color'] ?? 'white',
      '#font_size' => $this->configuration['font_size'] ?? 'md',
      '#font_weight' => $this->configuration['font_weight'] ?? 'black',
      '#mobile_label' => $this->configuration['mobile_label'] ?: 'Menu',
      '#attached' => [
        'library' => [
          'moody_mini_nav/mini_nav',
        ],
      ],
    ];
  }

  /**
   * Normalizes configured URLs for template output.
   */
  private function normalizeUrl(string $url): ?string {
    $url = trim($url);
    if ($url === '') {
      return NULL;
    }

    try {
      if ($url === '<front>') {
        return Url::fromRoute('<front>')->toString();
      }
      if (str_starts_with($url, '/')) {
        return Url::fromUserInput($url)->toString();
      }
      if (str_starts_with($url, '#')) {
        return $url;
      }
      if (parse_url($url, PHP_URL_SCHEME)) {
        return Url::fromUri($url)->toString();
      }
      if (str_starts_with($url, 'internal:')) {
        return Url::fromUri($url)->toString();
      }
    }
    catch (\Throwable) {
      return NULL;
    }

    return NULL;
  }

  /**
   * Returns approved text color options.
   */
  private function getTextColorOptions(): array {
    return [
      'burnt-orange' => $this->t('Burnt Orange'),
      'charcoal' => $this->t('Charcoal'),
      'white' => $this->t('White'),
      'black' => $this->t('Black'),
    ];
  }

  /**
   * Returns approved background color options.
   */
  private function getBackgroundColorOptions(): array {
    return [
      'white' => $this->t('White'),
      'light' => $this->t('Light gray'),
      'charcoal' => $this->t('Charcoal'),
      'burnt-orange' => $this->t('Burnt Orange'),
    ];
  }

}
