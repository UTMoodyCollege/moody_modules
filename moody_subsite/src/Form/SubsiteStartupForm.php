<?php

namespace Drupal\moody_subsite\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;

/**
 * Reuses the native subsite widgets with a reviewed, atomic startup operation.
 */
class SubsiteStartupForm extends MoodySubsiteForm {

  public function buildForm(array $form, FormStateInterface $form_state) {
    if ($result = $form_state->get('startup_result')) {
      $form['result'] = ['#theme' => 'item_list', '#title' => $this->t('Subsite setup complete'), '#items' => $result];
      return $form;
    }
    if ($plan = $form_state->get('startup_plan')) {
      $items = [
        $this->t('Create subsite: @name. Homepage: @url. Published: @status.', ['@name' => $plan['name'], '@url' => $plan['home'], '@status' => $plan['published'] ? 'yes' : 'no']),
        $this->t('Use directory section: @term (#@id). Existing content in this section will also be editable by assigned editors.', ['@term' => $plan['term_label'], '@id' => $plan['term']]),
        $this->t('Use logo: @logo.', ['@logo' => $plan['logo']]),
        $this->t('Create permission-free assignment role @role (@label), assigned only to this Workbench section. Existing roles and section assignments are preserved.', ['@role' => $plan['role'], '@label' => $plan['role_label']]),
        $this->t('Add the common Moody Subsite Editor role and the new assignment role to selected users. This does not remove any existing access.'),
      ];
      foreach ($plan['pages'] as $page) {
        $items[] = $this->t('Create draft page: @title at @alias.', ['@title' => $page['title'], '@alias' => $page['alias']]);
      }
      foreach ($plan['users'] as $id => $label) {
        $items[] = $this->t('Assign editor: @label (#@id).', ['@label' => $label, '@id' => $id]);
      }
      $items[] = $this->t('No pages will be published. With no starter page at the homepage URL, create that page separately before launch.');
      $form['preview'] = ['#theme' => 'item_list', '#title' => $this->t('Review the setup plan — no setup changes saved'), '#items' => $items];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['execute'] = ['#type' => 'submit', '#value' => $this->t('Confirm and create subsite'), '#validate' => [], '#submit' => ['::executeStartup']];
      $form['actions']['back'] = ['#type' => 'submit', '#value' => $this->t('Back to settings'), '#validate' => [], '#submit' => ['::backToSettings']];
      return $form;
    }
    $form = parent::buildForm($form, $form_state);
    $form['subsite_edit_intro']['title']['#value'] = $this->t('Set up a new subsite');
    $form['subsite_edit_intro']['description']['#markup'] = $this->t('Choose branding and one directory section, then preview the startup steps before creating anything.');
    $form['administration']['directory_structure']['#access'] = TRUE;
    $form['startup_pages'] = [
      '#type' => 'textarea', '#title' => $this->t('Optional starter pages'),
      '#description' => $this->t('One per line: Page title | /desired/path. Pages are created as drafts. Use the homepage URL for the home page. Maximum 20 pages.'),
      '#default_value' => $form_state->get('startup_pages') ?? '',
    ];
    $form['startup_users'] = [
      '#type' => 'entity_autocomplete', '#target_type' => 'user', '#tags' => TRUE,
      '#title' => $this->t('Optional initial editors'),
      '#description' => $this->t('Select existing active accounts. This tool does not create or unblock accounts.'),
      '#default_value' => $this->entityTypeManager->getStorage('user')->loadMultiple($form_state->get('startup_users') ?? []),
    ];
    $form['actions'] = ['#type' => 'actions', '#weight' => 100, 'preview' => ['#type' => 'submit', '#button_type' => 'primary', '#value' => $this->t('Preview setup'), '#validate' => ['::validateStartup'], '#submit' => ['::previewStartup']]];
    return $form;
  }

  public function validateStartup(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    if ($form_state->hasAnyErrors()) {
      return;
    }
    try {
      $plan = $this->makePlan($this->buildEntity($form, $form_state), (string) $form_state->getValue('startup_pages'), array_column($form_state->getValue('startup_users') ?? [], 'target_id'));
      $form_state->set('startup_candidate', $plan);
    }
    catch (\Throwable $e) {
      $form_state->setErrorByName('startup_pages', $e->getMessage());
    }
  }

  public function previewStartup(array &$form, FormStateInterface $form_state) {
    $plan = $form_state->get('startup_candidate');
    $this->entity = $this->buildEntity($form, $form_state);
    $form_state->set('startup_plan', $plan)->set('startup_pages', $form_state->getValue('startup_pages'))
      ->set('startup_users', array_keys($plan['users']))->setRebuild();
  }

  public function backToSettings(array &$form, FormStateInterface $form_state) {
    $form_state->set('startup_plan', NULL)->setRebuild();
  }

