<?php

use Drupal\moody_card\Plugin\Field\FieldFormatter\MoodyCardFormatterBase;

$method = new ReflectionMethod(MoodyCardFormatterBase::class, 'buildCta');
$link = $method->invoke(NULL, '#overview', 'Overview', [], ['anchor-btn']);
if (!$link || $link->getUrl()->toString() !== '#overview') {
  throw new RuntimeException('Fragment-only card links were not normalized.');
}

$invalid = $method->invoke(NULL, 'javascript:alert(1)', 'Unsafe', [], ['anchor-btn']);
if ($invalid !== NULL) {
  throw new RuntimeException('Malformed card links must not be rendered.');
}

print "Moody Card link checks passed.\n";
