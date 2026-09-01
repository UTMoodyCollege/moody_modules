<?php

namespace Drupal\moody_page_launch;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\PathAliasInterface;
use Drupal\path_alias\AliasManagerInterface;

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
  ) {}

  /**
   * Builds the exact launch plan without changing content.
   */
  public function buildPlan(NodeInterface $current, NodeInterface $replacement): array {
    if ($current->id() === $replacement->id()) {
      throw new \InvalidArgumentException('Select two different pages.');
    }
    if (!$current->isPublished()) {
      throw new \InvalidArgumentException('The current page must be published before it can be replaced.');
    }
    if (!$current->hasField('path') || !$replacement->hasField('path')) {
      throw new \InvalidArgumentException('Both pages must support URL aliases.');
    }
    if (count($current->getTranslationLanguages()) !== 1 || count($replacement->getTranslationLanguages()) !== 1) {
      throw new \InvalidArgumentException('This first version supports pages with one language only.');
    }
    if ($current->language()->getId() !== $replacement->language()->getId()) {
      throw new \InvalidArgumentException('Both pages must use the same language.');
    }

    $current_alias = $this->loadSingleAlias($current);
    $replacement_alias = $this->loadSingleAlias($replacement);
    if ($current_alias->getAlias() === $replacement_alias->getAlias()) {
      throw new \InvalidArgumentException('The current and replacement pages already use the same URL alias.');
    }

    return [
      'current' => $this->pageSnapshot($current, $current_alias),
      'replacement' => $this->pageSnapshot($replacement, $replacement_alias),
      'archive_alias' => $this->nextArchiveAlias($current_alias->getAlias()),
      'retarget_redirects' => $this->redirectsTargetingNode((int) $current->id()),
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
  public function launch(NodeInterface $current, NodeInterface $replacement, string $expected_fingerprint): array {
    $lock_name = 'moody_page_launch.launch';
    if (!$this->lock->acquire($lock_name, 30.0)) {
      throw new \RuntimeException('Another page launch is currently running. Try again shortly.');
    }

    $node_storage = $this->entityTypeManager->getStorage('node');
    $path_alias_storage = $this->entityTypeManager->getStorage('path_alias');
    $redirect_storage = $this->entityTypeManager->getStorage('redirect');

    try {
      $node_storage->resetCache([$current->id(), $replacement->id()]);
      $current = $node_storage->load($current->id());
      $replacement = $node_storage->load($replacement->id());
      if (!$current instanceof NodeInterface || !$replacement instanceof NodeInterface) {
        throw new \RuntimeException('One of the selected pages no longer exists.');
      }

      $plan = $this->buildPlan($current, $replacement);
      if (!hash_equals($expected_fingerprint, $this->fingerprint($plan))) {
        throw new \RuntimeException('The pages, aliases, or redirects changed after the preview. Refresh the preview before launching.');
      }

      $transaction = $this->database->startTransaction();
      try {
        $this->prepareRevision(
          $current,
          FALSE,
          sprintf('Archived and replaced by node %d with Moody Page Launch.', $replacement->id()),
        );
        $current->set('path', [
          'alias' => $plan['archive_alias'],
          'pid' => $plan['current']['alias_id'],
          'langcode' => $plan['current']['alias_langcode'],
          'pathauto' => 0,
        ]);
        $current->save();

        $this->prepareRevision(
          $replacement,
          TRUE,
          sprintf('Launched in place of node %d with Moody Page Launch.', $current->id()),
        );
        $replacement->set('path', [
          'alias' => $plan['current']['alias'],
          'pid' => $plan['replacement']['alias_id'],
          'langcode' => $plan['replacement']['alias_langcode'],
          'pathauto' => 0,
        ]);
        $replacement->save();

        $retarget_ids = array_column($plan['retarget_redirects'], 'id');
        foreach ($redirect_storage->loadMultiple($retarget_ids) as $redirect) {
          $redirect->setRedirect('/node/' . $replacement->id());
          $redirect->setStatusCode(301);
          $redirect->setPublished();
          $redirect->save();
        }

        // Real aliases must never also be Redirect sources.
        $this->deleteRedirectsBySource($plan['current']['alias']);
        $this->deleteRedirectsBySource($plan['archive_alias']);

        // Both the redesign's preview URL and the retired node URL now resolve
        // directly to the launched replacement page.
        $this->replaceRedirect($plan['replacement']['alias'], (int) $replacement->id());
        $this->replaceRedirect('/node/' . $current->id(), (int) $replacement->id());

        unset($transaction);
      }
      catch (\Throwable $exception) {
        $transaction->rollBack();
        throw $exception;
      }

      $node_storage->resetCache([$current->id(), $replacement->id()]);
      $path_alias_storage->resetCache();
      $redirect_storage->resetCache();
      $this->aliasManager->cacheClear();
      $this->cacheTagsInvalidator->invalidateTags(['route_match']);

      $this->logger->notice(
        'User @uid launched replacement node @replacement for node @current at @alias. The retired page is available to editors at @archive.',
        [
          '@uid' => $this->currentUser->id(),
          '@replacement' => $replacement->id(),
          '@current' => $current->id(),
          '@alias' => $plan['current']['alias'],
          '@archive' => $plan['archive_alias'],
        ],
      );

      return $plan;
    }
    catch (\Throwable $exception) {
      $node_storage->resetCache([$current->id(), $replacement->id()]);
      $path_alias_storage->resetCache();
      $redirect_storage->resetCache();
      throw $exception;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Loads the one active alias used by a page.
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
   * Captures all values that must still match when launch is submitted.
   */
  protected function pageSnapshot(NodeInterface $node, PathAliasInterface $alias): array {
    return [
      'id' => (int) $node->id(),
      'revision_id' => (int) $node->getRevisionId(),
      'title' => (string) $node->label(),
      'bundle' => $node->bundle(),
      'published' => $node->isPublished(),
      'langcode' => $node->language()->getId(),
      'alias_id' => (int) $alias->id(),
      'alias_langcode' => $alias->language()->getId(),
      'alias' => $alias->getAlias(),
    ];
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
   * Checks aliases and redirects before reserving an archive URL.
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

    return (bool) $this->entityTypeManager->getStorage('redirect')->getQuery()
      ->accessCheck(FALSE)
      ->condition('redirect_source.path', ltrim($path, '/'))
      ->count()
      ->execute();
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
  protected function replaceRedirect(string $source, int $destination_node_id): void {
    $this->deleteRedirectsBySource($source);
    $redirect = $this->entityTypeManager->getStorage('redirect')->create([
      'language' => LanguageInterface::LANGCODE_NOT_SPECIFIED,
      'uid' => $this->currentUser->id(),
      'status_code' => 301,
      'enabled' => TRUE,
    ]);
    $redirect->setSource($source);
    $redirect->setRedirect('/node/' . $destination_node_id);
    $redirect->save();
  }

}
