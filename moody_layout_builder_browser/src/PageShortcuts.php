<?php

namespace Drupal\moody_layout_builder_browser;

use Drupal\Core\Form\FormStateInterface;
use Drupal\layout_builder\Form\OverridesEntityForm;
use Drupal\node\NodeInterface;

/**
 * Small, separate page saves inside the existing Layout Builder form.
 */
final class PageShortcuts {

  public static function build(array &$form, FormStateInterface $state): void {
    $object = $state->getFormObject();
    if (!$object instanceof OverridesEntityForm || !$object->getEntity() instanceof NodeInterface) {
      return;
    }
    $node = $object->getEntity();
    if (\Drupal::hasService('workspaces.manager') && \Drupal::service('workspaces.manager')->hasActiveWorkspace()) {
      return;
    }
    if (!$node->access('update') || !$node->isDefaultRevision()) {
      return;
    }
    // Workflow transitions belong to the moderation form, not a status toggle.
    if (\Drupal::hasService('content_moderation.moderation_information') && \Drupal::service('content_moderation.moderation_information')->isModeratedEntity($node)) {
      return;
    }
    $form['actions']['page_shortcuts'] = [
      '#type' => 'container', '#weight' => -20, '#tree' => TRUE,
      '#attributes' => ['class' => ['moody-page-shortcuts']],
    ];
    $group = &$form['actions']['page_shortcuts'];
    $group['version'] = ['#type' => 'hidden', '#default_value' => self::version($node)];
    foreach (['title' => t('Page title'), 'status' => t('Published'), 'path' => t('URL alias')] as $field => $label) {
      if (!$node->hasField($field) || !$node->get($field)->access('edit')) {
        continue;
      }
      $group[$field] = [
        '#type' => 'details', '#title' => $field === 'title' ? $node->label() . ' ✎' : ($field === 'status' ? ($node->isPublished() ? t('Published') : t('Draft')) : $label),
        '#attributes' => ['class' => ['moody-page-shortcut', 'moody-page-shortcut--' . $field]],
        '#open' => (bool) $state->getErrors(),
      ];
      $item = &$group[$field];
      $item['value'] = [
        '#type' => $field === 'status' ? 'checkbox' : 'textfield',
        '#title' => $label,
        '#default_value' => match ($field) { 'title' => $node->label(), 'status' => $node->isPublished(), 'path' => $node->get('path')->alias ?? '' },
      ];
      if ($field !== 'status') {
        $item['value']['#maxlength'] = 255;
      }
      $item['note'] = ['#markup' => '<p class="moody-page-shortcut__note">' . ($field === 'path' ? t('Start with /. Saving sets a manual alias. Layout changes stay unsaved.') : t('Saves this page setting immediately. Layout changes stay unsaved.')) . '</p>'];
      $item['save'] = [
        '#type' => 'submit', '#value' => $field === 'title' ? t('✓ Save title') : t('Save @setting', ['@setting' => strtolower((string) $label)]),
        '#name' => 'moody_save_' . $field, '#moody_field' => $field,
        '#validate' => [[self::class, 'validate']], '#submit' => [[self::class, 'save']],
        '#limit_validation_errors' => [['page_shortcuts']],
      ];
      if ($field === 'title') {
        $item['editor'] = ['#type' => 'container', '#parents' => ['page_shortcuts', 'title'], '#attributes' => ['class' => ['moody-page-title-editor']]];
        foreach (['value', 'note', 'save'] as $key) {
          $item['editor'][$key] = $item[$key];
          unset($item[$key]);
        }
      }
    }
    $form['#attached']['library'][] = 'moody_layout_builder_browser/page_shortcuts';
  }

  private static function version(NodeInterface $node): string {
    return hash('sha256', serialize([$node->getRevisionId(), $node->getChangedTime(), $node->label(), $node->isPublished(), $node->hasField('path') ? $node->get('path')->getValue() : []]));
  }

