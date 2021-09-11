<?php

namespace Drupal\moody_social_accounts\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_social_accounts_widget' widget.
 *
 * @FieldWidget(
 *   id = "moody_social_accounts_widget",
 *   module = "moody_subsite",
 *   label = @Translation("Moody social accounts widget"),
 *   field_types = {
 *     "moody_social_accounts"
 *   }
 * )
 */
class MoodySocialAccountsWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $values = $items->getValue();
    $links = (!empty($values[0]['links'])) ? unserialize($items->getValue()[0]['links']) : [];
    $element['social_accounts'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Moody Social Accounts'),
    ];

    $element['social_accounts']['markup'] = [
      '#type' => 'markup',
      '#markup' => '<br>If any values are entered for the social accounts below, this list of social accounts will display in lieu of the main site\'s social accounts in the header.',
    ];

    $element['social_accounts']['facebook'] = [
      '#type' => 'url',
      '#title' => $this->t('Facebook'),
      '#default_value' => isset($links['facebook']) ? $links['facebook'] : NULL,
      '#placeholder' => 'https://www.facebook.com/',
    ];

    $element['social_accounts']['twitter'] = [
      '#type' => 'url',
      '#title' => $this->t('Twitter'),
      '#default_value' => isset($links['twitter']) ? $links['twitter'] : NULL,
      '#placeholder' => 'https://www.twitter.com/',
    ];

    $element['social_accounts']['youtube'] = [
      '#type' => 'url',
      '#title' => $this->t('YouTube'),
      '#default_value' => isset($links['youtube']) ? $links['youtube'] : NULL,
      '#placeholder' => 'https://www.youtube.com/',
    ];

    $element['social_accounts']['instagram'] = [
      '#type' => 'url',
      '#title' => $this->t('Instagram'),
      '#default_value' => isset($links['instagram']) ? $links['instagram'] : NULL,
      '#placeholder' => 'https://www.instagram.com/',
    ];

    $element['social_accounts']['googleplus'] = [
      '#type' => 'url',
      '#title' => $this->t('Google Plus'),
      '#default_value' => isset($links['googleplus']) ? $links['googleplus'] : NULL,
      '#placeholder' => 'https://www.googleplus.com/',
    ];

    $element['social_accounts']['pinterest'] = [
      '#type' => 'url',
      '#title' => $this->t('Pinterest'),
      '#default_value' => isset($links['pinterest']) ? $links['pinterest'] : NULL,
      '#placeholder' => 'https://www.pinterest.com/',
    ];

    $element['social_accounts']['flickr'] = [
      '#type' => 'url',
      '#title' => $this->t('Flickr'),
      '#default_value' => isset($links['flickr']) ? $links['flickr'] : NULL,
      '#placeholder' => 'https://www.flickr.com/',
    ];

    $element['social_accounts']['tumblr'] = [
      '#type' => 'url',
      '#title' => $this->t('Tumblr'),
      '#default_value' => isset($links['tumblr']) ? $links['tumblr'] : NULL,
      '#placeholder' => 'https://www.tumblr.com/',
    ];

    $element['social_accounts']['vimeo'] = [
      '#type' => 'url',
      '#title' => $this->t('Vimeo'),
      '#default_value' => isset($links['vimeo']) ? $links['vimeo'] : NULL,
      '#placeholder' => 'https://www.vimeo.com/',
    ];

    $element['social_accounts']['linkedin'] = [
      '#type' => 'url',
      '#title' => $this->t('LinkedIn'),
      '#default_value' => isset($links['linkedin']) ? $links['linkedin'] : NULL,
      '#placeholder' => 'https://www.linkedin.com/',
    ];

    $element['social_accounts']['weibo'] = [
      '#type' => 'url',
      '#title' => $this->t('Weibo'),
      '#default_value' => isset($links['weibo']) ? $links['weibo'] : NULL,
      '#placeholder' => 'https://www.weibo.com/',
    ];

    $element['social_accounts']['medium'] = [
      '#type' => 'url',
      '#title' => $this->t('Medium'),
      '#default_value' => isset($links['medium']) ? $links['medium'] : NULL,
      '#placeholder' => 'https://www.medium.com/',
    ];

    $element['social_accounts']['newsletter'] = [
      '#type' => 'url',
      '#title' => $this->t('Newsletter'),
      '#default_value' => isset($links['newsletter']) ? $links['newsletter'] : NULL,
      '#placeholder' => 'https://www.google.com/',
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $storage = [];
    $links = [];
    foreach ($values as &$value) {
      $links['facebook'] = isset($value['social_accounts']['facebook']) ? $value['social_accounts']['facebook'] : NULL;
      $links['twitter'] = isset($value['social_accounts']['twitter']) ? $value['social_accounts']['twitter'] : NULL;
      $links['youtube'] = isset($value['social_accounts']['youtube']) ? $value['social_accounts']['youtube'] : NULL;
      $links['instagram'] = isset($value['social_accounts']['instagram']) ? $value['social_accounts']['instagram'] : NULL;
      $links['googleplus'] = isset($value['social_accounts']['googleplus']) ? $value['social_accounts']['googleplus'] : NULL;
      $links['pinterest'] = isset($value['social_accounts']['pinterest']) ? $value['social_accounts']['pinterest'] : NULL;
      $links['flickr'] = isset($value['social_accounts']['flickr']) ? $value['social_accounts']['flickr'] : NULL;
      $links['tumblr'] = isset($value['social_accounts']['tumblr']) ? $value['social_accounts']['tumblr'] : NULL;
      $links['vimeo'] = isset($value['social_accounts']['vimeo']) ? $value['social_accounts']['vimeo'] : NULL;
      $links['linkedin'] = isset($value['social_accounts']['linkedin']) ? $value['social_accounts']['linkedin'] : NULL;
      $links['weibo'] = isset($value['social_accounts']['weibo']) ? $value['social_accounts']['weibo'] : NULL;
      $links['medium'] = isset($value['social_accounts']['medium']) ? $value['social_accounts']['medium'] : NULL;
      $links['newsletter'] = isset($value['social_accounts']['newsletter']) ? $value['social_accounts']['newsletter'] : NULL;
    }
    $storage = serialize($links);
    return $storage;
  }

}
