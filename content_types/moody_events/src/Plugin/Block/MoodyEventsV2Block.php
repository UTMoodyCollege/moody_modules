<?php declare(strict_types = 1);

namespace Drupal\moody_events\Plugin\Block;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an API-backed upcoming events block.
 *
 * @Block(
 *   id = "moody_events_moody_events_v2",
 *   admin_label = @Translation("Moody Events V2"),
 *   category = @Translation("Custom"),
 * )
 */
final class MoodyEventsV2Block extends BlockBase implements ContainerFactoryPluginInterface {

  private const CACHE_ID = 'moody_events:v2:remote_feed';
  private const CACHE_TTL = 3600;

  /**
   * Constructs the plugin instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ClientInterface $httpClient,
    private readonly CacheBackendInterface $cache,
    private readonly DateFormatterInterface $dateFormatter,
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
      $container->get('http_client'),
      $container->get('cache.default'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'limit' => 4,
      'event_exclusions' => [],
      'event_host' => [],
      'show_images' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $events = $this->getUpcomingEvents();
    $event_options = [];
    $host_options = [];

    foreach ($events as $event) {
      $event_options[$event['id']] = $event['title'];
      foreach ($event['departments'] as $department) {
        $host_options[$department] = $department;
      }
    }

    asort($event_options);
    asort($host_options);

    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of events'),
      '#default_value' => $this->configuration['limit'],
      '#min' => 1,
      '#max' => 20,
      '#required' => TRUE,
    ];
    $form['show_images'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show event images'),
      '#default_value' => $this->configuration['show_images'],
    ];
    $form['event_exclusions'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#title' => $this->t('Excluded events'),
      '#description' => $this->t('Select specific upcoming events to omit from this block.'),
      '#default_value' => $this->configuration['event_exclusions'],
      '#options' => $event_options,
    ];
    $form['event_host'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#title' => $this->t('Included event hosts'),
      '#description' => $this->t('Only show events whose host/department matches one of these values. Leave empty to show all hosts.'),
      '#default_value' => $this->configuration['event_host'],
      '#options' => $host_options,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['limit'] = max(1, (int) $form_state->getValue('limit'));
    $this->configuration['show_images'] = (bool) $form_state->getValue('show_images');
    $this->configuration['event_exclusions'] = array_values(array_filter($form_state->getValue('event_exclusions') ?? []));
    $this->configuration['event_host'] = array_values(array_filter($form_state->getValue('event_host') ?? []));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $events = array_slice($this->getFilteredEvents(), 0, (int) $this->configuration['limit']);

    return [
      '#theme' => 'moody_events_v2_block',
      '#events' => $events,
      '#show_images' => (bool) $this->configuration['show_images'],
      '#attached' => [
        'library' => [
          'moody_events/v2_block',
        ],
      ],
      '#cache' => [
        'max-age' => self::CACHE_TTL,
      ],
    ];
  }

  /**
   * Returns the filtered event list for the current block configuration.
   *
   * @return array<int, array<string, mixed>>
   *   The filtered event data.
   */
  private function getFilteredEvents(): array {
    $events = $this->getUpcomingEvents();
    $excluded_ids = array_flip($this->configuration['event_exclusions'] ?? []);
    $host_filters = array_filter($this->configuration['event_host'] ?? []);

    $events = array_filter($events, static function (array $event) use ($excluded_ids, $host_filters): bool {
      if (isset($excluded_ids[$event['id']])) {
        return FALSE;
      }

      if ($host_filters === []) {
        return TRUE;
      }

      return count(array_intersect($host_filters, $event['departments'])) > 0;
    });

    return array_values($events);
  }

  /**
   * Fetches and normalizes upcoming events from the remote API.
   *
   * @return array<int, array<string, mixed>>
   *   The normalized event list.
   */
  private function getUpcomingEvents(): array {
    if ($cache = $this->cache->get(self::CACHE_ID)) {
      return $cache->data;
    }

    $events = [];

    try {
      $response = $this->httpClient->get(\constant('MOODY_EVENTS_MEDIA_CHANNEL_ENDPOINT'), [
        'headers' => [
          'Accept' => 'application/json',
          'User-Agent' => 'Moody Events V2 Drupal block',
        ],
        'timeout' => 20,
        'connect_timeout' => 10,
      ]);
    }
    catch (GuzzleException $exception) {
      \Drupal::logger('moody_events')->warning('Unable to fetch remote events for Moody Events V2 block: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return [];
    }

    if ($response->getStatusCode() !== 200) {
      return [];
    }

    $payload = Json::decode((string) $response->getBody());
    $timestamp = \Drupal::time()->getRequestTime();

    foreach (($payload['events'] ?? []) as $event) {
      $start_timestamp = strtotime((string) ($event['start_date'] ?? ''));
      if (!$start_timestamp || $start_timestamp < $timestamp) {
        continue;
      }

      $end_timestamp = strtotime((string) ($event['end_date'] ?? '')) ?: $start_timestamp;
      $description = trim(Html::decodeEntities(strip_tags((string) ($event['description'] ?? ''))));
      $description = mb_strimwidth($description, 0, 220, '...');

      $formatted_date = $this->formatEventDate($start_timestamp, $end_timestamp);

      $events[] = [
        'id' => (string) ($event['id'] ?? ''),
        'title' => Html::decodeEntities((string) ($event['title'] ?? '')),
        'url' => (string) ($event['url'] ?? ''),
        'description' => $description,
        'image_url' => $event['image']['sizes']['medium']['url'] ?? NULL,
        'image_alt' => Html::decodeEntities((string) ($event['title'] ?? '')),
        'departments' => array_values(array_filter($event['field_event_department'] ?? [])),
        'date_display' => $formatted_date['text'],
        'date_sort' => $formatted_date['sort'],
      ];
    }

    usort($events, static fn(array $a, array $b): int => strcmp($a['date_sort'], $b['date_sort']));

    $this->cache->set(self::CACHE_ID, $events, $timestamp + self::CACHE_TTL);

    return $events;
  }

  /**
   * Formats the event date/time for display.
   */
  private function formatEventDate(int $start_timestamp, int $end_timestamp): array {
    $start_day = $this->dateFormatter->format($start_timestamp, 'custom', 'D, M j Y');
    $start_time = strtolower($this->dateFormatter->format($start_timestamp, 'custom', 'g:ia'));
    $end_time = strtolower($this->dateFormatter->format($end_timestamp, 'custom', 'g:ia'));

    $display = $start_day;
    if ($start_time !== $end_time) {
      $display .= ', ' . $start_time . ' - ' . $end_time;
    }
    else {
      $display .= ', ' . $start_time;
    }

    return [
      'text' => $display,
      'sort' => date('c', $start_timestamp),
    ];
  }

}
