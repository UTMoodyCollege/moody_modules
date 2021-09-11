<?php

namespace Drupal\moody_subsite;

use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Access controller for the Moody subsite entity.
 *
 * @see \Drupal\moody_subsite\Entity\MoodySubsite.
 */
class MoodySubsiteAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\moody_subsite\Entity\MoodySubsiteInterface $entity */

    switch ($operation) {

      case 'view':

        if (!$entity->isPublished()) {
          return AccessResult::allowedIfHasPermission($account, 'view unpublished moody subsite entities');
        }


        return AccessResult::allowedIfHasPermission($account, 'view published moody subsite entities');

      case 'update':

        return AccessResult::allowedIfHasPermission($account, 'edit moody subsite entities');

      case 'delete':

        return AccessResult::allowedIfHasPermission($account, 'delete moody subsite entities');
    }

    // Unknown operation, no opinion.
    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'add moody subsite entities');
  }


}