  public static function validate(array &$form, FormStateInterface $state): void {
    $field = $state->getTriggeringElement()['#moody_field'];
    $original = $state->getFormObject()->getEntity();
    $node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($original->id());
    $langcode = $original->language()->getId();
    if (!$node || !$node->hasTranslation($langcode)) {
      $state->setErrorByName('page_shortcuts', t('This page no longer exists. Reload before trying again.'));
      return;
    }
    $node = $node->getTranslation($langcode);
    if (!$node->access('update') || !$node->hasField($field) || !$node->get($field)->access('edit') || !in_array($field, ['title', 'status', 'path'], TRUE)) {
      $state->setErrorByName('page_shortcuts', t('You do not have access to change this setting.'));
      return;
    }
    if ((\Drupal::hasService('content_moderation.moderation_information') && \Drupal::service('content_moderation.moderation_information')->isModeratedEntity($node)) || !$node->isDefaultRevision() || (\Drupal::hasService('workspaces.manager') && \Drupal::service('workspaces.manager')->hasActiveWorkspace()) || (string) \Drupal::entityTypeManager()->getStorage('node')->getLatestRevisionId($node->id()) !== (string) $node->getRevisionId()) {
      $state->setErrorByName('page_shortcuts', t('Use the Edit tab for this page’s publishing workflow.'));
      return;
    }
    if (!hash_equals(self::version($node), (string) $state->getValue(['page_shortcuts', 'version']))) {
      $state->setErrorByName('page_shortcuts', t('This page changed since you opened it. Reload before saving. Your layout draft is retained.'));
      return;
    }
    $value = $state->getValue(['page_shortcuts', $field, 'value']);
    if ($field === 'path') {
      $value = rtrim(trim((string) $value), '/');
      if ($value === '') {
        $state->setErrorByName('page_shortcuts][path][value', t('Enter an alias starting with /. Use the Edit tab to remove an alias.'));
        return;
      }
      $alias = \Drupal::entityTypeManager()->getStorage('path_alias')->create(['path' => '/node/' . $node->id(), 'alias' => $value, 'langcode' => $langcode]);
      foreach ($alias->validate() as $violation) {
        $state->setErrorByName('page_shortcuts][path][value', $violation->getMessage());
      }
      $path = $node->get('path')->first()->getValue();
      $path['alias'] = $value;
      $path['pathauto'] = 0;
      $node->set('path', $path);
    }
    else {
      if ($field === 'title' && trim((string) $value) === '') {
        $state->setErrorByName('page_shortcuts][title][value', t('Enter a page title.'));
        return;
      }
      $node->set($field, $field === 'title' ? trim((string) $value) : (bool) $value);
      foreach ($node->get($field)->validate() as $violation) {
        $state->setErrorByName('page_shortcuts][' . $field . '][value', $violation->getMessage());
      }
    }
    $state->set('moody_shortcut_node', $node);
  }

  public static function save(array &$form, FormStateInterface $state): void {
    $node = $state->get('moody_shortcut_node');
    $field = $state->getTriggeringElement()['#moody_field'];
    $section_storage = $state->getFormObject()->getSectionStorage();
    $sections = $section_storage->getSections();
    // Save the fresh persisted entity, never the entity containing draft blocks.
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId(\Drupal::currentUser()->id());
    $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $node->setChangedTime(\Drupal::time()->getRequestTime());
    $node->setRevisionLogMessage('Layout toolbar: updated ' . $field . '.');
    $node->save();
    // Keep the working layout but refresh metadata to prevent a later layout
    // save from restoring the old title, status, alias or revision identity.
    $draft = clone $node;
    $draft->set('layout_builder__layout', $sections);
    $section_storage->setContextValue('entity', $draft);
    \Drupal::service('layout_builder.tempstore_repository')->set($section_storage);
    \Drupal::messenger()->addStatus(t('Page setting saved. Layout changes are still separate.'));
    $state->setRedirectUrl($section_storage->getLayoutBuilderUrl());
  }

}
