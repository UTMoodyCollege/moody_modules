<?php

declare(strict_types=1);

namespace Drupal\moody_broken_links\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;

/** Preview and confirm broad HTTP-code based removals, without starting scans. */
final class BulkRemovalForm extends FormBase {

  public function getFormId(): string {
    return 'moody_broken_links_bulk_remove';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $bulk = \Drupal::service('moody_broken_links.bulk_removal');
    $form['warning'] = ['#markup' => '<p>' . $this->t('403, 401, 429 and connection failures can be temporary or access restrictions—not dead links. Review destinations before confirming. HTML anchors keep their visible content; removing a dedicated link-field item deletes that item. No replacements are guessed.') . '</p>'];
    if ($id = $form_state->get('bulk_audit_id')) {
      $plan = $bulk->read($id);
      $links = array_merge(...array_column($plan['pages'], 'links'));
      $form['summary'] = ['#markup' => '<p>' . $this->t('Remove @links links from @pages pages for HTTP codes @codes. @excluded findings are excluded. One new revision per successfully changed page; publication status is preserved. Source changes will stop the affected page.', [
        '@links' => count($links), '@pages' => count($plan['pages']), '@codes' => implode(', ', $plan['codes']), '@excluded' => count($plan['excluded']),
      ]) . '</p>'];
      $form['audit'] = Link::fromTextAndUrl($this->t('Download full preview (private JSON)'), $this->auditUrl($id))->toRenderable();
      $items = [];
      foreach (array_slice($links, 0, 50) as $link) {
        $items[] = ['#plain_text' => $link['http_code'] . ' — ' . $link['href'] . ' — ' . $link['source'] . ($link['removes_field_item'] ? ' (entire link-field item)' : '')];
      }
      $form['preview'] = ['#theme' => 'item_list', '#title' => $this->t('First 50 matching links; the download includes every page and URL'), '#items' => $items];
      $form['confirm'] = ['#type' => 'checkbox', '#required' => TRUE, '#title' => $this->t('I reviewed this exact selection and authorize removing these links, including any selected access-denied responses or link-field items.')];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['execute'] = ['#type' => 'submit', '#value' => $this->t('Confirm and remove selected links'), '#button_type' => 'primary', '#submit' => ['::execute']];
      $form['actions']['back'] = ['#type' => 'submit', '#value' => $this->t('Change selection'), '#submit' => ['::back'], '#limit_validation_errors' => []];
      return $form;
    }
    $options = $bulk->codeOptions();
    if (!$options) {
      $form['empty'] = ['#markup' => '<p>' . $this->t('No completed scan with unrepaired HTTP errors is available. Wait for the current scan to finish.') . '</p>'];
      return $form;
    }
    $form['codes'] = ['#type' => 'select', '#multiple' => TRUE, '#required' => TRUE, '#title' => $this->t('HTTP codes to remove'), '#options' => $options, '#default_value' => array_values(array_intersect([404, 410], array_keys($options)))];
    $form['include_fields'] = ['#type' => 'checkbox', '#title' => $this->t('Also include dedicated link fields (their link item or URL is removed)'), '#default_value' => FALSE];
    $form['actions'] = ['#type' => 'actions', 'preview' => ['#type' => 'submit', '#value' => $this->t('Preview bulk removals'), '#button_type' => 'primary']];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('bulk_audit_id')) {
      return;
    }
    try {
      $id = \Drupal::service('moody_broken_links.bulk_removal')->preview((array) $form_state->getValue('codes'), (bool) $form_state->getValue('include_fields'));
      $form_state->set('bulk_candidate_id', $id);
    }
    catch (\Throwable $e) {
      $form_state->setErrorByName('codes', $e->getMessage());
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->set('bulk_audit_id', $form_state->get('bulk_candidate_id'))->setRebuild();
  }

  public function back(array &$form, FormStateInterface $form_state): void {
    $form_state->set('bulk_audit_id', NULL)->setRebuild();
  }

  public function execute(array &$form, FormStateInterface $form_state): void {
    $id = $form_state->get('bulk_audit_id');
    if (!$form_state->getValue('confirm') || !$id) {
      throw new \RuntimeException('Review and confirm the preview first.');
    }
    $pages = \Drupal::service('moody_broken_links.bulk_removal')->start($id);
    $operations = array_map(static fn($nid) => [[self::class, 'processPage'], [$id, (int) $nid]], $pages);
    batch_set(['title' => $this->t('Removing reviewed links'), 'operations' => $operations, 'finished' => [self::class, 'finished']]);
    $form_state->setRedirect('moody_broken_links.dashboard');
  }

  public static function processPage(string $id, int $nid, array &$context): void {
    $context['results']['audit_id'] = $id;
    $result = \Drupal::service('moody_broken_links.bulk_removal')->applyPage($id, $nid);
    $context['results'][$result['status']] = ($context['results'][$result['status']] ?? 0) + 1;
    $context['message'] = t('Processed page @nid; @status.', ['@nid' => $nid, '@status' => $result['status']]);
  }

  public static function finished(bool $success, array $results, array $operations): void {
    if (!empty($results['audit_id'])) {
      \Drupal::service('moody_broken_links.bulk_removal')->finish($results['audit_id'], $success);
    }
    \Drupal::messenger()->addStatus(t('Bulk removal: @changed pages changed, @failed failed. Review the private audit report; a fresh scan is still needed.', ['@changed' => $results['changed'] ?? 0, '@failed' => $results['failed'] ?? 0]));
    if (!$success) {
      \Drupal::messenger()->addError(t('The batch was interrupted. Do not repeat it blindly; inspect the audit outcomes first.'));
    }
  }

  private function auditUrl(string $id): Url {
    [$date, $token] = explode('/', $id);
    return Url::fromRoute('moody_broken_links.audit_download', ['date' => $date, 'token' => $token]);
  }

}
