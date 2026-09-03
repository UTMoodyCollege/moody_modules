<?php

namespace Drupal\moody_page_launch\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\moody_page_launch\PageLauncher;
use Drupal\node\NodeInterface;
use Drupal\views\ViewEntityInterface;
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
    $type_options = [
      'node' => $this->t('Content page'),
      'view' => $this->t('Views page display'),
    ];

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Choose the currently live page and its finished replacement. Either side may be a content page or one fixed Views page display. Nothing changes until you review the exact plan and click Launch replacement.') . '</p>',
    ];

    $form['current_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Current live page type'),
      '#options' => $type_options,
      '#default_value' => $preview['current']['type'] ?? 'node',
      '#required' => TRUE,
    ];
    $form['current_node'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Current content page'),
      '#description' => $this->t('This page will be unpublished and moved to an unused -old-vN URL.'),
      '#target_type' => 'node',
      '#selection_handler' => 'moody_page_launch:node_alias',
      '#selection_settings' => ['match_limit' => 20],
      '#default_value' => ($preview['current']['type'] ?? NULL) === 'node'
        ? $node_storage->load($preview['current']['id'])
        : NULL,
      '#states' => [
        'visible' => [':input[name="current_type"]' => ['value' => 'node']],
      ],
    ];
    $form['current_view'] = [
      '#type' => 'select',
      '#title' => $this->t('Current Views page display'),
      '#description' => $this->t('Only this page display will be disabled; the View and its other displays remain enabled.'),
      '#options' => $this->launcher->viewPageOptions(TRUE),
      '#empty_option' => $this->t('- Select a Views page -'),
      '#default_value' => ($preview['current']['type'] ?? NULL) === 'view'
        ? $preview['current']['view_id'] . ':' . $preview['current']['display_id']
        : '',
      '#states' => [
        'visible' => [':input[name="current_type"]' => ['value' => 'view']],
      ],
    ];

    $form['replacement_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Replacement page type'),
      '#options' => $type_options,
      '#default_value' => $preview['replacement']['type'] ?? 'node',
      '#required' => TRUE,
    ];
    $form['replacement_node'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Replacement content page'),
      '#description' => $this->t('This page will be published at the current live URL.'),
      '#target_type' => 'node',
      '#selection_handler' => 'moody_page_launch:node_alias',
      '#selection_settings' => ['match_limit' => 20],
      '#default_value' => ($preview['replacement']['type'] ?? NULL) === 'node'
        ? $node_storage->load($preview['replacement']['id'])
        : NULL,
      '#states' => [
        'visible' => [':input[name="replacement_type"]' => ['value' => 'node']],
      ],
    ];
    $form['replacement_view'] = [
      '#type' => 'select',
      '#title' => $this->t('Replacement Views page display'),
      '#description' => $this->t('This display will be enabled at the current live URL. Its other View displays remain unchanged.'),
      '#options' => $this->launcher->viewPageOptions(FALSE),
      '#empty_option' => $this->t('- Select a Views page -'),
      '#default_value' => ($preview['replacement']['type'] ?? NULL) === 'view'
        ? $preview['replacement']['view_id'] . ':' . $preview['replacement']['display_id']
        : '',
      '#states' => [
        'visible' => [':input[name="replacement_type"]' => ['value' => 'view']],
      ],
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
              $preview['replacement_destination'],
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
        '#title' => $this->t('I reviewed this plan and understand that it can publish or unpublish content, enable or disable a Views page display, and change redirects and aliases.'),
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
    $current_target = $this->targetFromForm($form_state, 'current');
    $replacement_target = $this->targetFromForm($form_state, 'replacement');
    if (!$current_target || !$replacement_target || $form_state->hasAnyErrors()) {
      return;
    }

    try {
      $plan = $this->launcher->buildPlan($current_target, $replacement_target);
      $form_state->set('moody_page_launch_targets', [$current_target, $replacement_target]);
      $form_state->set('moody_page_launch_current_plan', $plan);
    }
    catch (\InvalidArgumentException $exception) {
      $form_state->setErrorByName('replacement_type', $exception->getMessage());
      return;
    }

    if (($form_state->getTriggeringElement()['#name'] ?? '') === 'launch') {
      if (!$form_state->getValue('confirm')) {
        $form_state->setErrorByName('confirm', $this->t('Confirm that you reviewed the plan before launching.'));
      }
      $preview_fingerprint = (string) $form_state->getValue('plan_fingerprint');
      if (!hash_equals($preview_fingerprint, $this->launcher->fingerprint($plan))) {
        $form_state->setErrorByName('replacement_type', $this->t('The launch plan changed. Refresh the preview and review it again.'));
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
    [$current_target, $replacement_target] = $form_state->get('moody_page_launch_targets');

    try {
      $plan = $this->launcher->launch(
        $current_target,
        $replacement_target,
        (string) $form_state->getValue('plan_fingerprint'),
      );
    }
    catch (\Throwable $exception) {
      $this->getLogger('moody_page_launch')->error(
        'Page launch failed for @current and @replacement: @message',
        [
          '@current' => json_encode($current_target),
          '@replacement' => json_encode($replacement_target),
          '@message' => $exception->getMessage(),
        ],
      );
      $this->messenger()->addError($this->t('The replacement was not launched: @message', [
        '@message' => $exception->getMessage(),
      ]));
      $form_state->setRebuild();
      return;
    }

    $retired = $plan['current']['type'] === 'node'
      ? $this->t('The unpublished former page is available to editors at %archive.', [
        '%archive' => $plan['archive_path'],
      ])
      : $this->t('The former Views page display is disabled; its other displays are unchanged.');
    $this->messenger()->addStatus($this->t(
      'Launched %replacement at %path. @retired',
      [
        '%replacement' => $plan['replacement']['title'],
        '%path' => $plan['current']['path'],
        '@retired' => $retired,
      ],
    ));

    if ($plan['replacement']['type'] === 'node') {
      $form_state->setRedirect('entity.node.canonical', ['node' => $plan['replacement']['id']]);
    }
    else {
      $form_state->setRedirectUrl(Url::fromUserInput($plan['current']['path']));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {}

  /**
   * Converts one pair of type-specific fields into a launch target.
   */
  protected function targetFromForm(FormStateInterface $form_state, string $prefix): ?array {
    $type = (string) $form_state->getValue($prefix . '_type');
    if ($type === 'node') {
      $node = $this->entityTypeManager->getStorage('node')->load($form_state->getValue($prefix . '_node'));
      if (!$node instanceof NodeInterface) {
        $form_state->setErrorByName($prefix . '_node', $this->t('Select a valid content page.'));
        return NULL;
      }
      if (!$node->access('update', $this->currentUser())) {
        $form_state->setErrorByName($prefix . '_node', $this->t('You do not have permission to update this content page.'));
        return NULL;
      }
      return ['type' => 'node', 'id' => (int) $node->id()];
    }

    if ($type === 'view') {
      $selection = (string) $form_state->getValue($prefix . '_view');
      [$view_id, $display_id] = array_pad(explode(':', $selection, 2), 2, '');
      $view = $this->entityTypeManager->getStorage('view')->load($view_id);
      if (!$view instanceof ViewEntityInterface || !$display_id) {
        $form_state->setErrorByName($prefix . '_view', $this->t('Select a valid Views page display.'));
        return NULL;
      }
      if (!$view->access('update', $this->currentUser())) {
        $form_state->setErrorByName($prefix . '_view', $this->t('You do not have permission to update this View.'));
        return NULL;
      }
      return [
        'type' => 'view',
        'view_id' => $view_id,
        'display_id' => $display_id,
      ];
    }

    $form_state->setErrorByName($prefix . '_type', $this->t('Select a valid page type.'));
    return NULL;
  }

  /**
   * Builds the human-readable before/after table.
   */
  protected function previewRows(array $plan): array {
    $current = $plan['current'];
    $replacement = $plan['replacement'];
    $rows = [
      [
        $this->t('Current page'),
        $this->targetBeforeLabel($current),
        $current['type'] === 'node'
          ? $this->t('Unpublished with a new revision')
          : $this->t('Page display disabled; other View displays unchanged'),
      ],
      [
        $this->t('Current page URL'),
        $current['path'],
        $current['type'] === 'node'
          ? $plan['archive_path']
          : $this->t('Served by the replacement; retained on the disabled display for rollback'),
      ],
      [
        $this->t('Replacement page'),
        $this->targetBeforeLabel($replacement),
        $replacement['type'] === 'node'
          ? $this->t('Published with a new revision')
          : $this->t('Page display enabled; other View displays unchanged'),
      ],
      [
        $this->t('Replacement page URL'),
        $replacement['path'],
        $current['path'],
      ],
    ];

    if ($plan['replacement_legacy_path']) {
      $rows[] = [
        $this->t('Legacy replacement URL'),
        $plan['replacement_legacy_path'],
        $this->t('301 redirect to @path', ['@path' => $current['path']]),
      ];
    }
    if ($current['type'] === 'node') {
      $rows[] = [
        $this->t('Former node URL'),
        '/node/' . $current['id'],
        $this->t('301 redirect to @destination', [
          '@destination' => $plan['replacement_destination'],
        ]),
      ];
    }
    return $rows;
  }

  /**
   * Formats a target's current state for the preview.
   */
  protected function targetBeforeLabel(array $target) {
    if ($target['type'] === 'node') {
      return $this->t('@title (node @id), @status', [
        '@title' => $target['title'],
        '@id' => $target['id'],
        '@status' => $target['published'] ? $this->t('Published') : $this->t('Unpublished'),
      ]);
    }
    return $this->t('@title, @status', [
      '@title' => $target['title'],
      '@status' => $target['enabled'] ? $this->t('Enabled') : $this->t('Disabled'),
    ]);
  }

}
