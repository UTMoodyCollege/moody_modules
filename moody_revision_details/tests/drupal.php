<?php

// DDEV integration checks. Only self-created fixtures are deleted in finally.
use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Site\Settings;
use Drupal\Core\Pager\PagerManager;
use Drupal\diff\Form\RevisionOverviewForm;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\moody_revision_details\Controller\RevisionDetailsController;
use Drupal\moody_revision_details\RevisionSummary;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

if (!getenv('DDEV_SITENAME')) {
  throw new RuntimeException('Run these fixture-creating tests only in DDEV.');
}
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
  $checks++;
};
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(User::load(1));
$nodes = [];
$block = $language = NULL;
$keep = FALSE;
$test_module_added = !\Drupal::moduleHandler()->moduleExists('moody_revision_details_test');
$language_module_added = !\Drupal::moduleHandler()->moduleExists('language');
$original_page = \Drupal::request()->query->get('page');
$diff_config = \Drupal::configFactory()->getEditable('diff.settings');
$pager_limit = $diff_config->get('general_settings.revision_pager_limit');
$original_settings = Settings::getAll();
try {
  if ($test_module_added) {
    new Settings(['extension_discovery_scan_tests' => TRUE] + $original_settings);
    \Drupal::service('extension.list.module')->reset();
    \Drupal::service('module_installer')->install(['moody_revision_details_test']);
  }
  $manager = \Drupal::entityTypeManager();
  $summary = new RevisionSummary($manager, \Drupal::currentUser());
  $overview = static function ($node): array {
    // Each normal page request starts with a fresh pager manager. Simulate that
    // for multiple independent form builds within this one CLI request.
    \Drupal::getContainer()->set('pager.manager', new PagerManager(\Drupal::service('pager.parameters')));
    return \Drupal::formBuilder()->getForm(RevisionOverviewForm::class, $node);
  };
  $text = static function ($node) use ($summary): string {
    $build = $summary->build($node);
    return (string) \Drupal::service('renderer')->renderInIsolation($build);
  };
  $save = static function ($node, string $message): void {
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId(1);
    $node->setRevisionLogMessage($message);
    $node->setRevisionTranslationAffected(TRUE);
    $node->save();
  };
  $node = Node::create(['type' => 'moody_standard_page', 'title' => 'Moody revision details QA', 'uid' => 1, 'status' => 1]);
  $node->save();
  $nodes[] = $node;
  $check(str_contains($text($node), 'First available revision'), 'First retained revision was not explained.');
  $check($node->hasField('layout_builder__layout'), 'QA content type has no Layout Builder field.');
  $single = $overview($node);
  $check(isset($single['node_revisions_table'][0]['revision']['details']), 'Single revision is missing Show details.');

  $node->setTitle('Moody revision details QA — updated');
  $node->setUnpublished();
  $save($node, 'QA: edit title and unpublish');
  $html = $text($node);
  $check(str_contains($html, 'Title changed.'), 'Title change not detected.');
  $check(str_contains($html, 'Page unpublished.'), 'Publication change not detected.');
  \Drupal::request()->attributes->set('_revision_details_hidden_field', 'title');
  $check(!str_contains($text($node), 'Title changed.'), 'Field access was bypassed.');
  \Drupal::request()->attributes->remove('_revision_details_hidden_field');
  $save($node, 'QA: metadata-only save');
  $check(str_contains($text($node), 'No changes to accessible saved'), 'Metadata-only save produced a false change.');

  $uuid = \Drupal::service('uuid');
  $a = $uuid->generate();
  $b = $uuid->generate();
  $c = $uuid->generate();
  $block = BlockContent::create(['type' => 'basic', 'info' => 'QA inline text', 'reusable' => FALSE, 'body' => ['value' => '<p>Original text.</p>', 'format' => 'plain_text']]);
  $block->save();
  $section = new Section('layout_onecol', [], [
    new SectionComponent($a, 'content', ['id' => 'system_powered_by_block', 'label' => 'QA first block']),
    (new SectionComponent($b, 'content', ['id' => 'inline_block:basic', 'label' => 'QA inline text', 'block_revision_id' => $block->getRevisionId(), 'block_serialized' => NULL]))->setWeight(1),
  ]);
  $node->set('layout_builder__layout', [['section' => $section]]);
  $save($node, 'QA: Layout tab — add two blocks');
  $html = $text($node);
  $check(substr_count($html, 'Block added:') === 2, 'Added blocks not detected: ' . strip_tags($html));
  $check(str_contains($html, 'Page-specific layout added.'), 'Layout override creation not detected.');
  $no_layout_account = new class extends AnonymousUserSession {
    public function hasPermission($permission) {
      return in_array($permission, ['access content', 'view all revisions', 'bypass node access'], TRUE);
    }
  };
  $restricted_build = (new RevisionSummary($manager, $no_layout_account))->build($node);
  $restricted_html = (string) \Drupal::service('renderer')->renderInIsolation($restricted_build);
  $check(!str_contains($restricted_html, 'Block added:') && str_contains($restricted_html, 'current Layout Builder access'), 'Layout access was bypassed.');

  $section = clone $node->get('layout_builder__layout')->first()->section;
  $section->insertComponent(0, new SectionComponent($c, 'content', ['id' => 'system_powered_by_block', 'label' => '<script>unsafe label</script>']));
  $node->set('layout_builder__layout', [['section' => $section]]);
  $save($node, 'QA: insert a block before existing siblings');
  $html = $text($node);
  $check(str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>'), 'Block labels were not escaped.');
  $check(!str_contains($html, 'order changed'), 'Insertion falsely reordered existing siblings.');

  $section = clone $node->get('layout_builder__layout')->first()->section;
  $section->getComponent($a)->setWeight(5);
  $node->set('layout_builder__layout', [['section' => $section]]);
  $save($node, 'QA: reorder existing blocks');
  $check(str_contains($text($node), 'Block order changed:'), 'Reordering not detected.');
  $section = clone $node->get('layout_builder__layout')->first()->section;
  foreach ($section->getComponents() as $component) {
    $component->setWeight($component->getWeight() + 100);
  }
  $node->set('layout_builder__layout', [['section' => $section]]);
  $save($node, 'QA: normalize weights without reordering');
  $check(str_contains($text($node), 'No changes to accessible saved'), 'Weight-only changes produced false moves.');

  $block->setNewRevision(TRUE);
  $block->save();
  $section = clone $node->get('layout_builder__layout')->first()->section;
  $inline_config = $section->getComponent($b)->toArray()['configuration'];
  $inline_config['block_revision_id'] = $block->getRevisionId();
  $section->getComponent($b)->setConfiguration($inline_config);
  $node->set('layout_builder__layout', [['section' => $section]]);
  $save($node, 'QA: new inline revision with unchanged content');
  $html = $text($node);
  $check(str_contains($html, 'No changes to accessible saved'), 'New inline revision ID falsely changed content: ' . strip_tags($html));
  $block->set('body', ['value' => '<p>Updated text.</p>', 'format' => 'plain_text']);
  $block->setNewRevision(TRUE);
  $block->save();
  $section = clone $node->get('layout_builder__layout')->first()->section;
  $inline_config = $section->getComponent($b)->toArray()['configuration'];
  $inline_config['block_revision_id'] = $block->getRevisionId();
  $section->getComponent($b)->setConfiguration($inline_config);
  $node->set('layout_builder__layout', [['section' => $section]]);
  $save($node, 'QA: edit inline block body');
  $check(str_contains($text($node), 'Block content changed:'), 'Saved inline content change not detected.');
  \Drupal::request()->attributes->set('_revision_details_hidden_field', 'body');
  $check(!str_contains($text($node), 'Block content changed:'), 'Inline field access was bypassed.');
  \Drupal::request()->attributes->remove('_revision_details_hidden_field');

  $section = clone $node->get('layout_builder__layout')->first()->section;
  $section->removeComponent($c);
  $section->getComponent($a)->setConfiguration(['id' => 'system_powered_by_block', 'label' => 'QA renamed block']);
  $section->getComponent($a)->setRegion('first');
  $section->getComponent($b)->setRegion('second');
  $section = new Section('layout_twocol_section', ['column_widths' => '50-50'], $section->getComponents());
  $node->set('layout_builder__layout', [['section' => $section]]);
  $save($node, 'QA: remove a block, change settings and move region');
  $html = $text($node);
  $check(str_contains($html, 'Block removed:'), 'Removed block not detected.');
  $check(str_contains($html, 'Block settings changed:'), 'Block settings change not detected.');
  $check(str_contains($html, 'Block position changed:'), 'Region move not detected.');

  $diff_config = \Drupal::configFactory()->getEditable('diff.settings');
  $diff_config->set('general_settings.revision_pager_limit', 1)->save();
  \Drupal::configFactory()->reset('diff.settings');
  $form = $overview($node);
  $link = $form['node_revisions_table'][0]['revision']['details']['link'];
  $check($link['#url']->getRouteParameters()['node_revision'] == $node->getRevisionId(), 'Pager link targets wrong revision.');
  $check(str_contains($text($node), 'Compared with preceding revision'), 'Cross-pager predecessor was lost.');
  \Drupal::request()->query->set('page', '1');
  $older_form = $overview($node);
  $older_vid = $older_form['node_revisions_table'][0]['revision']['details']['link']['#url']->getRouteParameters()['node_revision'];
  $check($older_vid < $node->getRevisionId(), 'A single-row older pager page linked to the newest revision.');
  \Drupal::request()->query->remove('page');
  $diff_config->set('general_settings.revision_pager_limit', $pager_limit)->save();
  \Drupal::configFactory()->reset('diff.settings');
  $form = $overview($node);
  $check(isset($form['node_revisions_table'][0]['select_column_one'], $form['node_revisions_table'][0]['select_column_two'], $form['node_revisions_table'][1]['operations']), 'Existing Diff controls changed.');

  $controller = RevisionDetailsController::create(\Drupal::getContainer());
  $response = $controller->show($node, $node, Request::create('/?_wrapper_format=drupal_ajax'));
  $check($response->getCommands()[0]['command'] === 'insert' && $response->getCommands()[0]['method'] === 'replaceWith', 'AJAX response does not replace the row details.');
  $check(str_contains($response->getCommands()[0]['data'], '<details') && str_contains($response->getCommands()[0]['data'], 'open'), 'AJAX details did not open.');
  $check(isset($controller->show($node, $node, Request::create('/'))['back']), 'No-JavaScript fallback missing.');
  $other = Node::create(['type' => 'moody_standard_page', 'title' => 'QA unrelated node', 'status' => 0]);
  $other->save();
  $nodes[] = $other;
  try {
    $controller->show($other, $node, Request::create('/'));
    $check(FALSE, 'Cross-node revision was accepted.');
  }
  catch (NotFoundHttpException) { $check(TRUE, ''); }
  try {
    (new RevisionSummary($manager, new AnonymousUserSession()))->build($node);
    $check(FALSE, 'Anonymous revision access was accepted.');
  }
  catch (AccessDeniedHttpException) { $check(TRUE, ''); }

  // A saved edit in another language must not become the comparison baseline.
  if ($language_module_added) {
    \Drupal::service('module_installer')->install(['language']);
  }
  if (!ConfigurableLanguage::load('fr')) {
    $language = ConfigurableLanguage::createFromLangcode('fr');
    $language->save();
    \Drupal::languageManager()->reset();
  }
  // The earlier fixture cached the monolingual language list before Language
  // was installed. Reload it as a fresh request would after that installation.
  $manager = \Drupal::entityTypeManager();
  $other = $manager->getStorage('node')->loadUnchanged($other->id());
  $translated = $other->addTranslation('fr', ['title' => 'QA traduction']);
  $save($translated, 'QA: first French revision');
  $french_vid = $translated->getRevisionId();
  $other = $manager->getStorage('node')->loadUnchanged($other->id());
  $other->setTitle('QA English-only edit');
  $other->setNewRevision(TRUE);
  $other->getTranslation('fr')->setRevisionTranslationAffected(FALSE);
  $other->setRevisionTranslationAffected(TRUE);
  $other->save();
  $other = $manager->getStorage('node')->loadUnchanged($other->id());
  $translated = $other->getTranslation('fr');
  $single_french_form = $overview($translated);
  $check($single_french_form['node_revisions_table'][0]['revision']['details']['link']['#url']->getRouteParameters()['node_revision'] == $french_vid, 'Single-language row linked to an unrelated-language edit.');
  $translated->setTitle('QA traduction mise à jour');
  $save($translated, 'QA: French title edit');
  $check(str_contains($text($translated), 'revision ' . $french_vid . ' in French'), 'Comparison did not use the preceding same-language revision.');

  print "Revision details: $checks integration checks passed.\n";
  $keep = getenv('MOODY_REVISION_DETAILS_KEEP_DEMO') === '1';
  if ($keep) {
    print 'Unpublished QA example: /node/' . $node->id() . '/revisions' . PHP_EOL;
  }
}
finally {
  \Drupal::request()->attributes->remove('_revision_details_hidden_field');
  if ($original_page === NULL) { \Drupal::request()->query->remove('page'); }
  else { \Drupal::request()->query->set('page', $original_page); }
  $diff_config->set('general_settings.revision_pager_limit', $pager_limit)->save();
  foreach ($nodes as $fixture) {
    if (!$keep || $fixture->id() !== $nodes[0]->id()) {
      $fixture->delete();
    }
  }
  if ($block && !$keep) { $block->delete(); }
  if ($language) { $language->delete(); }
  if ($test_module_added && \Drupal::moduleHandler()->moduleExists('moody_revision_details_test')) {
    \Drupal::service('module_installer')->uninstall(['moody_revision_details_test']);
  }
  if ($language_module_added && \Drupal::moduleHandler()->moduleExists('language')) {
    \Drupal::service('module_installer')->uninstall(['language']);
  }
  new Settings($original_settings);
  \Drupal::service('extension.list.module')->reset();
  $switcher->switchBack();
}
