<?php

namespace Drupal\moody_ai_assistant\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;

/**
 * Builds a compact, authoritative access snapshot for assistant prompts.
 */
class UserCapabilityCollector {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs the capability collector.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Collects the current account's useful site-building capabilities.
   */
  public function collect(AccountInterface $account, ?ContentEntityInterface $entity = NULL) {
    return [
      'authority' => 'Calculated by Drupal for this request. Never infer additional access; every action must still be rechecked before execution.',
      'roles' => $this->collectRoleLabels($account),
      'content_types' => $this->collectContentTypeAccess($account),
      'current_content' => $entity ? $this->collectCurrentContentAccess($account, $entity) : NULL,
      'site_tools' => $this->collectSiteToolAccess($account),
      'creatable_media_types' => $this->collectCreatableMediaTypes($account),
    ];
  }

  /**
   * Returns role IDs and human-readable labels without exposing permissions.
   */
  protected function collectRoleLabels(AccountInterface $account) {
    if (!$this->entityTypeManager->hasDefinition('user_role')) {
      return [];
    }

    $role_ids = $account->getRoles();
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple($role_ids);
    $labels = [];
    foreach ($role_ids as $role_id) {
      if (isset($roles[$role_id])) {
        $labels[] = [
          'id' => $role_id,
          'label' => (string) $roles[$role_id]->label(),
        ];
      }
    }

    return $labels;
  }

  /**
   * Returns content types the account can create or may edit.
   */
  protected function collectContentTypeAccess(AccountInterface $account) {
    if (!$this->entityTypeManager->hasDefinition('node') || !$this->entityTypeManager->hasDefinition('node_type')) {
      return [];
    }

    $handler = $this->entityTypeManager->getAccessControlHandler('node');
    $types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $access = [];

    foreach ($types as $type) {
      $bundle = $type->id();
      $can_create = $handler->createAccess($bundle, $account);
      $can_edit_any = $account->hasPermission('administer nodes') || $account->hasPermission('edit any ' . $bundle . ' content');
      $can_edit_own = $can_edit_any || $account->hasPermission('edit own ' . $bundle . ' content');
      if (!$can_create && !$can_edit_own) {
        continue;
      }

      $access[] = [
        'id' => $bundle,
        'label' => (string) $type->label(),
        'create' => (bool) $can_create,
        'edit_scope' => $can_edit_any ? 'any' : ($can_edit_own ? 'own' : 'none'),
        'individual_items_require_access_check' => TRUE,
      ];
    }

    usort($access, static function (array $a, array $b) {
      return strnatcasecmp($a['label'], $b['label']);
    });
    return $access;
  }

  /**
   * Returns exact access for the current content item and its publish state.
   */
  protected function collectCurrentContentAccess(AccountInterface $account, ContentEntityInterface $entity) {
    $operations = [
      'view' => $entity->access('view', $account),
      'update' => $entity->access('update', $account),
      'delete' => $entity->access('delete', $account),
    ];

    return [
      'entity_type' => $entity->getEntityTypeId(),
      'bundle' => $entity->bundle(),
      'operations' => $operations,
      'publication' => $this->collectPublicationAccess($account, $entity, $operations['update']),
    ];
  }

