<?php

namespace Drupal\moody_subsite;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;

/**
 * Defines a class to build a listing of Moody subsite entities.
 *
 * @ingroup moody_subsite
 */
class MoodySubsiteListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['id'] = $this->t('Moody subsite ID');
    $header['name'] = $this->t('Name');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /* @var \Drupal\moody_subsite\Entity\MoodySubsite $entity */
    $row['id'] = $entity->id();
    $row['name'] = Link::createFromRoute(
      $entity->label(),
      'entity.moody_subsite.edit_form',
      ['moody_subsite' => $entity->id()]
    );
    return $row + parent::buildRow($entity);
  }

}
