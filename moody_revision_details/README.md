# Moody Revision Details

Adds **Show details** to each row of Diff's existing node **Revisions** screen.
Clicking it loads an expandable summary of the differences from the preceding
retained revision in the same language, including across revision-list pages.
Without JavaScript the link opens a normal, access-checked details page.

The summary covers publication, moderation, revisionable page fields, layout
sections, added/removed blocks, relative ordering and section/region changes,
block settings (including Hero Builder compositions), and saved inline block
content. Drupal's author, timestamp, revision message, comparison radios, and
revert/delete operations remain unchanged. No database tables, save hooks,
permissions, or configuration imports are added.

## Enable and QA

Requires the already-installed Diff module; Layout Builder summaries are used
when the node has a stored layout field. Enable `moody_revision_details`, clear
caches, and open a page's Revisions tab. This does not enable it automatically
on other sites. Sites without Diff, such as the content portal, must install
that dependency before enabling this optional enhancement.

Run the integration check on a DDEV site with Diff, Layout Builder, and the
Moody Standard Page content type:

```sh
ddev exec --raw -- drush php:script web/modules/custom/moody_modules/moody_revision_details/tests/drupal.php
```

The check creates and deletes its own temporary nodes and inline block. It
temporarily enables its hidden access-test helper and, if needed, core Language
for the translation tests, then restores the original module/pager settings.
It refuses to run outside DDEV. To retain its unpublished example page for
manual QA, explicitly set `MOODY_REVISION_DETAILS_KEEP_DEMO=1` with `env` before
`drush` in the command; the script prints that page's revision-history path.

## What history can prove

- This is a comparison of saved snapshots, not a click-by-click action log.
  Position/order changes describe the result; they do not identify which item
  the editor dragged. Adding a sibling or renumbering weights alone does not
  report the existing siblings as reordered.
- The existing revision author identifies the recorded saver. Drupal does not
  retain the original editor tab, every intermediate drag, or unsaved changes.
- Deleted prior revisions cannot be recovered. The oldest available entry is
  described as the first *available* revision, not necessarily page creation.
- Reusable block edits, referenced media changes, and default-layout changes
  made independently are not snapshots in a page revision. Saved inline block
  revisions are compared by their accessible fields, not merely by revision ID.
- Missing historical inline snapshots are reported explicitly. Serialized
  plugin data is never deserialized and block plugins are never executed to
  generate a summary. Field values are not exposed; use Diff's existing
  comparison for a full content diff.
- Existing revision and ordinary field access applies to both sides. Layout
  summaries additionally require the existing Layout Builder section-storage
  access on both revisions; Drupal deliberately denies raw layout-field access.
  No raw field access is granted or altered. Comparisons are uncached and
  computed one revision at a time on request.
