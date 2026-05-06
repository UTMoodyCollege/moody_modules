<?php

declare(strict_types=1);

namespace Drupal\moody_vimeo\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\moody_vimeo\VimeoApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the Vimeo video library admin pages.
 */
class VimeoVideoListController extends ControllerBase {

  /**
   * Constructs a VimeoVideoListController.
   */
  public function __construct(
    protected readonly VimeoApiService $vimeoApi,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('moody_vimeo.api'),
    );
  }

  // ---------------------------------------------------------------------------
  // Video list page
  // ---------------------------------------------------------------------------

  /**
   * Renders a paginated table of the authenticated user's Vimeo videos.
   */
  public function listVideos(): array {
    if (!$this->vimeoApi->isConfigured()) {
      return $this->notConfiguredBuild();
    }

    $config     = $this->config('moody_vimeo.settings');
    $per_page   = (int) ($config->get('videos_per_page') ?: 25);
    $page       = (int) (\Drupal::request()->query->get('page', 1));
    $page       = max(1, $page);

    $result = $this->vimeoApi->getVideos($page, $per_page);

    if ($result === NULL) {
      return [
        '#markup' => '<p class="messages messages--error">' . $this->t('Could not fetch videos from Vimeo. Check the API credentials.') . '</p>',
      ];
    }

    $videos = $result['data'] ?? [];
    $total  = $result['total'] ?? 0;

    $rows = [];
    foreach ($videos as $video) {
      $video_id   = $this->vimeoApi->parseVideoId($video['uri'] ?? '');
      $links      = $this->vimeoApi->extractLinks($video);
      $thumb      = $this->getThumbnailUrl($video);
      $duration   = $this->formatDuration((int) ($video['duration'] ?? 0));
      $created    = !empty($video['created_time'])
        ? \Drupal::service('date.formatter')->format(strtotime($video['created_time']), 'short')
        : '';

      $detail_link = $video_id
        ? Link::fromTextAndUrl($video['name'] ?? $this->t('(untitled)'), Url::fromRoute('moody_vimeo.video_detail', ['video_id' => $video_id]))->toString()
        : ($video['name'] ?? $this->t('(untitled)'));

      $rows[] = [
        'thumbnail' => [
          'data' => [
            '#markup' => $thumb
              ? '<img src="' . htmlspecialchars($thumb, ENT_QUOTES | ENT_HTML5) . '" alt="" width="120" loading="lazy" />'
              : '',
          ],
        ],
        'title'    => ['data' => ['#markup' => $detail_link]],
        'duration' => $duration,
        'created'  => $created,
        'privacy'  => $video['privacy']['view'] ?? '',
        'status'   => $video['status'] ?? '',
        'links'    => [
          'data' => [
            '#markup' => $this->buildLinkBadges($links),
          ],
        ],
      ];
    }

    $build = [];

    $build['actions'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['moody-vimeo-actions']],
      'upload_link' => [
        '#markup' => Link::fromTextAndUrl(
          $this->t('+ Upload new video'),
          Url::fromRoute('moody_vimeo.video_upload')
        )->toString(),
      ],
    ];

    $build['summary'] = [
      '#markup' => '<p>' . $this->t('@total video(s) in your library.', ['@total' => $total]) . '</p>',
    ];

    $build['table'] = [
      '#type'   => 'table',
      '#header' => [
        $this->t('Thumbnail'),
        $this->t('Title'),
        $this->t('Duration'),
        $this->t('Created'),
        $this->t('Privacy'),
        $this->t('Status'),
        $this->t('Links'),
      ],
      '#rows'   => $rows,
      '#empty'  => $this->t('No videos found in your Vimeo library.'),
      '#attributes' => ['class' => ['moody-vimeo-table']],
    ];

    // Simple pager links.
    $total_pages = (int) ceil($total / $per_page);
    if ($total_pages > 1) {
      $pager_items = [];
      if ($page > 1) {
        $pager_items[] = Link::fromTextAndUrl('‹ ' . $this->t('Previous'), Url::fromRoute('moody_vimeo.video_list', [], ['query' => ['page' => $page - 1]]))->toString();
      }
      $pager_items[] = $this->t('Page @current of @total', ['@current' => $page, '@total' => $total_pages]);
      if ($page < $total_pages) {
        $pager_items[] = Link::fromTextAndUrl($this->t('Next') . ' ›', Url::fromRoute('moody_vimeo.video_list', [], ['query' => ['page' => $page + 1]]))->toString();
      }
      $build['pager'] = [
        '#markup' => '<div class="moody-vimeo-pager">' . implode(' &nbsp; ', $pager_items) . '</div>',
      ];
    }

    $build['#attached']['library'][] = 'moody_vimeo/moody_vimeo';

    return $build;
  }

  // ---------------------------------------------------------------------------
  // Single video detail page
  // ---------------------------------------------------------------------------

  /**
   * Renders a detail page for a single video with all available links.
   *
   * @param string $video_id
   *   Numeric Vimeo video ID from the route.
   */
  public function videoDetail(string $video_id): array {
    if (!$this->vimeoApi->isConfigured()) {
      return $this->notConfiguredBuild();
    }

    $video = $this->vimeoApi->getVideo($video_id);

    if ($video === NULL) {
      return ['#markup' => '<p class="messages messages--error">' . $this->t('Video not found or API error.') . '</p>'];
    }

    $links    = $this->vimeoApi->extractLinks($video);
    $thumb    = $this->getThumbnailUrl($video);
    $duration = $this->formatDuration((int) ($video['duration'] ?? 0));

    $build = [];

    $build['back'] = [
      '#markup' => '<p>' . Link::fromTextAndUrl('← ' . $this->t('Back to video library'), Url::fromRoute('moody_vimeo.video_list'))->toString() . '</p>',
    ];

    if ($thumb) {
      $build['thumbnail'] = [
        '#markup' => '<img src="' . htmlspecialchars($thumb, ENT_QUOTES | ENT_HTML5) . '" alt="" class="moody-vimeo-detail-thumb" />',
      ];
    }

    $build['meta'] = [
      '#type'  => 'table',
      '#header' => [],
      '#rows'  => [
        [$this->t('Title'),       $video['name'] ?? ''],
        [$this->t('Description'), $video['description'] ?? ''],
        [$this->t('Duration'),    $duration],
        [$this->t('Privacy'),     $video['privacy']['view'] ?? ''],
        [$this->t('Status'),      $video['status'] ?? ''],
      ],
      '#attributes' => ['class' => ['moody-vimeo-meta-table']],
    ];

    // --- Link cards ---
    $build['links_heading'] = [
      '#markup' => '<h3>' . $this->t('Video Links') . '</h3>',
    ];

    $link_rows = [];

    if ($links['vimeo_url']) {
      $link_rows[] = [
        $this->t('Vimeo page'),
        ['data' => ['#markup' => $this->buildLinkRowMarkup($links['vimeo_url'])]],
      ];
    }

    if ($links['embed_url']) {
      $link_rows[] = [
        $this->t('Player embed URL'),
        ['data' => ['#markup' => $this->buildLinkRowMarkup($links['embed_url'])]],
      ];
    }

    if ($links['embed_code']) {
      $safe_code = htmlspecialchars($links['embed_code'], ENT_QUOTES | ENT_HTML5);
      $link_rows[] = [
        $this->t('Embed code'),
        ['data' => ['#markup' => '<textarea class="moody-vimeo-embed-code" rows="3" readonly>' . $safe_code . '</textarea> <button class="moody-vimeo-copy-btn button button--small" data-copy="' . $safe_code . '">' . $this->t('Copy') . '</button>']],
      ];
    }

    foreach ($links['direct_files'] as $file) {
      $label = $this->t('Direct file (@quality @width×@height)', [
        '@quality' => $file['quality'],
        '@width'   => $file['width'],
        '@height'  => $file['height'],
      ]);
      $link_rows[] = [
        $label,
        ['data' => ['#markup' => $this->buildLinkRowMarkup($file['link'])]],
      ];
    }

    if (empty($link_rows)) {
      $link_rows[] = [
        ['data' => ['#markup' => '<em>' . $this->t('No link data available. The video may still be processing, or your access token may lack the video_files scope.') . '</em>'], 'colspan' => 2],
      ];
    }

    $build['links_table'] = [
      '#type'       => 'table',
      '#header'     => [$this->t('Type'), $this->t('URL / Code')],
      '#rows'       => $link_rows,
      '#attributes' => ['class' => ['moody-vimeo-links-table']],
    ];

    $build['#attached']['library'][] = 'moody_vimeo/moody_vimeo';

    return $build;
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Returns a "not configured" render array prompting the admin to add creds.
   */
  protected function notConfiguredBuild(): array {
    $url = Url::fromRoute('moody_vimeo.settings')->toString();
    return [
      '#markup' => '<p class="messages messages--warning">' . $this->t(
        'Vimeo API credentials are not configured. <a href="@url">Configure them here</a>.',
        ['@url' => $url]
      ) . '</p>',
    ];
  }

  /**
   * Returns a thumbnail URL for a video object, preferring medium-size.
   *
   * @param array $video
   *   Video data from the Vimeo API.
   *
   * @return string|null
   *   URL string or NULL if no pictures available.
   */
  protected function getThumbnailUrl(array $video): ?string {
    $sizes = $video['pictures']['sizes'] ?? [];
    if (empty($sizes)) {
      return NULL;
    }
    // Find a size around 200px wide, else fall back to the last one.
    foreach ($sizes as $size) {
      if (isset($size['width']) && $size['width'] >= 200 && $size['width'] <= 400) {
        return $size['link'] ?? NULL;
      }
    }
    $last = end($sizes);
    return $last['link'] ?? NULL;
  }

  /**
   * Formats a duration in seconds as mm:ss or h:mm:ss.
   */
  protected function formatDuration(int $seconds): string {
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($h > 0) {
      return sprintf('%d:%02d:%02d', $h, $m, $s);
    }
    return sprintf('%d:%02d', $m, $s);
  }

  /**
   * Builds a short set of link badges for the list-page table cell.
   *
   * @param array $links
   *   Links array from VimeoApiService::extractLinks().
   *
   * @return string
   *   HTML string.
   */
  protected function buildLinkBadges(array $links): string {
    $parts = [];

    if ($links['vimeo_url']) {
      $parts[] = $this->buildBadgeLink($links['vimeo_url'], (string) $this->t('Vimeo page'));
    }
    if ($links['embed_url']) {
      $parts[] = $this->buildBadgeLink($links['embed_url'], (string) $this->t('Embed URL'));
    }
    if (!empty($links['direct_files'])) {
      $first = reset($links['direct_files']);
      $parts[] = $this->buildBadgeLink($first['link'], (string) $this->t('Direct link'));
    }

    return implode(' ', $parts);
  }

  /**
   * Builds markup for a clickable external link plus a copy button.
   */
  protected function buildLinkRowMarkup(string $url): string {
    $safe_url = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5);

    return '<a href="' . $safe_url . '" target="_blank" rel="noopener noreferrer" class="button button--small moody-vimeo-open-link">' . $this->t('Open') . '</a> <code class="moody-vimeo-link-value">' . $safe_url . '</code> <button type="button" class="moody-vimeo-copy-btn button button--small" data-copy="' . $safe_url . '">' . $this->t('Copy') . '</button>';
  }

  /**
   * Builds a compact badge link for the listing page.
   */
  protected function buildBadgeLink(string $url, string $label): string {
    return '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5) . '" target="_blank" rel="noopener noreferrer" class="moody-vimeo-badge">' . htmlspecialchars($label, ENT_QUOTES | ENT_HTML5) . '</a>';
  }

}
