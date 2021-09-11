<?php

namespace Drupal\moody_subsite\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Plugin implementation of the 'subsite_url_constraint'.
 *
 * @Constraint(
 *   id = "subsite_url_constraint",
 *   label = @Translation("Subsite url constraint", context = "Validation"),
 * )
 */
class SubsiteUrlConstraint extends Constraint
{

    // The message that will be shown if the value is empty.
    public $isInternal = 'This item must be an internal link';

    // The message that will be shown if the value is not unique.
    public $isRelative = 'This item must use a relative path';

    // The message that will be shown if an internal link does not start with a slash.
    public $noSlashOnRelativeLink = 'This item contains an internal link but does not have a slash at the beginning';

}
