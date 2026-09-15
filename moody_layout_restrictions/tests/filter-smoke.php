<?php

/** Read-only Drupal smoke check; no entities or configuration are saved. */

use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\moody_layout_restrictions\Restrictions;
use Drupal\node\Entity\Node;

$check = static function ($condition, $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$definitions = [
  'safe' => ['category' => 'Specialty Blocks'],
  'field' => ['category' => new TranslatableMarkup('@entity fields')],
  'system' => ['category' => 'System'],
  'inline_block:utexas_hero' => ['category' => 'Inline blocks'],
  'inline_block:basic' => ['category' => 'Inline blocks'],
  'utexas_announcement' => ['category' => 'UTexas'],
  'other_utexas' => ['category' => 'UTexas'],
];
$rules = ['entity_view_mode_restriction' => [
  'restricted_categories' => ['System'],
  'allowlisted_blocks' => ['Content fields' => [], 'UTexas' => ['utexas_announcement']],
  'denylisted_blocks' => ['Inline blocks' => ['inline_block:utexas_hero']],
]];
$check(array_keys(Restrictions::blocks($definitions, $rules)) === ['safe', 'inline_block:basic', 'utexas_announcement'], 'Category/allowlist/denylist/inline filtering failed.');
$check(Restrictions::blocks($definitions, []) === $definitions, 'Unrestricted contexts changed.');
$check(Restrictions::settings([]) === [], 'Absent section context must be unrestricted.');
$legacy = ['entity_view_mode_restriction' => ['whitelisted_blocks' => ['Specialty Blocks' => []]]];
$check(Restrictions::blocks($definitions, $legacy) === $definitions, 'Inert historical keys were reactivated.');
$overrides = 0;
foreach (\Drupal::entityTypeManager()->getStorage('entity_view_display')->loadMultiple() as $display) {
  if ($display->getTargetEntityTypeId() !== 'node' || !$display->getThirdPartySettings('moody_layout_restrictions') || !$display->isOverridable()) {
    continue;
  }
  $node = Node::create(['type' => $display->getTargetBundle()]);
  $storage = \Drupal::service('plugin.manager.layout_builder.section_storage')->load('overrides', ['entity' => EntityContext::fromEntity($node)]);
  $check($storage && Restrictions::settings(['section_storage' => $storage]) === $display->getThirdPartySettings('moody_layout_restrictions'), 'Override failed to inherit display rules.');
  $overrides++;
}
echo "PASS: category, allow/deny, inline, inert legacy, missing-context and $overrides override-inheritance checks.\n";
