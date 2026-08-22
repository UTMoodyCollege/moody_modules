# Moody AI

This directory is the shared home for opt-in AI features that ship with
`moody_modules`.

- `moody_ai_base` owns provider credentials, model allowlists, fixed
  guardrails, style-guide context, outbound requests, and generated-HTML
  sanitization.
- `moody_ai_ckeditor` provides the first editor-facing feature. It sends only
  the prompt and optional attachments selected in its dialog, shows a sanitized
  preview, and inserts the result only after the editor approves it.
- `moody_ai_assistant` provides a page-aware chat assistant for Layout Builder.
  It uses the block definitions and page context already available to the
  editor, creates and places new blocks, previews edits to existing blocks,
  previews redirect creation, and links to normal access-controlled Drupal
  workflows for new pages, menus, content, Media, users, taxonomy, and
  configuration.
- `MOODY_PAGE_BUILDING.md` remains the larger Layout Builder component context
  for future features; it is not sent with each CKEditor request.

Both editor experiences consume the same UI contract from `moody_ai_base`.
They share the configured provider and model choices, prompt ideas and token
estimates, image-generation preference, file upload/drag/drop/clipboard paste,
existing Media selection, reference limits, privacy guidance, and reusable
field styling. Each keeps the workflow-specific controls it needs: CKEditor
previews sanitized HTML before insertion, while the page assistant retains its
conversation, page context, block tools, and action approval flows.

Both experiences also consume one shared context catalog from
`moody_ai_base`. Security, accuracy, accessibility, editorial, and Moody
design-system knowledge ships in code and appears read-only on the
configuration dashboard. A site can add its own context in three organized
configuration fields: site identity and audiences; names, terminology, and
factual defaults; and editorial and design guidance. These values live in
`moody_ai_base.settings`, so they can travel through the normal Drupal
configuration workflow. Site context can narrow the task but cannot override
the built-in guardrails. Keep changing facts out unless the site will actively
maintain them, and never store secrets in context.

Administrators control the entire service from the **Moody AI configuration**
dashboard at `/admin/config/services/moody-ai`. Its global switch is enforced
by the base provider gateway and by each editor endpoint, so disabling it stops
provider requests, new reference uploads, and generated-content insertion.
Both editor experiences show the dashboard's configurable offline message.
New installations start offline; an existing installation without the new
setting retains its current behavior until an administrator saves the form.

For an emergency command-line change, run the matching command and clear
caches. The dashboard remains the normal interface:

```bash
drush config:set moody_ai_base.settings enabled 0 -y
drush cache:rebuild

# Re-enable after the pause has been approved.
drush config:set moody_ai_base.settings enabled 1 -y
drush cache:rebuild
```

## Security defaults

No API key is stored in Drupal configuration or sent to the browser. The base
module resolves the configured lowercase secret name in this order:

1. Pantheon's `pantheon_get_secret()` runtime API.
2. The uppercase environment variable with the same name for local use.

The default `moody_ai_openai_api_key` therefore maps to
`MOODY_AI_OPENAI_API_KEY` in DDEV. Generation also requires the restricted
`use moody ai ckeditor` permission, a per-user hourly limit, a valid CSRF token,
an allowlisted model, and server-side HTML sanitization. Do not grant the usage
permission to anonymous or broadly trusted roles during the pilot.

Optional reference files are temporary Drupal managed files stored at
`private://USER_ID/YYYY-MM-DD/moody-ai-ckeditor-uploads/FILENAME`. The upload
and generation routes both require the feature permission and a valid CSRF
token. Generation rechecks file ownership, path, type, size, and readability;
private file URLs are never sent to the provider or browser. The pilot accepts
up to three combined uploads and existing Media references. Source files are
limited to 5 MB each and 10 MB combined. Drupal's temporary-file cleanup policy
controls uploaded-file retention.

Editors can also paste a clipboard image while focus is inside the generator
dialog. It joins the same attachment queue and follows the same limits and
private storage path as a chosen file; ordinary text paste is unchanged.

When an editor explicitly asks to use an attached image in the generated
content, the provider may return a restricted internal placeholder with
generated alt text. The preview uses the local image and does not create a
Media entity. Only when the editor chooses **Insert HTML** does the server
recheck file ownership, image validity, the site's image Media bundle, and the
editor's Media create access. It then reuses or creates a Media entity and
replaces the placeholder with standard `<drupal-media data-entity-type="media"
data-entity-uuid="…"></drupal-media>` markup. This delayed, idempotent step
prevents canceled previews and insertion retries from creating duplicate Media.

