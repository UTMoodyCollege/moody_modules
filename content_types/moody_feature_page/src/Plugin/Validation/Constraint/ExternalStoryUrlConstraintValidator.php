<?php

namespace Drupal\moody_feature_page\Plugin\Validation\Constraint;

use Drupal\moody_feature_page\ExternalStory;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class ExternalStoryUrlConstraintValidator extends ConstraintValidator {

  public function validate($items, Constraint $constraint): void {
    $node = $items->getEntity();
    $url = (string) $items->value;
    if (($node->get('field_feature_external')->value || $url !== '') && !ExternalStory::validUrl($url)) {
      $this->context->addViolation($constraint->message);
    }
    elseif ($url !== '' && strcasecmp((string) parse_url($url, PHP_URL_HOST), \Drupal::request()->getHost()) === 0) {
      $this->context->addViolation('Use a story URL on another site, not this site.');
    }
  }

}
