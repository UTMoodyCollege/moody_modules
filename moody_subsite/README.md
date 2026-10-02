# Subsite startup

Use **Set up a subsite (preview first)** on the subsite collection, or visit
`/admin/structure/moody_subsite/startup`. Access requires all three existing
permissions: add Moody subsites, administer permissions, and administer users.

The form reuses the normal subsite fields and logo/media widgets. Choose an
unused Directory Structure term, complete required branding, optionally enter
up to 20 starter pages (`Title | /path`), and select existing active editors.
Review the plan before confirming. Uploading media uses the normal media/file
workflow; preview does not create subsites, pages, roles, or user assignments.

Execution creates a permission-free section assignment role, wires it into the
existing Directory Structure Workbench scheme, and adds that role plus the
existing `moody_subsite_editor` role to selected users. Existing roles and
section assignments are preserved. Starter pages are drafts with explicit
aliases; publish them after review. The existing scheme must cover both
`moody_subsite.directory_structure` and
`node.moody_subsite_page.field_moody_url_generator`. Missing prerequisites,
occupied URLs, reused sections, blocked users, or changed preview inputs stop
setup. Writes use a database transaction and a site-wide startup lock.

Local/Test rollback smoke check (never run against Live):

```sh
drush php:script web/modules/custom/moody_modules/moody_subsite/tests/startup.php
```

This uses existing image media, creates temporary fixtures within a rolled-back
transaction, and checks preview, execution, editor access, anonymous draft
protection, duplicate rejection, and permission rejection.
