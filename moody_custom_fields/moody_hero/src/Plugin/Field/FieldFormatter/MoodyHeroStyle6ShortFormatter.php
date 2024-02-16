<?php

namespace Drupal\moody_hero\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'moody_hero' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_hero_6_short",
 *   label = @Translation("Style 6 Short: Short image and extra bold headline, image anchored right"),
 *   field_types = {
 *     "moody_hero"
 *   }
 * )
 */
class MoodyHeroStyle6ShortFormatter extends MoodyHeroStyle6Formatter {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);
    foreach ($items as $delta => $item) {
      $elements[$delta]['#theme'] = 'moody_hero_6_short';
    }

    // Lets add the moody_hero/hero-style-6-short library
    $elements['#attached']['library'][] = 'moody_hero/hero-style-6-short';
    return $elements;
  }

}
