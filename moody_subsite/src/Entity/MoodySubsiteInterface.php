<?php

namespace Drupal\moody_subsite\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for defining Moody subsite entities.
 *
 * @ingroup moody_subsite
 */
interface MoodySubsiteInterface extends ContentEntityInterface, EntityChangedInterface, EntityPublishedInterface, EntityOwnerInterface {

  /**
   * Add get/set methods for your configuration properties here.
   */

  /**
   * Gets the Moody subsite name.
   *
   * @return string
   *   Name of the Moody subsite.
   */
  public function getName();

  /**
   * Sets the Moody subsite name.
   *
   * @param string $name
   *   The Moody subsite name.
   *
   * @return \Drupal\moody_subsite\Entity\MoodySubsiteInterface
   *   The called Moody subsite entity.
   */
  public function setName($name);

  /**
   * Gets the Moody subsite creation timestamp.
   *
   * @return int
   *   Creation timestamp of the Moody subsite.
   */
  public function getCreatedTime();

  /**
   * Sets the Moody subsite creation timestamp.
   *
   * @param int $timestamp
   *   The Moody subsite creation timestamp.
   *
   * @return \Drupal\moody_subsite\Entity\MoodySubsiteInterface
   *   The called Moody subsite entity.
   */
  public function setCreatedTime($timestamp);

}
