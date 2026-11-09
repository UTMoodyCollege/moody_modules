<?php

namespace Drupal\moody_url_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\moody_url_manager\UrlManager;

/** A server-rendered, preview-first URL workspace. */
final class UrlManagerForm extends FormBase {

  public function getFormId() { return 'moody_url_manager'; }

  public function buildForm(array $form, FormStateInterface $form_state, $moody_subsite = NULL) {
    $scope = $moody_subsite ? (int) $moody_subsite->id() : NULL;
    UrlManager::assertScope($scope);
    $form_state->set('scope', $scope);
    $form['#attached']['library'][] = 'moody_url_manager/manager';
    $form['#attributes']['class'][] = 'moody-urls';
    $form['#cache']['max-age'] = 0;
    $label = $moody_subsite ? \Drupal::service('moody_subsite.editor_context')->label($moody_subsite) : 'Overall site';
    $form['intro'] = ['#type' => 'container', '#attributes' => ['class' => ['url-intro']],
      'eyebrow' => ['#markup' => '<p class="url-eyebrow">MOODY EDITOR TOOLS</p>'],
      'title' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => \Drupal\Component\Utility\Html::escape($label)],
      'copy' => ['#markup' => '<p>Understand your page addresses. Preview changes together. Keep the URLs people already use working.</p>'],
    ];
    $last = \Drupal::service('tempstore.private')->get('moody_url_manager')->get('last_result') ?? [];
    $last = array_filter($last, fn($row) => $row['scope'] === $scope);
    if ($last && !$form_state->get('plans')) {
      $form['last_result'] = ['#type' => 'details', '#title' => $this->t('Last batch: @count URLs updated', ['@count' => count($last)]), '#open' => TRUE,
        'table' => ['#type' => 'table', '#header' => ['Old URL (301 retained)', 'New URL'], '#attributes' => ['class' => ['url-report-table']], '#rows' => array_map(fn($row) => [$row['old'], $row['new']], $last)]];
    }
    if ($plans = $form_state->get('plans')) {
      $form['steps'] = ['#markup' => '<p class="url-steps">1. Select pages <strong>→ 2. Review changes</strong> → 3. Run updates</p>'];
      $form['notice'] = ['#markup' => '<div class="url-notice"><strong>Nothing has changed yet.</strong> Every old alias will receive a permanent (301) redirect. Existing incoming redirects are retained. Publication status and page copy stay the same.</div>'];
      $rows = [];
      foreach ($plans as $plan) {
        $s = $plan['before'];
        $rows[] = [$s['title'], $s['alias'], $plan['new'], $s['alias'] === $s['path'] ? 'System path stays available' : '301 → new address', count($s['redirects']) . ' retained'];
      }
      $form['preview'] = ['#type' => 'table', '#header' => ['Page', 'Current address', 'New address', 'Old URL protection', 'Existing redirects'], '#rows' => $rows, '#attributes' => ['class' => ['url-table', 'url-preview-table']]];
      $form['caution'] = ['#markup' => '<p>Hard-coded navigation and campaign links are not rewritten. They will continue through redirects. Multiple aliases, occupied destinations and stale previews are blocked.</p>'];
      $form['confirm'] = ['#type' => 'checkbox', '#title' => $this->t('I reviewed these @count changes and want to run the batch.', ['@count' => count($plans)]), '#required' => TRUE];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['apply'] = ['#type' => 'submit', '#value' => $this->t('Run @count URL updates', ['@count' => count($plans)]), '#button_type' => 'primary', '#submit' => ['::execute']];
      $form['actions']['back'] = ['#type' => 'submit', '#value' => $this->t('Back to selection'), '#limit_validation_errors' => [], '#submit' => ['::back']];
      return $form;
    }
    if ($scope === NULL) {
      $form['redirect_inventory'] = ['#type' => 'link', '#title' => $this->t('Browse all site redirects, including external destinations'), '#url' => Url::fromRoute('redirect.list'), '#attributes' => ['class' => ['button', 'button--small']]];
    }
    $request = $this->getRequest();
    $search = trim((string) $request->query->get('search', ''));
    $status = (string) $request->query->get('status', 'all');
    $base = UrlManager::query($scope);
    if ($status === 'published' || $status === 'draft') { $base->condition('status', $status === 'published' ? 1 : 0); }
    if ($search !== '') {
      $alias_ids = \Drupal::entityQuery('path_alias')->accessCheck(FALSE)->condition('alias', $search, 'CONTAINS')->execute();
      $nids = [];
      foreach (\Drupal::entityTypeManager()->getStorage('path_alias')->loadMultiple($alias_ids) as $a) {
        if (preg_match('@^/node/(\d+)$@D', $a->getPath(), $m)) { $nids[] = (int) $m[1]; }
      }
      if (preg_match('@^/node/(\d+)$@D', $search, $m)) { $nids[] = (int) $m[1]; }
      $base->condition($base->orConditionGroup()->condition('title', $search, 'CONTAINS')->condition('nid', $nids ?: [-1], 'IN'));
    }
    $count = (clone $base)->count()->execute();
    $form['summary'] = ['#markup' => '<p class="url-summary"><strong>' . (int) $count . '</strong> matching pages <span>· Page aliases · Incoming redirects · Published & draft</span></p>'];
    $form['filters'] = ['#type' => 'container', '#attributes' => ['class' => ['url-filters']]];
    $form['filters']['search'] = ['#type' => 'textfield', '#title' => $this->t('Find a page or path'), '#default_value' => $search, '#placeholder' => 'Title, alias or /node/123'];
    $form['filters']['status'] = ['#type' => 'select', '#title' => $this->t('Visibility'), '#options' => ['all' => 'Published & draft', 'published' => 'Published', 'draft' => 'Draft'], '#default_value' => $status];
    $form['filters']['filter'] = ['#type' => 'submit', '#value' => $this->t('Filter'), '#submit' => ['::filter'], '#limit_validation_errors' => [['search'], ['status']]];
    $form['bulk'] = ['#type' => 'details', '#title' => $this->t('Optional: replace a path prefix for selected pages'), '#open' => FALSE];
    $form['bulk']['from'] = ['#type' => 'textfield', '#title' => $this->t('Current prefix'), '#placeholder' => '/programs/old-name', '#description' => $this->t('Matches a whole path segment; not words inside a URL.')];
    $form['bulk']['to'] = ['#type' => 'textfield', '#title' => $this->t('New prefix'), '#placeholder' => '/summer'];
    $form['help'] = ['#markup' => '<p>Select one or more pages and enter their new addresses, or use the prefix fields above. You will review the exact changes before anything is saved.</p>'];
    $form['select_all'] = ['#type' => 'html_tag', '#tag' => 'button', '#value' => 'Select all editable pages shown', '#attributes' => ['type' => 'button', 'class' => ['button', 'url-select-all']]];
    $form['pages'] = ['#type' => 'table', '#header' => ['Select', 'Page / system path', 'Visibility', 'Current alias', 'Incoming redirects', 'New alias'], '#tree' => TRUE, '#empty' => $this->t('No pages match these filters.'), '#attributes' => ['class' => ['url-table']]];
    $ids = $base->pager(25)->execute();
    $editable = [];
    foreach (\Drupal::entityTypeManager()->getStorage('node')->loadMultiple($ids) as $node) {
      if (!$node->access('view')) { continue; }
      $s = UrlManager::snapshot($node);
      $nid = $node->id();
      $can = $node->access('update') && $node->hasField('path') && $node->get('path')->access('edit');
      if ($can) { $editable[] = (int) $nid; }
      $form['pages'][$nid]['selected'] = ['#type' => 'checkbox', '#title' => $this->t('Select @title', ['@title' => $node->label()]), '#title_display' => 'invisible', '#disabled' => !$can];
      $form['pages'][$nid]['page'] = ['title' => ['#type' => 'link', '#title' => $node->label(), '#url' => $node->toUrl()], 'path' => ['#type' => 'html_tag', '#tag' => 'small', '#value' => $s['path'] . ' · ' . $s['language']]];
      $form['pages'][$nid]['status'] = ['#type' => 'html_tag', '#tag' => 'span', '#value' => $s['published'] ? 'Published' : 'Draft', '#attributes' => ['class' => ['url-badge', $s['published'] ? 'is-published' : 'is-draft']]];
      $form['pages'][$nid]['alias'] = ['#plain_text' => implode("\n", array_column($s['aliases'], 'alias')) ?: 'No alias — system path only'];
      $redirect_rows = [];
      foreach ($s['redirects'] as $r) {
        $redirect_rows[] = [
          'source' => ['#type' => 'html_tag', '#tag' => 'code', '#value' => \Drupal\Component\Utility\Html::escape('/' . $r['source'][0]['path'])],
          'destination' => ['#type' => 'html_tag', '#tag' => 'small', '#value' => \Drupal\Component\Utility\Html::escape($r['code'] . ' → ' . ($r['destination'][0]['uri'] ?? ''))],
        ];
      }
      $form['pages'][$nid]['redirects'] = $redirect_rows ? ['#type' => 'details', '#title' => $this->t('@count redirects', ['@count' => count($redirect_rows)]), 'rows' => ['#theme' => 'item_list', '#items' => $redirect_rows, '#attributes' => ['class' => ['url-redirect-list']]]] : ['#plain_text' => 'None'];
      $form['pages'][$nid]['new'] = ['#type' => 'textfield', '#title' => $this->t('New alias for @title', ['@title' => $node->label()]), '#title_display' => 'invisible', '#placeholder' => '/new-page-address', '#disabled' => !$can, '#size' => 26];
    }
    $form_state->set('editable', $editable);
    $form['pager'] = ['#type' => 'pager'];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['preview'] = ['#type' => 'submit', '#value' => $this->t('Preview selected URL changes'), '#button_type' => 'primary'];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getTriggeringElement()['#parents'][0] !== 'preview') { return; }
    $plans = []; $destinations = [];
    $from = trim((string) $form_state->getValue('from')); $to = trim((string) $form_state->getValue('to'));
    foreach ($form_state->getValue('pages', []) as $nid => $row) {
      if (empty($row['selected']) || !in_array((int) $nid, $form_state->get('editable'), TRUE)) { continue; }
      try {
        $new = trim((string) $row['new']);
        if ($new === '' && $from !== '' && $to !== '') {
          UrlManager::validateAlias($from); UrlManager::validateAlias($to);
          $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
          $old = UrlManager::snapshot($node)['alias'];
          if ($old !== $from && !str_starts_with($old, $from . '/')) { throw new \InvalidArgumentException('Current alias does not match the requested prefix.'); }
          $new = $to . substr($old, strlen($from));
        }
        $key = strtolower($new);
        if (isset($destinations[$key])) { throw new \InvalidArgumentException('Two selected pages cannot have the same destination.'); }
        $plans[] = UrlManager::plan((int) $nid, $new, $form_state->get('scope'));
        $destinations[$key] = TRUE;
      }
      catch (\Throwable $e) { $form_state->setErrorByName('pages][' . $nid . '][new', $e->getMessage()); }
    }
    if (!$plans && !$form_state->hasAnyErrors()) { $form_state->setErrorByName('pages', 'Select at least one page and provide a new alias.'); }
    if (!$form_state->hasAnyErrors()) { $form_state->set('pending', $plans); }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->set('plans', $form_state->get('pending'));
    $form_state->setRebuild();
  }

  public function back(array &$form, FormStateInterface $form_state) { $form_state->set('plans', NULL); $form_state->setRebuild(); }

  public function filter(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirectUrl(Url::fromRoute('<current>', [], ['query' => ['search' => $form_state->getValue('search'), 'status' => $form_state->getValue('status')]]));
  }

  public function execute(array &$form, FormStateInterface $form_state) {
    UrlManager::assertScope($form_state->get('scope'));
    $operations = [];
    foreach ($form_state->get('plans') as $plan) { $operations[] = [[UrlManager::class, 'apply'], [$plan]]; }
    batch_set(['title' => $this->t('Updating page URLs'), 'operations' => $operations, 'finished' => [UrlManager::class, 'finished'], 'error_message' => $this->t('A URL update could not finish. Review completed updates before retrying.')]);
    $form_state->set('plans', NULL);
    $scope = $form_state->get('scope');
    $form_state->setRedirect($scope === NULL ? 'moody_url_manager.overall' : 'moody_url_manager.subsite', $scope === NULL ? [] : ['moody_subsite' => $scope]);
  }
}
