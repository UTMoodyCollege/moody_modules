<?php

namespace Drupal\moody_newsletter\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\utexas_form_elements\UtexasLinkOptionsHelper;

/**
 * Plugin implementation of the 'moody_newsletter_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_newsletter_formatter",
 *   label = @Translation("Moody newsletter formatter"),
 *   field_types = {
 *     "moody_newsletter"
 *   }
 * )
 */
class MoodyNewsletterFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      $cta_item['link']['uri'] = $item->link_uri;
      $cta_item['link']['title'] = $item->link_title ?? NULL;
      $cta_item['link']['options'] = $item->link_options ?? [];
      $cta = UtexasLinkOptionsHelper::buildLink($cta_item, ['ut-btn--homepage']);
      $elements[] = [
        '#theme' => 'moody_newsletter',
        '#headline' => $item->headline,
        '#style' => $item->style,
        '#cta' => $cta,
      ];
    }

    return $elements;
  }

}
