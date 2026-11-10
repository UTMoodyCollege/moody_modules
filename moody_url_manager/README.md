# Moody URL Manager

A native Drupal workspace for reviewing content-page aliases and incoming
redirect chains, then selecting up to 250 visible rows for a preview-first Batch
API operation. Search by title, alias or `/node/ID`; filter published/draft.
The top toolbar exports every filtered match as a private CSV regardless of
the current pager, and offers 25/50/100/250 rows per screen. CSV includes
all aliases and incoming redirects as JSON columns, with spreadsheet formula
protection. Unapplied filter edits do not alter the export; click Filter first.

Edit opens the native page form. Delete and bulk Delete preview the actual
pages first, require an explicit confirmation checkbox, and use native node
delete access in addition to workspace scope. Deletion removes all node
revisions and aliases through Drupal hooks; shared Media is not deleted.
Incoming redirects and external/menu references may become broken. Conflicting
previews and newer drafts block deletion. Completed deletions have a private
per-user receipt and watchdog log; earlier deletions remain applied if a later
page fails. No Live pages are deleted merely by deploying this feature.

Enter aliases individually or replace a whole path prefix for selected rows.

- Overall site: `/admin/content/moody-urls`, available only to user 1 or the
  `administrator` role. The native Redirect inventory is linked for all site
  redirects, including external destinations and unassociated sources.
- Subsite integration is optional; sites without Moody Subsite receive only
  the overall workspace. No subsite module is installed as a dependency.
- Subsite: the **URLs & redirects** tab and **Manage URLs & redirects** dashboard
  action. Uses the existing manageable-subsites/Workbench context and directory
  term membership, plus node view/update and path-field edit access.
- Preview is read-only. Confirmation starts a per-page transactional batch;
  stale content/alias/redirect snapshots, pending draft revisions, reserved
  routes, duplicate destinations, existing aliases and redirect sources stop
  the operation. Each successful page gets a revision and a permanent redirect
  from its old alias to its node. Existing redirects remain in place.
- Completion shows the user's latest batch receipt in private temporary storage;
  per-page changes are also logged under `moody_url_manager`. A conflict stops
  the remaining batch; earlier completed pages remain saved.

Current scope: node pages only, not Views or arbitrary routes. Multiple
aliases/languages require individual review. Selection is per displayed page,
not across pagination. Existing hard-coded links and subsite homepage/menu
settings are not rewritten; old URLs continue to redirect. Search does not yet
search redirect source strings. No automatic repairs, scheduled changes, bulk
publishing, or automatic module enablement/update hook are included.

Local checks (DDEV only; fixtures are rolled back):

```sh
ddev drush php:script web/modules/custom/moody_url_manager/tests/runtime.php
```

The browser rehearsal uses separately labelled local-only demo pages. Do not
run demo content creation against a remote environment. Module enablement is a separate, targeted operation after code deployment.
