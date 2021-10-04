<?php

namespace Drupal\moody_resource_group\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\utexas_form_elements\UtexasLinkOptionsHelper;

/**
 * Plugin implementation of the 'resource_group_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "resource_group_formatter",
 *   label = @Translation("Resource group formatter"),
 *   field_types = {
 *     "resource_group"
 *   }
 * )
 */
class ResourceGroupFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      $links = unserialize($item->links);
      if (!empty($links)) {
        foreach ($links as &$link) {
          if (!empty($link['uri'])) {
            $link_item['link'] = $link;
            $link = UtexasLinkOptionsHelper::buildLink($link_item, ['ut-link']);
          }
        }
      }
      else {
        $links = [];
      }
      $elements[] = [
        '#theme' => 'moody_resource_group',
        '#headline' => $item->headline,
        '#style' => $item->style,
        '#links' => $links,
      ];
    }

    return $elements;
  }

}
