<?php

namespace Drupal\moody_url_manager;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Shared scope, preview and guarded alias operations. */
final class UrlManager {

  public static function overallAccess(AccountInterface $account) {
    return AccessResult::allowedIf((int) $account->id() === 1 || in_array('administrator', $account->getRoles(), TRUE))->cachePerUser();
  }

  public static function subsiteAccess($moody_subsite, AccountInterface $account) {
    if (!\Drupal::entityTypeManager()->hasDefinition('moody_subsite') || !\Drupal::hasService('moody_subsite.editor_context')) {
      return AccessResult::forbidden()->addCacheTags(['config:core.extension']);
    }
    // Named-route access checks can receive the raw ID before parameter conversion.
    if (!$moody_subsite instanceof \Drupal\Core\Entity\EntityInterface) {
      $moody_subsite = is_scalar($moody_subsite) && ctype_digit((string) $moody_subsite)
        ? \Drupal::entityTypeManager()->getStorage('moody_subsite')->load($moody_subsite) : NULL;
    }
    if (!$moody_subsite) { return AccessResult::forbidden()->cachePerUser(); }

    $allowed = self::overallAccess($account)->isAllowed()
      || isset(\Drupal::service('moody_subsite.editor_context')->manageableSubsites($account)[$moody_subsite->id()]);
    return AccessResult::allowedIf($allowed)->cachePerUser()->addCacheableDependency($moody_subsite);
  }

  public static function assertScope(?int $subsite_id): void {
    $account = \Drupal::currentUser();
    if ($subsite_id === NULL) {
      $allowed = self::overallAccess($account)->isAllowed();
    }
    else {
      $allowed = self::subsiteAccess($subsite_id, $account)->isAllowed();
    }
    if (!$allowed) {
      throw new AccessDeniedHttpException();
    }
  }

  public static function query(?int $subsite_id) {
    self::assertScope($subsite_id);
    $q = \Drupal::entityQuery('node')->accessCheck(TRUE)->sort('title');
    if ($subsite_id !== NULL) {
      $subsite = \Drupal::entityTypeManager()->getStorage('moody_subsite')->load($subsite_id);
      $terms = array_column($subsite->get('directory_structure')->getValue(), 'target_id');
      $q->condition('field_moody_url_generator.target_id', $terms ?: [-1], 'IN');
    }
    return $q;
  }

  /** One filter definition for the paged workspace and full CSV export. */
  public static function filteredQuery(?int $scope, string $search, string $status) {
    $query = self::query($scope);
    if ($status === 'published' || $status === 'draft') { $query->condition('status', $status === 'published' ? 1 : 0); }
    if ($search !== '') {
      $alias_ids = \Drupal::entityQuery('path_alias')->accessCheck(FALSE)->condition('alias', $search, 'CONTAINS')->execute();
      $nids = [];
      foreach (\Drupal::entityTypeManager()->getStorage('path_alias')->loadMultiple($alias_ids) as $a) {
        if (preg_match('@^/node/(\d+)$@D', $a->getPath(), $m)) { $nids[] = (int) $m[1]; }
      }
      if (preg_match('@^/node/(\d+)$@D', $search, $m)) { $nids[] = (int) $m[1]; }
      $query->condition($query->orConditionGroup()->condition('title', $search, 'CONTAINS')->condition('nid', $nids ?: [-1], 'IN'));
    }
    return $query;
  }

  /** CSV cells must never become spreadsheet formulas. */
  public static function csvCell(string $value): string {
    return preg_match('/^[\s]*[=+@-]/u', $value) ? "\t" . $value : $value;
  }

  public static function snapshot(NodeInterface $node): array {
    $path = '/node/' . $node->id();
    $langcode = $node->language()->getId();
    $alias = \Drupal::service('path_alias.manager')->getAliasByPath($path, $langcode);
    $aliases = [];
    foreach (\Drupal::entityTypeManager()->getStorage('path_alias')->loadByProperties(['path' => $path]) as $a) {
      $aliases[] = ['id' => $a->id(), 'alias' => $a->getAlias(), 'language' => $a->language()->getId()];
    }
    $redirects = [];
    $pending = array_unique(array_merge([$path], array_column($aliases, 'alias')));
    $seen = [];
    $storage = \Drupal::entityTypeManager()->getStorage('redirect');
    while ($pending) {
      $destinations = array_values(array_diff($pending, $seen));
      if (!$destinations) { break; }
      $seen = array_merge($seen, $destinations);
      $q = $storage->getQuery()->accessCheck(FALSE);
      $or = $q->orConditionGroup()->condition('redirect_redirect.uri', 'entity:node/' . $node->id());
      foreach ($destinations as $destination) { $or->condition('redirect_redirect.uri', 'internal:' . $destination); }
      $ids = $q->condition($or)->sort('rid')->execute();
      $pending = [];
      foreach ($storage->loadMultiple($ids) as $r) {
        if (isset($redirects[$r->id()])) { continue; }
        $source = $r->get('redirect_source')->getValue();
        $redirects[$r->id()] = ['id' => $r->id(), 'source' => $source, 'destination' => $r->get('redirect_redirect')->getValue(), 'code' => $r->getStatusCode(), 'language' => $r->language()->getId()];
        $pending[] = '/' . $source[0]['path'];
      }
    }
    ksort($redirects);
    $redirects = array_values($redirects);
    return ['nid' => (int) $node->id(), 'revision' => $node->getRevisionId(), 'title' => $node->label(), 'language' => $langcode, 'published' => $node->isPublished(), 'path' => $path, 'alias' => $alias, 'aliases' => $aliases, 'redirects' => $redirects];
  }

