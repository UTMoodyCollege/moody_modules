<?php

// Run with DDEV Drush php:script. All test data rolls back by default.
use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\layout_builder\InlineBlockUsage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\moody_block_clone\BlockCloneManager;
use Drupal\moody_block_clone\Form\CloneBlockChooserForm;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

if (getenv('DDEV_SITENAME') !== '2moody-core' || getenv('PANTHEON_ENVIRONMENT')) {
  throw new RuntimeException('Run this fixture-creating check only in local 2moody-core.');
}
$database = \Drupal::database();
$transaction = $database->startTransaction();
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(User::load(1));
$keep = FALSE;
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
  $checks++;
};
try {
  $manager = \Drupal::service('moody_block_clone.manager');
  $entity_manager = \Drupal::entityTypeManager();
  $blocks = [];
  $components = [];
  foreach ([
    ['Welcome and overview', '<h2>Welcome to Moody</h2><p>A clear introduction to our community.</p>', 'first', 10],
    ['Program opportunities', '<h2>Find your next opportunity</h2><ul><li>Explore programs</li><li>Connect with a mentor</li></ul>', 'first', -2],
    ['Contact and next steps', '<h2>Let’s connect</h2>' . str_repeat('<p>Explore the details, ask a question, and plan your next step.</p>', 16), 'second', 0],
  ] as [$label, $body, $region, $weight]) {
    $block = BlockContent::create(['type' => 'basic', 'info' => $label, 'reusable' => FALSE, 'status' => 1, 'body' => ['value' => $body, 'format' => 'flex_html']]);
    $block->save();
    $blocks[] = $block;
    $components[] = (new SectionComponent(\Drupal::service('uuid')->generate(), $region, [
      'id' => 'inline_block:basic', 'label' => $label, 'label_display' => FALSE,
      'provider' => 'layout_builder', 'view_mode' => 'full',
      'block_revision_id' => $block->getRevisionId(), 'block_serialized' => NULL,
    ]))->setWeight($weight);
  }
  $source = Node::create(['type' => 'moody_standard_page', 'title' => 'Block copy QA — source', 'uid' => 1, 'status' => 1,
    'layout_builder__layout' => [['section' => new Section('layout_twocol_section', ['column_widths' => '50-50'], $components)]],
  ]);
  $source->save();
  $target = Node::create(['type' => 'moody_standard_page', 'title' => 'Block copy QA — destination', 'uid' => 1, 'status' => 0,
    'layout_builder__layout' => [['section' => new Section('layout_onecol')]],
  ]);
  $target->save();
  $storage = \Drupal::service('plugin.manager.layout_builder.section_storage')->load('overrides', ['entity' => EntityContext::fromEntity($target)]);

  // Explicit opt-in leaves only the source and empty draft for manual browser QA.
  if (getenv('MOODY_BLOCK_CLONE_QA_FIXTURE') === '1') {
    $keep = TRUE;
    print "Local QA source: {$source->id()}; destination: {$target->id()}\n";
    return;
  }

  $placements = $manager->getCloneableBlocks($source);
  $ids = array_keys($placements);
  $check(array_column($placements, 'label') === ['Program opportunities', 'Welcome and overview', 'Contact and next steps'], 'Source blocks must follow region and weight order.');
  $count_blocks = static fn () => (int) $entity_manager->getStorage('block_content')->getQuery()->accessCheck(FALSE)->count()->execute();
  $before = $count_blocks();
  foreach ([[[], 0, 'content'], [[$ids[0], 'missing'], 0, 'content'], [[$ids[0]], 99, 'content'], [[$ids[0]], 0, 'missing']] as [$selection, $delta, $region]) {
    try {
      $manager->cloneComponentsToSection($storage, $delta, $region, $source, $selection);
      throw new RuntimeException('Invalid batch was accepted.');
    }
    catch (\InvalidArgumentException | \Symfony\Component\HttpKernel\Exception\NotFoundHttpException $expected) {}
    $check($count_blocks() === $before && !$storage->getSection(0)->getComponents(), 'Invalid batch created partial copies.');
  }

  $failing_usage = new class($database) extends InlineBlockUsage {
    private int $calls = 0;
    public function addUsage($block_content_id, EntityInterface $entity) {
      parent::addUsage($block_content_id, $entity);
      if (++$this->calls === 2) {
        throw new RuntimeException('Simulated second-copy failure');
      }
    }
  };
  $failing_manager = new BlockCloneManager($entity_manager, \Drupal::service('entity_field.manager'), \Drupal::service('entity_type.bundle.info'), $failing_usage, \Drupal::service('uuid'), $database);
  try {
    $failing_manager->cloneComponentsToSection($storage, 0, 'content', $source, $ids);
    throw new RuntimeException('Expected a simulated save failure.');
  }
  catch (RuntimeException $error) {
    $check($error->getMessage() === 'Simulated second-copy failure', 'Unexpected failure.');
  }
  $check($count_blocks() === $before && !$storage->getSection(0)->getComponents(), 'Failed batch did not roll back copies and leave the draft intact.');

  $copies = $manager->cloneComponentsToSection($storage, 0, 'content', $source, [$ids[2], $ids[0], $ids[1], $ids[0]]);
  $check(count($copies) === 3 && $count_blocks() === $before + 3, 'Selection must be deduplicated.');
  foreach ($copies as $index => $copy) {
    $config = $copy->get('configuration');
    $original = $placements[$ids[$index]];
    $check($config['label'] === $original['label'] && $copy->getRegion() === 'content', 'Copies must retain source order and target region.');
    $check($config['block_id'] != $original['block']->id() && $copy->getUuid() !== $ids[$index], 'Copy identities must be independent.');
    $duplicate = $entity_manager->getStorage('block_content')->loadRevision($config['block_revision_id']);
    $check($duplicate->get('body')->getValue() === $original['block']->get('body')->getValue(), 'Copy content changed.');
    $check(\Drupal::service('inline_block.usage')->getUsage($duplicate->id())->layout_entity_id == $target->id(), 'Copy usage must belong to the destination.');
  }
  $single = $manager->cloneComponentToSection($storage, 0, 'content', $source, $ids[0]);
  $check($single instanceof SectionComponent && count($storage->getSection(0)->getComponents()) === 4, 'Single-block cloning regressed.');
  $entity_manager->getStorage('node')->resetCache([$source->id(), $target->id()]);
  $check(count($manager->getCloneableBlocks(Node::load($source->id()))) === 3, 'Source page changed.');
  $check(!Node::load($target->id())->get('layout_builder__layout')->first()->section->getComponents(), 'Copying saved the destination page instead of its draft.');

  $chooser = CloneBlockChooserForm::create(\Drupal::getContainer());
  $state = (new FormState())->setValues(['source_page' => (int) $source->id()]);
  $form = $chooser->buildForm([], $state, $storage, 0, 'content');
  $check(isset($form['results']['grid'][$ids[0]]['header']['select']), 'Validated integer autocomplete value failed.');
  $state->setTriggeringElement($form['results']['copy_actions']['copy']);
  $chooser->validateForm($form, $state);
  $check(isset($state->getErrors()['selected_blocks']), 'Empty selection must be rejected.');
  $state->clearErrors();
  $state->setValue('selected_blocks', [$ids[0] => $ids[0], 'missing' => 'missing']);
  $chooser->validateForm($form, $state);
  $check(isset($state->getErrors()['selected_blocks']), 'Stale selection must be rejected.');
  $state->clearErrors();
  $state->setValue('source_page', (int) $target->id());
  $chooser->validateForm($form, $state);
  $check(isset($state->getErrors()['source_page']), 'Changed source must be rejected.');
  $state->clearErrors();
  $state->setValue('source_page', (int) $source->id())->setValue('selected_blocks', [$ids[1] => $ids[1]]);
  $chooser->validateForm($form, $state);
  $check(!$state->hasAnyErrors(), 'Valid selection was rejected.');
  $chooser->submitForm($form, $state);
  $check($state->getRedirect() !== NULL, 'Non-JavaScript submission needs a layout redirect.');
  $commands = $chooser->ajaxSubmit($form, $state)->getCommands();
  $check($commands[0]['command'] === 'insert' && $commands[0]['selector'] === '#layout-builder', 'AJAX must rebuild the layout once.');
  $check($commands[1]['selector'] === '#drupal-off-canvas' && $commands[2]['selector'] === '#layout-builder-modal', 'Both dialog presentations must close.');
  $state->setUserInput(['selected_blocks' => [$ids[0] => $ids[0]]]);
  $chooser->rebuildResults($form, $state);
  $check(!$state->getValue('selected_blocks') && !isset($state->getUserInput()['selected_blocks']), 'Reloading a page must clear the old selection.');

  $switcher->switchTo(new class extends AnonymousUserSession {
    public function hasPermission($permission) { return FALSE; }
  });
  try {
    $check(!$manager->getCloneableBlocks($source), 'Unavailable source content must not be exposed in previews.');
    try {
      $manager->cloneComponentsToSection($storage, 0, 'content', $source, $ids);
      throw new RuntimeException('Denied source was copied.');
    }
    catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $expected) { $checks++; }
  }
  finally { $switcher->switchBack(); }
  print "PASS: $checks block-copy checks; fixture data rolls back.\n";
}
finally {
  $switcher->switchBack();
  if (!$keep) {
    $transaction->rollBack();
  }
}
