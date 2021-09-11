<?php

namespace Drupal\moody_subsite\Entity;

use Drupal\views\EntityViewsData;

/**
 * Provides Views data for Moody subsite entities.
 */
class MoodySubsiteViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    $data = parent::getViewsData();

    // Additional information for Views integration, such as table joins, can be
    // put here.
    return $data;
  }

}
