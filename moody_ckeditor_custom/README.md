# Moody CKEditor Custom

## High-resolution aligned media

The shared utility stylesheet limits left/right-aligned image wrappers to 50%
of their content area, matching CKEditor's desktop preview. Captioned images
use block layout rather than an intrinsic-width table. Below 768px, images and
editor previews stack without a float. Centered/unaligned media, non-image
embeds, stored content, image styles and Flex HTML restrictions are unchanged.
Higher-resolution derivatives therefore do not force aligned images full-width.
This applies wherever this module is enabled, independently of the active theme.

Update 9002 (also used on installation) restores the supported toolbar controls
on existing CKEditor 5 `flex_html` and `moody_flex_html` formats: Styles, Moody
Nice Letter, Readable Text Block, and Image Caption. It appends missing UT text
color/background/size styles and enables the Nice Letter filter, preserving
existing toolbar order, styles, filter weight/settings, HTML restrictions and
role permissions. Repeating the update is safe.

Other formats, missing formats and CKEditor 4 are left unchanged. Content Portal
has no Flex HTML format and is deliberately skipped; do not expand Basic HTML
permissions or filtering to achieve toolbar parity. `moodyContentBlocks` is a
demo that inserts “It works!” and is not added.

After source publication and fleet component propagation, both local and hosted
deployment paths run Drupal database updates. No separate module re-enable or
full configuration import is needed. Capture the resulting active configuration
before a later config import so older exports do not remove the restored buttons.

Check with `php moody_ckeditor_custom/tests/rollout.php`. During Test QA, verify
Readable Text and Image Caption markup survives editor and text-filter round trips.
# Responsive utilities and style guide

The shared editor/front-end library includes mobile-first `ut-` layout, sizing,
positioning, spacing, media, type, and approved-surface utilities. Regenerate CSS,
the exact AI class allowlist, compact AI context, and the public reference with:

```sh
node scripts/build-utilities.cjs
node scripts/build-utilities.cjs --check
```

Edit that catalog rather than its generated files. The reference is automatically
appended to the full node view whose alias is `/style-guide`; existing editorial
content is not overwritten. Clear Drupal caches after deployment. No database
migration or config import is required. The same stylesheet loads in CKEditor
and on public pages through the existing module library.

Viewport prefixes are `sm:` 576px, `md:` 768px, `lg:` 992px, `xl:` 1200px.
These do **not** change Hero/Card Builder's container breakpoints (600/900px).
Utilities expose their reusable layout ideas without depending on their private
`mhb-`/`mcb-` markup or loading a builder. Prefer the builders for managed media,
overlays, interactive editing, and structured cards. No rounded corner options
or retired accent-color choices are introduced.

Embedded image alignment uses Drupal's existing `align-left`, `align-center`,
and `align-right` utilities automatically; editors do not need source markup.
All three match CKEditor's 50% width cap at tablet/desktop sizes. Below 768px,
images stack at up to the content width in both editor and page, preserving
center alignment and keeping captions within the image wrapper. Choosing no
alignment leaves the normal full-width rendering unchanged. Image resolution
and display width remain independent.
