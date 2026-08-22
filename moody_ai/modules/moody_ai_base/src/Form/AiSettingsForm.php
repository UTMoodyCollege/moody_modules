<?php

declare(strict_types=1);

namespace Drupal\moody_ai_base\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\moody_ai_base\AiGenerationService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the shared Moody AI service without accepting secrets.
 */
final class AiSettingsForm extends ConfigFormBase {

  /**
   * Constructs the settings form.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly AiGenerationService $generator,
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
      $container->get('moody_ai_base.generator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'moody_ai_base_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['moody_ai_base.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('moody_ai_base.settings');
    $models = $this->generator->modelOptions();
    $model_lines = [];
    foreach ($models as $id => $label) {
      $model_lines[] = $id . '|' . $label;
    }

    $form['security'] = [
      '#type' => 'item',
      '#markup' => $this->t('<p>API keys are never stored in Drupal configuration. The configured name is resolved from a Pantheon runtime secret or an uppercase local environment variable.</p>'),
    ];

    $form['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Provider'),
      '#options' => $this->generator->providerOptions(),
      '#default_value' => 'openai',
      '#disabled' => TRUE,
    ];

    $form['secret_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OpenAI runtime secret name'),
      '#default_value' => $config->get('openai.secret_name'),
      '#required' => TRUE,
      '#maxlength' => 64,
      '#description' => $this->t('For example, <code>moody_ai_openai_api_key</code>. DDEV resolves the same name as <code>MOODY_AI_OPENAI_API_KEY</code>.'),
    ];

    $form['models'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed models'),
      '#default_value' => implode("\n", $model_lines),
      '#required' => TRUE,
      '#rows' => 4,
      '#description' => $this->t('One allowlisted model per line in <code>model-id|Editor label</code> format.'),
    ];

    $form['default_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default model'),
      '#default_value' => $config->get('openai.default_model'),
      '#required' => TRUE,
      '#description' => $this->t('Must match one of the model IDs above.'),
    ];

    $form['image_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image model'),
      '#default_value' => $config->get('openai.image_model') ?: 'gpt-image-2',
      '#required' => TRUE,
      '#description' => $this->t('Used only when a feature explicitly requests a generated image.'),
    ];

    $form['additional_context'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Additional editorial context'),
      '#default_value' => $config->get('additional_context'),
      '#rows' => 6,
      '#maxlength' => 5000,
      '#description' => $this->t('Optional organization-specific guidance. Do not enter secrets. Built-in security, accuracy, accessibility, and design-system rules cannot be relaxed here.'),
    ];

    $form['limits'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Usage limits'),
    ];
    $form['limits']['max_prompt_characters'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum prompt characters'),
      '#default_value' => $config->get('max_prompt_characters') ?: 2000,
      '#min' => 200,
      '#max' => 10000,
      '#required' => TRUE,
    ];
    $form['limits']['max_output_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum output tokens'),
      '#default_value' => $config->get('max_output_tokens') ?: 1800,
      '#min' => 200,
      '#max' => 4000,
      '#required' => TRUE,
    ];
    $form['limits']['hourly_request_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Requests per user per hour'),
      '#default_value' => $config->get('hourly_request_limit') ?: 20,
      '#min' => 1,
      '#max' => 100,
      '#required' => TRUE,
    ];

    $form['status'] = [
      '#type' => 'item',
      '#markup' => $this->generator->isConfigured()
        ? '<p class="messages messages--status">' . $this->t('The OpenAI runtime secret is available.') . '</p>'
        : '<p class="messages messages--warning">' . $this->t('The OpenAI runtime secret is not available in this environment.') . '</p>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $secret_name = trim((string) $form_state->getValue('secret_name'));
    if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $secret_name)) {
      $form_state->setErrorByName('secret_name', $this->t('Use 2–64 lowercase letters, numbers, or underscores, beginning with a letter.'));
    }

    $models = $this->parseModels((string) $form_state->getValue('models'));
    if ($models === NULL) {
      $form_state->setErrorByName('models', $this->t('Enter 1–10 unique lines using model-id|Editor label.'));
      return;
    }

    $default = trim((string) $form_state->getValue('default_model'));
    if (!isset($models[$default])) {
      $form_state->setErrorByName('default_model', $this->t('The default model must be in the allowed model list.'));
    }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/', trim((string) $form_state->getValue('image_model')))) {
      $form_state->setErrorByName('image_model', $this->t('Enter a valid image model ID.'));
    }
    $form_state->set('moody_ai_models', $models);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $models = $form_state->get('moody_ai_models');
    $model_records = [];
    foreach ($models as $id => $label) {
      $model_records[] = ['id' => $id, 'label' => $label];
    }
    $this->config('moody_ai_base.settings')
      ->set('provider', 'openai')
      ->set('openai.secret_name', trim((string) $form_state->getValue('secret_name')))
      ->set('openai.models', $model_records)
      ->set('openai.default_model', trim((string) $form_state->getValue('default_model')))
      ->set('openai.image_model', trim((string) $form_state->getValue('image_model')))
      ->set('additional_context', trim((string) $form_state->getValue('additional_context')))
      ->set('max_prompt_characters', (int) $form_state->getValue('max_prompt_characters'))
      ->set('max_output_tokens', (int) $form_state->getValue('max_output_tokens'))
      ->set('hourly_request_limit', (int) $form_state->getValue('hourly_request_limit'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Parses an administrator-maintained model allowlist.
   */
  private function parseModels(string $input): ?array {
    $models = [];
    foreach (preg_split('/\R/', trim($input)) ?: [] as $line) {
      $parts = array_map('trim', explode('|', $line, 2));
      if (count($parts) !== 2 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/', $parts[0]) || $parts[1] === '' || isset($models[$parts[0]])) {
        return NULL;
      }
      $models[$parts[0]] = mb_substr($parts[1], 0, 100);
    }
    return $models && count($models) <= 10 ? $models : NULL;
  }

}
