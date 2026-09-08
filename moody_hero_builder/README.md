# Moody Hero Builder

An opt-in Layout Builder block; existing hero fields and blocks are unchanged.
Uses Drupal's block form, Media Library, Twig and native DOM APIs. No build step
or client-side framework. Public output needs CSS only.

Enable `moody_hero_builder` on a local prototype site. Optional configuration adds
the block to **Specialty Blocks** in the Moody Layout Builder Browser when that
module and category are installed. Its normal list weight is `0`; the category's
existing open/closed setting is preserved. Update `9001` moves an already-enabled
entry without changing its label, thumbnail or enabled state. The native Drupal
block picker also uses Specialty Blocks; sites without the contributed browser
need no catalog configuration.
Fleet enablement/publication is a separate, explicitly authorized release step.

The configuration contract is bounded and rejects arbitrary layout values, unsafe
links, oversized input and compositions without exactly one heading. Twig escapes
all editor text. Media access and cache dependencies are retained. Composition
arrays replace Drupal's defaults rather than being deep-merged into them.

Run standalone validation with `php tests/contract.php` and
`php tests/catalog-update.php`. With the module enabled
on a local site, run `drush php:script <module-path>/tests/drupal.php` for read-only
plugin, form, rendering and schema checks. `tests/builder.preview.html` is a
standalone editor/state specimen for visual and browser regression checks.

Before release, evaluate an unpublished page in Layout Builder: select/remove
media, change composition, submit, save layout and reopen. Check keyboard move and
reorder, undo/redo, invalid links, alt requirements, desktop/tablet/mobile previews,
and the rendered hero at 320, 375, 414, 768 and 1280px. Do not import unrelated site
configuration as part of enabling this module.

## Prototype verification (2026-09-06)

- Local `2moody-core`, unpublished node 7716: add/edit through the actual iframe
  dialog, nested Media Library selection/removal/replacement, block submission,
  layout save and reopen passed. Existing site pages were not changed.
- 75 configuration checks plus Drupal plugin round trips, form submission,
  escaped Twig rendering, malformed imported data and typed schema passed.
- Native browser checks passed for keyboard/pointer movement, keyboard reorder,
  undo/redo, input validation, media updates and responsive previews. Public hero
  showed no component overflow at 320/375/414/768/1280/1920px; desktop and mobile
  were visually inspected. Width-only image derivatives retain editor crop parity.
- Allowed foreground/background text pairs exceed 4.5:1 (the tested worst-case
  soft orange surface is 5.6:1); editor focus on white is 7.04:1. This is not a
  full-page accessibility certification. User-authored headings, alt text and
  destinations still require review.
- No component publication, fleet enablement, config export/import, GitHub
  Actions run or remote deployment was performed for this prototype.