  /**
   * Revalidates the complete plan before preview and again before mutation.
   */
  public function makePlan($subsite, string $page_input, array $user_ids): array {
    foreach (['add moody subsite entities', 'administer permissions', 'administer users'] as $permission) {
      if (!$this->account->hasPermission($permission)) {
        throw new \RuntimeException('You do not have permission to set up subsites and assign editors.');
      }
    }
    $manager = $this->entityTypeManager;
    if (!$manager->hasDefinition('access_scheme') || !$manager->getStorage('access_scheme')->load('directory_structure')
      || !$manager->getStorage('user_role')->load('moody_subsite_editor')) {
      throw new \RuntimeException('The Directory Structure Workbench scheme and Moody Subsite Editor role must already be configured.');
    }
    $scheme = $manager->getStorage('access_scheme')->load('directory_structure');
    $scheme_settings = $scheme->get('scheme_settings');
    foreach ([['entity_type' => 'moody_subsite', 'bundle' => 'moody_subsite', 'field' => 'directory_structure'],
      ['entity_type' => 'node', 'bundle' => 'moody_subsite_page', 'field' => 'field_moody_url_generator']] as $field) {
      if (!in_array($field, $scheme_settings['fields'] ?? [])) {
        throw new \RuntimeException('The Workbench directory scheme must cover both subsites and subsite pages.');
      }
    }
    if (!$subsite->isNew() || count($subsite->get('directory_structure')) !== 1) {
      throw new \InvalidArgumentException('Choose exactly one directory section for this new subsite.');
    }
    $term_id = (int) $subsite->get('directory_structure')->target_id;
    $term = $manager->getStorage('taxonomy_term')->load($term_id);
    if (!$term || $term->bundle() !== 'directory_structure' || !$term->access('view', $this->account)) {
      throw new \InvalidArgumentException('Select an accessible Directory Structure term.');
    }
    if ($manager->getStorage('moody_subsite')->loadByProperties(['directory_structure' => $term_id])) {
      throw new \InvalidArgumentException('This directory section already belongs to a subsite. Choose an unused section.');
    }
    if ($manager->getStorage('moody_subsite')->loadByProperties(['base_url' => $subsite->get('base_url')->value])) {
      throw new \InvalidArgumentException('Another subsite already uses this homepage URL.');
    }
    $role_id = 'moody_subsite_' . $term_id;
    if ($manager->getStorage('user_role')->load($role_id)) {
      throw new \InvalidArgumentException('The assignment role for this section already exists. Review the existing setup first.');
    }
    $logo = $subsite->get('custom_logo')->first()?->getValue() ?? [];
    if (!empty($logo['svg_logo'])) {
      $image = $manager->getStorage('file')->load((int) $logo['svg_logo']);
      if (!$image || $image->getMimeType() !== 'image/svg+xml') {
        throw new \InvalidArgumentException('Select a valid SVG logo file.');
      }
      $logo_label = 'SVG file #' . $image->id();
    }
    else {
      $media = $manager->getStorage('media')->load((int) ($logo['media'] ?? 0));
      if (!$media || !$media->access('view', $this->account) || !$media->hasField('field_utexas_media_image') || $media->get('field_utexas_media_image')->isEmpty()) {
        throw new \InvalidArgumentException('Select an accessible image media logo.');
      }
      $image = $media->get('field_utexas_media_image')->entity;
      $logo_label = 'image media #' . $media->id();
    }
    if (!$image || !is_file($image->getFileUri())) {
      throw new \InvalidArgumentException('The selected logo file is unavailable.');
    }
    $violations = $subsite->validate();
    if ($violations->count()) {
      throw new \InvalidArgumentException((string) $violations->get(0)->getMessage());
    }
    $pages = static::parsePages($page_input);
    foreach ($pages as $page) {
      if ($manager->getStorage('path_alias')->loadByProperties(['alias' => $page['alias']])) {
        throw new \InvalidArgumentException('A requested starter page URL already exists: ' . $page['alias']);
      }
      $node = $manager->getStorage('node')->create(['type' => 'moody_subsite_page', 'title' => $page['title'], 'status' => 0, 'field_moody_url_generator' => $term_id]);
      if (!$manager->getAccessControlHandler('node')->createAccess('moody_subsite_page', $this->account) || $node->validate()->count()) {
        throw new \InvalidArgumentException('Starter pages cannot be created with this site configuration or your permissions.');
      }
    }
    if (count($user_ids) > 100) {
      throw new \InvalidArgumentException('Select no more than 100 initial editors.');
    }
    $users = [];
    foreach (array_unique($user_ids) as $id) {
      $user = $manager->getStorage('user')->load($id);
      if (!$user || !$user->isActive() || !$user->access('update', $this->account)) {
        throw new \InvalidArgumentException('Select existing active users you may administer.');
      }
      $users[$user->id()] = $user->getAccountName();
    }
    return ['entity' => $subsite->toArray(), 'name' => $subsite->get('display_name')->value, 'home' => $subsite->get('base_url')->value,
      'published' => $subsite->isPublished(), 'term' => $term_id, 'term_label' => $term->label(), 'logo' => $logo_label,
      'role' => $role_id, 'role_label' => mb_substr($subsite->get('display_name')->value, 0, 247) . ' Editors', 'pages' => $pages, 'users' => $users];
  }