  /**
   * Returns valid moderation transitions or direct published-field access.
   */
  protected function collectPublicationAccess(AccountInterface $account, ContentEntityInterface $entity, $can_update) {
    $published_key = $entity->getEntityType()->getKey('published');
    if (!$published_key || !($entity instanceof EntityPublishedInterface)) {
      return [
        'supported' => FALSE,
        'can_publish' => FALSE,
        'can_unpublish' => FALSE,
      ];
    }

    $is_published = $entity->isPublished();
    $workflow = $this->findModerationWorkflow($entity);
    if ($workflow) {
      $plugin = $workflow->getTypePlugin();
      $state_id = $entity->hasField('moderation_state') ? (string) $entity->get('moderation_state')->value : '';
      $state = $state_id !== '' ? $plugin->getState($state_id) : $plugin->getInitialState($entity);
      $transitions = [];
      $can_publish = FALSE;
      $can_unpublish = FALSE;

      if ($can_update) {
        foreach ($state->getTransitions() as $transition) {
          if (!$account->hasPermission('use ' . $workflow->id() . ' transition ' . $transition->id())) {
            continue;
          }

          $to_state = $transition->to();
          $to_is_published = method_exists($to_state, 'isPublishedState') && $to_state->isPublishedState();
          $to_is_default_revision = method_exists($to_state, 'isDefaultRevisionState') && $to_state->isDefaultRevisionState();
          $transitions[] = [
            'id' => $transition->id(),
            'label' => (string) $transition->label(),
            'to_state' => $to_state->id(),
            'to_label' => (string) $to_state->label(),
            'publishes' => $to_is_published,
            'becomes_default_revision' => $to_is_default_revision,
          ];
          $can_publish = $can_publish || (!$is_published && $to_is_published && $to_is_default_revision);
          $can_unpublish = $can_unpublish || ($is_published && !$to_is_published && $to_is_default_revision);
        }
      }

      return [
        'supported' => TRUE,
        'moderated' => TRUE,
        'workflow_id' => $workflow->id(),
        'workflow_label' => (string) $workflow->label(),
        'current_state' => $state->id(),
        'current_state_label' => (string) $state->label(),
        'is_published' => $is_published,
        'can_publish' => $can_publish,
        'can_unpublish' => $can_unpublish,
        'available_transitions' => $transitions,
      ];
    }

    $can_change_status = $can_update && $entity->get($published_key)->access('edit', $account);
    return [
      'supported' => TRUE,
      'moderated' => FALSE,
      'current_state' => $is_published ? 'published' : 'unpublished',
      'current_state_label' => $is_published ? 'Published' : 'Unpublished',
      'is_published' => $is_published,
      'can_publish' => !$is_published && $can_change_status,
      'can_unpublish' => $is_published && $can_change_status,
      'available_transitions' => [],
    ];
  }

  /**
   * Finds the enabled content moderation workflow for an entity, if any.
   */
  protected function findModerationWorkflow(ContentEntityInterface $entity) {
    if (!$entity->hasField('moderation_state') || !$this->entityTypeManager->hasDefinition('workflow')) {
      return NULL;
    }

    try {
      foreach ($this->entityTypeManager->getStorage('workflow')->loadMultiple() as $workflow) {
        if (method_exists($workflow, 'status') && !$workflow->status()) {
          continue;
        }
        $plugin = $workflow->getTypePlugin();
        if (method_exists($plugin, 'appliesToEntityTypeAndBundle') && $plugin->appliesToEntityTypeAndBundle($entity->getEntityTypeId(), $entity->bundle())) {
          return $workflow;
        }
      }
    }
    catch (\Exception $exception) {
    }

    return NULL;
  }

  /**
   * Returns access to common assistant-guided Drupal tools.
   */
  protected function collectSiteToolAccess(AccountInterface $account) {
    $can_create_redirect = FALSE;
    if ($this->entityTypeManager->hasDefinition('redirect')) {
      try {
        $can_create_redirect = $account->hasPermission('administer redirects')
          && $this->entityTypeManager->getAccessControlHandler('redirect')->createAccess(NULL, $account);
      }
      catch (\Exception $exception) {
      }
    }

    return [
      'create_redirect' => $can_create_redirect,
      'manage_redirects' => $this->routeAccess('redirect.list', $account),
      'manage_menus' => $this->routeAccess('entity.menu.collection', $account),
      'content_overview' => $this->routeAccess('system.admin_content', $account),
      'media_library' => $this->routeAccess('entity.media.collection', $account),
      'manage_users' => $this->routeAccess('entity.user.collection', $account),
      'manage_taxonomy' => $this->routeAccess('entity.taxonomy_vocabulary.collection', $account),
      'configuration_overview' => $this->routeAccess('system.admin_config', $account),
    ];
  }

  /**
   * Returns Media bundles the account can create through entity access.
   */
  protected function collectCreatableMediaTypes(AccountInterface $account) {
    if (!$this->entityTypeManager->hasDefinition('media') || !$this->entityTypeManager->hasDefinition('media_type')) {
      return [];
    }

    $handler = $this->entityTypeManager->getAccessControlHandler('media');
    $types = [];
    foreach ($this->entityTypeManager->getStorage('media_type')->loadMultiple() as $type) {
      if ($handler->createAccess($type->id(), $account)) {
        $types[] = [
          'id' => $type->id(),
          'label' => (string) $type->label(),
        ];
      }
    }

    usort($types, static function (array $a, array $b) {
      return strnatcasecmp($a['label'], $b['label']);
    });
    return $types;
  }

  /**
   * Checks access to a named route without allowing missing routes to fail.
   */
  protected function routeAccess($route_name, AccountInterface $account) {
    try {
      return Url::fromRoute($route_name)->access($account);
    }
    catch (\Exception $exception) {
      return FALSE;
    }
  }

}
