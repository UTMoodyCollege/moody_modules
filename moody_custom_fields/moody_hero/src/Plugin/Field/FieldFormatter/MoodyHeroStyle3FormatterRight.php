<?php

namespace Drupal\moody_hero\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'moody_hero' formatter.
 *
 * @FieldFormatter(
 *   id = "moody_hero_3_right",
 *   label = @Translation("Style 3: White bottom pane with heading, subheading and burnt orange call to action, image anchored right"),
 *   field_types = {
 *     "moody_hero"
 *   }
 * )
 */
class MoodyHeroStyle3FormatterRight extends MoodyHeroStyle3Formatter {

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