  public static function parsePages(string $input): array {
    $pages = [];
    foreach (preg_split('/\R/u', trim($input)) as $line) {
      if (trim($line) === '') {
        continue;
      }
      $parts = array_map('trim', explode('|', $line));
      if (count($parts) !== 2 || $parts[0] === '' || mb_strlen($parts[0]) > 255
        || mb_strlen($parts[1]) > 255 || !preg_match('~^/(?!/)[a-zA-Z0-9/_-]+$~D', $parts[1])
        || preg_match('~^/(admin|user|node|media|taxonomy|system)(/|$)~i', $parts[1]) || isset($pages[strtolower($parts[1])]) || count($pages) >= 20) {
        throw new \InvalidArgumentException('Enter up to 20 unique starter pages as Title | /path (letters, numbers, hyphens, underscores and slashes).');
      }
      $pages[strtolower($parts[1])] = ['title' => $parts[0], 'alias' => $parts[1]];
    }
    return array_values($pages);
  }

  public function executeStartup(array &$form, FormStateInterface $form_state) {
    $lock = \Drupal::lock();
    if (!$lock->acquire('moody_subsite.startup', 120)) {
      $this->messenger()->addError($this->t('Another setup is running. Try again shortly.'));
      return;
    }
    $transaction = NULL;
    try {
      $stored = $form_state->get('startup_plan');
      if (!$stored) {
        throw new \RuntimeException('Preview the setup before executing it.');
      }
      $subsite = $this->entityTypeManager->getStorage('moody_subsite')->create($stored['entity']);
      $plan = $this->makePlan($subsite, implode("\n", array_map(fn($page) => $page['title'] . ' | ' . $page['alias'], $stored['pages'])), array_keys($stored['users']));
      if ($plan !== $stored) {
        throw new \RuntimeException('The setup inputs changed since preview. Go back and preview again.');
      }
      $transaction = \Drupal::database()->startTransaction();
      $subsite->save();
      $role = $this->entityTypeManager->getStorage('user_role')->create(['id' => $plan['role'], 'label' => $plan['role_label'], 'permissions' => []]);
      $role->save();
      $scheme = $this->entityTypeManager->getStorage('access_scheme')->load('directory_structure');
      \Drupal::service('workbench_access.role_section_storage')->addRole($scheme, $role->id(), [(string) $plan['term']]);
      $result = [$subsite->toLink()->toRenderable(), $this->t('Created Workbench assignment role @role for directory section @section.', ['@role' => $role->label(), '@section' => $plan['term_label']])];
      foreach ($plan['pages'] as $page) {
        $node = $this->entityTypeManager->getStorage('node')->create(['type' => 'moody_subsite_page', 'title' => $page['title'], 'status' => 0,
          'field_moody_url_generator' => $plan['term'], 'path' => ['alias' => $page['alias'], 'pathauto' => 0]]);
        $node->save();
        $result[] = Link::fromTextAndUrl($this->t('Draft: @title — @alias', ['@title' => $page['title'], '@alias' => $page['alias']]), $node->toUrl('edit-form'))->toRenderable();
      }
      foreach (array_keys($plan['users']) as $id) {
        $user = $this->entityTypeManager->getStorage('user')->load($id);
        $user->addRole('moody_subsite_editor')->addRole($role->id())->save();
        $result[] = $this->t('Assigned editor @user.', ['@user' => $plan['users'][$id]]);
      }
      \Drupal::logger('moody_subsite')->notice('Subsite @id startup completed by user @uid; section @section; @pages drafts; @users editors.', ['@id' => $subsite->id(), '@uid' => $this->account->id(), '@section' => $plan['term'], '@pages' => count($plan['pages']), '@users' => count($plan['users'])]);
      unset($transaction);
      $form_state->set('startup_result', $result)->set('startup_plan', NULL)->setRebuild();
    }
    catch (\Throwable $e) {
      if ($transaction) {
        $transaction->rollBack();
      }
      foreach (['moody_subsite', 'user_role', 'node', 'user', 'path_alias', 'section_association'] as $type) {
        if ($this->entityTypeManager->hasDefinition($type)) {
          $this->entityTypeManager->getStorage($type)->resetCache();
        }
      }
      \Drupal::service('cache_tags.invalidator')->invalidateTags(['config:user.role.' . ($stored['role'] ?? ''), 'workbench_access_view']);
      \Drupal::logger('moody_subsite')->error('Subsite startup failed: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addError($this->t('Setup did not complete: @message. No partial setup was retained.', ['@message' => $e->getMessage()]));
      $form_state->setRebuild();
    }
    finally {
      $lock->release('moody_subsite.startup');
    }
  }

}
