<?php

namespace Drupal\moody_feature_page\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Requires a safe destination when external-story mode is enabled.
 *
 * @Constraint(
 *   id = "MoodyExternalStoryUrl",
 *   label = @Translation("External story URL", context = "Validation")
 * )
 */
class ExternalStoryUrlConstraint extends Constraint {

  public $message = 'Enter a complete https:// or http:// story URL without embedded credentials.';

}
