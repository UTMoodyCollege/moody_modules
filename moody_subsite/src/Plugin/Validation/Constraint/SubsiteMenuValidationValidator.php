<?php

namespace Drupal\moody_subsite\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Drupal\Component\Utility\UrlHelper;

/**
 * Validates the UniqueInteger constraint.
 */
class SubsiteMenuValidationValidator extends ConstraintValidator
{

    /**
     * {@inheritdoc}
     */
    public function validate($items, Constraint $constraint) {
        $has_parent = FALSE;

        foreach ($items as $delta => $item) {
            $values = $item->getValue();
            $link = $values['link'];
            $title = $values['title'];
            $host = \Drupal::request()->getSchemeAndHttpHost();
            $is_external = (UrlHelper::isExternal($link)) ? TRUE : FALSE;
            $char1 = substr($link, 0, 1);

            // Check that links have corresponding titles.
            if ($title == "" && $link != "") {
                $this->context
                    ->buildViolation($constraint->linkWithNoTitle)
                    ->atPath($delta)
                    ->addViolation();
            }

            // Check that titles have corresponding links.
            if ($title != "" && $link == "") {
                $this->context
                    ->buildViolation($constraint->titleWithNoLink)
                    ->atPath($delta)
                    ->addViolation();
            }

            // Check that internal links start with a slash.
            if (!$is_external && $char1 != '/') {
                $this->context
                    ->buildViolation($constraint->noSlashOnRelativeLink)
                    ->atPath($delta)
                    ->addViolation();
            }

            // Check that external links are valid.
            if ($is_external && !UrlHelper::isValid($link, $absolute = TRUE)) {
                $this->context
                    ->buildViolation($constraint->invalidExternalLink)
                    ->atPath($delta)
                    ->addViolation();
            }

            // Check that internal links are not using absolute paths.
            if ($is_external && UrlHelper::isValid($link, $absolute = TRUE) && UrlHelper::externalIsLocal($link, $host)) {
                $this->context
                    ->buildViolation($constraint->notRelativePath)
                    ->atPath($delta)
                    ->addViolation();
            }

            // Check that submenu items have a preceding top-level item.
            if (!empty($values['is_child']) && !$has_parent) {
                $this->context
                    ->buildViolation($constraint->childWithoutParent)
                    ->atPath($delta)
                    ->addViolation();
            }

            if (empty($values['is_child'])) {
                $has_parent = TRUE;
            }
        }
    }
}
