<?php

namespace Drupal\moody_subsite\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\moody_subsite\Entity\MoodySubsiteInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Adds an existing user to the subsite's existing Workbench role. */
class AddSubsiteUserForm extends FormBase {

  public function getFormId() {
    return 'moody_subsite_add_user';
  }

  /** Resolve only one existing, non-administrative role for every section. */
  public function editorRoles(MoodySubsiteInterface $subsite) {
    $manager = \Drupal::entityTypeManager();
    $base = $manager->getStorage('user_role')->load('moody_subsite_editor');
    if (!$base || $base->isAdmin() || $base->hasPermission('administer users') || $base->hasPermission('administer permissions') || !\Drupal::hasService('workbench_access.role_section_storage')) {
      throw new \RuntimeException('Subsite Editor or Workbench Access is not configured.');
    }
    $scheme = $manager->getStorage('access_scheme')->load('directory_structure');
    $terms = array_column($subsite->get('directory_structure')->getValue(), 'target_id');
    if (!$scheme || !$terms) {
      throw new \RuntimeException('This subsite needs a Directory Structure assignment.');
    }
    $ids = NULL;
    foreach ($terms as $term) {
      $assigned = \Drupal::service('workbench_access.role_section_storage')->getRoles($scheme, $term);
      $ids = $ids === NULL ? $assigned : array_intersect($ids, $assigned);
    }
    $roles = $manager->getStorage('user_role')->loadMultiple(array_diff($ids, ['anonymous', 'authenticated', 'moody_subsite_editor']));
    $roles = array_filter($roles, static function ($role) {
      return !$role->isAdmin() && !$role->hasPermission('administer users') && !$role->hasPermission('administer permissions');
    });
    if (count($roles) !== 1) {
      throw new \RuntimeException('No unique subsite-specific editor role is configured. Ask an administrator to review Workbench assignments.');
    }
    return [$base, reset($roles)];
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?MoodySubsiteInterface $moody_subsite = NULL) {
    $form_state->set('subsite_id', $moody_subsite->id());
    try {
      $roles = $this->editorRoles($moody_subsite);
    }
    catch (\RuntimeException $e) {
      $form['problem'] = ['#plain_text' => $e->getMessage()];
      return $form;
    }
    $form['summary'] = ['#markup' => '<p>' . $this->t('Add an existing user to @subsite. This adds @base and @role without removing any existing roles. The user receives the Workbench sections already assigned to that role.', ['@subsite' => $moody_subsite->label(), '@base' => $roles[0]->label(), '@role' => $roles[1]->label()]) . '</p>'];
    $form['user'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('User'),
      '#target_type' => 'user',
      '#required' => TRUE,
      '#description' => $this->t('Select an existing active account. This does not create or unblock accounts.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Add user to subsite'), '#button_type' => 'primary'];
    $form_state->set('subsite_editor_roles', array_map(static fn($role) => $role->id(), $roles));
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $user = \Drupal::entityTypeManager()->getStorage('user')->load($form_state->getValue('user'));
    if (!$user || !$user->id() || !$user->isActive() || !$user->access('update', $this->currentUser())) {
      $form_state->setErrorByName('user', $this->t('Choose an existing active user.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    if (!$this->currentUser()->hasPermission('administer users') || !$this->currentUser()->hasPermission('administer moody subsite entities')) {
      throw new AccessDeniedHttpException();
    }
    $manager = \Drupal::entityTypeManager();
    $subsite = $manager->getStorage('moody_subsite')->load($form_state->get('subsite_id'));
    $user = $manager->getStorage('user')->load($form_state->getValue('user'));
    if (!$subsite || !$user || !$user->id() || !$user->isActive() || !$user->access('update', $this->currentUser())) {
      throw new \RuntimeException('The subsite or active user is no longer available.');
    }
    $roles = $this->editorRoles($subsite);
    if (array_map(static fn($role) => $role->id(), $roles) !== $form_state->get('subsite_editor_roles')) {
      throw new \RuntimeException('Workbench roles changed. Reload the form and review the assignment again.');
    }
    foreach ($roles as $role) {
      $user->addRole($role->id());
    }
    $user->save();
    \Drupal::logger('moody_subsite')->notice('Administrator @actor added user @uid to subsite @subsite.', ['@actor' => $this->currentUser()->id(), '@uid' => $user->id(), '@subsite' => $subsite->id()]);
    $this->messenger()->addStatus($this->t('@user can now edit @subsite.', ['@user' => $user->getDisplayName(), '@subsite' => $subsite->label()]));
    $form_state->setRedirect('entity.moody_subsite.canonical', ['moody_subsite' => $subsite->id()]);
  }

}
