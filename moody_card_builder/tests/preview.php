<?php
if (!getenv('DDEV_SITENAME') || getenv('PANTHEON_ENVIRONMENT')) { throw new RuntimeException('Local DDEV only.'); }
use Drupal\moody_card_builder\CardConfiguration as Cards;
use Drupal\node\Entity\Node;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
$title = 'Moody Card Builder — Local Preview';
$existing = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('title', $title)->execute();
if ($existing) { echo 'Existing preview node: ' . reset($existing) . PHP_EOL; return; }
$images = [];
$media = \Drupal::entityTypeManager()->getStorage('media');
foreach ($media->getQuery()->accessCheck(FALSE)->condition('bundle', 'utexas_image')->condition('status', 1)->sort('mid', 'DESC')->range(0, 100)->execute() as $mid) {
  $m = $media->load($mid); $field = $m->getSource()->getConfiguration()['source_field'];
  $file = $m->get($field)->entity;
  if ($file && file_exists($file->getFileUri())) { $images[] = (int) $mid; }
  if (count($images) === 3) break;
}
$programs = Cards::defaults(); $programs['cards'] = [];
foreach (['World Schools Debate', 'Policy / Cross Examination', 'Congressional Debate'] as $i => $name) {
  $card = Cards::card('program-' . $i);
  $card['rows'][0]['cells'][0]['text'] = $name;
  $card['rows'][1]['cells'][0]['text'] = ['International-format team debate with experienced coaches. Build your voice, collaborate and practice.', 'Evidence-based debate with practical instruction and time to refine your research and delivery.', 'Legislative debate and public advocacy through focused practice and thoughtful feedback.'][$i];
  $card['rows'][2]['cells'][0]['text'] = '$1,400 / $2,800';
  $card['rows'][2]['cells'][1]['text'] = 'Register →';
  $card['rows'][2]['cells'][1]['url'] = '/about';
  array_unshift($card['rows'], ['id' => 'badge', 'split' => 'full', 'stack_mobile' => TRUE, 'divider' => FALSE, 'bottom' => FALSE, 'cells' => [Cards::cell('badge', ['WSD', 'CX', 'CD'][$i])]]);
  array_splice($card['rows'], 2, 0, [['id' => 'label', 'split' => 'full', 'stack_mobile' => TRUE, 'divider' => FALSE, 'bottom' => FALSE, 'cells' => [Cards::cell('eyebrow', '1 OR 2 WEEK · OVERNIGHT')]]]);
  $programs['cards'][] = $card;
}
$photos = Cards::defaults(); $photos['cards'] = [];
foreach (['Learn together', 'Build your confidence', 'Share your story'] as $i => $name) {
  $card = Cards::card('photo-' . $i); $card['image'] = $images[$i] ?? 0;
  $card['rows'][0]['cells'][0]['text'] = $name;
  $card['rows'][1]['cells'][0]['text'] = 'A focused environment for practice, discovery and collaboration.';
  array_pop($card['rows']);
  foreach ($card['responsive'] as &$settings) { $settings['share'] = 75; } unset($settings);
  $photos['cards'][] = $card;
}
$node = Node::create(['type' => 'moody_standard_page', 'title' => $title, 'status' => 0, 'uid' => 1]);
$section = new Section('layout_onecol');
foreach ([$programs, $photos] as $i => $collection) {
  $component = new SectionComponent(\Drupal::service('uuid')->generate(), 'content', ['id' => 'moody_card_builder', 'label' => 'Card Builder example', 'label_display' => FALSE, 'provider' => 'moody_card_builder', 'collection' => Cards::validate($collection), 'images' => $images]);
  $component->setWeight($i); $section->appendComponent($component);
}
$node->set('layout_builder__layout', [$section]); $node->save();
echo 'Local unpublished preview: /node/' . $node->id() . PHP_EOL;
