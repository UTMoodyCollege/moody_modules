<?php

/** Read-only native Drupal regression: run with drush php:script locally. */
if (!str_ends_with(\Drupal::request()->getHost(), '.ddev.site')) {
  throw new RuntimeException('Local DDEV only.');
}
$manager = \Drupal::service('moody_broken_links.manager');
$method = new ReflectionMethod($manager, 'generatedAnchors');
$html = '<p><a href="http://www.taintedbloodfilm.com">Existing link</a> www.taintedbloodfilm.com and https://example.com/test.</p>';
$links = $method->invoke($manager, $html, 'flex_html', 'en');
$hrefs = array_column($links, 'href');
if (count($links) !== 2 || !in_array('http://www.taintedbloodfilm.com', $hrefs, TRUE) || !in_array('https://example.com/test', $hrefs, TRUE)) {
  throw new RuntimeException('Generated bare-www/HTTPS links were missed or stored anchors duplicated.');
}
if ($method->invoke($manager, '<p><a href="https://example.com">Only explicit</a></p>', 'flex_html', 'en') !== []
  || $method->invoke($manager, $html, 'missing_test_format', 'en') !== []) {
  throw new RuntimeException('Explicit anchors or missing formats must not create generated results.');
}
if (\Drupal\moody_broken_links\BulkRemoval::eligible(['result_status' => 'broken', 'source_data' => ['value_kind' => 'generated']], TRUE)) {
  throw new RuntimeException('Generated links must not be offered for bulk removal.');
}
echo "Generated URL detection, duplicate handling and bulk exclusion passed.\n";
