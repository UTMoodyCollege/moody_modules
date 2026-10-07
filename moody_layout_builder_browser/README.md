# Moody block editor

The browser keeps its curated categories and supplements missing, accessible
Views block displays and reusable content blocks at request time. It uses the
normal Layout Builder filtered definitions, so layout restrictions remain in
effect. Disabled Views displays are excluded. Existing entries are not duplicated;
new reusable block types do not need a per-site bundle-list update.

Moody Quotation has an optional catalog entry and a thumbnail rendered from its
real template. The entry requires the Quotation bundle/module and the Moody
Site Building category; sites without those dependencies do not install it.

Reusable placement forms offer an update-access-checked edit link in a new tab.
Editing shared content affects every placement; layout changes stay in the
original tab. No configuration import or permission grant is required.

Opening block dialogs and rebuilding the layout retain the parent page's scroll
position. After a rebuild, keyboard focus returns to the edited block or the
original Add block link without scrolling. Other Drupal dialogs keep their
normal behavior.

## October 2026 block audit

The directory also supplements missing Hero Builder and Card Builder entries
when their plugins are installed and accessible. View-mode controls precede
media selection. Unused Flex Color items collapse; populated items remain open
and the four-item limit is unchanged.

Retired Turtlepond, Turquoise and Bluebonnet section choices are no longer
offered. Editing an existing retired background defaults to Charcoal and warns
before saving; stored sections are not migrated automatically. Featured
Highlight's legacy Medium/Bluebonnet mode renders as Charcoal without rewriting
content. Its selector and formatter labels reflect that replacement.

The accompanying templates provide semantic hero/Flex Grid headings, accordion
state and panel relationships, conditional Showcase headings, valid alignment
classes, and working position controls for homepage heroes. Usage-report
placements show block title and view mode in separate columns.

Legacy Hero 5 and duplicate existing view modes retain their rendering/CSS.
Changing an existing page to a replacement design, removing those modes, and
adding new reference-page content require a separate reviewed content change;
this code-only release does not silently redesign existing pages.

From the site-manager root, run `php scripts/test-block-audit.php /path/to/drupal`
and `NODE_PATH=/path/to/node_modules node scripts/test-block-audit.cjs /path/to/drupal`.
The browser check uses installed Playwright; `PLAYWRIGHT_CHANNEL=chrome` uses an
installed Chrome instead of Playwright's bundled Chromium.

The tracked fleet enables this browser on 14 sites. Moody Content Portal does
not use the browser; this module does not enable or replace its editing setup.

Checks:

- `NODE_PATH=/path/to/node_modules node tests/compact-dialog.cjs`
- `NODE_PATH=/path/to/node_modules node tests/scroll-position.cjs`
- `MOODY_TEST_NODE=7730 drush php:script tests/browser-smoke.php` on a local
  Drupal site with a Layout Builder node, Views displays and a reusable block.
  The smoke test changes no saved content.
- `drush php:script tests/catalog-report.php` reports enabled entries, unavailable
  plugin IDs and Quotation thumbnail readiness without saving content or config.
