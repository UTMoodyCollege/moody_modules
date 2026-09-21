<?php

// Read-only local smoke test: drush php:script <this file>.
use Drupal\Core\Form\FormState;
$check = static function ($value, $message) { if (!$value) throw new RuntimeException($message); };
$manager = \Drupal::service('plugin.manager.block');
$block = $manager->createInstance('moody_scroll_reveal_media_block');
$state = new FormState();
$form = $block->blockForm([], $state);
$check($form['slides']['#tree'], 'Slide values must retain nesting.');
$slide = ['title' => 'Heading', 'body' => ['value' => 'Subheading', 'format' => 'flex_html'], 'title_display' => 'overlay', 'video_url' => 'https://vimeo.com/123456789', 'text_layout' => ['enabled' => TRUE, 'mobile' => ['title' => ['x' => 4, 'size' => 36]], 'desktop' => ['body' => ['width' => 40]]]];
$state->setValues(['slides' => [$slide]]);
$block->blockSubmit($form, $state);
$saved = $block->getConfiguration();
$reloaded = $manager->createInstance('moody_scroll_reveal_media_block', $saved);
$build = $reloaded->build();
$check($build['#slides'][0]['text_layout']['mobile']['title']['x'] == 4, 'Mobile position did not persist.');
$check($build['#slides'][0]['text_layout']['desktop']['body']['width'] == 40, 'Desktop body width did not persist.');
$html = (string) \Drupal::service('renderer')->renderRoot($build);
$check(str_contains($html, 'overlay--visual') && str_contains($html, '--reveal-mobile-size:2.25rem'), 'Responsive markup missing.');
$legacy = $manager->createInstance('moody_scroll_reveal_media_block', ['slides' => [array_diff_key($slide, ['text_layout' => TRUE])]])->build();
$check(!$legacy['#slides'][0]['text_layout']['enabled'], 'Legacy changed.');
echo "PASS: Drupal form, submit, reload, responsive Twig rendering and legacy fallback.\n";
