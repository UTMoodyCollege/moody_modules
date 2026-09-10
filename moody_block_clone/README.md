# Moody Block Clone

In Layout Builder, open **Add block → Clone blocks from other page**. Choose a
published page you can view, select one or more inline blocks, then choose
**Copy selected blocks**. Each card identifies its block type and source
section/region; long previews can scroll with a keyboard. The selection count
and checked cards show what will be copied.

Copies are independent and appended in source section, region, and weight
order—not checkbox-click order. Reorder them in Layout Builder and save the
layout to keep the changes. Loading another page clears the selection. Source
content is never edited. Reusable blocks and unpublished source blocks are not
offered by this picker.

The whole selection and destination are checked before copying. Database
failures roll back the batch; the existing single-block service delegates to
the same operation. The chooser uses Drupal's CSRF-protected form submission,
native checkboxes, AJAX validation and layout rebuild, with a non-JavaScript
redirect fallback. No config import or schema update is needed.

## Local checks

From the existing `2moody-core` DDEV project, after syncing this module:

```bash
ddev exec --raw -- drush php:script web/modules/custom/moody_modules/moody_block_clone/tests/drupal.php
```

The check creates isolated fixtures inside a rollback transaction. It covers
ordered multi-copy, single-copy compatibility, independent content/identities,
usage tracking, invalid selections/destinations, batch failure rollback,
source access, autocomplete IDs, form validation and both AJAX dialog targets.
It refuses Pantheon and other environments. For manual browser QA only, prefix
the inner command with `env MOODY_BLOCK_CLONE_QA_FIXTURE=1`; this retains a local
published source and an empty unpublished destination and prints their IDs.
