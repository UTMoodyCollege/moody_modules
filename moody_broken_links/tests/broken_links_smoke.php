<?php

declare(strict_types=1);

use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\NodeInterface;

/**
 * Small local smoke check for field and Layout Builder remediation behavior.
 */
function broken_links_check(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/**
 * Permanently removes test entities even when the Trash module is enabled.
 */
function broken_links_delete_fixtures(object $storage, array $ids): void {
  if (!$ids) {
    return;
  }
  $delete = static function () use ($storage, $ids): void {
    $storage->resetCache($ids);
    $entities = $storage->loadMultiple($ids);
    if ($entities) {
      $storage->delete($entities);
    }
  };
  if (\Drupal::hasService('trash.manager')) {
    \Drupal::service('trash.manager')->executeInTrashContext('ignore', $delete);
  }
  else {
    $delete();
  }
}

$database = \Drupal::database();
$manager = \Drupal::service('moody_broken_links.manager');
$node_storage = \Drupal::entityTypeManager()->getStorage('node');
$block_storage = \Drupal::entityTypeManager()->getStorage('block_content');
$account_switcher = \Drupal::service('account_switcher');
$admin = \Drupal::entityTypeManager()->getStorage('user')->load(1);
$node_ids = [];
$block_ids = [];
$scan_ids = [];
$switched_account = FALSE;

try {
  broken_links_check($admin !== NULL, 'User 1 is required for this local smoke check.');
  $account_switcher->switchTo($admin);
  $switched_account = TRUE;
  $suffix = bin2hex(random_bytes(5));

  $remove_node = $node_storage->create([
    'type' => 'article',
    'title' => "Broken link remove $suffix",
    'status' => 0,
    'body' => [[
      'value' => '<p>Before <a class="kept" href="http://127.0.0.1/remove"><strong>linked words</strong></a> after.</p>',
      'format' => 'flex_html',
    ]],
  ]);
  $remove_node->save();
  $node_ids[] = (int) $remove_node->id();
  $remove_original_revision = (int) $remove_node->getRevisionId();

  $revise_node = $node_storage->create([
    'type' => 'article',
    'title' => "Broken link revise $suffix",
    'status' => 0,
    'body' => [[
      'value' => '<p><a href="http://127.0.0.1/revise">Revise me</a></p>',
      'format' => 'flex_html',
    ]],
  ]);
  $revise_node->save();
  $node_ids[] = (int) $revise_node->id();
  $revise_original_revision = (int) $revise_node->getRevisionId();

  $link_field_node = $node_storage->create([
    'type' => 'moody_faculty_bio',
    'title' => "Broken link field $suffix",
    'status' => 0,
    'field_personal_link_faculty_bio' => [[
      'uri' => 'http://127.0.0.1/link-field',
      'title' => 'Personal website',
    ]],
  ]);
  $link_field_node->save();
  $node_ids[] = (int) $link_field_node->id();

  $stale_node = $node_storage->create([
    'type' => 'article',
    'title' => "Broken stale link $suffix",
    'status' => 0,
    'body' => [[
      'value' => '<p><a href="http://127.0.0.1/stale">Stale link</a></p>',
      'format' => 'flex_html',
    ]],
  ]);
  $stale_node->save();
  $node_ids[] = (int) $stale_node->id();

  $batch_node = $node_storage->create([
    'type' => 'article',
    'title' => "Broken link batch $suffix",
    'status' => 0,
    'body' => [[
      'value' => '<p><a href="http://127.0.0.1/batch-one"><strong>First</strong></a> <a href="http://127.0.0.1/batch-two"><em>Second</em></a> <a href="http://127.0.0.1/batch-three">Third</a></p>',
      'format' => 'flex_html',
    ]],
  ]);
  $batch_node->save();
  $node_ids[] = (int) $batch_node->id();
  $batch_original_revision = (int) $batch_node->getRevisionId();

  $scan_id = $manager->createScan(1, ['article', 'moody_faculty_bio'], 5);
  $scan_ids[] = $scan_id;
  $context = [];
  $manager->scanNodes($scan_id, [
    $remove_node->id(),
    $revise_node->id(),
    $link_field_node->id(),
    $stale_node->id(),
    $batch_node->id(),
  ], 'https://2moody-core.ddev.site', $context);
  $summary = $manager->finishScan($scan_id);
  broken_links_check($summary['total_links'] === 7, 'The node text and Link field URLs were not all recorded.');

  $result_ids = [];
  $batch_results = [];
  foreach ($manager->resultQuery($scan_id, 'all')->execute() as $row) {
    if ((int) $row->nid === (int) $batch_node->id()) {
      $batch_results[(string) $row->href] = (int) $row->result_id;
    }
    else {
      $result_ids[(int) $row->nid] = (int) $row->result_id;
    }
  }
  broken_links_check(isset(
    $result_ids[$remove_node->id()],
    $result_ids[$revise_node->id()],
    $result_ids[$link_field_node->id()],
    $result_ids[$stale_node->id()],
  ), 'Node-field results were not addressable.');
  broken_links_check(count($batch_results) === 3, 'The queued formatted-text links were not all addressable.');

  $manager->remediate($result_ids[$remove_node->id()], 'remove');
  $node_storage->resetCache([$remove_node->id()]);
  $updated_remove_node = $node_storage->load($remove_node->id());
  $remove_markup = (string) $updated_remove_node->body->value;
  broken_links_check((int) $updated_remove_node->getRevisionId() > $remove_original_revision, 'Removing a link did not create a node revision.');
  broken_links_check(!str_contains($remove_markup, '<a'), 'The anchor was not removed.');
  broken_links_check(str_contains($remove_markup, '<strong>linked words</strong>'), 'Removing the anchor did not preserve nested markup.');

  $manager->remediate($result_ids[$revise_node->id()], 'revise', '/replacement');
  $node_storage->resetCache([$revise_node->id()]);
  $updated_revise_node = $node_storage->load($revise_node->id());
  broken_links_check((int) $updated_revise_node->getRevisionId() > $revise_original_revision, 'Revising a link did not create a node revision.');
  broken_links_check(str_contains((string) $updated_revise_node->body->value, 'href="/replacement"'), 'The replacement URL was not saved.');

  $manager->remediate($result_ids[$link_field_node->id()], 'revise', '/faculty-profile');
  $node_storage->resetCache([$link_field_node->id()]);
  $updated_link_field_node = $node_storage->load($link_field_node->id());
  broken_links_check(
    (string) $updated_link_field_node->field_personal_link_faculty_bio->uri === 'internal:/faculty-profile',
    'The Drupal Link field replacement was not saved as an internal URI.',
  );

  $stale_node->body->value = '<p>The field changed after the scan.</p>';
  $stale_node->setNewRevision(TRUE);
  $stale_node->save();
  try {
    $manager->remediate($result_ids[$stale_node->id()], 'remove');
    throw new RuntimeException('Source drift did not stop link remediation.');
  }
  catch (RuntimeException $exception) {
    broken_links_check(
      str_contains($exception->getMessage(), 'changed after the scan'),
      'Source drift returned an unexpected error.',
    );
  }

  $batch_change = $manager->remediatePage($scan_id, (int) $batch_node->id(), [
    $batch_results['http://127.0.0.1/batch-one'] => [
      'action' => 'revise',
      'replacement' => '/first-replacement',
    ],
    $batch_results['http://127.0.0.1/batch-two'] => ['action' => 'remove'],
    $batch_results['http://127.0.0.1/batch-three'] => [
      'action' => 'revise',
      'replacement' => '/third-replacement',
    ],
  ]);
  broken_links_check($batch_change['changed'] === 3, 'The page queue did not report all three changes.');
  $node_storage->resetCache([$batch_node->id()]);
  $updated_batch_node = $node_storage->load($batch_node->id());
  $batch_markup = (string) $updated_batch_node->body->value;
  broken_links_check((int) $updated_batch_node->getRevisionId() > $batch_original_revision, 'The page queue did not create a node revision.');
  broken_links_check(str_contains($batch_markup, 'href="/first-replacement"'), 'The first queued replacement was not saved.');
  broken_links_check(str_contains($batch_markup, '<em>Second</em>') && !str_contains($batch_markup, 'batch-two'), 'The queued removal did not retain its nested markup.');
  broken_links_check(str_contains($batch_markup, 'href="/third-replacement"'), 'The third queued replacement was not saved.');
  broken_links_check($manager->getPageResults($scan_id, (int) $batch_node->id()) === [], 'Applied page results remained active.');

  $block = $block_storage->create([
    'type' => 'utexas_flex_content_area',
    'info' => "Broken link block $suffix",
    'reusable' => FALSE,
    'field_block_fca' => [[
      'headline' => 'Broken links smoke',
      'copy_value' => '<p><a href="http://127.0.0.1/layout"><em>Layout link</em></a></p>',
      'copy_format' => 'flex_html',
      'links' => serialize([[
        'uri' => 'http://127.0.0.1/structured',
        'title' => 'Structured link',
      ]]),
      'link_uri' => 'http://127.0.0.1/custom-property',
      'link_text' => 'Custom property link',
    ]],
  ]);
  $configuration = [
    'id' => 'inline_block:utexas_flex_content_area',
    'label' => '',
    'provider' => 'layout_builder',
    'label_display' => FALSE,
    'view_mode' => 'full',
    'block_revision_id' => NULL,
    'block_serialized' => serialize($block),
  ];
  $section = new Section('layout_onecol');
  $section->appendComponent(new SectionComponent(
    \Drupal::service('uuid')->generate(),
    'content',
    $configuration,
  ));
  $layout_node = $node_storage->create([
    'type' => 'moody_standard_page',
    'title' => "Broken layout link $suffix",
    'status' => 0,
  ]);
  broken_links_check($layout_node instanceof NodeInterface && $layout_node->hasField('layout_builder__layout'), 'The local standard page bundle does not support Layout Builder overrides.');
  $layout_node->get('layout_builder__layout')->appendSection($section);
  $layout_node->save();
  $node_ids[] = (int) $layout_node->id();
  $layout_original_revision = (int) $layout_node->getRevisionId();
  $saved_component = reset($layout_node->get('layout_builder__layout')->getSection(0)->getComponents());
  $saved_configuration = $saved_component->toArray()['configuration'];
  $old_block_revision = (int) $saved_configuration['block_revision_id'];
  $block_ids[] = (int) $saved_configuration['block_id'];

  $layout_scan_id = $manager->createScan(1, ['moody_standard_page'], 1);
  $scan_ids[] = $layout_scan_id;
  $context = [];
  $manager->scanNodes($layout_scan_id, [$layout_node->id()], 'https://2moody-core.ddev.site', $context);
  $layout_summary = $manager->finishScan($layout_scan_id);
  broken_links_check($layout_summary['total_links'] === 3, 'The Layout Builder markup, custom property, and structured links were not all detected.');
  $layout_results = [];
  foreach ($manager->resultQuery($layout_scan_id, 'all')->execute() as $row) {
    $layout_results[(string) $row->href] = (array) $row;
  }
  broken_links_check(isset(
    $layout_results['http://127.0.0.1/layout'],
    $layout_results['http://127.0.0.1/custom-property'],
    $layout_results['http://127.0.0.1/structured'],
  ), 'The Layout Builder result sources were not addressable.');
  broken_links_check(
    $layout_results['http://127.0.0.1/layout']['source_type'] === 'inline_block_field',
    'The Layout Builder inline-block link source was incorrect.',
  );

  $layout_change = $manager->remediatePage($layout_scan_id, (int) $layout_node->id(), [
    (int) $layout_results['http://127.0.0.1/layout']['result_id'] => ['action' => 'remove'],
    (int) $layout_results['http://127.0.0.1/custom-property']['result_id'] => [
      'action' => 'revise',
      'replacement' => '/custom-cta',
    ],
    (int) $layout_results['http://127.0.0.1/structured']['result_id'] => ['action' => 'remove'],
  ]);
  broken_links_check($layout_change['changed'] === 3, 'The Layout Builder page queue did not report all three changes.');
  $node_storage->resetCache([$layout_node->id()]);
  $updated_layout_node = $node_storage->load($layout_node->id());
  broken_links_check((int) $updated_layout_node->getRevisionId() > $layout_original_revision, 'Layout remediation did not create a node revision.');
  $updated_component = reset($updated_layout_node->get('layout_builder__layout')->getSection(0)->getComponents());
  $updated_configuration = $updated_component->toArray()['configuration'];
  broken_links_check((int) $updated_configuration['block_revision_id'] > $old_block_revision, 'Layout remediation did not create an inline-block revision.');
  $updated_block = $block_storage->loadRevision((int) $updated_configuration['block_revision_id']);
  $updated_custom_field = $updated_block->field_block_fca->first();
  $layout_markup = (string) $updated_custom_field->copy_value;
  broken_links_check(!str_contains($layout_markup, '<a'), 'The Layout Builder anchor was not removed.');
  broken_links_check(str_contains($layout_markup, '<em>Layout link</em>'), 'The Layout Builder link text and markup were not retained.');
  broken_links_check((string) $updated_custom_field->link_uri === 'internal:/custom-cta', 'The custom field URL property was not revised.');
  $structured_links = unserialize((string) $updated_custom_field->links, ['allowed_classes' => FALSE]);
  broken_links_check(($structured_links[0]['uri'] ?? NULL) === '', 'The URL in serialized custom-field storage was not removed.');

  try {
    $manager->prepareScan([], 1, PHP_INT_MAX);
    throw new RuntimeException('An unknown single-page selection was accepted.');
  }
  catch (InvalidArgumentException $exception) {
    broken_links_check($exception->getMessage() === 'Select a valid page.', 'An invalid page returned an unexpected error.');
  }
  $single_page_scan = $manager->prepareScan([], 1, (int) $updated_layout_node->id());
  $scan_ids[] = (int) $single_page_scan['scan_id'];
  broken_links_check(
    $single_page_scan['node_ids'] === [(int) $updated_layout_node->id()],
    'The single-page scan selected more than the requested page.',
  );
  $single_page_record = $manager->getLatestScan();
  broken_links_check(
    $single_page_record['bundles'] === [$updated_layout_node->bundle()] && (int) $single_page_record['total_nodes'] === 1,
    'The single-page scan metadata was incorrect.',
  );
  $manager->finishScan((int) $single_page_scan['scan_id']);

  print "Broken links smoke check passed.\n";
}
finally {
  broken_links_delete_fixtures($node_storage, $node_ids);
  broken_links_delete_fixtures($block_storage, $block_ids);
  foreach ($scan_ids as $scan_id) {
    $database->delete('moody_broken_links_result')->condition('scan_id', $scan_id)->execute();
    $database->delete('moody_broken_links_scan')->condition('scan_id', $scan_id)->execute();
  }
  if ($switched_account) {
    $account_switcher->switchBack();
  }
}
