<?php

namespace Drupal\moody_revision_details;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Compares retained revision snapshots, not a speculative editor action log.
 */
final class RevisionSummary {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $account,
  ) {}

  public function build(NodeInterface $revision): array {
    if (!$revision->access('view revision', $this->account)) {
      throw new AccessDeniedHttpException();
    }
    $storage = $this->entityTypeManager->getStorage('node');
    // The preceding displayed-language revision may be on another pager page.
    // Query access is checked on the actual loaded revision below, not bypassed.
    $ids = $storage->getQuery()->accessCheck(FALSE)->allRevisions()
      ->condition('nid', $revision->id())
      ->condition('vid', $revision->getRevisionId(), '<')
      ->condition('langcode', $revision->language()->getId())
      ->condition('revision_translation_affected', 1)
      ->sort('vid', 'DESC')->range(0, 1)->execute();
    $previous = $ids ? $storage->loadRevision(array_key_first($ids)) : NULL;
    if (!$previous || !$previous->hasTranslation($revision->language()->getId())) {
      return ['message' => ['#markup' => $this->t('First available revision in this language. No earlier saved revision remains to compare.')]];
    }
    $previous = $previous->getTranslation($revision->language()->getId());
    if (!$previous->access('view revision', $this->account)) {
      return ['message' => ['#markup' => $this->t('Details are unavailable because you cannot view the preceding revision.')]];
    }

    $changes = [];
    foreach ($this->changedFields($previous, $revision) as $name => $label) {
      if ($name === 'status') {
        $changes[] = $revision->isPublished() ? $this->t('Page published.') : $this->t('Page unpublished.');
      }
      else {
        $changes[] = $this->t('@field changed.', ['@field' => $label]);
      }
    }
    if ($revision->hasField('moderation_state') && $previous->hasField('moderation_state')
      && $revision->get('moderation_state')->access('view', $this->account)
      && $previous->get('moderation_state')->access('view', $this->account)
      && !$revision->get('moderation_state')->equals($previous->get('moderation_state'))) {
      $changes[] = $this->t('Moderation state changed from @before to @after.', [
        '@before' => $previous->get('moderation_state')->value,
        '@after' => $revision->get('moderation_state')->value,
      ]);
    }
    foreach ($revision->getFieldDefinitions() as $name => $definition) {
      if ($name === 'layout_builder__layout' && $previous->hasField($name)) {
        if ($this->canViewLayout($previous) && $this->canViewLayout($revision)) {
          $changes = array_merge($changes, $this->layoutChanges($previous, $revision, $name));
        }
        else {
          $changes[] = $this->t('Layout details are not available with your current Layout Builder access.');
        }
      }
    }
    return [
      'comparison' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Compared with preceding revision @revision in @language.', [
          '@revision' => $previous->getRevisionId(),
          '@language' => $revision->language()->getName(),
        ]),
      ],
      'changes' => $changes ? [
        '#theme' => 'item_list',
        '#items' => $changes,
      ] : ['#markup' => $this->t('No changes to accessible saved page fields or layout were found.')],
      'scope' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Saved changes only—not individual clicks or unsaved edits. Reusable content and default-layout changes made elsewhere are not included.'),
      ],
    ];
  }

  private function canViewLayout(NodeInterface $node): bool {
    // Core intentionally forbids ordinary field access to this internal field.
    // Use the Layout tab's section-storage access policy, never expose raw data
    // or grant access to the field globally. No tempstore drafts are loaded.
    $contexts = [
      'entity' => EntityContext::fromEntity($node),
      'view_mode' => new Context(new ContextDefinition('string'), LayoutBuilderEntityViewDisplay::collectRenderDisplay($node, 'full')->getMode()),
    ];
    $storage = \Drupal::service('plugin.manager.layout_builder.section_storage')->load('overrides', $contexts);
    return $storage && $storage->access('view', $this->account);
  }

  /**
   * Uses Drupal field equality and access; does not expose raw field contents.
   */
  private function changedFields(ContentEntityInterface $before, ContentEntityInterface $after): array {
    $type = $after->getEntityType();
    $skip = array_merge([
      'changed', 'created', 'default_langcode', 'revision_default',
      'revision_translation_affected', 'moderation_state',
    ], array_values($type->getRevisionMetadataKeys()), [
      $type->getKey('id'), $type->getKey('revision'), $type->getKey('uuid'),
      $type->getKey('bundle'), $type->getKey('langcode'),
    ]);
    $changed = [];
    foreach ($after->getFieldDefinitions() as $name => $definition) {
      if (in_array($name, $skip, TRUE) || $definition->isComputed()
        || !$definition->getFieldStorageDefinition()->isRevisionable() || $definition->getType() === 'layout_section'
        || !$before->hasField($name)) {
        continue;
      }
      if ($before->get($name)->access('view', $this->account)
        && $after->get($name)->access('view', $this->account)
        && !$before->get($name)->equals($after->get($name))) {
        $changed[$name] = (string) $definition->getLabel();
      }
    }
    return $changed;
  }

  private function sections(NodeInterface $node, string $field): array {
    $sections = [];
    foreach ($node->get($field) as $item) {
      if ($item->section) {
        $sections[] = $item->section->toArray();
      }
    }
    return $sections;
  }

  private function components(array $sections): array {
    $result = [];
    foreach ($sections as $index => $section) {
      $components = $section['components'];
      uasort($components, static fn(array $a, array $b): int => $a['weight'] <=> $b['weight']);
      foreach ($components as $uuid => $component) {
        $result[$uuid] = $component + ['section' => $index + 1];
      }
    }
    return $result;
  }

  /**
   * Relative order among surviving siblings ignores additions and reweighting.
   */
  private function siblingRanks(array $components, array $other): array {
    $counts = $ranks = [];
    foreach ($components as $uuid => $component) {
      if (!isset($other[$uuid]) || $component['section'] !== $other[$uuid]['section']
        || $component['region'] !== $other[$uuid]['region']) {
        continue;
      }
      $group = $component['section'] . ':' . $component['region'];
      $ranks[$uuid] = $counts[$group] ?? 0;
      $counts[$group] = $ranks[$uuid] + 1;
    }
    return $ranks;
  }

  private function blockLabel(array $component): string {
    $config = $component['configuration'];
    $label = trim((string) ($config['label'] ?? ''));
    if ($label === '') {
      $label = ucwords(str_replace(['inline_block:', '_'], ['', ' '], $config['id'] ?? 'Block'));
    }
    return mb_substr($label, 0, 160);
  }

  private function layoutChanges(NodeInterface $before, NodeInterface $after, string $field): array {
    $old_sections = $this->sections($before, $field);
    $new_sections = $this->sections($after, $field);
    $changes = [];
    if (!$old_sections && $new_sections) {
      $changes[] = $this->t('Page-specific layout added.');
    }
    elseif ($old_sections && !$new_sections) {
      $changes[] = $this->t('Page-specific layout removed; the page now uses its default layout.');
    }
    if (count($old_sections) !== count($new_sections)) {
      $changes[] = $this->t('Layout section count changed from @before to @after.', ['@before' => count($old_sections), '@after' => count($new_sections)]);
    }
    foreach ($new_sections as $index => $section) {
      if (!isset($old_sections[$index])) {
        continue;
      }
      $old = $old_sections[$index];
      unset($section['components'], $old['components']);
      if ($section != $old) {
        $changes[] = $this->t('Layout or settings changed for section @section.', ['@section' => $index + 1]);
      }
    }
    $old = $this->components($old_sections);
    $new = $this->components($new_sections);
    $old_ranks = $this->siblingRanks($old, $new);
    $new_ranks = $this->siblingRanks($new, $old);
    foreach (array_diff_key($old, $new) as $component) {
      $changes[] = $this->t('Block removed: @block (section @section, @region).', [
        '@block' => $this->blockLabel($component), '@section' => $component['section'], '@region' => $component['region'],
      ]);
    }
    foreach ($new as $uuid => $component) {
      $args = ['@block' => $this->blockLabel($component), '@section' => $component['section'], '@region' => $component['region']];
      if (!isset($old[$uuid])) {
        $changes[] = $this->t('Block added: @block (section @section, @region).', $args);
        continue;
      }
      $previous = $old[$uuid];
      if ($previous['section'] !== $component['section'] || $previous['region'] !== $component['region']) {
        $changes[] = $this->t('Block position changed: @block, from section @old_section (@old_region) to section @section (@region).', $args + [
          '@old_section' => $previous['section'], '@old_region' => $previous['region'],
        ]);
      }
      elseif (($old_ranks[$uuid] ?? NULL) !== ($new_ranks[$uuid] ?? NULL)) {
        $changes[] = $this->t('Block order changed: @block within section @section (@region).', $args);
      }
      $old_config = $previous['configuration'];
      $new_config = $component['configuration'];
      unset($old_config['block_revision_id'], $new_config['block_revision_id'], $old_config['block_serialized'], $new_config['block_serialized']);
      if ($old_config != $new_config || $previous['additional'] != $component['additional']) {
        $changes[] = $this->t('Block settings changed: @block.', $args);
      }
      $changes = array_merge($changes, $this->inlineChanges($previous, $component, $before, $after));
    }
    return $changes;
  }

  private function inlineChanges(array $before, array $after, NodeInterface $old_node, NodeInterface $new_node): array {
    $old = $before['configuration'];
    $new = $after['configuration'];
    if (!str_starts_with($new['id'] ?? '', 'inline_block:')) {
      return [];
    }
    $args = ['@block' => $this->blockLabel($after)];
    // Never deserialize saved plugin configuration or run a block plugin here.
    if (!empty($old['block_serialized']) || !empty($new['block_serialized'])) {
      return [$this->t('Historical inline content cannot be compared for @block: it has no usable saved block snapshot.', $args)];
    }
    $old_vid = $old['block_revision_id'] ?? NULL;
    $new_vid = $new['block_revision_id'] ?? NULL;
    if ($old_vid == $new_vid) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('block_content');
    $left = $old_vid ? $storage->loadRevision($old_vid) : NULL;
    $right = $new_vid ? $storage->loadRevision($new_vid) : NULL;
    if (!$left || !$right) {
      return [$this->t('Saved block revision changed for @block; a historical block snapshot is no longer available.', $args)];
    }
    // Inline block access is inherited from the owning node revision.
    $left = clone $left;
    $right = clone $right;
    $left->setAccessDependency($old_node);
    $right->setAccessDependency($new_node);
    $langcode = $new_node->language()->getId();
    if (!$left->hasTranslation($langcode) || !$right->hasTranslation($langcode)) {
      return [$this->t('Saved block revision changed for @block; matching-language block snapshots are unavailable.', $args)];
    }
    $left = $left->getTranslation($langcode);
    $right = $right->getTranslation($langcode);
    if (!$left->access('view', $this->account) || !$right->access('view', $this->account)) {
      return [];
    }
    $fields = $this->changedFields($left, $right);
    if ($fields) {
      return [$this->t('Block content changed: @block (@fields).', $args + ['@fields' => implode(', ', $fields)])];
    }
    return [];
  }

}
