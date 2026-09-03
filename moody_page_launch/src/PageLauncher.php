<?php

namespace Drupal\moody_page_launch;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\path_alias\PathAliasInterface;
use Drupal\views\ViewEntityInterface;

/**
 * Plans and executes atomic replacement-page launches.
 */
final class PageLauncher {

  /**
   * Constructs the page launcher.
   */
  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected AccountProxyInterface $currentUser,
    protected LockBackendInterface $lock,
    protected LoggerChannelInterface $logger,
    protected AliasManagerInterface $aliasManager,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected RouteBuilderInterface $routerBuilder,
  ) {}

  /**
   * Returns fixed, non-administrative Views page displays the user can update.
   */
  public function viewPageOptions(bool $enabled_only): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('view')->loadMultiple() as $view) {
      if (!$view instanceof ViewEntityInterface || !$view->status() || !$view->access('update', $this->currentUser)) {
        continue;
      }
      foreach ($view->get('display') as $display_id => $display) {
        if (($display['display_plugin'] ?? '') !== 'page') {
          continue;
        }
        $enabled = $this->displayEnabled($display);
        if ($enabled_only && !$enabled) {
          continue;
        }
        try {
          $path = $this->viewPath($display);
        }
        catch (\InvalidArgumentException) {
          continue;
        }
        $label = sprintf(
          '%s — %s (%s)%s',
          $view->label(),
          $display['display_title'] ?? $display_id,
          $path,
          $enabled ? '' : ' [disabled]',
        );
        $options[$view->id() . ':' . $display_id] = $label;
      }
    }
    natcasesort($options);
    return $options;
  }

  /**
   * Builds the exact launch plan without changing content or configuration.
   */
  public function buildPlan(array $current_target, array $replacement_target): array {
    $current = $this->targetSnapshot($current_target, 'current');
    $replacement = $this->targetSnapshot($replacement_target, 'replacement');

    if ($current['key'] === $replacement['key']) {
      throw new \InvalidArgumentException('Select two different pages.');
    }
    if ($current['type'] === 'view' && $replacement['type'] === 'view') {
      throw new \InvalidArgumentException('Choose a content page for either the current page or replacement. View-to-View launches are not supported yet.');
    }
    if ($current['type'] === 'node' && $replacement['type'] === 'node') {
      if ($current['langcode'] !== $replacement['langcode']) {
        throw new \InvalidArgumentException('Both pages must use the same language.');
      }
      if ($current['path'] === $replacement['path']) {
        throw new \InvalidArgumentException('The current and replacement pages already use the same URL alias.');
      }
    }

    $allowed_owners = [$current['key'], $replacement['key']];
    $this->assertNoOtherPathOwner($current['path'], $allowed_owners);
    $this->assertNoOtherPathOwner($replacement['path'], $allowed_owners);

    return [
      'current' => $current,
      'replacement' => $replacement,
      'archive_path' => $current['type'] === 'node'
        ? $this->nextArchiveAlias($current['path'])
        : NULL,
      'replacement_legacy_path' => $replacement['path'] !== $current['path']
        ? $replacement['path']
        : NULL,
      'replacement_destination' => $replacement['type'] === 'node'
        ? '/node/' . $replacement['id']
        : $current['path'],
      'retarget_redirects' => $current['type'] === 'node'
        ? $this->redirectsTargetingNode($current['id'])
        : [],
    ];
  }

  /**
   * Returns a stable fingerprint used to reject a stale preview.
   */
  public function fingerprint(array $plan): string {
    return hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
  }

  /**
   * Executes a previously previewed plan.
   */
  public function launch(array $current_target, array $replacement_target, string $expected_fingerprint): array {
    $lock_name = 'moody_page_launch.launch';
    if (!$this->lock->acquire($lock_name, 30.0)) {
      throw new \RuntimeException('Another page launch is currently running. Try again shortly.');
    }

    $node_storage = $this->entityTypeManager->getStorage('node');
    $path_alias_storage = $this->entityTypeManager->getStorage('path_alias');
    $redirect_storage = $this->entityTypeManager->getStorage('redirect');
    $view_storage = $this->entityTypeManager->getStorage('view');

    try {
      $this->resetTargetCaches($current_target, $replacement_target);
      $plan = $this->buildPlan($current_target, $replacement_target);
      if (!hash_equals($expected_fingerprint, $this->fingerprint($plan))) {
        throw new \RuntimeException('The pages, aliases, Views displays, or redirects changed after the preview. Refresh the preview before launching.');
      }

      $transaction = $this->database->startTransaction();
      try {
        if ($plan['current']['type'] === 'node') {
          $current = $node_storage->load($plan['current']['id']);
          if (!$current instanceof NodeInterface) {
            throw new \RuntimeException('The current content page no longer exists.');
          }
          $this->prepareRevision(
            $current,
            FALSE,
            sprintf('Archived by Moody Page Launch for %s.', $plan['replacement']['key']),
          );
          $current->set('path', [
            'alias' => $plan['archive_path'],
            'pid' => $plan['current']['alias_id'],
            'langcode' => $plan['current']['alias_langcode'],
            'pathauto' => 0,
          ]);
          $current->save();
        }
        else {
          $this->updateViewDisplay($plan['current'], FALSE);
        }

        if ($plan['replacement']['type'] === 'node') {
          $replacement = $node_storage->load($plan['replacement']['id']);
          if (!$replacement instanceof NodeInterface) {
            throw new \RuntimeException('The replacement content page no longer exists.');
          }
          $this->prepareRevision(
            $replacement,
            TRUE,
            sprintf('Launched in place of %s with Moody Page Launch.', $plan['current']['key']),
          );
          $replacement->set('path', [
            'alias' => $plan['current']['path'],
            'pid' => $plan['replacement']['alias_id'],
            'langcode' => $plan['replacement']['alias_langcode'],
            'pathauto' => 0,
          ]);
          $replacement->save();
        }
        else {
          $this->updateViewDisplay($plan['replacement'], TRUE, $plan['current']['path']);
        }

        $retarget_ids = array_column($plan['retarget_redirects'], 'id');
        foreach ($redirect_storage->loadMultiple($retarget_ids) as $redirect) {
          $redirect->setRedirect($plan['replacement_destination']);
          $redirect->setStatusCode(301);
          $redirect->setPublished();
          $redirect->save();
        }

        // Real page paths must never also be Redirect sources.
        $this->deleteRedirectsBySource($plan['current']['path']);
        if ($plan['archive_path']) {
          $this->deleteRedirectsBySource($plan['archive_path']);
        }

        if ($plan['replacement_legacy_path']) {
          $this->replaceRedirect(
            $plan['replacement_legacy_path'],
            $plan['replacement_destination'],
          );
        }
        if ($plan['current']['type'] === 'node') {
          $this->replaceRedirect(
            '/node/' . $plan['current']['id'],
            $plan['replacement_destination'],
          );
        }

        unset($transaction);
      }
      catch (\Throwable $exception) {
        $transaction->rollBack();
        throw $exception;
      }

      $this->resetTargetCaches($current_target, $replacement_target);
      $path_alias_storage->resetCache();
      $redirect_storage->resetCache();
      $view_storage->resetCache();
      $this->aliasManager->cacheClear();
      $this->cacheTagsInvalidator->invalidateTags(['route_match']);
      if ($plan['current']['type'] === 'view' || $plan['replacement']['type'] === 'view') {
        try {
          $this->routerBuilder->rebuild();
        }
        catch (\Throwable $exception) {
          $this->routerBuilder->setRebuildNeeded();
          $this->logger->warning('The page launch succeeded, but the router rebuild was deferred: @message', [
            '@message' => $exception->getMessage(),
          ]);
        }
      }

      $this->logger->notice(
        'User @uid launched @replacement in place of @current at @path.',
        [
          '@uid' => $this->currentUser->id(),
          '@replacement' => $plan['replacement']['key'],
          '@current' => $plan['current']['key'],
          '@path' => $plan['current']['path'],
        ],
      );

      return $plan;
    }
    catch (\Throwable $exception) {
      $this->resetTargetCaches($current_target, $replacement_target);
      $path_alias_storage->resetCache();
      $redirect_storage->resetCache();
      $view_storage->resetCache();
      throw $exception;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Loads a node or Views page display into a stable plan snapshot.
   */
  protected function targetSnapshot(array $target, string $role): array {
    return match ($target['type'] ?? '') {
      'node' => $this->nodeSnapshot((int) ($target['id'] ?? 0), $role),
      'view' => $this->viewSnapshot(
        (string) ($target['view_id'] ?? ''),
        (string) ($target['display_id'] ?? ''),
        $role,
      ),
      default => throw new \InvalidArgumentException('Select a valid page type.'),
    };
  }

  /**
   * Captures a node and its one active alias.
   */
  protected function nodeSnapshot(int $node_id, string $role): array {
    $node = $this->entityTypeManager->getStorage('node')->load($node_id);
    if (!$node instanceof NodeInterface) {
      throw new \InvalidArgumentException(sprintf('Select a valid %s content page.', $role));
    }
    if ($role === 'current' && !$node->isPublished()) {
      throw new \InvalidArgumentException('The current content page must be published before it can be replaced.');
    }
    if (!$node->hasField('path')) {
      throw new \InvalidArgumentException(sprintf('The %s content page must support URL aliases.', $role));
    }
    if (count($node->getTranslationLanguages()) !== 1) {
      throw new \InvalidArgumentException('This version supports content pages with one language only.');
    }

    $alias = $this->loadSingleAlias($node);
    return [
      'type' => 'node',
      'key' => 'node:' . $node->id(),
      'id' => (int) $node->id(),
      'revision_id' => (int) $node->getRevisionId(),
      'title' => (string) $node->label(),
      'bundle' => $node->bundle(),
      'published' => $node->isPublished(),
      'langcode' => $node->language()->getId(),
      'alias_id' => (int) $alias->id(),
      'alias_langcode' => $alias->language()->getId(),
      'path' => $alias->getAlias(),
    ];
  }

  /**
   * Captures one fixed Views page display.
   */
  protected function viewSnapshot(string $view_id, string $display_id, string $role): array {
    if (!preg_match('/^[a-z0-9_]+$/', $view_id) || !preg_match('/^[a-z0-9_]+$/', $display_id)) {
      throw new \InvalidArgumentException(sprintf('Select a valid %s Views page display.', $role));
    }
    $view = $this->entityTypeManager->getStorage('view')->load($view_id);
    if (!$view instanceof ViewEntityInterface || !$view->status()) {
      throw new \InvalidArgumentException(sprintf('The %s View must exist and be enabled.', $role));
    }
    $display = $view->get('display')[$display_id] ?? NULL;
    if (!is_array($display) || ($display['display_plugin'] ?? '') !== 'page') {
      throw new \InvalidArgumentException(sprintf('Select a valid %s Views page display.', $role));
    }
    $enabled = $this->displayEnabled($display);
    if ($role === 'current' && !$enabled) {
      throw new \InvalidArgumentException('The current Views page display must be enabled before it can be replaced.');
    }

    return [
      'type' => 'view',
      'key' => 'view:' . $view_id . ':' . $display_id,
      'view_id' => $view_id,
      'display_id' => $display_id,
      'title' => sprintf(
        '%s — %s',
        $view->label(),
        $display['display_title'] ?? $display_id,
      ),
      'view_label' => (string) $view->label(),
      'display_title' => (string) ($display['display_title'] ?? $display_id),
      'enabled' => $enabled,
      'display_hash' => hash('sha256', json_encode($display, JSON_THROW_ON_ERROR)),
      'path' => $this->viewPath($display),
    ];
  }

  /**
   * Loads the one active alias used by a content page.
   */
  protected function loadSingleAlias(NodeInterface $node): PathAliasInterface {
    $langcodes = array_unique([
      $node->language()->getId(),
      LanguageInterface::LANGCODE_NOT_SPECIFIED,
    ]);
    $storage = $this->entityTypeManager->getStorage('path_alias');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('path', '/node/' . $node->id())
      ->condition('status', 1)
      ->condition('langcode', $langcodes, 'IN')
      ->execute();

    if (count($ids) !== 1) {
      throw new \InvalidArgumentException(sprintf(
        'Page %d must have exactly one active URL alias; found %d.',
        $node->id(),
        count($ids),
      ));
    }

    $alias = $storage->load(reset($ids));
    if (!$alias instanceof PathAliasInterface || str_starts_with($alias->getAlias(), '/node/')) {
      throw new \InvalidArgumentException(sprintf('Page %d must use a non-system URL alias.', $node->id()));
    }
    return $alias;
  }

  /**
   * Returns whether a Views display is enabled.
   */
  protected function displayEnabled(array $display): bool {
    return !array_key_exists('enabled', $display['display_options'] ?? [])
      || !empty($display['display_options']['enabled']);
  }

  /**
   * Returns a supported Views page path with a leading slash.
   */
  protected function viewPath(array $display): string {
    $path = trim((string) ($display['display_options']['path'] ?? ''), '/');
    $menu_type = $display['display_options']['menu']['type'] ?? 'none';
    if ($path === '' || mb_strlen($path) > 254 || preg_match('/[%{}*]/', $path) || !empty($display['display_options']['arguments'])) {
      throw new \InvalidArgumentException('Views page displays must use a fixed path without contextual placeholders.');
    }
    if ($path === 'admin' || str_starts_with($path, 'admin/')) {
      throw new \InvalidArgumentException('Administrative Views paths cannot be launched with this tool.');
    }
    if ($menu_type === 'default tab') {
      throw new \InvalidArgumentException('Default-tab Views page displays cannot be launched with this tool.');
    }
    return '/' . $path;
  }

  /**
   * Rejects paths currently owned by an unrelated alias or Views display.
   */
  protected function assertNoOtherPathOwner(string $path, array $allowed_owners): void {
    $alias_storage = $this->entityTypeManager->getStorage('path_alias');
    $alias_ids = $alias_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('alias', $path)
      ->condition('status', 1)
      ->execute();
    foreach ($alias_storage->loadMultiple($alias_ids) as $alias) {
      $owner = preg_match('@^/node/(\d+)$@', $alias->getPath(), $matches)
        ? 'node:' . $matches[1]
        : 'alias:' . $alias->id();
      if (!in_array($owner, $allowed_owners, TRUE)) {
        throw new \InvalidArgumentException(sprintf('%s is also owned by another active URL alias.', $path));
      }
    }

    foreach ($this->entityTypeManager->getStorage('view')->loadMultiple() as $view) {
      if (!$view instanceof ViewEntityInterface || !$view->status()) {
        continue;
      }
      foreach ($view->get('display') as $display_id => $display) {
        if (($display['display_plugin'] ?? '') !== 'page' || !$this->displayEnabled($display)) {
          continue;
        }
        try {
          $display_path = $this->viewPath($display);
        }
        catch (\InvalidArgumentException) {
          continue;
        }
        $owner = 'view:' . $view->id() . ':' . $display_id;
        if ($display_path === $path && !in_array($owner, $allowed_owners, TRUE)) {
          throw new \InvalidArgumentException(sprintf('%s is also owned by another enabled Views page display.', $path));
        }
      }
    }
  }

  /**
   * Finds the first unused -old-vN URL.
   */
  protected function nextArchiveAlias(string $alias): string {
    for ($version = 1; $version <= 9999; $version++) {
      $suffix = '-old-v' . $version;
      $candidate = mb_substr($alias, 0, 255 - strlen($suffix)) . $suffix;
      if (!$this->sourcePathExists($candidate)) {
        return $candidate;
      }
    }
    throw new \RuntimeException('Could not find an unused archive URL for the current page.');
  }

  /**
   * Checks aliases, redirects, and configured Views page paths.
   */
  protected function sourcePathExists(string $path): bool {
    $alias_count = $this->entityTypeManager->getStorage('path_alias')->getQuery()
      ->accessCheck(FALSE)
      ->condition('alias', $path)
      ->count()
      ->execute();
    if ($alias_count) {
      return TRUE;
    }

    $redirect_count = $this->entityTypeManager->getStorage('redirect')->getQuery()
      ->accessCheck(FALSE)
      ->condition('redirect_source.path', ltrim($path, '/'))
      ->count()
      ->execute();
    if ($redirect_count) {
      return TRUE;
    }

    foreach ($this->entityTypeManager->getStorage('view')->loadMultiple() as $view) {
      if (!$view instanceof ViewEntityInterface) {
        continue;
      }
      foreach ($view->get('display') as $display) {
        if (($display['display_plugin'] ?? '') !== 'page') {
          continue;
        }
        try {
          if ($this->viewPath($display) === $path) {
            return TRUE;
          }
        }
        catch (\InvalidArgumentException) {
        }
      }
    }
    return FALSE;
  }

  /**
   * Gets redirects whose destination is the current node's canonical path.
   */
  protected function redirectsTargetingNode(int $node_id): array {
    $storage = $this->entityTypeManager->getStorage('redirect');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('redirect_redirect.uri', [
        'internal:/node/' . $node_id,
        'entity:node/' . $node_id,
      ], 'IN')
      ->execute();

    $redirects = [];
    foreach ($storage->loadMultiple($ids) as $redirect) {
      $source = $redirect->getSource();
      $redirects[] = [
        'id' => (int) $redirect->id(),
        'source' => '/' . ltrim((string) ($source['path'] ?? ''), '/'),
        'language' => $redirect->language()->getId(),
        'destination' => (string) ($redirect->getRedirect()['uri'] ?? ''),
      ];
    }
    usort($redirects, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
    return $redirects;
  }

  /**
   * Prepares a publication change as a recoverable node revision.
   */
  protected function prepareRevision(NodeInterface $node, bool $published, string $message): void {
    $published ? $node->setPublished() : $node->setUnpublished();
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage($message);
    $node->setRevisionUserId($this->currentUser->id());
    $node->setRevisionCreationTime($this->time->getRequestTime());
  }

  /**
   * Enables or disables one Views page display and optionally changes its path.
   */
  protected function updateViewDisplay(array $snapshot, bool $enabled, ?string $path = NULL): void {
    $view = $this->entityTypeManager->getStorage('view')->load($snapshot['view_id']);
    if (!$view instanceof ViewEntityInterface) {
      throw new \RuntimeException('The selected View no longer exists.');
    }
    $executable = $view->getExecutable();
    if (!$executable->setDisplay($snapshot['display_id'])) {
      throw new \RuntimeException('The selected Views page display no longer exists.');
    }
    $display = $executable->displayHandlers->get($snapshot['display_id']);
    if (!$display || $display->getPluginId() !== 'page') {
      throw new \RuntimeException('The selected Views display is no longer a page display.');
    }
    if ($path !== NULL) {
      $display->setOption('path', ltrim($path, '/'));
    }
    $display->setOption('enabled', $enabled);
    $view->save();
  }

  /**
   * Resets selected node and View entity caches.
   */
  protected function resetTargetCaches(array ...$targets): void {
    $node_ids = [];
    $view_ids = [];
    foreach ($targets as $target) {
      if (($target['type'] ?? '') === 'node' && !empty($target['id'])) {
        $node_ids[] = (int) $target['id'];
      }
      elseif (($target['type'] ?? '') === 'view' && !empty($target['view_id'])) {
        $view_ids[] = (string) $target['view_id'];
      }
    }
    if ($node_ids) {
      $this->entityTypeManager->getStorage('node')->resetCache($node_ids);
    }
    if ($view_ids) {
      $this->entityTypeManager->getStorage('view')->resetCache($view_ids);
    }
  }

  /**
   * Removes every language variant of a redirect source.
   */
  protected function deleteRedirectsBySource(string $source): void {
    $storage = $this->entityTypeManager->getStorage('redirect');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('redirect_source.path', ltrim($source, '/'))
      ->execute();
    if ($ids) {
      $storage->delete($storage->loadMultiple($ids));
    }
  }

  /**
   * Replaces all variants with one permanent, language-neutral redirect.
   */
  protected function replaceRedirect(string $source, string $destination): void {
    $this->deleteRedirectsBySource($source);
    $redirect = $this->entityTypeManager->getStorage('redirect')->create([
      'language' => LanguageInterface::LANGCODE_NOT_SPECIFIED,
      'uid' => $this->currentUser->id(),
      'status_code' => 301,
      'enabled' => TRUE,
    ]);
    $redirect->setSource($source);
    $redirect->setRedirect($destination);
    $redirect->save();
  }

}
