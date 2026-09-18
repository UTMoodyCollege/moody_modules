<?php

require_once __DIR__ . '/../src/QuotationStyle.php';

use Drupal\moody_quotation\QuotationStyle;

function check($condition) {
  if (!$condition) {
    throw new RuntimeException('Quotation style regression');
  }
}
foreach (['default', 'orange', 'grey', 'feature'] as $legacy) {
  check(QuotationStyle::encode(QuotationStyle::decode($legacy)) === $legacy);
}
foreach (QuotationStyle::OPTIONS['palette'] as $palette => $label) {
  foreach (QuotationStyle::OPTIONS['size'] as $size => $label) {
    foreach (QuotationStyle::OPTIONS['alignment'] as $alignment => $label) {
      foreach (['left', 'right'] as $position) {
        $style = "split:$palette:$size:$alignment:$position";
        check(QuotationStyle::encode(QuotationStyle::decode($style)) === $style);
      }
    }
  }
}
check(QuotationStyle::encode(QuotationStyle::decode('split:unsafe:huge:bad:evil')) === 'split:blue:medium:center:left');
check(QuotationStyle::decode(NULL)['style'] === 'default');
echo "Quotation styles: legacy, round-trip and invalid tokens passed.\n";
