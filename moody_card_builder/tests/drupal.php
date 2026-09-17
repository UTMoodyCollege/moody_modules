<?php
// Local-only plugin/form/render test. No saved content or configuration.
use Drupal\Core\Form\FormState;
use Drupal\moody_card_builder\CardConfiguration as Cards;
use Drupal\moody_card_builder\Plugin\Block\MoodyCardBuilderBlock;
$data = Cards::defaults();
$data['cards'][0]['rows'][0]['cells'][0]['text'] = '<script>alert("escaped")</script>';
$manager = \Drupal::service('plugin.manager.block');
$block = $manager->createInstance('moody_card_builder', ['collection' => $data]);
for ($i = 0; $i < 3; $i++) {
  $block = $manager->createInstance('moody_card_builder', $block->getConfiguration());
  if ($block->getConfiguration()['collection'] !== $data) { throw new RuntimeException('Cards duplicated on reopen.'); }
}
$state = new FormState();
$state->setValues(['card_builder' => ['source' => json_encode($data), 'images' => '']]);
$form = $block->blockForm([], $state);
$form['card_builder']['source']['#parents'] = ['card_builder', 'source'];
$block->blockValidate($form, $state);
if ($state->hasAnyErrors()) { throw new RuntimeException('Valid form rejected.'); }
$block->blockSubmit($form, $state);
$build = $block->build();
$html = (string) \Drupal::service('renderer')->renderRoot($build);
if (str_contains($html, '<script>') || !str_contains($html, '&lt;script&gt;') || !str_contains($html, 'mcb-split--halves')) { throw new RuntimeException('Unsafe or missing output.'); }
$schema = \Drupal::service('config.typed')->createFromNameAndData('block.settings.moody_card_builder', $block->getConfiguration());
$walk = function ($item) use (&$walk) { if ($item instanceof \Drupal\Core\Config\Schema\Undefined) { throw new RuntimeException('Undefined schema'); } if ($item instanceof \Traversable) { foreach ($item as $child) $walk($child); } };
$walk($schema);
if (MoodyCardBuilderBlock::imageIds(['media_library_selection' => '12,13,12']) !== [12, 13]) { throw new RuntimeException('Media selection parsing'); }
$data['cards'][0]['image'] = 2147483647;
$state->setValue(['card_builder', 'source'], json_encode($data));
$block->blockValidate($form, $state);
if (!$state->hasAnyErrors()) { throw new RuntimeException('Inaccessible image accepted.'); }
echo "PASS: plugin/form round trip, escaped rendering, typed schema, missing media rejected.\n";
