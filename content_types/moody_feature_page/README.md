# Moody Feature Page

## External stories

Select **External redirect feature page** and enter the full HTTP(S) story URL
on an affiliated site. Keep the local title, thumbnail, categories, date and
body summary for existing news Views, Editors Picks and other cards. They still
link to the local node; no listing changes or separate Redirect record are needed.

Published canonical HTML pages immediately redirect visitors with an HTTP 302;
there is no countdown or JavaScript dependency. Destination URLs are preserved
unchanged, including query strings and fragments. Editors with update access and
unpublished previews retain the destination notice and **Read the story** link
without automatically forwarding. Edit, Layout, revisions,
API responses and embedded node rendering retain their normal behavior.

Both settings belong to the node and follow its revisions/translations. The
option defaults off. Turning it off restores normal rendering without deleting
the saved destination. Existing Redirect-module records are not modified;
remove any old source-path redirect when converting that page, since Redirect
may act before this module at the request stage. The legacy Feature Page
Redirects controller subscriber defers to the new notice when applicable.

Run database updates after installing this release. Update `11002` adds only
the two fields and their widgets to Feature Page form displays, preserving
existing fields, layouts and content. Capture the resulting config before a
later full config import. Fresh installs add the same fields in `hook_install`.

After updating a local Drupal site, run the read-only smoke check:

```sh
ddev drush php:script web/modules/custom/moody_modules/content_types/moody_feature_page/tests/external-story.php
```
