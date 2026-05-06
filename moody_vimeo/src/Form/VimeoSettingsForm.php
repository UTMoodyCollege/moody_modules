<?php

declare(strict_types=1);

namespace Drupal\moody_vimeo\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\moody_vimeo\VimeoApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin settings form for configuring Vimeo API credentials.
 */
class VimeoSettingsForm extends ConfigFormBase {

  /**
   * Constructs a VimeoSettingsForm.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected readonly VimeoApiService $vimeoApi,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('moody_vimeo.api'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'moody_vimeo_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['moody_vimeo.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('moody_vimeo.settings');

    $form['intro'] = [
      '#type'   => 'item',
      '#markup' => $this->t(
        'Create a Vimeo app at <a href="https://developer.vimeo.com/apps" target="_blank" rel="noopener noreferrer">developer.vimeo.com</a> and paste the credentials below.'
      ),
    ];

    $form['credentials'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('API Credentials'),
    ];

    $form['credentials']['client_id'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Client ID'),
      '#description'   => $this->t('The Client ID from your Vimeo app.'),
      '#default_value' => $config->get('client_id'),
    ];

    $form['credentials']['client_secret'] = [
      '#type'          => 'password',
      '#title'         => $this->t('Client Secret'),
      '#description'   => $this->t('The Client Secret from your Vimeo app. Leave blank to keep the existing value.'),
      // Password fields intentionally do not pre-populate for security.
    ];

    $form['credentials']['access_token'] = [
      '#type'          => 'password',
      '#title'         => $this->t('Personal Access Token'),
      '#description'   => $this->t('A personal access token with <em>public</em>, <em>private</em>, <em>video_files</em>, and <em>upload</em> scopes. Leave blank to keep the existing value.'),
    ];

    $form['defaults'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Upload Defaults'),
    ];

    $form['defaults']['default_privacy'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Default video privacy'),
      '#options'       => [
        'anybody'  => $this->t('Anyone'),
        'unlisted' => $this->t('Unlisted (link only)'),
        'nobody'   => $this->t('Only me'),
        'password' => $this->t('Password-protected'),
      ],
      '#default_value' => $config->get('default_privacy') ?: 'nobody',
    ];

    $form['defaults']['videos_per_page'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Videos per page'),
      '#min'           => 5,
      '#max'           => 100,
      '#default_value' => $config->get('videos_per_page') ?: 25,
    ];

    // Connection status indicator.
    if ($this->vimeoApi->isConfigured()) {
      $form['status'] = [
        '#type'   => 'item',
        '#markup' => '<p class="messages messages--status">' . $this->t('✓ Access token is configured. Save changes to update credentials.') . '</p>',
      ];
    }
    else {
      $form['status'] = [
        '#type'   => 'item',
        '#markup' => '<p class="messages messages--warning">' . $this->t('No access token saved yet. Enter credentials above to connect.') . '</p>',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('moody_vimeo.settings');

    $config->set('client_id', $form_state->getValue('client_id'));
    $config->set('default_privacy', $form_state->getValue('default_privacy'));
    $config->set('videos_per_page', (int) $form_state->getValue('videos_per_page'));

    // Only overwrite secrets when a new value is provided.
    $client_secret = $form_state->getValue('client_secret');
    if ($client_secret !== '') {
      $config->set('client_secret', $client_secret);
    }

    $access_token = $form_state->getValue('access_token');
    if ($access_token !== '') {
      $config->set('access_token', $access_token);
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
