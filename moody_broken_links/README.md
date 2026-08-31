# Moody Broken Links

Moody Broken Links scans selected node bundles for links in Link fields,
formatted text fields, Moody/UT custom field URL properties and serialized
item collections, and per-node Layout Builder inline blocks. Results are
checked in batches and shown at **Content > Broken links**.

Scans can target selected content types, all content types, or one specific
page selected through Drupal's entity autocomplete.

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
