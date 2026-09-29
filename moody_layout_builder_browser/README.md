# Moody block editor

The browser keeps its curated categories and supplements missing, accessible
Views block displays and reusable content blocks at request time. It uses the
normal Layout Builder filtered definitions, so layout restrictions remain in
effect. Disabled Views displays are excluded. Existing entries are not duplicated;
new reusable block types do not need a per-site bundle-list update.

Reusable placement forms offer an update-access-checked edit link in a new tab.
Editing shared content affects every placement; layout changes stay in the
original tab. No configuration import or permission grant is required.

The tracked fleet enables this browser on 14 sites. Moody Content Portal does
not use the browser; this module does not enable or replace its editing setup.

Checks:

- `NODE_PATH=/path/to/node_modules node tests/compact-dialog.cjs`
- `MOODY_TEST_NODE=7730 drush php:script tests/browser-smoke.php` on a local
  Drupal site with a Layout Builder node, Views displays and a reusable block.
  The smoke test changes no saved content.
