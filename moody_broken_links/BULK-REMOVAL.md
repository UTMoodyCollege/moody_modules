# Bulk removals and private audits

From `/admin/content/broken-links`, choose **Preview bulk removals by HTTP code**.
Select one or more HTTP errors from the latest completed scan. Only 404/410 are
preselected. 403/401/429 and connection failures may be temporary/access-related;
they are available for deliberate review, not automatically treated as dead URLs.
Working links are never selected. A scan in progress prevents preparation or
execution. The page repair queue separately defaults to non-200 results.

Formatted-text anchors retain their visible text and nested markup. Dedicated
link fields/URI properties require a separate opt-in: removal deletes the item
or clears the URL. The preview shows counts and a sample, with a private JSON
download containing the **entire exact selection**, exclusions and expected
revisions. Confirming is a CSRF-protected form submission. Deployment alone
does not execute removals.

Drupal's native batch processes one page at a time using the existing
`BrokenLinksManager::remediatePage()` implementation. Permission, page access,
source hashes and expected revisions are checked. Existing publication status
is retained and each successfully changed page gets a new revision. Changed
sources fail that page without retrying blindly; other pages continue.

Evidence lives in `private://moody-broken-links-audit/YYYY-MM-DD/<random-id>/`
(folder date is UTC). It includes the actor/site/scan, HTTP codes, full selected
URLs and sources, expected revisions, exclusions, confirmation time, per-page
intent and outcome, new revisions, failure messages and batch completion time.
The dashboard's **Private removal audit reports** link exposes JSON downloads
only to authenticated users with `administer moody broken links reports`.
Direct private-file downloads are denied. There is no public-storage fallback:
unavailable private storage stops preparation before content changes.

Interrupted `intent` or `pending_commit` entries require reviewing the report
against current revisions/results; they are not automatically reapplied.
Completion of the native batch does not mean every page succeeded: inspect
individual outcomes. Run a new scan after repairs to validate remaining URLs.
Audits are retained; no automatic deletion or retention policy is imposed.

This change adds the requested Drupal dashboard workflow, not a new site-manager
fleet/Actions operation. The reusable module service remains callable through
Drupal; existing local fleet repair runners keep their conservative 404/410
policy rather than silently expanding Live automation to access-denied URLs.

Local verification (creates/deletes only its own local fixtures):

```sh
ddev exec drush --uri=https://2moody-core.ddev.site php:script \
  web/modules/custom/moody_modules/moody_broken_links/tests/bulk_removal_smoke.php
```
