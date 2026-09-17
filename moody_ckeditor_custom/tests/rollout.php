<?php

// Standalone regression check: php moody_ckeditor_custom/tests/rollout.php
final class ConfigStub {
  public function __construct(public array $data = [], private bool $missing = FALSE) {}
  public function isNew() { return $this->missing; }
  public function get($key) { return $this->data[$key] ?? NULL; }
  public function set($key, $value) { $this->data[$key] = $value; return $this; }
  public function save($trusted = FALSE) { return $this; }
}
final class Drupal {
  public static array $configs = [];
  public static array $installed = [];
  public static function configFactory() { return new self(); }
  public static function service($name) { return new self(); }
  public function getEditable($name) { return self::$configs[$name] ??= new ConfigStub([], TRUE); }
  public function install($modules, $enable_dependencies) { self::$installed = array_unique(array_merge(self::$installed, $modules)); }
}
function check($condition, $message) {
  if (!$condition) { throw new RuntimeException($message); }
}
require dirname(__DIR__) . '/moody_ckeditor_custom.install';
$original = ['editor' => 'ckeditor5', 'settings.toolbar.items' => ['bold', 'style', 'moodyNiceLetter', 'sourceEditing'], 'settings.plugins.ckeditor5_style.styles' => [['label' => 'Existing', 'element' => '<p class="existing">']], 'settings.plugins.ckeditor5_sourceEditing.allowed_tags' => ['<div class>', '<span class>']];
foreach (['flex_html', 'moody_flex_html', 'basic_html', 'full_html'] as $id) {
  Drupal::$configs["editor.editor.$id"] = new ConfigStub($original);
  Drupal::$configs["filter.format.$id"] = new ConfigStub(['filters.filter_html.settings.allowed_html' => '<p> <div class> <span class>', 'filters.moody_nice_letter' => ['id' => 'moody_nice_letter', 'provider' => 'moody_nice_letter', 'weight' => 9, 'settings' => [], 'status' => FALSE]]);
}
moody_ckeditor_custom_update_9002();
foreach (['flex_html', 'moody_flex_html'] as $id) {
  $editor = Drupal::$configs["editor.editor.$id"];
  $items = $editor->get('settings.toolbar.items');
  check($items === ['bold', 'style', 'moodyNiceLetter', 'sourceEditing', 'moodyReadableText', 'moodyImageCaption'], 'Existing order preserved, working controls added once.');
  check(!in_array('moodyContentBlocks', $items), 'Demo remains unavailable.');
  check(count($editor->get('settings.plugins.ckeditor5_style.styles')) === 26, 'Existing style retained alongside all Moody styles.');
  $filter = Drupal::$configs["filter.format.$id"];
  check($filter->get('filters.moody_nice_letter')['status'] === TRUE && $filter->get('filters.moody_nice_letter')['weight'] === 9, 'Nice Letter enabled without reordering filters.');
  check($filter->get('filters.filter_html.settings.allowed_html') === '<p> <div class> <span class>', 'HTML safety restrictions unchanged.');
}
$snapshot = serialize(Drupal::$configs);
moody_ckeditor_custom_update_9002();
check(serialize(Drupal::$configs) === $snapshot, 'Repeat rollout is idempotent.');
check(Drupal::$configs['editor.editor.basic_html']->data === $original && Drupal::$configs['editor.editor.full_html']->data === $original, 'Other formats unchanged.');
Drupal::$configs = ['editor.editor.flex_html' => new ConfigStub(['editor' => 'ckeditor']), 'filter.format.flex_html' => new ConfigStub(['existing' => TRUE])];
Drupal::$installed = [];
moody_ckeditor_custom_update_9002();
check(Drupal::$configs['editor.editor.flex_html']->data === ['editor' => 'ckeditor'] && Drupal::$installed === [], 'Legacy and missing formats skipped without installing modules.');
echo "PASS: both Flex formats, toolbar/style preservation, filter safety, idempotence, missing/legacy formats.\n";
