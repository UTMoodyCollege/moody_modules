<?php

namespace Drupal\moody_scheduled_publishing\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

final class Dashboard extends ControllerBase {

  public function build(): array {
    $scheduler = \Drupal::service('moody_scheduled_publishing.scheduler');
    $account = $this->currentUser();
    $db = \Drupal::database();
    $format = static fn(int $time): string => \Drupal::service('date.formatter')->format($time, 'custom', 'M j, Y g:i a T');
    $last = \Drupal::state()->get('moody_scheduled_publishing.last_run');
    $build = ['#cache' => ['max-age' => 0]];
    $build['intro'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('Schedule content from its normal Edit form, beside Published. Use Edit below to reschedule or cancel. Only content you may edit and publish is shown.')];
    $build['add'] = Link::createFromRoute($this->t('Add content'), 'node.add_page')->toRenderable();
    $build['add']['#attributes']['class'] = ['button', 'button--primary'];
    $build['health'] = ['#type' => 'container', 'text' => ['#plain_text' => $last
      ? $this->t('Last processing run: @time. Due content waits until the next run.', ['@time' => $format($last['time'])])
      : $this->t('No processing run recorded. This local prototype uses the explicit Drush processing command.')]];
    // Bound each page before loading nodes; never expose unfiltered totals.
    $after = max(0, (int) \Drupal::request()->query->get('after', 0));
    $records = $db->select('moody_publish_schedule', 's')->fields('s')
      ->condition('nid', $after, '>')->condition('state', ['pending', 'blocked'], 'IN')
      ->orderBy('nid')->range(0, 51)->execute()->fetchAll();
    $more = count($records) > 50;
    $records = array_slice($records, 0, 50);
    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple(array_column($records, 'nid'));
    $rows = [];
    foreach ($records as $record) {
      $node = $nodes[$record->nid] ?? NULL;
      if (!$node || !$scheduler->canSchedule($node, $account)) {
        continue;
      }
      $rows[] = [
        $node->label(), $format((int) $record->publish_at),
        $record->state === 'blocked' ? $this->t('Needs attention') : ((int) $record->publish_at <= time() ? $this->t('Due / awaiting processing') : $this->t('Scheduled')),
        ['data' => Link::fromTextAndUrl($this->t('Edit schedule'), $node->toUrl('edit-form'))->toRenderable()],
      ];
    }
    $build['schedules'] = ['#type' => 'table', '#caption' => $this->t('Upcoming and overdue'), '#header' => [$this->t('Content'), $this->t('Publish on'), $this->t('Status'), $this->t('Manage')], '#rows' => $rows, '#empty' => $this->t('No editable schedules on this page.')];
    if ($more) {
      $build['next'] = Link::fromTextAndUrl($this->t('Next schedules'), Url::fromRoute('moody_scheduled_publishing.dashboard', [], ['query' => ['after' => end($records)->nid]]))->toRenderable();
    }
    $before = max(0, (int) \Drupal::request()->query->get('before', 0));
    $query = $db->select('moody_publish_event', 'e')->fields('e')->orderBy('id', 'DESC')->range(0, 51);
    if ($before) {
      $query->condition('id', $before, '<');
    }
    $events = $query->execute()->fetchAll();
    $more_events = count($events) > 50;
    $events = array_slice($events, 0, 50);
    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple(array_column($events, 'nid'));
    $users = $this->entityTypeManager()->getStorage('user')->loadMultiple(array_column($events, 'uid'));
    $rows = [];
    foreach ($events as $event) {
      $node = $nodes[$event->nid] ?? NULL;
      if (!$node || !$scheduler->canSchedule($node, $account)) {
        continue;
      }
      $rows[] = [$format((int) $event->created), $node->label(), str_replace('_', ' ', $event->event), isset($users[$event->uid]) ? $users[$event->uid]->label() : $this->t('System / deleted account'), $event->publish_at ? $format((int) $event->publish_at) : '—', $event->revision_id ?: '—'];
    }
    $build['history'] = ['#type' => 'table', '#caption' => $this->t('History'), '#header' => [$this->t('When'), $this->t('Content'), $this->t('Event'), $this->t('Actor'), $this->t('Scheduled time'), $this->t('Revision')], '#rows' => $rows, '#empty' => $this->t('No accessible history on this page.')];
    if ($more_events) {
      $build['older'] = Link::fromTextAndUrl($this->t('Older events'), Url::fromRoute('moody_scheduled_publishing.dashboard', [], ['query' => ['before' => end($events)->id]]))->toRenderable();
    }
    return $build;
  }

}
