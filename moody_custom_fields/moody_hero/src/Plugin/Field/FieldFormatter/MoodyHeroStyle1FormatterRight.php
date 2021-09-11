<?php

namespace Drupal\moody_hero\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'moody_hero' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_hero_1_right",
 *   label = @Translation("Style 1: Bold heading & subheading on burnt orange background, image anchored right"),
 *   field_types = {
 *     "moody_hero"
 *   }
 * )
 */
class MoodyHeroStyle1FormatterRight extends MoodyHeroStyle1Formatter {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);
    foreach ($items as $delta => $item) {
      $elements[$delta]['#anchor_position'] = 'right';
    }
    return $elements;
  }

}
