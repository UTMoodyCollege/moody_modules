# Moody Page Launch

Provides an administrator-only, two-step tool at
`/admin/content/moody-page-launch` for replacing a published content page or
fixed Views page display with a redesigned page.

The preview identifies every planned publication, alias, and redirect change.
Launch then revalidates that plan and applies it in one database transaction:

- a current content page is unpublished and moved to the first free `-old-vN`
  URL, or a current Views page display is disabled without affecting its other
  displays;
- a replacement content page is published at the current page's URL, or a
  replacement Views page display is enabled and moved to that path;
- the replacement's preview URL redirects to its new live URL;
- the former page's `/node/N` URL redirects to the replacement; and
- existing redirects that directly target the former node are retargeted.

Both page saves create revisions. Redirect and alias recovery still relies on
the platform backup after a successful launch.

Choose **Keep published / enabled at an archive URL** to retain the former
content publicly instead of retiring it. Optionally enter an unused local
archive URL, such as `/archive/program-2025`; blank uses the first free
`-old-vN` URL. This also supports moving a former Views page display while
leaving its other displays unchanged. Existing behavior remains the default.

The replacement owns the original public URL (no redirect is needed there).
The replacement's former URL and existing legacy redirects lead to the new
page. In public-archive mode the old `/node/N` continues serving the archive:
redirecting that canonical path would also redirect archive-alias visitors.
Archive URLs cannot collide with aliases, redirects, Views paths, or routes.
The preview fingerprints these choices and execution rechecks them under the
existing lock and transaction. No content is copied or deleted.

Both page pickers search titles, node IDs, active aliases, pasted URLs, and
humanized alias text. Suggestions display the current alias beside the title.
Views page displays use explicit selects that show their current paths and
enabled state. Dynamic, administrative, default-tab, and View-to-View launches
are intentionally excluded from this first Views integration.
