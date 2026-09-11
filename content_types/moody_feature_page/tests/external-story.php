<?php

/**
 * @file
 * Local Drupal smoke test: drush php:script <path>/tests/external-story.php.
 * Does not save content or change configuration.
 */

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\moody_feature_page\EventSubscriber\ExternalStorySubscriber;
use Drupal\moody_feature_page\ExternalStory;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$check = static function ($condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
foreach (['https://news.utexas.edu/story?a=1&b=2#section', 'http://example.org/story'] as $url) {
  $check(ExternalStory::validUrl($url), 'Valid story URL rejected.');
}
foreach (['', '/node/1', '//example.org', 'javascript:alert(1)', 'mailto:a@example.org', 'ftp://example.org', 'https://user:pass@example.org/', "https://example.org/\nfoo", 'https://example.org/\\evil', 'https://example.org/' . str_repeat('x', 2048)] as $url) {
  $check(!ExternalStory::validUrl($url), 'Unsafe URL accepted.');
}
$node = Node::create(['type' => 'moody_feature_page', 'title' => 'External story smoke test', 'status' => 1]);
$check(!$node->get('field_feature_external')->value, 'Legacy/new pages must default to local.');
$node->set('field_feature_external', 1);
$check(count($node->get('field_feature_external_url')->validate()) > 0, 'Enabled mode needs a URL.');
$node->set('field_feature_external_url', 'https://news.utexas.edu/story');
$check(count($node->get('field_feature_external_url')->validate()) === 0, 'Safe URL failed field validation.');

$subscriber = new ExternalStorySubscriber();
$original = static fn() => ['#markup' => 'Original node content'];
$eventFor = static function (string $route = 'entity.node.canonical', string $format = 'html', int $request_type = HttpKernelInterface::MAIN_REQUEST) use ($node, $original): ControllerEvent {
  $request = Request::create('https://local.example/node/123');
  $request->attributes->add(['_route' => $route, 'node' => $node]);
  $request->setRequestFormat($format);
  return new ControllerEvent(\Drupal::service('http_kernel'), $original, $request, $request_type);
};
foreach (['entity.node.edit_form', 'layout_builder.overrides.node.view', 'entity.node.revision', 'entity.node.preview', 'view.news.page_1'] as $route) {
  $event = $eventFor($route);
  $subscriber->onController($event);
  $check($event->getController() === $original, 'Non-canonical route changed: ' . $route);
}
foreach ([$eventFor('entity.node.canonical', 'json'), $eventFor('entity.node.canonical', 'html', HttpKernelInterface::SUB_REQUEST)] as $event) {
  $subscriber->onController($event);
  $check($event->getController() === $original, 'API or embedded rendering changed.');
}
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(new AnonymousUserSession());
try {
  $event = $eventFor();
  $subscriber->onController($event);
  $build = ($event->getController())();
  $check($build['#automatic'] === TRUE, 'Published visitor notice should forward.');
  $check($event->getRequest()->attributes->get('_moody_external_story'), 'Legacy redirect guard missing.');
  $node->setUnpublished();
  $event = $eventFor();
  $subscriber->onController($event);
  $check(($event->getController())()['#automatic'] === FALSE, 'Unpublished preview must not forward.');
  $node->set('field_feature_external', 0);
  $event = $eventFor();
  $subscriber->onController($event);
  $check($event->getController() === $original, 'Opting out must restore ordinary rendering.');
  $node->set('field_feature_external', 1)->set('field_feature_external_url', 'https://local.example/node/123');
  $event = $eventFor();
  $subscriber->onController($event);
  $check($event->getController() === $original, 'Same-site loop must not forward.');
}
finally {
  $switcher->switchBack();
}
print "PASS: external story URL validation, defaults, visitor forwarding, preview/route guards and opt-out.\n";
