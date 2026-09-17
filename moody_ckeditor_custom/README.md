# Moody CKEditor Custom

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
