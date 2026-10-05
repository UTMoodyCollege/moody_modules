<?php

namespace Drupal\moody_custom_roles;

use Drupal\Component\Utility\Html;
use Drupal\Core\Url;

/**
 * A read-only starting point on the signed-in user's own profile.
 */
final class EditorHome {

  public static function build(): array {
    $account = \Drupal::currentUser();
    $manager = \Drupal::entityTypeManager();
    $build = [
      '#type' => 'container',
      '#weight' => -100,
      '#attributes' => ['class' => ['moody-editor-home']],
      // Personal assignments and entity grants must never cross user caches.
      '#cache' => ['max-age' => 0],
      '#attached' => ['library' => ['moody_custom_roles/editor_home']],
      'heading' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => t('Your editor workspace')],
    ];
    $roles = [];
    foreach ($manager->getStorage('user_role')->loadMultiple($account->getRoles()) as $role) {
      if ($role->id() !== 'authenticated') {
        $roles[] = $role->label();
      }
    }
    $build['roles'] = ['#theme' => 'item_list', '#title' => t('Your roles'), '#items' => $roles ?: [t('Signed-in member')]];

    $actions = [];
    foreach ([
      'system.admin_content' => t('Manage content'),
      'node.add_page' => t('Create content'),
      'entity.media.collection' => t('Browse media'),
      'entity.user.edit_form' => t('Account settings'),
    ] as $route => $label) {
      $url = Url::fromRoute($route, $route === 'entity.user.edit_form' ? ['user' => $account->id()] : []);
      if ($url->access($account)) {
        $actions[] = self::link($label, $url);
      }
    }
    $build['actions'] = ['#theme' => 'item_list', '#title' => t('Start here'), '#items' => $actions];

    if (\Drupal::hasService('moody_subsite.editor_context')) {
      $resolver = \Drupal::service('moody_subsite.editor_context');
      $subsites = [];
      foreach ($resolver->assignedSubsites($account) as $subsite) {
        $subsites[] = self::link($resolver->label($subsite), $subsite->toUrl());
      }
      if ($subsites) {
        $build['subsites'] = ['#theme' => 'item_list', '#title' => t('Your subsites'), '#items' => $subsites];
      }
    }

    // Revision author, not content owner: these are pages this user edited.
    $definition = $manager->getDefinition('node');
    $query = \Drupal::database()->select($definition->getRevisionTable(), 'r');
    $query->addField('r', 'nid');
    $query->addExpression('MAX(revision_timestamp)', 'last_edit');
    $recent = $query->condition('revision_uid', $account->id())->groupBy('nid')
      ->orderBy('last_edit', 'DESC')->range(0, 100)->execute()->fetchAllKeyed();
    $items = [];
    foreach ($manager->getStorage('node')->loadMultiple(array_keys($recent)) as $node) {
      if (!$node->access('view', $account) || !$node->access('update', $account)) {
        continue;
      }
      $items[] = self::link($node->label(), $node->toUrl('edit-form'));
      if (count($items) === 6) {
        break;
      }
    }
    $build['recent'] = ['#theme' => 'item_list', '#title' => t('Pages you edited recently'), '#items' => $items ?: [t('No recent editable pages yet.')]];

    if ($account->hasPermission('administer nodes') || $account->hasPermission('bypass node access')) {
      $items = [];
      $storage = $manager->getStorage('node');
      $ids = $storage->getQuery()->accessCheck(TRUE)->sort('changed', 'DESC')->range(0, 30)->execute();
      foreach ($storage->loadMultiple($ids) as $node) {
        if (!$node->access('view', $account)) {
          continue;
        }
        $row = ['#type' => 'container', 'page' => self::link($node->label(), $node->toUrl())];
        $author = $node->getRevisionUser();
        $date = \Drupal::service('date.formatter')->format($node->getChangedTime(), 'short');
        $detail = $author && $author->access('view', $account)
          ? ' — ' . t('@date · latest revision by @name', ['@date' => $date, '@name' => $author->getDisplayName()])
          : ' — ' . $date;
        $row['detail'] = ['#type' => 'html_tag', '#tag' => 'small', '#value' => Html::escape((string) $detail)];
        $items[] = $row;
        if (count($items) === 6) {
          break;
        }
      }
      $build['activity'] = ['#theme' => 'item_list', '#title' => t('Recently updated on this site'), '#items' => $items ?: [t('No accessible content yet.')]];
      if ($manager->hasDefinition('media') && $account->hasPermission('access media overview')) {
        $items = [];
        $storage = $manager->getStorage('media');
        $ids = $storage->getQuery()->accessCheck(TRUE)->sort('created', 'DESC')->range(0, 30)->execute();
        foreach ($storage->loadMultiple($ids) as $media) {
          if ($media->access('view', $account)) {
            $items[] = self::link($media->label(), $media->toUrl());
          }
          if (count($items) === 6) {
            break;
          }
        }
        $build['media'] = ['#theme' => 'item_list', '#title' => t('Recently uploaded media'), '#items' => $items ?: [t('No accessible media yet.')]];
      }
    }
    foreach ($build as $key => $section) {
      if (!str_starts_with($key, '#') && $key !== 'heading') {
        $build[$key] = ['#type' => 'container', '#attributes' => ['class' => ['moody-editor-home-panel']], 'content' => $section];
      }
    }
    return $build;
  }

  private static function link($label, Url $url): array {
    return ['#type' => 'link', '#title' => $label, '#url' => $url];
  }

}
