<?php

declare(strict_types=1);

namespace Drupal\moody_ai_seo\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures public agent guidance and organization structured data.
 */
final class SeoSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'moody_ai_seo_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['moody_ai_seo.settings', 'llms_txt.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('moody_ai_seo.settings');
    $llms_config = $this->config('llms_txt.settings');
    $llms_url = Url::fromRoute('llms_txt.llms_txt', [], ['absolute' => TRUE])->toString();

    $form['overview'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Agent-readiness dashboard'),
      '#description' => $this->t('Protocol behavior stays consistent in code. This page stores the identity and editorial guidance that must be reviewed for each site.'),
    ];
    $form['overview']['automatic'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Managed automatically'),
      '#items' => [
        $this->t('Real 404 responses with a concise Markdown recovery path for agents.'),
        $this->t('Discovery headers pointing public HTML responses to /llms.txt.'),
        $this->t('Correct Accept cache variation on negotiated Markdown 404 responses.'),
        $this->t('A read-only Drush audit for public endpoints and machine-readable signals.'),
      ],
    ];
    $form['overview']['manual'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Review for this site'),
      '#items' => [
        $this->t('Agent guidance and any real developer resources.'),
        $this->t('Organization contact details, postal address, and official profiles before enabling JSON-LD.'),
        $this->t('Published About, Contact, and Privacy pages with meaningful content.'),
        $this->t('A server-rendered homepage with one H1, useful supporting headings, and substantial text.'),
      ],
    ];

    $form['guidance'] = [
      '#type' => 'details',
      '#title' => $this->t('Agent guidance (llms.txt)'),
      '#description' => $this->t('This public Markdown file tells agents when to use the site and where to find canonical information. Tokens such as [site:name], [site:slogan], and [site:url] are supported.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['guidance']['llms_content'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Public agent guidance'),
      '#default_value' => $llms_config->get('content'),
      '#required' => TRUE,
      '#rows' => 14,
      '#wysiwyg' => FALSE,
      '#description' => $this->t('Keep “When to use this site” factual. Add a “Developer resources” section only when the site has real public technical documentation. <a href=":url" target="_blank" rel="noopener">Preview /llms.txt</a>.', [':url' => $llms_url]),
    ];

    $form['organization'] = [
      '#type' => 'details',
      '#title' => $this->t('Organization structured data'),
      '#description' => $this->t('Publish a Schema.org identity record on the homepage. Blank name, URL, description, and email fields fall back to existing site and Metatag configuration.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['organization']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Publish Organization JSON-LD on the homepage'),
      '#default_value' => $config->get('organization.enabled'),
    ];
    $form['organization']['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Organization type'),
      '#options' => [
        'CollegeOrUniversity' => $this->t('College or university'),
        'EducationalOrganization' => $this->t('Educational organization'),
        'Organization' => $this->t('Organization'),
      ],
      '#default_value' => $config->get('organization.type'),
    ];

    foreach ([
      'name' => [$this->t('Name override'), 255],
      'description' => [$this->t('Description override'), 500],
      'url' => [$this->t('Canonical URL override'), 2048],
      'logo' => [$this->t('Logo URL'), 2048],
      'email' => [$this->t('Contact email override'), 254],
      'telephone' => [$this->t('Contact telephone'), 64],
      'contact_type' => [$this->t('Contact type'), 128],
    ] as $key => [$title, $maxlength]) {
      $element = [
        '#type' => $key === 'description' ? 'textarea' : ($key === 'email' ? 'email' : 'textfield'),
        '#title' => $title,
        '#default_value' => $config->get("organization.$key"),
        '#maxlength' => $maxlength,
      ];
      if ($key === 'description') {
        $element['#rows'] = 3;
      }
      $form['organization'][$key] = $element;
    }

    $form['organization']['address'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Postal address'),
    ];
    foreach ([
      'street_address' => $this->t('Street address'),
      'address_locality' => $this->t('City'),
      'address_region' => $this->t('State or region'),
      'postal_code' => $this->t('Postal code'),
      'address_country' => $this->t('Country code'),
    ] as $key => $title) {
      $form['organization']['address'][$key] = [
        '#type' => 'textfield',
        '#title' => $title,
        '#default_value' => $config->get("organization.$key"),
        '#maxlength' => 255,
      ];
    }

    $form['organization']['same_as'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Official identity URLs'),
      '#default_value' => $config->get('organization.same_as'),
      '#rows' => 4,
      '#description' => $this->t('One absolute HTTPS URL per line, such as an official social profile.'),
    ];
    $form['organization']['parent'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Parent organization'),
    ];
    foreach ([
      'parent_name' => $this->t('Parent organization name'),
      'parent_url' => $this->t('Parent organization URL'),
    ] as $key => $title) {
      $form['organization']['parent'][$key] = [
        '#type' => 'textfield',
        '#title' => $title,
        '#default_value' => $config->get("organization.$key"),
        '#maxlength' => $key === 'parent_url' ? 2048 : 255,
      ];
    }

    $form['trust'] = [
      '#type' => 'details',
      '#title' => $this->t('Trust anchor pages'),
      '#description' => $this->t('The readiness audit expects each public page to return 200 and contain meaningful content. The module does not generate legal or contact copy.'),
      '#open' => FALSE,
      '#tree' => TRUE,
    ];
    foreach ([
      'about' => $this->t('About page'),
      'contact' => $this->t('Contact page'),
      'privacy' => $this->t('Privacy page'),
    ] as $key => $title) {
      $form['trust'][$key] = [
        '#type' => 'textfield',
        '#title' => $title,
        '#default_value' => $config->get("trust.$key"),
        '#required' => TRUE,
        '#description' => $this->t('Use an internal path beginning with / or an absolute HTTPS URL.'),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $guidance = trim((string) $form_state->getValue(['guidance', 'llms_content']));
    if (preg_match('/^#\s+\S/m', $guidance) !== 1) {
      $form_state->setErrorByName('guidance][llms_content', $this->t('Agent guidance must include a level-one Markdown heading naming the site.'));
    }
    if (preg_match('/^##\s+When to use this site\s*$/mi', $guidance) !== 1) {
      $form_state->setErrorByName('guidance][llms_content', $this->t('Agent guidance must include a “When to use this site” section.'));
    }
    $organization = $form_state->getValue('organization');

    if (!empty($organization['enabled'])) {
      $contact_email = trim((string) ($organization['email'] ?? ''));
      $site_email = trim((string) $this->config('system.site')->get('mail'));
      $telephone = trim((string) ($organization['telephone'] ?? ''));
      if ($contact_email === '' && $site_email === '' && $telephone === '') {
        $form_state->setErrorByName('organization][email', $this->t('Provide a contact email or telephone before publishing Organization JSON-LD.'));
      }
      foreach ([
        'street_address' => $this->t('Street address'),
        'address_locality' => $this->t('City'),
        'address_region' => $this->t('State or region'),
        'postal_code' => $this->t('Postal code'),
        'address_country' => $this->t('Country code'),
      ] as $key => $label) {
        if (trim((string) ($organization['address'][$key] ?? '')) === '') {
          $form_state->setErrorByName('organization][address][' . $key, $this->t('@field is required when publishing Organization JSON-LD.', ['@field' => $label]));
        }
      }
    }

    foreach (['url', 'logo', 'parent_url'] as $key) {
      $value = trim((string) ($organization[$key] ?? $organization['parent'][$key] ?? ''));
      if ($value !== '' && !$this->isHttpsUrl($value)) {
        $name = $key === 'parent_url'
          ? 'organization][parent][parent_url'
          : 'organization][' . $key;
        $form_state->setErrorByName($name, $this->t('Enter an absolute HTTPS URL.'));
      }
    }
    foreach (preg_split('/\R/', (string) ($organization['same_as'] ?? '')) ?: [] as $url) {
      if (trim($url) !== '' && !$this->isHttpsUrl(trim($url))) {
        $form_state->setErrorByName('organization][same_as', $this->t('Every identity URL must be an absolute HTTPS URL.'));
        break;
      }
    }
    foreach ($form_state->getValue('trust') as $key => $value) {
      $value = trim((string) $value);
      if ((!str_starts_with($value, '/') || str_starts_with($value, '//')) && !$this->isHttpsUrl($value)) {
        $form_state->setErrorByName('trust][' . $key, $this->t('Enter an internal path beginning with / or an absolute HTTPS URL.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('llms_txt.settings')
      ->set('content', trim((string) $form_state->getValue(['guidance', 'llms_content'])) . "\n")
      ->save();

    $organization = $form_state->getValue('organization');
    $address = $organization['address'];
    $parent = $organization['parent'];
    unset($organization['address'], $organization['parent']);
    $organization += $address + $parent;

    $editable = $this->configFactory->getEditable('moody_ai_seo.settings');
    foreach ($organization as $key => $value) {
      $editable->set("organization.$key", is_string($value) ? trim($value) : $value);
    }
    foreach ($form_state->getValue('trust') as $key => $value) {
      $editable->set("trust.$key", trim((string) $value));
    }
    $editable->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Checks for an absolute HTTPS URL.
   */
  private function isHttpsUrl(string $url): bool {
    $parts = parse_url($url);
    return UrlHelper::isValid($url, TRUE)
      && is_array($parts)
      && ($parts['scheme'] ?? '') === 'https'
      && !isset($parts['user'])
      && !isset($parts['pass']);
  }

}
