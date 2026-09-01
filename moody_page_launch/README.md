# Moody Page Launch

Provides an administrator-only, two-step tool at
`/admin/content/moody-page-launch` for replacing a published page with a
redesigned page.

The preview identifies every planned publication, alias, and redirect change.
Launch then revalidates that plan and applies it in one database transaction:

- the current page is unpublished and moved to the first free `-old-vN` URL;
- the replacement is published at the current page's URL;
- the replacement's preview URL redirects to its new live URL;
- the former page's `/node/N` URL redirects to the replacement; and
- existing redirects that directly target the former node are retargeted.

Both page saves create revisions. Redirect and alias recovery still relies on
the platform backup after a successful launch.

Both page pickers search titles, node IDs, active aliases, pasted URLs, and
humanized alias text. Suggestions display the current alias beside the title.
