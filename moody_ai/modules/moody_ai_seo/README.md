# Moody AI SEO

`moody_ai_seo` adds public, provider-independent signals that help search
engines and agents understand a Moody site. The module is opt-in and does not
enable itself on fleet sites.

## What it provides

- a managed `/llms.txt` endpoint through the maintained `llms_txt` module;
- a discovery `Link` header on public HTML responses;
- an optional homepage `CollegeOrUniversity`/`Organization` JSON-LD record;
- a concise Markdown representation for negotiated HTML 404 responses while
  preserving the real HTTP 404 status;
- a read-only readiness audit covering server-rendered content, headings,
  Markdown negotiation, agent guidance, structured identity, sitemap, 404s,
  developer-resource disclosure, and configurable trust pages.

Markdown negotiation for normal HTML pages is supplied by the maintained
`markdownify` module when that module is enabled. `moody_ai_seo` deliberately
does not require it at module-enable time so Pantheon's native Markdown
negotiation can be evaluated in Test without two implementations competing.

## Administration

Configure the site at `/admin/config/services/moody-ai/seo`. The dashboard keeps
the editable `llms.txt` guidance, organization identity, contact and address
values, official profiles, and trust-page paths together. These are normal
Drupal configuration, so every site can review or override them and export the
result through its existing configuration workflow.

Protocol behavior remains in code: discovery headers, negotiated Markdown 404
recovery, cache variation, and the audit are not per-site switches. Organization
JSON-LD stays disabled until an administrator reviews the identity, contact, and
address values. The dashboard also identifies content decisions the module
should not guess, such as homepage copy and the substance of legal or contact
pages.

Do not add a `Developer resources` section merely to satisfy the audit. Add it
only when the site has a real public API, specification, integration guide,
webhook guide, MCP server, or similar resource, and link to the canonical HTTPS
documentation.

## Audit

Run the audit against the current Drush URI:

```bash
drush --uri=https://example.edu moody-ai-seo:audit
```

Or name a public HTTPS base URL explicitly:

```bash
drush moody-ai-seo:audit --base-url=https://example.edu
```

The command prints JSON containing only status codes, response headers,
semantic counts, and pass/fail results. It never prints downloaded page bodies.
It exits nonzero while any check remains unresolved, which makes it suitable
for a later read-only fleet report before enablement.

## Rollout gate

Before fleet enablement, verify on one Pantheon Test site that:

1. HTML and Markdown variants include `Vary: Accept` and are not mixed by the
   CDN cache.
2. Missing HTML and negotiated Markdown paths both retain status 404.
3. The reviewed Organization JSON-LD contains the correct site contact and
   postal address.
4. Trust-page paths point to substantive, published pages.
5. Markdown negotiation is provided by exactly one implementation: Pantheon or
   `markdownify`.
