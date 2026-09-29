<?php

/**
 * Read-only local smoke test: MOODY_TEST_NODE=7730 drush php:script this-file.php.
 * Needs a Layout Builder node and at least one editable reusable block.
 */

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder_browser\Controller\BrowserController;
use Drupal\moody_layout_builder_browser\AvailableBlocks;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

$check = static function (bool $ok, string $message): void {
  if (!$ok) {
    throw new RuntimeException($message);
  }
};
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(User::load(1));
try {
  $node = Node::load(getenv('MOODY_TEST_NODE') ?: 7730);
  $storage = \Drupal::service('plugin.manager.layout_builder.section_storage')->load('overrides', ['entity' => EntityContext::fromEntity($node)]);
  $context = ['section_storage' => $storage, 'delta' => 0, 'region' => 'first'];
  $browser = BrowserController::create(\Drupal::getContainer());
  $build = $browser->browse($storage, 0, 'first');
  $before = serialize($build['block_categories']);
  AvailableBlocks::create(\Drupal::getContainer())->append($build, $context);
  $check($before === serialize($build['block_categories']), 'Repeated discovery must not duplicate entries.');
  $check(!empty($build['block_categories']['moody_available_views']['links']), 'Expected additional Views block displays.');
  $empty_browser = [];
  AvailableBlocks::create(\Drupal::getContainer())->append($empty_browser, $context);
  $check(!empty($empty_browser['block_categories']['moody_available_reusable']['links']), 'Reusable discovery must not depend on configured bundle lists.');
  $blocks = \Drupal::entityTypeManager()->getStorage('block_content')->loadByProperties(['reusable' => TRUE]);
  $block = reset($blocks);
  $check((bool) $block, 'Needs an existing reusable block.');
  $object = new class extends FormBase {
    public string $pluginId;
    public function getFormId() { return 'moody_browser_smoke'; }
    public function buildForm(array $form, FormStateInterface $form_state) { return $form; }
    public function submitForm(array &$form, FormStateInterface $form_state) {}
    public function getCurrentComponent() { return new SectionComponent('smoke', 'first', ['id' => $this->pluginId]); }
  };
  $object->pluginId = 'block_content:' . $block->uuid();
  $state = (new FormState())->setFormObject($object);
  $form = [];
  _moody_layout_builder_browser_reusable_edit_link($form, $state);
  $link = $form['settings']['moody_reusable_edit'];
  $check($link['#access'] && $link['link']['#attributes']['target'] === '_blank', 'Editor needs a new-tab edit link.');
  $check($link['link']['#url']->getRouteParameters()['block_content'] == $block->id(), 'Link must target the original reusable entity.');
  $switcher->switchTo(new AnonymousUserSession());
  try {
    $form = [];
    _moody_layout_builder_browser_reusable_edit_link($form, $state);
    $check(!$form['settings']['moody_reusable_edit']['#access'], 'No edit link without update access.');
  }
  finally { $switcher->switchBack(); }
  $object->pluginId = 'inline_block:basic';
  $form = [];
  _moody_layout_builder_browser_reusable_edit_link($form, $state);
  $check(!isset($form['settings']['moody_reusable_edit']), 'Inline blocks must not get a reusable edit link.');
  echo "PASS: Views discovery, deduplication, reusable target/new tab, denied edit access and inline exclusion. No content saved.\n";
}
finally { $switcher->switchBack(); }
