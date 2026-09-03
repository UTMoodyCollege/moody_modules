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

Both page pickers search titles, node IDs, active aliases, pasted URLs, and
humanized alias text. Suggestions display the current alias beside the title.
Views page displays use explicit selects that show their current paths and
enabled state. Dynamic, administrative, default-tab, and View-to-View launches
are intentionally excluded from this first Views integration.