  public static function validateAlias(string $alias): void {
    if (strlen($alias) > 255 || !preg_match('@^/(?!/)[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$@D', $alias)
      || preg_match('@^/(admin|node|user|system|saml|batch)(/|$)@i', $alias)) {
      throw new \InvalidArgumentException('Use a local path such as /programs/summer, without a trailing slash, query or reserved system path.');
    }
  }

  public static function plan(int $nid, string $new, ?int $subsite_id): array {
    self::validateAlias($new);
    if (!self::query($subsite_id)->condition('nid', $nid)->count()->execute()) {
      throw new AccessDeniedHttpException();
    }
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    if (!$node->access('update') || !$node->hasField('path') || !$node->get('path')->access('edit')) {
      throw new AccessDeniedHttpException('This account cannot edit the page URL.');
    }
    if ((string) \Drupal::entityTypeManager()->getStorage('node')->getLatestRevisionId($nid) !== (string) $node->getRevisionId()) {
      throw new \InvalidArgumentException('This page has a newer draft revision; finish or review that draft before changing its URL here.');
    }
    $old = self::snapshot($node);
    if ($new === $old['alias']) {
      throw new \InvalidArgumentException('The new URL matches the current URL.');
    }
    // Do not silently choose between language variants or secondary aliases.
    if (count($old['aliases']) > 1) {
      throw new \InvalidArgumentException('This page has multiple aliases or languages; review it individually before a bulk move.');
    }
    $aliases = \Drupal::entityQuery('path_alias')->accessCheck(FALSE)->condition('alias', $new)->count()->execute();
    $redirects = \Drupal::entityQuery('redirect')->accessCheck(FALSE)->condition('redirect_source.path', ltrim($new, '/'))->count()->execute();
    $system = \Drupal::service('path.validator')->getUrlIfValidWithoutAccessCheck($new);
    if ($aliases || $redirects || $system) {
      throw new \InvalidArgumentException('The destination already belongs to a page, route or redirect. Nothing was changed.');
    }
    if ($old['alias'] !== $old['path'] && \Drupal::entityQuery('redirect')->accessCheck(FALSE)->condition('redirect_source.path', ltrim($old['alias'], '/'))->count()->execute()) {
      throw new \InvalidArgumentException('The current alias is also a redirect source; resolve that conflict first.');
    }
    return ['before' => $old, 'new' => $new, 'subsite_id' => $subsite_id];
  }

  public static function apply(array $plan, array &$context): void {
    $lock = \Drupal::lock();
    if (!$lock->acquire('moody_url_manager.apply', 60)) {
      throw new \RuntimeException('Another URL update is running.');
    }
    try {
      $storage = \Drupal::entityTypeManager()->getStorage('node');
      $storage->resetCache([$plan['before']['nid']]);
      \Drupal::entityTypeManager()->getStorage('path_alias')->resetCache();
      \Drupal::entityTypeManager()->getStorage('redirect')->resetCache();
      \Drupal::service('path_alias.manager')->cacheClear();
      $fresh = self::plan($plan['before']['nid'], $plan['new'], $plan['subsite_id']);
      if ($fresh !== $plan) {
        throw new \RuntimeException('Page, aliases or redirects changed after preview. Preview again.');
      }
      $transaction = \Drupal::database()->startTransaction();
      try {
        $node = $storage->load($plan['before']['nid']);
        $node->setNewRevision(TRUE);
        $node->setRevisionUserId(\Drupal::currentUser()->id());
        $node->setRevisionLogMessage('Moody URL Manager: ' . $plan['before']['alias'] . ' → ' . $plan['new']);
        $node->set('path', ['alias' => $plan['new'], 'pid' => $plan['before']['aliases'][0]['id'] ?? NULL, 'langcode' => $plan['before']['aliases'][0]['language'] ?? $plan['before']['language'], 'pathauto' => 0]);
        $node->save();
        $old = $plan['before']['alias'];
        if ($old !== $plan['before']['path']) {
          $redirect_storage = \Drupal::entityTypeManager()->getStorage('redirect');
          $ids = $redirect_storage->getQuery()->accessCheck(FALSE)->condition('redirect_source.path', ltrim($old, '/'))->execute();
          // Normalize only the redirect created by this save; pre-existing sources were rejected.
          $redirects = $redirect_storage->loadMultiple($ids);
          if (!$redirects) {
            $redirects[] = $redirect_storage->create(['language' => $plan['before']['aliases'][0]['language'] ?? $plan['before']['language'], 'uid' => \Drupal::currentUser()->id()]);
          }
          foreach ($redirects as $r) {
            $r->setSource(ltrim($old, '/'));
            $r->setRedirect($plan['before']['path']);
            $r->setStatusCode(301);
            $r->save();
          }
        }
        $context['results'][] = ['old' => $old, 'new' => $plan['new'], 'scope' => $plan['subsite_id']];
        \Drupal::logger('moody_url_manager')->notice('Changed @old to @new for node @nid; previous redirects retained.', ['@old' => $old, '@new' => $plan['new'], '@nid' => $node->id()]);
      }
      catch (\Throwable $e) { $transaction->rollBack(); throw $e; }
    }
    finally { $lock->release('moody_url_manager.apply'); }
  }

