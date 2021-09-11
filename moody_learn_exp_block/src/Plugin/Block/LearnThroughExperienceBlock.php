<?php

namespace Drupal\moody_learn_exp_block\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'LearnThroughExperienceBlock' block.
 *
 * @Block(
 *  id = "learn_through_experience_block",
 *  category = @Translation("Moody"),
 *  admin_label = @Translation("Learn Through Experience Block"),
 * )
 */
class LearnThroughExperienceBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#theme' => 'learn_through_exp_block',
    ];
  }

}
