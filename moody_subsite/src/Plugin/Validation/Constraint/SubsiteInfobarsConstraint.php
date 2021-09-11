<?php

namespace Drupal\moody_subsite\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Plugin implementation of the 'subsite_infobars_constraint'.
 *
 * @Constraint(
 *   id = "subsite_infobars_constraint",
 *   label = @Translation("Subsite infobars constraint", context = "Validation"),
 * )
 */
class SubsiteInfobarsConstraint extends Constraint
{

    // The message that will be shown if there is a link without corresponding title.
    public $linkWithNoTitle = 'This item contains a link but no title';

    // The message that will be shown if there is a full path on internal link.
    public $notRelativePath = 'This item is using a full path when it should use a relative one';

    // The message that will be shown if an external link is invalid.
    public $invalidExternalLink = 'This item contains an invalid external link';

    // The message that will be shown if an internal link does not start with a slash.
    public $noSlashOnRelativeLink = 'This item contains an internal link but does not have a slash at the beginning';

}
