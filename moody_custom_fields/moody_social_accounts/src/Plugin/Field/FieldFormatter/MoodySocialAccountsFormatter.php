<?php

namespace Drupal\moody_social_accounts\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'moody_social_accounts_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_social_accounts_formatter",
 *   label = @Translation("Moody social accounts formatter"),
 *   field_types = {
 *     "moody_social_accounts"
 *   }
 * )
 */
class MoodySocialAccountsFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $values = $items->getValue();
    $links = (!empty($values[0]['links'])) ? unserialize($items->getValue()[0]['links']) : [];
    $links_output = FALSE;
    foreach ($links as $key => $value) {
      $invert = ($key == 'weibo') ? 'invert' : '';
      if (!empty($value)) {
        $links_output .= '<div class="' . $invert . '"><a class="block__ut-social-link" href="' . $value . '"><svg><use xlink:href="#ut-social-' . $key . '"></use></svg></a></a></div>';
      }
    }
    $output = ($links_output) ? '<div class="block__ut-social-links--items">' . $links_output . '</div>' : '';
    $markup = $output;
    foreach ($items as $delta => $item) {
      $elements[$delta] = [
        '#markup' => check_markup($markup, 'full_html'),
      ];
    }

    return $elements;
  }

}
