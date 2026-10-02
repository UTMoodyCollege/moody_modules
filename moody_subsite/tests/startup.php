<?php

// Local/Test only: drush php:script tests/startup.php. All fixtures roll back.
use Drupal\Core\Form\FormState;
use Drupal\moody_subsite\Form\SubsiteStartupForm;

$check = static function ($condition, $message) {
  if (!$condition) {
    throw new \RuntimeException($message);
  }
};
$manager = \Drupal::entityTypeManager();
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo($manager->getStorage('user')->load(1));
$transaction = \Drupal::database()->startTransaction();
try {
  foreach (['Bad', 'Title | //outside', 'Title | /admin/foo', "A | /same\nB | /SAME"] as $input) {
    try {
      SubsiteStartupForm::parsePages($input);
      throw new \RuntimeException('Invalid starter page input was accepted.');
    }
    catch (\InvalidArgumentException) {}
  }
  $check(count(SubsiteStartupForm::parsePages("Home | /startup-smoke\nAbout | /startup-smoke/about")) === 2, 'Valid page list rejected.');
  $logo = NULL;
  foreach ($manager->getStorage('media')->loadByProperties(['bundle' => 'utexas_image']) as $media) {
    if ($media->hasField('field_utexas_media_image') && ($file = $media->get('field_utexas_media_image')->entity) && is_file($file->getFileUri())) {
      $logo = $media;
      break;
    }
  }
  $check($logo !== NULL, 'This test needs one existing image media file.');
  $term = $manager->getStorage('taxonomy_term')->create(['vid' => 'directory_structure', 'name' => 'Startup rollback fixture']);
  $term->save();
  $user = $manager->getStorage('user')->create(['name' => 'startup_fixture_' . bin2hex(random_bytes(5)), 'status' => 1]);
  $user->save();
  $subsite = $manager->getStorage('moody_subsite')->create(['name' => 'Startup fixture', 'display_name' => 'Startup fixture', 'status' => 0,
    'base_url' => '/startup-smoke', 'directory_structure' => $term->id(), 'hero' => ['media' => $logo->id()], 'custom_logo' => ['media' => $logo->id(), 'size' => 'medium_logo']]);
  $form = SubsiteStartupForm::create(\Drupal::getContainer());
  $form->setEntityTypeManager($manager);
  $form->setEntity($subsite);
  $plan = $form->makePlan($subsite, "Home | /startup-smoke\nAbout | /startup-smoke/about", [$user->id()]);
  $check($subsite->isNew() && !$manager->getStorage('user_role')->load($plan['role']), 'Preview mutated storage.');
  $state = (new FormState())->set('startup_plan', $plan);
  $build = [];
  $form->executeStartup($build, $state);
  $check((bool) $state->get('startup_result'), 'Execution did not return a results report.');
  $role = $manager->getStorage('user_role')->load($plan['role']);
  $check($role && !$role->getPermissions(), 'Assignment role unexpectedly grants permissions.');
  $user = $manager->getStorage('user')->load($user->id());
  $check($user->hasRole('moody_subsite_editor') && $user->hasRole($plan['role']), 'User roles not assigned.');
  $scheme = $manager->getStorage('access_scheme')->load('directory_structure');
  $sections = \Drupal::service('workbench_access.user_section_storage')->getUserSections($scheme, $user);
  $check(in_array($term->id(), $sections), 'Workbench role-section assignment missing.');
  $nodes = $manager->getStorage('node')->loadByProperties(['type' => 'moody_subsite_page', 'field_moody_url_generator' => $term->id()]);
  $check(count($nodes) === 2, 'Starter pages not created.');
  foreach ($nodes as $node) {
    $check(!$node->isPublished() && $node->access('update', $user), 'Editor cannot edit the draft page.');
    $check(!$node->access('view', new \Drupal\Core\Session\AnonymousUserSession()), 'Draft exposed anonymously.');
    $check(str_starts_with($node->get('path')->alias, '/startup-smoke'), 'Explicit page alias missing.');
  }
  try {
    $form->makePlan($subsite, '', []);
    throw new \RuntimeException('Duplicate setup accepted.');
  }
  catch (\InvalidArgumentException) {}
  $switcher->switchTo(new \Drupal\Core\Session\AnonymousUserSession());
  try {
    $form->makePlan($subsite, '', []);
    throw new \LogicException('Unauthorized setup accepted.');
  }
  catch (\RuntimeException $e) {
    $check(str_contains($e->getMessage(), 'permission'), 'Wrong permission rejection.');
  }
  finally {
    $switcher->switchBack();
  }
  echo "Preview is read-only; execution creates scoped roles and editable drafts; duplicate and unauthorized setup rejected.\n";
}
finally {
  $transaction->rollBack();
  foreach (['user_role', 'user', 'node', 'moody_subsite', 'taxonomy_term', 'path_alias', 'section_association'] as $type) {
    $manager->getStorage($type)->resetCache();
  }
  \Drupal::service('cache_tags.invalidator')->invalidateTags(['config:user.role.' . ($plan['role'] ?? ''), 'workbench_access_view']);
  $switcher->switchBack();
}
