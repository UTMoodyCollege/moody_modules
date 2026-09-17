# Moody Card Builder

Local prototype: enable `moody_card_builder`, then add **Moody Card Builder**
under Specialty Blocks in Layout Builder. Existing card and Editors Picks
blocks are unchanged. No fleet enablement or deployment is performed here.

- Up to 12 cards, 8 rows per card, 1–3 cells per row. Drag or use keyboard/up/down
  controls to reorder cards and rows; duplicate cards and undo/redo edits.
- Rows: full width, 50/50, thirds, 33/67 or 67/33. Each cell has its own alignment,
  approved size and brand font. Text, one heading per card, intro labels, initials
  badges, links and UT-style buttons. Optional row dividers and bottom alignment.
- Mobile (375px), tablet (768px), desktop (1280px) canvas previews. Each device
  gets its own cards-per-row, image position/share, minimum height and focal point.
  Public breakpoints are 600px and 900px of the actual collection container.
- Images above/left/right/hidden; a 75% image share sets a minimum image area,
  not a crop of the text. Cards expand for long content and text zoom.
- Media Library supplies access-checked images. Add images to the shared card
  image library, then assign them to cards. Meaningful images need descriptions.
- Brand palettes, approved fonts, square/8px/24px corners. No arbitrary HTML,
  colors or CSS; server validation remains authoritative. Public output needs no JS.

Reuses Hero Builder's brand tokens and access/cache-aware image resolver through
an explicit module dependency. All card styles are module-scoped; no theme patch
or new frontend dependency is needed. Typed configuration makes the block
discoverable to the AI catalog; automated AI composition is not enabled yet.

Checks:

```sh
php moody_card_builder/tests/contract.php
node --check moody_card_builder/js/builder.js
ddev drush php:script web/modules/custom/moody_modules/moody_card_builder/tests/drupal.php
```

The editor keeps semantic reading order while changing image placement. Check
the three previews, heading level and descriptive link wording before publishing.
Reducing row columns removes trailing cells; Undo restores them. Image library
selection is managed by Drupal separately from the composition Undo history.
