<?php

namespace Drupal\moody_subsite;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Previews and executes access-checked Moody AI subsite actions.
 */
class SubsiteAiActionManager {

  /**
   * Simple subsite fields the assistant may update directly.
   */
  const EDITABLE_SETTINGS = [
    'name' => 'Administrative name',
    'display_name' => 'Display name',
    'base_url' => 'Homepage URL',
    'title_display_option' => 'Page title style',
    'subsite_footer_text' => 'Footer text',
    'give_link' => 'Give link',
    'subsite_home_hero' => 'Special homepage hero styling',
    'hide_all_social_accounts' => 'Hide all social accounts',
  ];

  /**
   * Constructs the subsite action manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SubsiteEditorContext $editorContext,
    protected Connection $database
  ) {}

  /**
   * Builds a normalized, non-mutating action preview.
   */
  public function prepareAction(array $plan, AccountInterface $account, array $uploaded_assets = [], $allow_noop = FALSE) {
    $subsite = $this->loadManageableSubsite((int) ($plan['subsite_id'] ?? 0), $account);
    $working = clone $subsite;
    $payload = [
      'subsite_id' => (int) $subsite->id(),
      'expected_state_hash' => $this->subsiteStateHash($subsite),
      'summary' => trim((string) ($plan['summary'] ?? '')),
      'settings' => [],
      'replace_menu' => FALSE,
      'menu_items' => [],
      'replace_logo' => FALSE,
      'logo_media_id' => 0,
      'logo_size' => '',
      'new_page' => [],
    ];
    $changes = [];

    foreach ($this->normalizeSettings($plan['settings'] ?? []) as $field_name => $new_value) {
      $old_value = $this->scalarFieldValue($subsite, $field_name);
      if ((string) $old_value === (string) $new_value) {
        continue;
      }
      $working->set($field_name, $new_value);
      $payload['settings'][$field_name] = $new_value;
      $changes[] = $this->change(
        static::EDITABLE_SETTINGS[$field_name],
        $this->displaySettingValue($field_name, $old_value),
        $this->displaySettingValue($field_name, $new_value)
      );
    }

    if (!empty($plan['replace_menu'])) {
      $menu_items = $this->normalizeMenuItems($plan['menu_items'] ?? []);
      $current_menu = $this->normalizeMenuItems($subsite->get('subsite_nav')->getValue());
      if ($menu_items !== $current_menu) {
        $working->set('subsite_nav', $menu_items);
        $payload['replace_menu'] = TRUE;
        $payload['menu_items'] = $menu_items;
        $changes[] = $this->change(
          'Subsite navigation',
          $this->formatMenuItems($current_menu),
          $this->formatMenuItems($menu_items),
          'Replace the navigation with the reviewed nested menu.'
        );
      }
    }

    if (!empty($plan['replace_logo']) || !empty($plan['logo_media_id'])) {
      $logo = $this->normalizeLogo($plan, $uploaded_assets, $account);
      $current_logo = $subsite->get('custom_logo')->first();
      $current_media_id = $current_logo ? (int) $current_logo->media : 0;
      $current_size = $current_logo && $current_logo->size ? (string) $current_logo->size : 'medium_logo';
      $current_svg_id = $current_logo ? (int) $current_logo->svg_logo : 0;
      if ($logo['media_id'] !== $current_media_id || $logo['size'] !== $current_size || $current_svg_id) {
        $working->set('custom_logo', [[
          'media' => (string) $logo['media_id'],
          'size' => $logo['size'],
          'svg_logo' => 0,
        ]]);
        $payload['replace_logo'] = TRUE;
        $payload['logo_media_id'] = $logo['media_id'];
        $payload['logo_size'] = $logo['size'];
        $changes[] = $this->change(
          'Custom logo',
          $this->mediaLabel($current_media_id) ?: ($current_svg_id ? 'Current SVG logo' : 'No logo'),
          $logo['label'] . ' (' . str_replace('_', ' ', $logo['size']) . ')',
          'Replace the logo with the selected image.'
        );
      }
    }

    if ($payload['settings'] || $payload['replace_menu'] || $payload['replace_logo']) {
      $this->assertValidEntity($working, 'The proposed subsite changes are invalid');
    }

    if (!empty($plan['new_page']) && is_array($plan['new_page'])) {
      $page = $this->normalizeNewPage($plan['new_page'], $subsite, $account);
      $payload['new_page'] = $page['payload'];
      $changes[] = $this->change(
        'New subsite page',
        'No page will be created',
        sprintf('Draft page “%s” in %s', $page['payload']['title'], $page['term_label']),
        'Create a new unpublished Moody Subsite Page using the selected Moody URL Generator group.'
      );
    }

    if (!$changes && !$allow_noop) {
      throw new \InvalidArgumentException('The subsite request did not contain a new setting, menu, logo, or page to preview.');
    }

    $summary = $payload['summary'] ?: sprintf('Review the proposed changes to %s.', $this->editorContext->label($subsite));
    return [
      'subsite_label' => $this->editorContext->label($subsite),
      'summary' => $summary,
      'changes' => $changes,
      'payload' => $payload,
    ];
  }

