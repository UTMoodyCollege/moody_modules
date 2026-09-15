# Moody Layout Restrictions

Drupal core plugin-filter hooks preserve each display's saved block categories,
allow/deny lists, reusable block types, inline blocks and allowed layouts.
Overrides inherit their default display's rules. Existing page layouts are not
rewritten and no authoring framework or replacement settings UI is introduced.
Obsolete `whitelisted_blocks`, `blacklisted_blocks` and `allowed_blocks` values
are retained but remain inactive, matching the fleet's Restrictions 3.x behavior.
This migration must not silently reactivate previously ignored restrictions.

Installation transfers active `layout_builder_restrictions` third-party settings
to this module. Fleet update 9019 installs it before Drupal Kit 3.32 removes the
old module. It does not uninstall the old module or update Drupal Kit itself.
Unsupported region/plugin customizations and conflicting providers stop migration
for review. A second migration is a no-op.

Capture configuration after rollout before a later config import. Do not import
older display configuration that reintroduces the old provider. The shared
content modules' fresh-install configuration uses the new provider directly.

`drush moody-layout-restrictions:verify` checks for unmigrated displays and
reports counts. Add `--uninstall-legacy` for the Test-only uninstall rehearsal;
it refuses other environments or installed dependent modules and verifies that
every display survives unchanged. Use the existing local/Actions Drush runners.

Back up the DDEV database, then run `drush php:script` against
`tests/migration-smoke.php` for a mutating local integration check: compare all
existing definitions, migrate twice, uninstall the old module, and verify the
same definitions and saved layouts survive. Never run that script on Pantheon.
