<?php

namespace Drupal\moody_page_launch\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\moody_page_launch\PageLauncher;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the preview-and-confirm page launch form.
 */
final class PageLaunchForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    protected PageLauncher $launcher,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('moody_page_launch.launcher'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'moody_page_launch_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $preview = $form_state->get('moody_page_launch_preview');
    $node_storage = $this->entityTypeManager->getStorage('node');

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Choose the currently live page and its finished replacement. Nothing changes until you review the exact plan and click Launch replacement.') . '</p>',
    ];

    $form['current'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Current live page'),
      '#description' => $this->t('This page will be unpublished and moved to an unused -old-vN URL.'),
      '#target_type' => 'node',
      '#selection_handler' => 'moody_page_launch:node_alias',
      '#selection_settings' => ['match_limit' => 20],
      '#required' => TRUE,
      '#default_value' => $preview ? $node_storage->load($preview['current']['id']) : NULL,
    ];
    $form['replacement'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Replacement page'),
      '#description' => $this->t('This page will be published at the current page URL.'),
      '#target_type' => 'node',
      '#selection_handler' => 'moody_page_launch:node_alias',
      '#selection_settings' => ['match_limit' => 20],
      '#required' => TRUE,
      '#default_value' => $preview ? $node_storage->load($preview['replacement']['id']) : NULL,
    ];

    if ($preview) {
      $form['preview'] = [
        '#type' => 'details',
        '#title' => $this->t('Launch preview'),
        '#open' => TRUE,
      ];
      $form['preview']['summary'] = [
        '#type' => 'table',
        '#header' => [$this->t('Item'), $this->t('Before'), $this->t('After launch')],
        '#rows' => $this->previewRows($preview),
        '#empty' => $this->t('No changes were found.'),
      ];
      $form['preview']['redirects'] = [
        '#markup' => '<p>' . $this->formatPlural(
          count($preview['retarget_redirects']),
          'One existing redirect that points directly to the current node will be retargeted.',
          '@count existing redirects that point directly to the current node will be retargeted.',
        ) . '</p>',
      ];
      if ($preview['retarget_redirects']) {
        $form['preview']['redirect_list'] = [
          '#type' => 'table',
          '#header' => [$this->t('Redirect ID'), $this->t('Existing source'), $this->t('New destination')],
          '#rows' => array_map(
            fn(array $redirect): array => [
              $redirect['id'],
              $redirect['source'],
              '/node/' . $preview['replacement']['id'],
            ],
            $preview['retarget_redirects'],
          ),
        ];
      }
      $form['plan_fingerprint'] = [
        '#type' => 'hidden',
        '#value' => $this->launcher->fingerprint($preview),
      ];
      $form['confirm'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('I reviewed this plan and understand that it publishes the replacement, unpublishes the current page, and changes redirects and aliases.'),
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['preview'] = [
      '#type' => 'submit',
      '#value' => $preview ? $this->t('Refresh preview') : $this->t('Preview launch'),
      '#submit' => ['::previewSubmit'],
    ];
    if ($preview) {
      $form['actions']['launch'] = [
        '#type' => 'submit',
        '#name' => 'launch',
        '#value' => $this->t('Launch replacement'),
        '#button_type' => 'primary',
        '#submit' => ['::launchSubmit'],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $storage = $this->entityTypeManager->getStorage('node');
    $current = $storage->load($form_state->getValue('current'));
    $replacement = $storage->load($form_state->getValue('replacement'));

    if (!$current instanceof NodeInterface) {
      $form_state->setErrorByName('current', $this->t('Select a valid current page.'));
    }
    elseif (!$current->access('update', $this->currentUser())) {
      $form_state->setErrorByName('current', $this->t('You do not have permission to update the current page.'));
    }
    if (!$replacement instanceof NodeInterface) {
      $form_state->setErrorByName('replacement', $this->t('Select a valid replacement page.'));
    }
    elseif (!$replacement->access('update', $this->currentUser())) {
      $form_state->setErrorByName('replacement', $this->t('You do not have permission to update the replacement page.'));
    }
    if ($form_state->hasAnyErrors()) {
      return;
    }

    try {
      $plan = $this->launcher->buildPlan($current, $replacement);
      $form_state->set('moody_page_launch_nodes', [$current, $replacement]);
      $form_state->set('moody_page_launch_current_plan', $plan);
    }
    catch (\InvalidArgumentException $exception) {
      $form_state->setErrorByName('replacement', $exception->getMessage());
      return;
    }

    if (($form_state->getTriggeringElement()['#name'] ?? '') === 'launch') {
      if (!$form_state->getValue('confirm')) {
        $form_state->setErrorByName('confirm', $this->t('Confirm that you reviewed the plan before launching.'));
      }
      $preview_fingerprint = (string) $form_state->getValue('plan_fingerprint');
      if (!hash_equals($preview_fingerprint, $this->launcher->fingerprint($plan))) {
        $form_state->setErrorByName('replacement', $this->t('The launch plan changed. Refresh the preview and review it again.'));
      }
    }
  }

  /**
   * Stores and displays a read-only launch preview.
   */
  public function previewSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->set('moody_page_launch_preview', $form_state->get('moody_page_launch_current_plan'));
    $form_state->setRebuild();
  }

  /**
   * Executes the confirmed launch.
   */
  public function launchSubmit(array &$form, FormStateInterface $form_state): void {
    [$current, $replacement] = $form_state->get('moody_page_launch_nodes');

    try {
      $plan = $this->launcher->launch(
        $current,
        $replacement,
        (string) $form_state->getValue('plan_fingerprint'),
      );
    }
    catch (\Throwable $exception) {
      $this->getLogger('moody_page_launch')->error(
        'Page launch failed for nodes @current and @replacement: @message',
        [
          '@current' => $current->id(),
          '@replacement' => $replacement->id(),
          '@message' => $exception->getMessage(),
        ],
      );
      $this->messenger()->addError($this->t('The replacement was not launched: @message', [
        '@message' => $exception->getMessage(),
      ]));
      $form_state->setRebuild();
      return;
    }

    $this->messenger()->addStatus($this->t(
      'Launched %replacement at %alias. The unpublished former page is now at %archive for editors, and its node URL redirects to the replacement.',
      [
        '%replacement' => $plan['replacement']['title'],
        '%alias' => $plan['current']['alias'],
        '%archive' => $plan['archive_alias'],
      ],
    ));
    $form_state->setRedirect('entity.node.canonical', ['node' => $replacement->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {}

  /**
   * Builds the human-readable before/after table.
   */
  protected function previewRows(array $plan): array {
    $current_status = $plan['current']['published'] ? $this->t('Published') : $this->t('Unpublished');
    $replacement_status = $plan['replacement']['published'] ? $this->t('Published') : $this->t('Unpublished');

    return [
      [
        $this->t('Current page'),
        $this->t('@title (node @id), @status', [
          '@title' => $plan['current']['title'],
          '@id' => $plan['current']['id'],
          '@status' => $current_status,
        ]),
        $this->t('Unpublished with a new revision'),
      ],
      [
        $this->t('Current page URL'),
        $plan['current']['alias'],
        $plan['archive_alias'],
      ],
      [
        $this->t('Replacement page'),
        $this->t('@title (node @id), @status', [
          '@title' => $plan['replacement']['title'],
          '@id' => $plan['replacement']['id'],
          '@status' => $replacement_status,
        ]),
        $this->t('Published with a new revision'),
      ],
      [
        $this->t('Replacement page URL'),
        $plan['replacement']['alias'],
        $plan['current']['alias'],
      ],
      [
        $this->t('Legacy redesign URL'),
        $plan['replacement']['alias'],
        $this->t('301 redirect to @alias', ['@alias' => $plan['current']['alias']]),
      ],
      [
        $this->t('Former node URL'),
        '/node/' . $plan['current']['id'],
        $this->t('301 redirect to replacement node @id', ['@id' => $plan['replacement']['id']]),
      ],
    ];
  }

}