  /**
   * Returns detailed prompt context for one already access-checked target.
   */
  public function actionContext($subsite_id, AccountInterface $account) {
    $subsite = $this->loadManageableSubsite((int) $subsite_id, $account);
    return $this->editorContext->manageTargetContext($subsite, $account, TRUE);
  }

  /**
   * Rechecks and executes an approved action.
   */
  public function executeAction(array $action, AccountInterface $account) {
    $subsite = $this->loadManageableSubsite((int) ($action['subsite_id'] ?? 0), $account);
    $expected_state_hash = (string) ($action['expected_state_hash'] ?? '');
    if ($expected_state_hash === '' || !hash_equals($expected_state_hash, $this->subsiteStateHash($subsite))) {
      throw new \InvalidArgumentException('This subsite changed after the preview was prepared. Ask Moody AI to prepare a fresh preview before applying it.');
    }

    $assets = [];
    if (!empty($action['logo_media_id'])) {
      $assets[] = [
        'asset_type' => 'image',
        'media_id' => (int) $action['logo_media_id'],
      ];
    }
    $proposal = $this->prepareAction($action, $account, $assets, TRUE);
    $payload = $proposal['payload'];
    $subsite = $this->loadManageableSubsite((int) $payload['subsite_id'], $account);
    $transaction = $this->database->startTransaction();
    $created_page = NULL;

    try {
      if ($payload['settings']) {
        foreach ($payload['settings'] as $field_name => $value) {
          $subsite->set($field_name, $value);
        }
      }
      if ($payload['replace_menu']) {
        $subsite->set('subsite_nav', $payload['menu_items']);
      }
      if ($payload['replace_logo']) {
        $subsite->set('custom_logo', [[
          'media' => (string) $payload['logo_media_id'],
          'size' => $payload['logo_size'],
          'svg_logo' => 0,
        ]]);
      }
      if ($payload['settings'] || $payload['replace_menu'] || $payload['replace_logo']) {
        $this->assertValidEntity($subsite, 'The approved subsite changes are no longer valid');
        $subsite->save();
      }

      if ($payload['new_page']) {
        $created_page = $this->buildNewPage($payload['new_page'], $subsite, $account);
        $created_page->save();
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }

    $parts = [];
    if ($payload['settings'] || $payload['replace_menu'] || $payload['replace_logo']) {
      $parts[] = sprintf('Updated %s.', $this->editorContext->label($subsite));
    }
    if ($created_page) {
      $parts[] = sprintf('Created the unpublished subsite page “%s”.', $created_page->label());
    }
    if (!$parts) {
      $parts[] = sprintf('%s already matches the approved changes.', $this->editorContext->label($subsite));
    }

    $result_entity = $created_page ?: $subsite;
    $url = $result_entity->access('update', $account)
      ? $result_entity->toUrl('edit-form')->toString()
      : $result_entity->toUrl()->toString();

    return [
      'message' => implode(' ', $parts),
      'subsite_id' => (int) $subsite->id(),
      'created_page_id' => $created_page ? (int) $created_page->id() : 0,
      'result_link' => [
        'url' => $url,
        'label' => $created_page ? 'Edit new subsite page' : 'View subsite',
      ],
    ];
  }

  /**
   * Loads a subsite and enforces both entity and assignment access.
   */
  protected function loadManageableSubsite($subsite_id, AccountInterface $account) {
    if (!$subsite_id || !$this->entityTypeManager->hasDefinition('moody_subsite')) {
      throw new \InvalidArgumentException('Select a valid subsite before continuing.');
    }
    $subsite = $this->entityTypeManager->getStorage('moody_subsite')->load($subsite_id);
    if (!$subsite || !$subsite->access('update', $account)) {
      throw new \InvalidArgumentException('You do not have permission to update that subsite.');
    }

    $is_restricted_editor = in_array('moody_subsite_editor', $account->getRoles(), TRUE)
      && (int) $account->id() !== 1
      && !$account->hasPermission('administer moody subsite entities');
    if ($is_restricted_editor && !isset($this->editorContext->assignedSubsites($account)[$subsite_id])) {
      throw new \InvalidArgumentException('That subsite is not assigned to your account.');
    }

    return $subsite;
  }

  /**
   * Normalizes the allowlisted scalar settings.
   */
  protected function normalizeSettings($settings) {
    if (!is_array($settings)) {
      return [];
    }
    $normalized = [];
    foreach (static::EDITABLE_SETTINGS as $field_name => $label) {
      if (!array_key_exists($field_name, $settings) || $settings[$field_name] === NULL) {
        continue;
      }
      $value = $settings[$field_name];
      if (in_array($field_name, ['subsite_home_hero', 'hide_all_social_accounts'], TRUE)) {
        $normalized[$field_name] = $this->normalizeBoolean($value);
        continue;
      }
      if (!is_scalar($value)) {
        throw new \InvalidArgumentException(sprintf('%s must be a single text value.', $label));
      }
      $value = trim((string) $value);
      $maximum = $field_name === 'subsite_footer_text' ? 5000 : 255;
      if (mb_strlen($value) > $maximum) {
        throw new \InvalidArgumentException(sprintf('%s is too long.', $label));
      }
      if (in_array($field_name, ['name', 'display_name', 'base_url', 'title_display_option'], TRUE) && $value === '') {
        throw new \InvalidArgumentException(sprintf('%s cannot be empty.', $label));
      }
      if ($field_name === 'title_display_option' && !in_array($value, ['1', '2', '3'], TRUE)) {
        throw new \InvalidArgumentException('The selected page title style is invalid.');
      }
      if (in_array($field_name, ['base_url', 'give_link'], TRUE) && $value !== '' && !$this->isAllowedLink($value)) {
        throw new \InvalidArgumentException(sprintf('%s must be an internal path or a full HTTP(S) URL.', $label));
      }
      $normalized[$field_name] = $value;
    }
    return $normalized;
  }

  /**
   * Normalizes a complete nested subsite menu.
   */
  protected function normalizeMenuItems($items) {
    if (!is_array($items)) {
      throw new \InvalidArgumentException('The proposed subsite navigation is invalid.');
    }
    if (count($items) > 50) {
      throw new \InvalidArgumentException('A subsite navigation preview may contain at most 50 links.');
    }
    $normalized = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        throw new \InvalidArgumentException('Every subsite navigation item needs a title and link.');
      }
      if (!is_scalar($item['title'] ?? '') || !is_scalar($item['link'] ?? '')) {
        throw new \InvalidArgumentException('Every subsite navigation title and link must be text.');
      }
      $title = trim((string) ($item['title'] ?? ''));
      $link = trim((string) ($item['link'] ?? ''));
      if ($title === '' || $link === '' || mb_strlen($title) > 255 || mb_strlen($link) > 255) {
        throw new \InvalidArgumentException('Every subsite navigation item needs a title and link no longer than 255 characters.');
      }
      if (!$this->isAllowedLink($link)) {
        throw new \InvalidArgumentException('Every subsite navigation link must be an internal path or a full HTTP(S) URL.');
      }
      $is_child = $this->normalizeBoolean($item['is_child'] ?? FALSE);
      if ($is_child && !$normalized) {
        throw new \InvalidArgumentException('The first subsite navigation item cannot be a child link.');
      }
      $normalized[] = [
        'title' => $title,
        'link' => $link,
        'is_child' => $is_child,
      ];
    }
    return $normalized;
  }

  /**
   * Resolves one selected uploaded image to a Media entity.
   */
  protected function normalizeLogo(array $plan, array $uploaded_assets, AccountInterface $account) {
    $allowed = [];
    foreach ($uploaded_assets as $asset) {
      if (($asset['asset_type'] ?? '') === 'image' && !empty($asset['media_id'])) {
        $allowed[(int) $asset['media_id']] = TRUE;
      }
    }
    $media_id = (int) ($plan['logo_media_id'] ?? 0);
    if (!$media_id && count($allowed) === 1) {
      $media_id = (int) array_key_first($allowed);
    }
    if (!$media_id || !isset($allowed[$media_id])) {
      throw new \InvalidArgumentException('Select exactly which attached image should become the subsite logo.');
    }

    $media = $this->entityTypeManager->getStorage('media')->load($media_id);
    if (!$media || !$media->access('view', $account)) {
      throw new \InvalidArgumentException('The selected logo Media item is unavailable.');
    }
    $media_type = $this->entityTypeManager->getStorage('media_type')->load($media->bundle());
    if (!$media_type || $media_type->getSource()->getPluginId() !== 'image') {
      throw new \InvalidArgumentException('A subsite logo must use an image Media item.');
    }

    $size = (string) ($plan['logo_size'] ?? 'medium_logo');
    if (!in_array($size, ['short_logo', 'medium_logo', 'tall_logo'], TRUE)) {
      throw new \InvalidArgumentException('The selected subsite logo size is invalid.');
    }
    return [
      'media_id' => $media_id,
      'label' => (string) $media->label(),
      'size' => $size,
    ];
  }

  /**
   * Validates and previews a new subsite page.
   */
  protected function normalizeNewPage(array $page, $subsite, AccountInterface $account) {
    $title = trim((string) ($page['title'] ?? ''));
    $term_id = (int) ($page['directory_term_id'] ?? 0);
    if ($title === '' || mb_strlen($title) > 255) {
      throw new \InvalidArgumentException('Provide a subsite page title no longer than 255 characters.');
    }
    $allowed_term_ids = array_map('intval', array_column($subsite->get('directory_structure')->getValue(), 'target_id'));
    if (!$term_id && count($allowed_term_ids) === 1) {
      $term_id = reset($allowed_term_ids);
    }
    if (!$term_id || !in_array($term_id, $allowed_term_ids, TRUE)) {
      throw new \InvalidArgumentException('Select a Moody URL Generator group assigned to this subsite.');
    }
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($term_id);
    if (!$term || $term->bundle() !== 'directory_structure' || !$term->access('view', $account)) {
      throw new \InvalidArgumentException('The selected Moody URL Generator group is unavailable.');
    }
    $payload = [
      'title' => $title,
      'directory_term_id' => $term_id,
    ];
    $this->buildNewPage($payload, $subsite, $account);
    return [
      'payload' => $payload,
      'term_label' => (string) $term->label(),
    ];
  }

  /**
   * Builds and validates an unpublished Moody Subsite Page.
   */
  protected function buildNewPage(array $page, $subsite, AccountInterface $account) {
    if (!$this->entityTypeManager->hasDefinition('node') || !$this->entityTypeManager->getAccessControlHandler('node')->createAccess('moody_subsite_page', $account)) {
      throw new \InvalidArgumentException('You do not have permission to create Moody Subsite Pages.');
    }
    $term_id = (int) ($page['directory_term_id'] ?? 0);
    $allowed_term_ids = array_map('intval', array_column($subsite->get('directory_structure')->getValue(), 'target_id'));
    if (!$term_id || !in_array($term_id, $allowed_term_ids, TRUE)) {
      throw new \InvalidArgumentException('The selected Moody URL Generator group is no longer assigned to this subsite.');
    }
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'moody_subsite_page',
      'title' => trim((string) ($page['title'] ?? '')),
      'uid' => (int) $account->id(),
      'status' => 0,
      'field_moody_url_generator' => ['target_id' => $term_id],
    ]);
    $this->assertValidEntity($node, 'The proposed subsite page is invalid');
    return $node;
  }

  /**
   * Throws a compact validation exception for an invalid entity.
   */
  protected function assertValidEntity($entity, $prefix) {
    $violations = $entity->validate();
    if (!$violations->count()) {
      return;
    }
    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = (string) $violation->getMessage();
      if (count($messages) === 3) {
        break;
      }
    }
    throw new \InvalidArgumentException($prefix . ': ' . implode(' ', $messages));
  }

  /**
   * Returns a scalar field value.
   */
  protected function scalarFieldValue($entity, $field_name) {
    return $entity->get($field_name)->value ?? '';
  }

  /**
   * Formats a setting for a readable before/after preview.
   */
  protected function displaySettingValue($field_name, $value) {
    if (in_array($field_name, ['subsite_home_hero', 'hide_all_social_accounts'], TRUE)) {
      return !empty($value) ? 'Enabled' : 'Disabled';
    }
    if ($field_name === 'title_display_option') {
      return [
        '1' => 'Page title only',
        '2' => 'No page or subsite titles',
        '3' => 'Subsite name prepended to page title',
      ][(string) $value] ?? (string) $value;
    }
    return trim((string) $value) ?: 'Empty';
  }

  /**
   * Formats a menu for the existing before/after change cards.
   */
  protected function formatMenuItems(array $items) {
    if (!$items) {
      return 'No navigation links';
    }
    return implode("\n", array_map(function (array $item) {
      return (!empty($item['is_child']) ? '↳ ' : '') . $item['title'] . ' → ' . $item['link'];
    }, $items));
  }

  /**
   * Returns a Media label without exposing file details.
   */
  protected function mediaLabel($media_id) {
    if (!$media_id) {
      return '';
    }
    $media = $this->entityTypeManager->getStorage('media')->load($media_id);
    return $media ? (string) $media->label() : 'Unavailable Media item';
  }

  /**
   * Normalizes model JSON booleans without treating "false" as TRUE.
   */
  protected function normalizeBoolean($value) {
    if (is_bool($value) || is_int($value)) {
      return $value ? 1 : 0;
    }
    if (is_string($value)) {
      $value = strtolower(trim($value));
      if (in_array($value, ['1', 'true', 'yes', 'on'], TRUE)) {
        return 1;
      }
      if (in_array($value, ['', '0', 'false', 'no', 'off'], TRUE)) {
        return 0;
      }
    }
    throw new \InvalidArgumentException('A proposed checkbox value is invalid.');
  }

  /**
   * Allows local absolute paths and full HTTP(S) URLs only.
   */
  protected function isAllowedLink($link) {
    $link = trim((string) $link);
    if (str_starts_with($link, '/') && !str_starts_with($link, '//')) {
      return TRUE;
    }
    $scheme = strtolower((string) parse_url($link, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], TRUE)
      && filter_var($link, FILTER_VALIDATE_URL) !== FALSE;
  }

  /**
   * Hashes all assistant-manageable values for optimistic concurrency checks.
   */
  protected function subsiteStateHash($subsite) {
    $settings = [];
    foreach (static::EDITABLE_SETTINGS as $field_name => $label) {
      $settings[$field_name] = $this->scalarFieldValue($subsite, $field_name);
    }
    return hash('sha256', json_encode([
      'settings' => $settings,
      'menu_items' => $this->normalizeMenuItems($subsite->get('subsite_nav')->getValue()),
      'custom_logo' => $subsite->get('custom_logo')->getValue(),
      'directory_terms' => array_map('intval', array_column($subsite->get('directory_structure')->getValue(), 'target_id')),
    ]));
  }

  /**
   * Creates a standard change-card payload.
   */
  protected function change($label, $before, $after, $summary = 'Update this field to the reviewed value.') {
    return [
      'field_label' => $label,
      'summary' => $summary,
      'before' => $before,
      'after' => $after,
    ];
  }

}
