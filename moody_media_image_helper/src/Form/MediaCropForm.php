<?php

namespace Drupal\moody_media_image_helper\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AnnounceCommand;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\file\FileInterface;
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

    $request = $this->moodyRequestStack->getCurrentRequest();
    $source_file_id = (int) $request->query->get('source_fid', 0) ?: NULL;
    $source_field = $this->cropManager->getSourceFieldName($media);
    $image_item = $media->get($source_field)->first();
    $file = $this->cropManager->getSourceFile($media, $source_file_id);
    if (!$file instanceof FileInterface) {
      throw new \InvalidArgumentException('The selected media file cannot be edited.');
    }

    $dimensions = $this->cropManager->getFileDimensions($file);

    $form['#attached']['library'][] = 'moody_media_image_helper/media_helper';
    $form['#attributes']['class'][] = 'moody-media-image-helper__form';
    $form['#tree'] = TRUE;

    $form['messages'] = ['#type' => 'status_messages'];
    $form['media_id'] = ['#type' => 'hidden', '#value' => $media->id()];
    foreach (['context_mode', 'widget_root_id', 'selection_input_id', 'target_input_id', 'file_input_id', 'preview_wrapper_id', 'action_wrapper_id', 'source_fid'] as $key) {
      $form[$key] = [
        '#type' => 'hidden',
        '#value' => (string) $request->query->get($key, ''),
      ];
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Draw a crop area over the image, then optionally resize the result. The helper always duplicates the original file first so the source image stays unchanged.') . '</p>',
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
    $form['workspace']['meta']['output'] = [
      '#markup' => '<div><strong>' . $this->t('Output size') . ':</strong> <span data-moody-media-helper-output-size>' . $dimensions['width'] . ' × ' . $dimensions['height'] . ' px</span></div>',
    ];
    $form['workspace']['meta']['position'] = [
      '#markup' => '<div><strong>' . $this->t('Offset') . ':</strong> <span data-moody-media-helper-offset>0, 0</span></div>',
    ];
    $form['workspace']['meta']['resize_controls'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['moody-media-image-helper__resize-controls']],
      'title' => [
        '#markup' => '<div class="moody-media-image-helper__resize-title"><strong>' . $this->t('Resize to') . '</strong></div>',
      ],
      'width' => [
        '#type' => 'number',
        '#title' => $this->t('Width'),
        '#default_value' => $dimensions['width'],
        '#min' => 1,
        '#step' => 1,
        '#parents' => ['resize', 'width'],
        '#attributes' => ['data-moody-media-helper-resize-input' => 'width'],
      ],
      'height' => [
        '#type' => 'number',
        '#title' => $this->t('Height'),
        '#default_value' => $dimensions['height'],
        '#min' => 1,
        '#step' => 1,
        '#parents' => ['resize', 'height'],
        '#attributes' => ['data-moody-media-helper-resize-input' => 'height'],
      ],
      'help' => [
        '#markup' => '<div class="moody-media-image-helper__source">' . $this->t('Leave these matched to the crop size for no extra resizing, or enter smaller dimensions to downscale the result.') . '</div>',
      ],
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
      '#value' => $this->t('Apply image changes'),
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

    $source_file_id = (int) $form_state->getValue('source_fid') ?: NULL;
    $crop = $form_state->getValue('crop') ?? [];
    $normalized = $this->cropManager->normalizeCrop($media, $crop, $source_file_id);
    if ($normalized['width'] < 2 || $normalized['height'] < 2) {
      $form_state->setErrorByName('crop][width', $this->t('Select a larger crop area.'));
      return;
    }

    $form_state->setValue('crop', $normalized);

    $resize = $this->cropManager->normalizeResize($form_state->getValue('resize') ?? [], [
      'width' => $normalized['width'],
      'height' => $normalized['height'],
    ]);
    $form_state->setValue('resize', $resize);

    if ((string) $form_state->getValue('context_mode') === 'selection') {
      $create_access = $this->entityTypeManager->getAccessControlHandler('media')->createAccess($media->bundle(), NULL, [], TRUE);
      if (!$create_access->isAllowed()) {
        $form_state->setErrorByName('media_id', $this->t('You do not have permission to create cropped media items.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $media = $this->entityTypeManager->getStorage('media')->load((int) $form_state->getValue('media_id'));
    $source_file_id = (int) $form_state->getValue('source_fid') ?: NULL;
    $context_mode = (string) $form_state->getValue('context_mode');
    $crop = $form_state->getValue('crop') ?? [];
    $resize = $form_state->getValue('resize') ?? [];

    if ($context_mode === 'media_edit') {
      $new_file = $this->cropManager->createDerivedFile($media, $crop, $resize, $source_file_id);
      $form_state->set('derived_file_id', (int) $new_file->id());
      return;
    }

    $new_media = $this->cropManager->createCroppedMedia($media, $crop, $resize, $source_file_id);
    $form_state->set('cropped_media_id', (int) $new_media->id());
  }

  /**
   * AJAX callback for crop creation.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state) {
    if ($form_state->getErrors()) {
      return $form;
    }

    $context_mode = (string) $form_state->getValue('context_mode');
    if ($context_mode === 'media_edit') {
      return $this->buildMediaEditResponse($form_state);
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
    $action = moody_media_image_helper_build_crop_action($new_media, [
      'context_mode' => 'selection',
      'widget_root_id' => $widget_root_id,
      'selection_input_id' => $selection_input_id !== '' ? $selection_input_id : NULL,
      'target_input_id' => $target_input_id,
      'preview_wrapper_id' => $preview_wrapper_id,
      'action_wrapper_id' => $action_wrapper_id,
    ]);

    $response = new AjaxResponse();
    $response->addCommand(new UpdateMediaSelectionCommand(
      'selection',
      $widget_root_id,
      $selection_input_id !== '' ? $selection_input_id : NULL,
      $target_input_id,
      NULL,
      $preview_wrapper_id,
      $this->renderer->renderRoot($preview),
      $action_wrapper_id,
      $this->renderer->renderRoot($action),
      (int) $new_media->id(),
      NULL,
    ));
    $response->addCommand(new CloseModalDialogCommand());
    $response->addCommand(new AnnounceCommand($this->t('Created cropped image and updated the selected media item.')));
    return $response;
  }

  /**
   * Builds the AJAX response for a media edit form update.
   */
  protected function buildMediaEditResponse(FormStateInterface $form_state): AjaxResponse|array {
    $media = $this->entityTypeManager->getStorage('media')->load((int) $form_state->getValue('media_id'));
    $new_file = $this->entityTypeManager->getStorage('file')->load((int) $form_state->get('derived_file_id'));
    if (!$media instanceof MediaInterface || !$new_file instanceof FileInterface) {
      return [];
    }

    $file_input_id = (string) $form_state->getValue('file_input_id');
    $preview_wrapper_id = (string) $form_state->getValue('preview_wrapper_id');
    $action_wrapper_id = (string) $form_state->getValue('action_wrapper_id');

    $preview = moody_media_image_helper_build_file_preview(
      $media,
      (int) $new_file->id(),
      $preview_wrapper_id,
    );
    $action = moody_media_image_helper_build_crop_action($media, [
      'context_mode' => 'media_edit',
      'file_input_id' => $file_input_id,
      'preview_wrapper_id' => $preview_wrapper_id,
      'action_wrapper_id' => $action_wrapper_id,
      'source_fid' => (int) $new_file->id(),
    ]);

    $response = new AjaxResponse();
    $response->addCommand(new UpdateMediaSelectionCommand(
      'media_edit',
      '',
      NULL,
      '',
      $file_input_id,
      $preview_wrapper_id,
      $this->renderer->renderRoot($preview),
      $action_wrapper_id,
      $this->renderer->renderRoot($action),
      (int) $media->id(),
      (int) $new_file->id(),
    ));
    $response->addCommand(new CloseModalDialogCommand());
    $response->addCommand(new AnnounceCommand($this->t('Created a derived image file and updated the media form. Save the media item when you are ready.')));
    return $response;
  }

}
