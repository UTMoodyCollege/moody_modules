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
or new frontend dependency is needed. With Moody AI Assistant enabled, the
assistant can compose cards or use **Edit with AI** from a card block's menu.
Its contract covers rows, cells, responsive layouts, images and brand options.
Attachments and permission-checked media lookups supply image references;
generating new images requires the editor's Create image option and Media-create
permission. All compositions pass the same server validator as the visual form.
AI changes only the working layout draft; the editor still saves the layout.
Concurrent changes are rejected rather than overwritten.

Checks:

```sh
php moody_card_builder/tests/contract.php
node --check moody_card_builder/js/builder.js
ddev drush php:script web/modules/custom/moody_modules/moody_card_builder/tests/drupal.php
ddev drush php:script web/modules/custom/moody_modules/moody_ai/modules/moody_ai_assistant/tests/card_builder_ai_smoke.php
```

Optional paid-provider creation/edit check (uses the configured provider):

```sh
ddev exec env MOODY_AI_CARD_PROVIDER_CHECK=1 drush php:script web/modules/custom/moody_modules/moody_ai/modules/moody_ai_assistant/tests/card_builder_ai_smoke.php
```

The AI smoke check creates and removes an unpublished test node and its draft.

The editor keeps semantic reading order while changing image placement. Check
the three previews, heading level and descriptive link wording before publishing.
Reducing row columns removes trailing cells; Undo restores them. Image library
selection is managed by Drupal separately from the composition Undo history.