The dialog also opens Drupal's existing CKEditor Media Library with its signed
URL, allowed bundles, and normal access checks. Each selected item defaults to
**Inspiration only**; the editor can instead choose **May insert in content**.
Accessible image and document source files are included as provider context
when their format and size satisfy the same attachment limits. Other Media
still contributes its managed name and type. Inspiration-only selections are
never allowed in returned markup. Insertable selections resolve to their real
UUID only after preview approval, preserving the Media entity's existing alt
text and other managed metadata.

## Local pilot

Keep the key out of the repository. With DDEV 1.23.5 or later, put this in the
untracked `.ddev/.env.web` file and restart DDEV:

```dotenv
MOODY_AI_OPENAI_API_KEY=replace-with-a-development-key
```

Enable the feature and grant its permission only to the pilot role:

```bash
ddev drush en -y moody_ai_base moody_ai_ckeditor
ddev drush config:set moody_ai_base.settings enabled 1 -y
ddev drush role:perm:add ROLE_ID 'use moody ai ckeditor'
ddev drush cr
```

The CKEditor submodule adds `Generate with Moody AI` to the `flex_html`
toolbar when it is installed. Editors can attach reference documents or images
from the dialog or paste clipboard images into it. The prompt and selected files
are sent to the configured provider only when they request a preview. To insert
an attached image as Drupal Media, say so in the prompt; generation supplies
the alt text and insertion performs the Media creation. Existing Media can be
selected as inspiration or explicitly made available for insertion. Provider,
model, context, and limit settings are at `/admin/config/services/moody-ai`.

## Pantheon Test pilot

Use one site-owned secret named `moody_ai_openai_api_key` with type `runtime`
and scope `web`. The Pantheon Dashboard's **Site Settings → Secrets** screen is
the safest way to enter the value. Terminus 4.2 or later also supports:

```bash
terminus secret:site:set PANTHEON_SITE moody_ai_openai_api_key API_KEY --scope=web --type=runtime
```

Avoid leaving the real value in shell history. Deploy the reviewed component
to the site's Dev environment, promote that exact code to Test, enable the two
modules on Test, and grant the usage permission only to the pilot role. Verify
configuration status before the pilot. Enabling the modules on Test creates
Test-only active-configuration changes; capture and review those changes before
the next verification-enforced deployment, or remove them when the pilot ends.
Do not import unrelated drift or enable the feature on Live during the pilot.

## Adding another provider or feature

Feature modules call `moody_ai_base.generator`; they do not read credentials or
call provider APIs directly. Add a provider in that service so model
validation, logging, response handling, and sanitization stay centralized.
Every editor-facing feature must add its own permission and access-controlled
server route.

## Page-aware assistant pilot

Enable the assistant independently from the CKEditor feature and grant its
restricted permission only to the pilot role:

```bash
ddev drush en -y moody_ai_base moody_ai_assistant
ddev drush role:perm:add ROLE_ID 'use moody ai assistant'
ddev drush cr
```

The floating assistant appears automatically when the current account has its
feature permission and update access to a content entity with a Layout Builder
field. It does not require a theme-specific block placement. The conversation
is scoped to that editor and page, persists between requests, and can be reset
by its owner. Prompts and assistant responses are stored in Drupal; the widget
warns editors not to include credentials or restricted personal information.

The assistant reuses `moody_ai_base.generator` for credentials, the model
allowlist, fixed guardrails, provider calls, and request limits. Its routes
also enforce CSRF protection, thread ownership, target-page update access, and
the shared per-user hourly flood limit. Access is checked again when an editor
approves an existing-block edit or redirect. The model can propose only; Drupal
remains authoritative for block types, Media creation, entity access, routes,
and final persistence.

Assistant attachments follow the proven Layout Builder workflow rather than
the CKEditor dialog's temporary private-reference workflow. With Media create
access, an upload becomes a permanent Drupal Media item backed by a public
file; uploaded images receive generated alt text when the provider is
available. Editors can also select access-checked existing Media as inspiration
or permit it for page content. Do not use assistant uploads for restricted
documents. Requests to generate an image also create Media, but model-supplied
remote image URLs are never downloaded by the server.

New-page and site-administration requests intentionally continue through
Drupal's standard forms so existing field validation, editorial workflow, and
route access checks stay in force. Redirects and edits to existing inline
blocks require an explicit preview approval. New block-building requests are
executed when the editor sends the request, matching the current Layout Builder
assistant workflow; pilot roles should therefore be limited to trusted site
editors.
