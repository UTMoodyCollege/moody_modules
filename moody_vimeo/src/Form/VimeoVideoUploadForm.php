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
 * Form for adding a new video to Vimeo by supplying a public URL.
 *
 * Uses the Vimeo "pull" upload approach: Vimeo fetches the file from the given
 * public URL, so no binary transfer is required from the Drupal server.
 */
class VimeoVideoUploadForm extends FormBase {

  /**
   * Constructs a VimeoVideoUploadForm.
   */
  public function __construct(
    protected readonly VimeoApiService $vimeoApi,
    protected readonly ConfigFactoryInterface $configFactory,
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

    $config = $this->configFactory->get('moody_vimeo.settings');

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

    $form['video_url'] = [
      '#type'        => 'url',
      '#title'       => $this->t('Public video URL'),
      '#description' => $this->t(
        'A publicly accessible URL to your video file (mp4, mov, etc.). Vimeo will fetch it directly. The URL must be reachable by Vimeo servers.'
      ),
      '#required'    => TRUE,
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

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $result = $this->vimeoApi->uploadByUrl(
      $form_state->getValue('video_url'),
      $form_state->getValue('name'),
      (string) $form_state->getValue('description'),
      $form_state->getValue('privacy'),
    );

    if ($result === NULL) {
      $this->messenger()->addError($this->t('Upload request failed. Check the Vimeo API credentials and the video URL, then try again.'));
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
