# Editor workspace

Moody Custom Roles adds a read-only workspace to the signed-in user's own full
profile. It does not replace login destinations, account fields, or public
profiles. No new permission or configuration import is required.

- Role labels and route-access-checked starting actions.
- Assigned subsite dashboard links reuse `moody_subsite.editor_context` when
  available; Workbench assignments remain authoritative.
- Six recently edited pages use revision authorship, not content ownership,
  and require both current view and update access. The latest 100 distinct
  edited page candidates are checked; deleted/inaccessible pages are skipped.
- Site activity is limited to users with `administer nodes` or
  `bypass node access`. Latest revision attribution is not a complete audit log.
- Recent media additionally requires `access media overview` and individual
  view access. Optional subsite/media features disappear when unavailable.

Personalized output is not render-cached. Queries are bounded; no new activity
storage, tracking, or writes occur during profile rendering.

Local verification (fixture writes roll back):

```sh
drush php:script modules/custom/moody_modules/moody_custom_roles/tests/editor_home.php
```
