<?php

declare(strict_types=1);

namespace Drupal\moody_broken_links\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\moody_broken_links\BrokenLinksManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queues and applies several link changes to one page at once.
 */
final class PageRemediationForm extends FormBase {

  private int $scanId;
  private int $nid;

  public function __construct(private readonly BrokenLinksManager $manager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('moody_broken_links.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'moody_broken_links_page_remediation';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $scan_id = NULL, ?int $nid = NULL): array {
    $this->scanId = (int) $scan_id;
    $this->nid = (int) $nid;
    $results = $this->manager->getPageResults($this->scanId, $this->nid);
    if (!$results) {
      throw new \InvalidArgumentException('This page has no active results in the selected scan.');
    }
    $first = reset($results);

    $form['page'] = [
      '#type' => 'item',
      '#title' => $this->t('Page'),
      '#markup' => Link::fromTextAndUrl(
        (string) $first['title'] ?: $this->t('Node @nid', ['@nid' => $this->nid]),
        Url::fromRoute('entity.node.canonical', ['node' => $this->nid], [
          'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
        ]),
      )->toString(),
    ];
    $form['instructions'] = [
      '#markup' => '<p>' . $this->t('Choose Keep, Revise, or Remove for each link. All queued changes are validated against the scan and saved together in one new page revision. Removing a link retains its text and nested markup.') . '</p>',
    ];
    $form['queue'] = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [
        $this->t('Source'),
        $this->t('Link text'),
        $this->t('Current URL'),
        $this->t('Check'),
        $this->t('Action'),
        $this->t('New URL'),
      ],
    ];
    foreach ($results as $result_id => $result) {
      $status = ucfirst((string) $result['result_status']);
      if ((int) $result['http_code']) {
        $status .= ' (' . $result['http_code'] . ')';
      }
      $form['queue'][$result_id]['source'] = ['#plain_text' => (string) $result['source_label']];
      $form['queue'][$result_id]['text'] = ['#plain_text' => trim((string) $result['link_text']) ?: '—'];
      $form['queue'][$result_id]['url'] = Link::fromTextAndUrl(
        (string) $result['href'],
        Url::fromUri((string) $result['absolute_url'], [
          'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
        ]),
      )->toRenderable();
      $form['queue'][$result_id]['status'] = ['#plain_text' => $status];
      $form['queue'][$result_id]['action'] = [
        '#type' => 'select',
        '#title' => $this->t('Action for @url', ['@url' => $result['href']]),
        '#title_display' => 'invisible',
        '#options' => [
          'keep' => $this->t('Keep'),
          'revise' => $this->t('Revise'),
          'remove' => $this->t('Remove'),
        ],
        '#default_value' => 'keep',
      ];
      $form['queue'][$result_id]['replacement'] = [
        '#type' => 'textfield',
        '#title' => $this->t('New URL for @url', ['@url' => $result['href']]),
        '#title_display' => 'invisible',
        '#maxlength' => 2048,
        '#size' => 32,
        '#placeholder' => '/new-path or https://…',
        '#states' => [
          'visible' => [
            ':input[name="queue[' . $result_id . '][action]"]' => ['value' => 'revise'],
          ],
        ],
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply queued changes'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('moody_broken_links.dashboard'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $queue = (array) $form_state->getValue('queue', []);
    $available = $this->manager->getPageResults($this->scanId, $this->nid);
    $changed = 0;
    foreach ($queue as $result_id => &$choice) {
      if (!isset($available[(int) $result_id])) {
        $form_state->setErrorByName('queue', $this->t('One or more results are no longer available.'));
        return;
      }
      $action = (string) ($choice['action'] ?? 'keep');
      if ($action === 'keep') {
        continue;
      }
      if (!in_array($action, ['revise', 'remove'], TRUE)) {
        $form_state->setErrorByName('queue', $this->t('Choose a valid action for every link.'));
        return;
      }
      $changed++;
      if ($action === 'revise') {
        try {
          $choice['replacement'] = $this->manager->validateReplacement((string) ($choice['replacement'] ?? ''));
        }
        catch (\InvalidArgumentException $exception) {
          $form_state->setErrorByName('queue][' . $result_id . '][replacement', $exception->getMessage());
        }
      }
    }
    unset($choice);
    $form_state->setValue('queue', $queue);
    if (!$changed) {
      $form_state->setErrorByName('queue', $this->t('Choose Revise or Remove for at least one link.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $changes = [];
    foreach ((array) $form_state->getValue('queue', []) as $result_id => $choice) {
      if (($choice['action'] ?? 'keep') !== 'keep') {
        $changes[(int) $result_id] = $choice;
      }
    }
    try {
      $result = $this->manager->remediatePage($this->scanId, $this->nid, $changes);
      $this->messenger()->addStatus($this->t('@count queued link changes were applied in node @nid revision @revision.', [
        '@count' => $result['changed'],
        '@nid' => $result['nid'],
        '@revision' => $result['revision_id'],
      ]));
      $form_state->setRedirect('moody_broken_links.dashboard');
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($exception->getMessage());
    }
  }

}
