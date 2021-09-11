<?php

namespace Drupal\moody_flex_tabs\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Plugin implementation of the 'flex_tabs_constraint'.
 *
 * @Constraint(
 *   id = "flex_tabs_constraint",
 *   label = @Translation("Flex tabs constraint", context = "Validation"),
 * )
 */
class FlexTabsConstraint extends Constraint {
  // The message that will be shown if no items or more than one items are set to active.
  public $onlyOneActive = 'One and only one flex tab item must be set to active';

}
