<?php

/**
 * Run with drush php:script; reads an existing image and never saves content.
 */

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormElementHelper;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;

$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(User::load(1));
try {
  $manager = \Drupal::service('moody_media_image_helper.crop_manager');
  $ids = \Drupal::entityTypeManager()->getStorage('media')->getQuery()->accessCheck(FALSE)->sort('mid', 'DESC')->range(0, 200)->execute();
  $image = NULL;
  foreach (\Drupal::entityTypeManager()->getStorage('media')->loadMultiple($ids) as $media) {
    if ($manager->supportsMedia($media) && $manager->getSourceFile($media)) {
      $image = $media;
      break;
    }
  }
  if (!$image) { throw new RuntimeException('Needs an existing image media entity.'); }
  $object = new class extends FormBase {
    public $image;
    public function getFormId() { return 'moody_media_form_errors_smoke'; }
    public function buildForm(array $form, FormStateInterface $form_state) {
      $form['image'] = ['#type' => 'media_library', '#allowed_bundles' => [$this->image->bundle()], '#default_value' => $this->image->id()];
      $form['promo'] = ['#type' => 'utexas_promo_unit', '#default_value' => ['image' => $this->image->id(), 'copy_value' => '', 'copy_format' => 'flex_html', 'link' => ['uri' => '', 'title' => '', 'options' => []]]];
      $form['url'] = ['#type' => 'url', '#title' => 'URL'];
      return $form;
    }
    public function submitForm(array &$form, FormStateInterface $form_state) {}
  };
  $object->image = $image;
  $form = \Drupal::formBuilder()->getForm($object);
  // Inline Form Errors traverses earlier fields to find the invalid URL.
  $url = FormElementHelper::getElementByName('url', $form);
  if (($url['#title'] ?? '') !== 'URL') { throw new RuntimeException('URL error target not found.'); }
  $state = new FormState();
  $state->setError($url, 'Enter a valid URL.');
  \Drupal::service('form_error_handler')->handleFormErrors($form, $state);
  $state->clearErrors();
  $promo_url = FormElementHelper::getElementByName('promo][link][uri', $form);
  if (!$promo_url) { throw new RuntimeException('Nested Promo Unit URL not found.'); }
  $state->setError($promo_url, 'Use a relative path for a link to this site.');
  \Drupal::service('form_error_handler')->handleFormErrors($form, $state);
  $state->clearErrors();
  $media_form = \Drupal::service('entity.form_builder')->getForm($image, 'edit');
  FormElementHelper::getElementByName('nonexistent-smoke-field', $media_form);
  \Drupal::messenger()->deleteByType('error');
  echo "PASS: selected-image helpers preserve form metadata and inline URL validation errors render without a crash. No content saved.\n";
}
finally { $switcher->switchBack(); }
