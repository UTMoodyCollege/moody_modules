# Moody Broken Links

Moody Broken Links scans selected node bundles for links in Link fields,
formatted text fields, Moody/UT custom field URL properties and serialized
item collections, and per-node Layout Builder inline blocks. Results are
checked in batches and shown at **Content > Broken links**.

Scans can target selected content types, all content types, or one specific
page selected through Drupal's entity autocomplete.

Dashboard scans use Drupal's persistent queue, with one page per item. The
first item starts after the kickoff response; remaining items run through
cron, independently of the browser. Refresh the dashboard to see progress.
CLI workers can drain the same queue with
`drush queue:run moody_broken_links_scan`; no alternate scanner is used.
Cron's 30-second budget is checked between items, not a hard timeout for one
large page. A 15-minute lease/lock prevents ordinary concurrent processing;
findings and checkpoints commit together, making retry acknowledgement safe.
Verify each site's cron cadence before claiming continuous/immediate fleet
processing. No recurring content repairs are enabled by this queue.

Formatted text is also checked for links created by the enabled Drupal
"Convert URLs into links" filter (including bare `www.example.com` text).
These results are labelled **auto-linked text (manual edit required)** and
excluded from automatic repairs and bulk removals. Removing an anchor while
retaining URL-shaped text would cause the filter to recreate the link. Edit
the source text or deliberately change its text-format settings instead.
This check runs the native URL filter only, not media/embed filters or a
full-page crawl; it does not claim coverage of every dynamically generated link.

The dashboard can revise a URL or remove an anchor while preserving its linked
text and markup. Its page workspace can queue several revise/remove choices and
apply them together in one revision, so links from the same field do not need a
rescan between changes. Every remediation:

- rechecks the exact field value and link occurrence recorded by the scan;
- requires update access to the page;
- creates a new Drupal revision; and
- stops if the source changed after the scan.

The module does not mutate default Layout Builder configuration or reusable
shared blocks. Non-HTTP links such as email, telephone, and fragment links are
ignored. Access is limited to user 1 and accounts explicitly granted
`administer moody broken links reports`. The installed **Moody Broken Links
Manager** role has the permission but is not assigned to any account.
