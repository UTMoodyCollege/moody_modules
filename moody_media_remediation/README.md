# Moody Media Remediation

This Drupal admin module scans the current site for missing files, unused
candidates, and exact duplicate binaries. Open **Content → Media remediation**
and run a scan. Metadata and usage are collected for every managed file;
SHA-256 is calculated only for files that share a recorded size.

The exact-duplicate review form can consolidate current managed `file` and
`image` field references onto a selected canonical file. Before saving, it
rehashes every selected binary, checks entity update access, and validates the
affected entities. It creates revisions where supported and records the exact
before/after field values for guarded undo when every file is retained.

Deletion is optional and off by default. When explicitly selected and confirmed,
the module consolidates known current core and Entity Usage sources, rejects
unmanaged usage sources and shared file URIs, retains all media entities, and
then deletes the redundant file entities and binaries. Deleted files cannot be
restored by the dashboard; historical revisions and untracked hard-coded URLs
may break, so use a Pantheon platform backup to recover them.
“Unused” reflects Drupal core file usage plus Entity Usage tracking and remains
a review signal, not proof that a file is safe to delete. Hard-coded URLs and
historical revisions may not be represented by those trackers.

Installing the module creates an unassigned **Media Remediation Manager** role
with access to the dashboard. User 1 retains Drupal's normal administrative
access; assign the dedicated role later only to accounts that should perform
remediation work.

For local verification:

```bash
ddev drush en moody_media_remediation -y
ddev drush role:perm:add moody_administrator \
  'administer moody media remediation'
ddev drush scr \
  web/modules/custom/moody_modules/moody_media_remediation/tests/remediation_smoke.php
```
