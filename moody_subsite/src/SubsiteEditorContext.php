<?php

namespace Drupal\moody_subsite;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Resolves the subsites and pages assigned to a Subsite Editor.
 */
class SubsiteEditorContext {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Workbench's user section storage, when available.
   *
   * @var object|null
   */
  protected $userSectionStorage;

  /**
   * Workbench's access manager, when available.
   *
   * @var object|null
   */
  protected $workbenchAccessManager;

  /**
   * Constructs the editor context service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, $user_section_storage = NULL, $workbench_access_manager = NULL) {
    $this->entityTypeManager = $entity_type_manager;
    $this->userSectionStorage = $user_section_storage;
    $this->workbenchAccessManager = $workbench_access_manager;
  }

  /**
   * Returns assigned subsite entities the account may view.
   */
  public function assignedSubsites(AccountInterface $account) {
    if (!$this->isSubsiteEditor($account) || !$this->userSectionStorage || !$this->entityTypeManager->hasDefinition('access_scheme')) {
      return [];
    }

    $scheme = $this->entityTypeManager->getStorage('access_scheme')->load('directory_structure');
    if (!$scheme) {
      return [];
    }

    try {
      $user_sections = $this->userSectionStorage->getUserSections($scheme, $account);
      $all_sections = $this->workbenchAccessManager && $this->workbenchAccessManager->userInAll($scheme, $account);
    }
    catch (\Exception $exception) {
      return [];
    }

    $subsites = [];
    foreach ($this->entityTypeManager->getStorage('moody_subsite')->loadMultiple() as $subsite) {
      $subsite_sections = array_column($subsite->get('directory_structure')->getValue(), 'target_id');
      $assigned = $all_sections || ($this->workbenchAccessManager
        ? $this->workbenchAccessManager->checkTree($scheme, $subsite_sections, $user_sections)
        : array_intersect($subsite_sections, $user_sections));
      if ($assigned && $subsite->access('view', $account)) {
        $subsites[$subsite->id()] = $subsite;
      }
    }

    uasort($subsites, function ($a, $b) {
      return strnatcasecmp($this->label($a), $this->label($b));
    });
    return $subsites;
  }

  /**
   * Returns compact, prompt-safe context for the account's subsites.
   */
  public function collect(AccountInterface $account) {
    $active = $this->isSubsiteEditor($account);
    $context = [
      'active' => $active,
      'role_id' => 'moody_subsite_editor',
      'paradigm' => 'A Moody subsite is a mini-site inside the main site with its own pages, nested navigation, logo, homepage URL, and directory assignment.',
      'content_type' => 'moody_subsite_page',
      'assigned_subsites' => [],
    ];
    if (!$active) {
      return $context;
    }

    foreach ($this->assignedSubsites($account) as $subsite) {
      $pages = $this->collectPages($subsite, $account);
      $context['assigned_subsites'][] = [
        'id' => (int) $subsite->id(),
        'label' => $this->label($subsite),
        'dashboard_url' => $subsite->toUrl('canonical')->toString(),
        'homepage_url' => (string) $subsite->get('base_url')->value,
        'directory_term_ids' => array_map('intval', array_column($subsite->get('directory_structure')->getValue(), 'target_id')),
        'page_count' => count($pages),
        'pages' => $pages,
      ];
    }
    $context['assigned_subsite_count'] = count($context['assigned_subsites']);
    return $context;
  }

  /**
   * Returns accessible subsite pages related to one subsite.
   */
  protected function collectPages($subsite, AccountInterface $account) {
    if (!$this->entityTypeManager->hasDefinition('node')) {
      return [];
    }

    $term_ids = array_column($subsite->get('directory_structure')->getValue(), 'target_id');
    if (!$term_ids) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('node');
    try {
      $ids = $storage->getQuery()
        ->condition('type', 'moody_subsite_page')
        ->condition('field_moody_url_generator.target_id', $term_ids, 'IN')
        ->sort('title')
        ->accessCheck(FALSE)
        ->execute();
    }
    catch (\Exception $exception) {
      return [];
    }

    $pages = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node->access('view', $account)) {
        continue;
      }
      $page = [
        'id' => (int) $node->id(),
        'title' => (string) $node->label(),
        'published' => (bool) $node->isPublished(),
        'view_url' => $node->toUrl()->toString(),
        'can_edit' => $node->access('update', $account),
      ];
      if ($page['can_edit']) {
        $page['edit_url'] = $node->toUrl('edit-form')->toString();
      }
      $pages[] = $page;
    }
    return $pages;
  }

  /**
   * Returns TRUE for the common Subsite Editor role.
   */
  protected function isSubsiteEditor(AccountInterface $account) {
    return in_array('moody_subsite_editor', $account->getRoles(), TRUE);
  }

  /**
   * Returns the visitor-facing label when available.
   */
  public function label($subsite) {
    return trim((string) $subsite->get('display_name')->value) ?: (string) $subsite->label();
  }

}
