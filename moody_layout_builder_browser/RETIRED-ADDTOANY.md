# Retired AddToAny layout component

AddToAny node sharing is disabled across the fleet. Its derived
`extra_field_block:node:<bundle>:addtoany` plugin therefore does not exist.
Legacy default layouts and page overrides still referenced it, causing the
missing-plugin log message. Do not re-enable sharing or uninstall the module
(content-type dependencies still require it).

The targeted update removes these components from active display configuration.
The source Feature Page default and tracked site configurations omit them too.
Node/display load and presave hooks prune only these retired components using
their raw configuration, never instantiating missing plugins. Old revisions
remain stored unchanged and usable; subsequent normal editor saves persist the
cleaned layout. No bulk article save, publication change, section deletion or
unrelated component removal occurs. Existing in-progress Layout Builder
tempstore sessions may need their normal editor reload/discard workflow; we do
not erase unsaved editor work.

Read-only Live check, October 7, 2026 (counts are matching current section rows,
not a claim that every row is a distinct page):

| Site | Stale default display | Current section rows |
| --- | --- | ---: |
| moody-core | Yes | 119 |
| moody-cms | Yes | 7 |
| moody-jam | Yes | 1 |
| moody-radio-television-film | Yes | 27 |
| moody-slhs | Yes | 0 |
| moody-srs | Yes | 4 |
| healthcomm2 | Yes | 1 |
| strauss-institute | Yes | 4 |
| ut-la-utdk | Yes | 0 |
| ut-ny-utdk | Yes | 0 |
| immersive-media, moody-events, moody-staff, moody-voces, moody-content-portal | No | 0 |

Local regression (no saves):

```sh
ddev exec drush --uri=https://2moody-core.ddev.site php:script \
  web/modules/custom/moody_modules/moody_layout_builder_browser/tests/retired_addtoany_smoke.php
```

After deployment, verify the raw active default configurations no longer contain
the retired ID, existing Feature Pages still render/edit, and new watchdog
messages stop. Raw historical revision rows intentionally retain their original
layout evidence; they are sanitized in memory when loaded.
