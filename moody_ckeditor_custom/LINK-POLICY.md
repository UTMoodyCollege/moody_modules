# Stable page links

Moody's page-launch workflow can replace a page with a different node, move the
old page to an archive URL, and transfer its public alias to the replacement.
New CKEditor page-picker links should therefore store the public alias without
Linkit's node UUID metadata. Otherwise a link can follow the archived node
instead of the newly launched page.

Node autocomplete results without a public alias are omitted. Assign the page
its intended alias before linking to it. Media and external suggestions retain
their existing behavior. Existing content is not rewritten by this change.

This guards Linkit autocomplete, not arbitrary HTML pasted or entered in Source
mode. Existing node links need a separate reviewed normalization pass. Verify
autocomplete, saved HTML, rendered links, and a page-launch alias transfer on
Test before fleet release. No automatic contextual replacement is safe for a
missing node.
