<?php

declare(strict_types=1);

namespace Drupal\moody_vimeo;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for interacting with the Vimeo API v3.
 *
 * All calls use the personal access token stored in moody_vimeo.settings and
 * the standard Vimeo API base URL (https://api.vimeo.com).
 */
class VimeoApiService {

  /**
   * Vimeo API base URL.
   */
  public const API_BASE = 'https://api.vimeo.com';

  /**
   * The logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a VimeoApiService.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('moody_vimeo');
  }

  // ---------------------------------------------------------------------------
  // Configuration helpers
  // ---------------------------------------------------------------------------

  /**
   * Returns the stored personal access token, or NULL if not configured.
   */
  public function getAccessToken(): ?string {
    $token = $this->configFactory->get('moody_vimeo.settings')->get('access_token');
    return ($token !== NULL && $token !== '') ? $token : NULL;
  }

  /**
   * Returns TRUE when a non-empty access token has been stored.
   */
  public function isConfigured(): bool {
    return $this->getAccessToken() !== NULL;
  }

  // ---------------------------------------------------------------------------
  // Video listing
  // ---------------------------------------------------------------------------

  /**
   * Fetches a page of videos from the authenticated user's Vimeo library.
   *
   * @param int $page
   *   1-based page number.
   * @param int $per_page
   *   Items per page (max 100 per Vimeo API limits).
   * @param string $sort
   *   Sort order: 'date', 'alphabetical', 'plays', 'likes', 'comments', 'duration'.
   * @param string $direction
   *   'asc' or 'desc'.
   *
   * @return array|null
   *   Decoded Vimeo response array, or NULL on failure.
   */
  public function getVideos(int $page = 1, int $per_page = 25, string $sort = 'date', string $direction = 'desc'): ?array {
    return $this->request('GET', '/me/videos', [
      'query' => [
        'page'       => $page,
        'per_page'   => min($per_page, 100),
        'sort'       => $sort,
        'direction'  => $direction,
        'fields'     => 'uri,name,description,duration,pictures,link,embed,files,created_time,privacy,status',
      ],
    ]);
  }

  /**
   * Fetches a single video by its Vimeo numeric ID.
   *
   * @param string|int $video_id
   *   The Vimeo video ID (numeric).
   *
   * @return array|null
   *   Decoded video object, or NULL on failure.
   */
  public function getVideo(string|int $video_id): ?array {
    return $this->request('GET', '/videos/' . $video_id, [
      'query' => [
        'fields' => 'uri,name,description,duration,pictures,link,embed,files,created_time,privacy,status',
      ],
    ]);
  }

  // ---------------------------------------------------------------------------
  // Video upload
  // ---------------------------------------------------------------------------

  /**
   * Initiates a Vimeo upload using the pull (upload by URL) approach.
   *
   * Vimeo will fetch the video from the provided public URL.
   *
   * @param string $url
   *   A publicly accessible URL to the video file.
   * @param string $name
   *   Video title.
   * @param string $description
   *   Optional description.
   * @param string $privacy
   *   Privacy setting: 'anybody', 'nobody', 'password', 'unlisted', etc.
   *
   * @return array|null
   *   Vimeo API response or NULL on failure.
   */
  public function uploadByUrl(string $url, string $name, string $description = '', string $privacy = 'nobody'): ?array {
    return $this->request('POST', '/me/videos', [
      'json' => [
        'upload'      => [
          'approach' => 'pull',
          'link'     => $url,
        ],
        'name'        => $name,
        'description' => $description,
        'privacy'     => ['view' => $privacy],
      ],
    ]);
  }

  /**
   * Creates a tus upload session for a local/streamed upload.
   *
   * Returns the upload link and video URI so the caller can stream bytes.
   *
   * @param int $file_size
   *   File size in bytes.
   * @param string $name
   *   Video title.
   * @param string $description
   *   Optional description.
   * @param string $privacy
   *   Privacy setting.
   *
   * @return array|null
   *   ['upload_link' => string, 'uri' => string] or NULL on failure.
   */
  public function createUploadSession(int $file_size, string $name, string $description = '', string $privacy = 'nobody'): ?array {
    $response = $this->request('POST', '/me/videos', [
      'json' => [
        'upload'      => [
          'approach' => 'tus',
          'size'     => $file_size,
        ],
        'name'        => $name,
        'description' => $description,
        'privacy'     => ['view' => $privacy],
      ],
    ]);

    if ($response && isset($response['upload']['upload_link'], $response['uri'])) {
      return [
        'upload_link' => $response['upload']['upload_link'],
        'uri'         => $response['uri'],
      ];
    }
    return NULL;
  }

  /**
   * Updates video metadata (title, description, privacy).
   *
   * @param string|int $video_id
   *   Vimeo numeric video ID.
   * @param array $data
   *   Associative array with keys: 'name', 'description', 'privacy' (optional).
   *
   * @return array|null
   *   Updated video data or NULL on failure.
   */
  public function updateVideo(string|int $video_id, array $data): ?array {
    $payload = array_filter([
      'name'        => $data['name'] ?? NULL,
      'description' => $data['description'] ?? NULL,
      'privacy'     => isset($data['privacy']) ? ['view' => $data['privacy']] : NULL,
    ]);
    return $this->request('PATCH', '/videos/' . $video_id, ['json' => $payload]);
  }

  // ---------------------------------------------------------------------------
  // Link helpers
  // ---------------------------------------------------------------------------

  /**
   * Returns a structured array of useful links for a video data object.
   *
   * @param array $video
   *   A video object as returned by getVideo() / getVideos().
   *
   * @return array
   *   Keyed array with:
   *   - 'vimeo_url'    Canonical Vimeo page URL
   *   - 'embed_url'    Embed-ready https://player.vimeo.com/video/{id} URL
   *   - 'embed_code'   Raw iframe HTML from Vimeo
   *   - 'direct_files' Array of ['quality'=>…,'width'=>…,'height'=>…,'link'=>…]
   */
  public function extractLinks(array $video): array {
    $video_id   = $this->parseVideoId($video['uri'] ?? '');
    $embed_url  = $video_id ? 'https://player.vimeo.com/video/' . $video_id : '';
    $embed_code = $video['embed']['html'] ?? '';
    $vimeo_url  = $video['link'] ?? ($video_id ? 'https://vimeo.com/' . $video_id : '');

    $direct_files = [];
    foreach ($video['files'] ?? [] as $file) {
      if (!empty($file['link'])) {
        $direct_files[] = [
          'quality' => $file['quality']         ?? 'unknown',
          'width'   => $file['width']            ?? 0,
          'height'  => $file['height']           ?? 0,
          'type'    => $file['type']             ?? '',
          'link'    => $file['link'],
          'rendition_type' => $file['rendition'] ?? '',
        ];
      }
    }

    return [
      'vimeo_url'    => $vimeo_url,
      'embed_url'    => $embed_url,
      'embed_code'   => $embed_code,
      'direct_files' => $direct_files,
    ];
  }

  /**
   * Parses a numeric video ID out of a Vimeo URI like "/videos/12345".
   */
  public function parseVideoId(string $uri): ?string {
    if (preg_match('#/videos/(\d+)#', $uri, $m)) {
      return $m[1];
    }
    return NULL;
  }

  // ---------------------------------------------------------------------------
  // Internal HTTP helper
  // ---------------------------------------------------------------------------

  /**
   * Sends an authenticated request to the Vimeo API.
   *
   * @param string $method
   *   HTTP method (GET, POST, PATCH, DELETE).
   * @param string $path
   *   API path, e.g. '/me/videos'.
   * @param array $options
   *   Guzzle request options.
   *
   * @return array|null
   *   Decoded JSON response body, or NULL on error.
   */
  protected function request(string $method, string $path, array $options = []): ?array {
    $token = $this->getAccessToken();
    if ($token === NULL) {
      $this->logger->error('Vimeo API request failed: no access token configured.');
      return NULL;
    }

    $options['headers'] = array_merge($options['headers'] ?? [], [
      'Authorization' => 'bearer ' . $token,
      'Content-Type'  => 'application/json',
      'Accept'        => 'application/vnd.vimeo.*+json;version=3.4',
    ]);

    try {
      $response = $this->httpClient->request($method, self::API_BASE . $path, $options);
      $body     = (string) $response->getBody();
      return $body !== '' ? json_decode($body, TRUE) : [];
    }
    catch (GuzzleException $e) {
      $this->logger->error('Vimeo API @method @path failed: @message', [
        '@method'  => $method,
        '@path'    => $path,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
