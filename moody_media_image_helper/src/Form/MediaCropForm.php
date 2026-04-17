<?php

namespace Drupal\moody_media_image_helper\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AnnounceCommand;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\media\MediaInterface;
use Drupal\moody_media_image_helper\Ajax\UpdateMediaSelectionCommand;
use Drupal\moody_media_image_helper\MediaCropManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Modal form for cropping a media image and swapping the selected reference.
 */
class MediaCropForm extends FormBase {

  /**
   * Current request stack.
   */
  protected RequestStack $moodyRequestStack;

  /**
   * Constructs the form.
   */
  public function __construct(
    protected MediaCropManager $cropManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RendererInterface $renderer,
    RequestStack $requestStack,
  ) {
    $this->moodyRequestStack = $requestStack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('moody_media_image_helper.crop_manager'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'moody_media_image_helper_crop_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?MediaInterface $media = NULL) {
    if (!$media instanceof MediaInterface || !$this->cropManager->supportsMedia($media)) {
      throw new \InvalidArgumentException('The selected media item cannot be cropped.');
    }

    $source_field = $this->cropManager->getSourceFieldName($media);
    $image_item = $media->get($source_field)->first();
    $file = $image_item->entity;
    $dimensions = $this->cropManager->getImageDimensions($media);

    $form['#attached']['library'][] = 'moody_media_image_helper/media_helper';
    $form['#attributes']['class'][] = 'moody-media-image-helper__form';
    $form['#tree'] = TRUE;

    $form['messages'] = ['#type' => 'status_messages'];
    $form['media_id'] = ['#type' => 'hidden', '#value' => $media->id()];
    $request = $this->moodyRequestStack->getCurrentRequest();
    foreach (['widget_root_id', 'selection_input_id', 'target_input_id', 'preview_wrapper_id', 'action_wrapper_id'] as $key) {
      $form[$key] = [
        '#type' => 'hidden',
        '#value' => (string) $request->query->get($key, ''),
      ];
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Draw a crop area over the image. A new media item will be created using a duplicated file in the same folder, while preserving the original alt text.') . '</p>',
    ];

    $form['workspace'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['moody-media-image-helper__workspace'],
        'data-moody-media-helper-workspace' => 'true',
        'data-original-width' => (string) $dimensions['width'],
        'data-original-height' => (string) $dimensions['height'],
      ],
    ];

    $form['workspace']['stage'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['moody-media-image-helper__stage'],
        'data-moody-media-helper-stage' => 'true',
      ],
    ];
    $form['workspace']['stage']['image'] = [
      '#theme' => 'image',
      '#uri' => $file->getFileUri(),
      '#alt' => (string) ($image_item->alt ?? ''),
      '#attributes' => [
        'class' => ['moody-media-image-helper__image'],
        'data-moody-media-helper-image' => 'true',
      ],
    ];
    $form['workspace']['stage']['selection'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['moody-media-image-helper__selection'],
        'data-moody-media-helper-selection' => 'true',
      ],
      'label' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['moody-media-image-helper__selection-label'],
          'data-moody-media-helper-selection-label' => 'true',
        ],
      ],
      'handle_nw' => moody_media_image_helper_build_handle('nw'),
      'handle_ne' => moody_media_image_helper_build_handle('ne'),
      'handle_se' => moody_media_image_helper_build_handle('se'),
      'handle_sw' => moody_media_image_helper_build_handle('sw'),
    ];

    $form['workspace']['meta'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['moody-media-image-helper__meta-panel']],
    ];
    $form['workspace']['meta']['original'] = [
      '#markup' => '<div><strong>' . $this->t('Original') . ':</strong> ' . $dimensions['width'] . ' × ' . $dimensions['height'] . ' px</div>',
    ];
    $form['workspace']['meta']['current'] = [
      '#markup' => '<div><strong>' . $this->t('Crop size') . ':</strong> <span data-moody-media-helper-size>' . $dimensions['width'] . ' × ' . $dimensions['height'] . ' px</span></div>',
    ];
    $form['workspace']['meta']['position'] = [
      '#markup' => '<div><strong>' . $this->t('Offset') . ':</strong> <span data-moody-media-helper-offset>0, 0</span></div>',
    ];

    foreach (['x', 'y', 'width', 'height'] as $key) {
      $form['crop'][$key] = [
        '#type' => 'hidden',
        '#attributes' => ['data-moody-media-helper-input' => $key],
        '#default_value' => $key === 'width' ? $dimensions['width'] : ($key === 'height' ? $dimensions['height'] : 0),
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create cropped image'),
      '#button_type' => 'primary',
      '#ajax' => [
        'callback' => '::ajaxSubmit',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $media = $this->entityTypeManager->getStorage('media')->load((int) $form_state->getValue('media_id'));
    if (!$media instanceof MediaInterface || !$this->cropManager->supportsMedia($media)) {
      $form_state->setErrorByName('media_id', $this->t('The selected media item is not available for cropping.'));
      return;
    }

    $crop = $form_state->getValue('crop') ?? [];
    $normalized = $this->cropManager->normalizeCrop($media, $crop);
    if ($normalized['width'] < 2 || $normalized['height'] < 2) {
      $form_state->setErrorByName('crop][width', $this->t('Select a larger crop area.'));
      return;
    }

    $form_state->setValue('crop', $normalized);

    $create_access = $this->entityTypeManager->getAccessControlHandler('media')->createAccess($media->bundle(), NULL, [], TRUE);
    if (!$create_access->isAllowed()) {
      $form_state->setErrorByName('media_id', $this->t('You do not have permission to create cropped media items.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $media = $this->entityTypeManager->getStorage('media')->load((int) $form_state->getValue('media_id'));
    $new_media = $this->cropManager->createCroppedMedia($media, $form_state->getValue('crop'));
    $form_state->set('cropped_media_id', (int) $new_media->id());
  }

  /**
   * AJAX callback for crop creation.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state) {
    if ($form_state->getErrors()) {
      return $form;
    }

    $new_media = $this->entityTypeManager->getStorage('media')->load((int) $form_state->get('cropped_media_id'));
    if (!$new_media instanceof MediaInterface) {
      return $form;
    }

    $widget_root_id = (string) $form_state->getValue('widget_root_id');
    $selection_input_id = (string) $form_state->getValue('selection_input_id');
    $target_input_id = (string) $form_state->getValue('target_input_id');
    $preview_wrapper_id = (string) $form_state->getValue('preview_wrapper_id');
    $action_wrapper_id = (string) $form_state->getValue('action_wrapper_id');

    $preview = [
      '#type' => 'container',
      '#media' => $new_media,
      '#attributes' => [
        'id' => $preview_wrapper_id,
        'class' => ['moody-media-image-helper__preview'],
      ],
      'content' => $this->entityTypeManager->getViewBuilder('media')->view($new_media, 'media_library'),
    ];
    $action = moody_media_image_helper_build_crop_action(
      $new_media,
      $widget_root_id,
      $selection_input_id !== '' ? $selection_input_id : NULL,
      $target_input_id,
      $preview_wrapper_id,
      $action_wrapper_id,
    );

    $response = new AjaxResponse();
    $response->addCommand(new UpdateMediaSelectionCommand(
      $widget_root_id,
      $selection_input_id !== '' ? $selection_input_id : NULL,
      $target_input_id,
      $preview_wrapper_id,
      $this->renderer->renderRoot($preview),
      $action_wrapper_id,
      $this->renderer->renderRoot($action),
      (int) $new_media->id(),
    ));
    $response->addCommand(new CloseModalDialogCommand());
    $response->addCommand(new AnnounceCommand($this->t('Created cropped image and updated the selected media item.')));
    return $response;
  }

}
