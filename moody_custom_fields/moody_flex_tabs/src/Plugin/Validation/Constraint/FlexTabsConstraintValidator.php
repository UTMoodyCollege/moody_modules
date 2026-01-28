<?php

namespace Drupal\moody_flex_tabs\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the UniqueInteger constraint.
 */
class FlexTabsConstraintValidator extends ConstraintValidator {
  /**
   * {@inheritdoc}
   */
  public function validate($items, Constraint $constraint) {
    foreach ($items as $item) {
      // First check if the value is not empty.
      if (empty($item->value)) {
        // Exactly one flex tab item must be set to active status.
        $this->context->addViolation($constraint->onlyOneActive, ['%value' => $item->value]);
      }
    }
  }

}