  /** Preview actual node deletion; never grant more than native delete access. */
  public static function planDelete(int $nid, ?int $scope): array {
    if (!self::query($scope)->condition('nid', $nid)->count()->execute()) { throw new AccessDeniedHttpException(); }
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $node = $storage->load($nid);
    if (!$node || !$node->access('delete')) { throw new AccessDeniedHttpException('You cannot delete this page.'); }
    if ((string) $storage->getLatestRevisionId($nid) !== (string) $node->getRevisionId()) {
      throw new \InvalidArgumentException('This page has a newer draft revision; review it before deleting.');
    }
    $stored = [];
    // Computed fields (for example metatags) vary with the current request URL.
    foreach ($node->getFieldDefinitions() as $name => $definition) {
      if (!$definition->isComputed()) { $stored[$name] = $node->get($name)->getValue(); }
    }
    return ['before' => self::snapshot($node), 'fingerprint' => hash('sha256', serialize($stored)), 'subsite_id' => $scope];
  }

  public static function deletePage(array $plan, array &$context): void {
    $lock = \Drupal::lock();
    if (!$lock->acquire('moody_url_manager.apply', 60)) { throw new \RuntimeException('Another URL operation is running.'); }
    try {
      $storage = \Drupal::entityTypeManager()->getStorage('node');
      $nid = $plan['before']['nid'];
      $storage->resetCache([$nid]);
      \Drupal::entityTypeManager()->getStorage('path_alias')->resetCache();
      \Drupal::entityTypeManager()->getStorage('redirect')->resetCache();
      \Drupal::service('path_alias.manager')->cacheClear();
      if (self::planDelete($nid, $plan['subsite_id']) !== $plan) {
        throw new \RuntimeException('Page, aliases or redirects changed after preview. Preview again.');
      }
      $transaction = \Drupal::database()->startTransaction();
      try {
        $storage->load($nid)->delete();
        \Drupal::logger('moody_url_manager')->notice('Deleted node @nid (@title), previously @alias, after confirmation by user @uid.', ['@nid' => $nid, '@title' => $plan['before']['title'], '@alias' => $plan['before']['alias'], '@uid' => \Drupal::currentUser()->id()]);
        $context['results'][] = ['nid' => $nid, 'title' => $plan['before']['title'], 'alias' => $plan['before']['alias'], 'scope' => $plan['subsite_id']];
      }
      catch (\Throwable $e) { $transaction->rollBack(); throw $e; }
    }
    finally { $lock->release('moody_url_manager.apply'); }
  }

  public static function deleted(bool $success, array $results, array $operations): void {
    \Drupal::service('tempstore.private')->get('moody_url_manager')->set('last_deleted', $results);
    \Drupal::messenger()->addStatus(t('@count pages permanently deleted.', ['@count' => count($results)]));
    if (!$success) { \Drupal::messenger()->addError(t('Deletion stopped. Earlier completed deletions remain applied; review remaining pages again.')); }
  }

  public static function finished(bool $success, array $results, array $operations): void {
    \Drupal::service('tempstore.private')->get('moody_url_manager')->set('last_result', $results);
    \Drupal::messenger()->addStatus(t('@count URL updates completed. Existing redirects were retained.', ['@count' => count($results)]));
    if (!$success) { \Drupal::messenger()->addError(t('The batch stopped on a conflict. Completed pages remain saved; preview the remaining pages again.')); }
  }
}
