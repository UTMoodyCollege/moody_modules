<?php

declare(strict_types=1);

namespace Drupal\moody_vimeo\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\moody_vimeo\VimeoApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for adding a new video to Vimeo by source URL or direct file upload.
 */
class VimeoVideoUploadForm extends FormBase {

  /**
   * Constructs a VimeoVideoUploadForm.
   */
  public function __construct(
    protected readonly VimeoApiService $vimeoApi,
    protected readonly ConfigFactoryInterface $moodyVimeoConfigFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('moody_vimeo.api'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'moody_vimeo_video_upload';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if (!$this->vimeoApi->isConfigured()) {
      $settings_url = Url::fromRoute('moody_vimeo.settings')->toString();
      $form['not_configured'] = [
        '#markup' => '<p class="messages messages--warning">' . $this->t(
          'Vimeo API credentials are not configured. <a href="@url">Configure them here</a>.',
          ['@url' => $settings_url]
        ) . '</p>',
      ];
      return $form;
    }

    $config = $this->moodyVimeoConfigFactory->get('moody_vimeo.settings');

    $form['name'] = [
      '#type'        => 'textfield',
      '#title'       => $this->t('Video title'),
      '#required'    => TRUE,
      '#maxlength'   => 255,
    ];

    $form['description'] = [
      '#type'  => 'textarea',
      '#title' => $this->t('Description'),
      '#rows'  => 4,
    ];

    $form['upload_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Upload method'),
      '#options' => [
        'url' => $this->t('Provide source video URL'),
        'file' => $this->t('Upload file'),
      ],
      '#default_value' => $form_state->getValue('upload_method', 'url'),
      '#required' => TRUE,
    ];

    $form['upload_help'] = [
      '#type' => 'item',
      '#markup' => '<p class="description">' . $this->t('Source URL uploads are submitted through Drupal. File uploads stream directly from your browser to Vimeo with live progress updates.') . '</p>',
    ];

    $form['video_url'] = [
      '#type'        => 'url',
      '#title'       => $this->t('Source video file URL'),
      '#description' => $this->t(
        'Provide a publicly reachable video file URL. Vimeo will fetch the file directly from that address.'
      ),
      '#states' => [
        'visible' => [
          ':input[name="upload_method"]' => ['value' => 'url'],
        ],
      ],
    ];

    $form['video_file'] = [
      '#type' => 'file',
      '#title' => $this->t('Video file'),
      '#description' => $this->t('Select a local video file. The upload will stream directly to Vimeo from your browser.'),
      '#attributes' => [
        'accept' => '.mp4,.mov,.m4v,.avi,.mpg,.mpeg,.webm,.mkv,video/*',
      ],
      '#states' => [
        'visible' => [
          ':input[name="upload_method"]' => ['value' => 'file'],
        ],
      ],
    ];

    $form['upload_status'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['moody-vimeo-upload-status'],
        'hidden' => 'hidden',
        'aria-live' => 'polite',
      ],
      'label' => [
        '#markup' => '<div class="moody-vimeo-upload-status__message"></div>',
      ],
      'progress' => [
        '#markup' => '<progress class="moody-vimeo-upload-status__progress" max="100" value="0"></progress>',
      ],
      'meta' => [
        '#markup' => '<div class="moody-vimeo-upload-status__meta"></div>',
      ],
    ];

    $form['privacy'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Privacy'),
      '#options'       => [
        'anybody'  => $this->t('Anyone'),
        'unlisted' => $this->t('Unlisted (link only)'),
        'nobody'   => $this->t('Only me'),
        'password' => $this->t('Password-protected'),
      ],
      '#default_value' => $config->get('default_privacy') ?: 'nobody',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Upload to Vimeo'),
    ];

    $form['#attached']['library'][] = 'moody_vimeo/moody_vimeo';
    $form['#attached']['drupalSettings']['moodyVimeo']['browserUpload'] = [
      'sessionUrl' => Url::fromRoute('moody_vimeo.video_upload_session')->toString(),
      'token' => \Drupal::service('csrf_token')->get('moody_vimeo.upload_session'),
      'allowedExtensions' => ['mp4', 'mov', 'm4v', 'avi', 'mpg', 'mpeg', 'webm', 'mkv'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $upload_method = $form_state->getValue('upload_method');

    if ($upload_method === 'url' && trim((string) $form_state->getValue('video_url')) === '') {
      $form_state->setErrorByName('video_url', $this->t('Enter a source video file URL.'));
    }

    if ($upload_method === 'file') {
      $form_state->setErrorByName('video_file', $this->t('JavaScript is required for browser-based file uploads. Use the source URL option if you need a non-JavaScript fallback.'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $upload_method = $form_state->getValue('upload_method');
    $name = (string) $form_state->getValue('name');
    $description = (string) $form_state->getValue('description');
    $privacy = (string) $form_state->getValue('privacy');

    if ($upload_method === 'file') {
      $this->messenger()->addError($this->t('Browser-based file uploads must be started from the page UI. Use the source URL option for standard form submission.'));
      return;
    }
    else {
      $result = $this->vimeoApi->uploadByUrl(
        (string) $form_state->getValue('video_url'),
        $name,
        $description,
        $privacy,
      );
    }

    if ($result === NULL) {
      $this->messenger()->addError($this->t('Upload request failed. Check the Vimeo API credentials and the selected upload source, then try again.'));
      return;
    }

    $video_id = $this->vimeoApi->parseVideoId($result['uri'] ?? '');
    if ($video_id) {
      $this->messenger()->addStatus($this->t(
        'Upload initiated. Vimeo is processing your video. <a href="@url">View video details</a>.',
        ['@url' => Url::fromRoute('moody_vimeo.video_detail', ['video_id' => $video_id])->toString()]
      ));
      $form_state->setRedirect('moody_vimeo.video_list');
    }
    else {
      $this->messenger()->addStatus($this->t('Upload initiated. Vimeo is processing your video. Check your Vimeo library shortly.'));
      $form_state->setRedirect('moody_vimeo.video_list');
    }
  }

}
