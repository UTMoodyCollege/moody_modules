<?php

namespace Drupal\moody_subsite\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Drupal\Component\Utility\UrlHelper;

/**
 * Validates the UniqueInteger constraint.
 */
class SubsiteUrlConstraintValidator extends ConstraintValidator
{

    /**
     * {@inheritdoc}
     */
    public function validate($items, Constraint $constraint) {
        foreach ($items as $item) {
            $values = $item->getValue();
            $link = $values['value'];
            $is_external = (UrlHelper::isExternal($link)) ? TRUE : FALSE;
            $char1 = substr($link, 0, 1);
            $host = \Drupal::request()->getSchemeAndHttpHost();

            // Make sure no external links are used.
            if ($is_external) {
                $this->context->addViolation($constraint->isInternal);
            }

            // Make sure relative links are used.
            if ($is_external && UrlHelper::isValid($link, $absolute = TRUE) && UrlHelper::externalIsLocal($link, $host)) {
                $this->context->addViolation($constraint->isRelative);
            }

            // Check that internal links start with a slash.
            if (!$is_external && $char1 != '/') {
                $this->context->addViolation($constraint->noSlashOnRelativeLink);
            }
        }
    }

}
