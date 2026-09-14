# Moody Scheduled Publishing

Enable `moody_scheduled_publishing`, then rebuild caches. On a node's Edit form,
expand **Scheduled publishing** beside Published, leave Published unchecked,
choose a future date/time, and save. Uncheck scheduling and save to cancel.
The timezone is displayed on the form and dashboard; timestamps are stored as
Unix UTC seconds. The toolbar links to `/admin/content/scheduled-publishing`.

The dashboard shows only nodes the current user can edit and whose Published
field they may edit. It adds no editor permissions. It includes pending/overdue
schedules and paged historical events, with actor, time and revision ID.
Deleted nodes retain audit IDs in storage but do not expose titles in this UI.

## Processing

From the local 2moody-core DDEV project:

```bash
ddev drush moody-scheduled-publishing:process --prototype
```

This is the single processing entry point: there is no Drupal cron hook and no
browser-triggered publishing. The command returns a JSON receipt containing
published, blocked and failed node IDs; blocked/failing work returns nonzero.
It locks per site, processes up to 25 due nodes, and stops taking more work after
45 seconds. Repeated runs do not republish completed schedules. Failed items
are marked Needs attention so they cannot starve other scheduled content;
correct the problem and reschedule to retry.

Production execution is deliberately off unless the Pantheon environment is
Live and non-exported settings explicitly enable it:

```php
$settings['moody_scheduled_publishing_enabled'] = TRUE;
```

The `--prototype` escape hatch works only inside DDEV, never on Pantheon.
Test processing requires the explicit `--target=test` option and a matching
Pantheon Test runtime. A database copy alone does not execute processing.
The site-manager hourly Test Action invokes this command through the existing
authenticated fleet Drush path. Live processing remains separately gated as
above; Test authorization never enables Live. Slack delivery is deferred.

## Publication semantics

- Publishes the latest saved default revision and creates a new revision/log
  attributed to the editor who set the schedule. Rechecks that account is active
  and still has edit and status-field access; no superuser bypass.
- Does not include unsaved Layout Builder drafts. A newer non-default revision
  blocks publication instead of silently publishing an older version.
- Native Content Moderation and translation-specific scheduling are not offered
  in this prototype. These need workflow-aware transitions, not a status bypass.
- Manual publication, permanent deletion and Trash soft deletion terminate the
  pending schedule. Restoring from Trash does not reactivate it.
- Scheduling/cancellation history and successful publication are transactional.
  Drupal's normal form content save precedes schedule persistence; if the
  processor lock prevents schedule persistence, the form explicitly reports
  that content saved but scheduling did not, so the editor can retry.
- History stores IDs/timestamps/event names, not duplicated page content.

## Local verification

```bash
ddev drush php:script web/modules/custom/moody_modules/moody_scheduled_publishing/tests/drupal.php
```

`tests/browser.cjs` exercises the real form and dashboard with Playwright using
installed Chrome. Set `MOODY_SCHEDULE_QA_SITE_DIR` to the local DDEV directory
and provide an installed Playwright through `NODE_PATH`. It uses an isolated
browser context and removes its own fixture. No fleet or hosted run is enabled.
