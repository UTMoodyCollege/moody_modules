# Moody page building

This document is context for an LLM that recommends or prepares Moody pages.
It describes only the components offered by Layout Builder Browser in the local
`moody-core` Drupal instance. It is a planning and authoring reference, not an
instruction to write Drupal configuration or database records directly.

## Source of truth

This snapshot was extracted on 2026-07-31 from Drupal 11.3.11 with the
`moody26` theme active. The sources, in priority order, are:

1. Enabled `layout_builder_browser_blockcat` and
   `layout_builder_browser_block` config entities in the running Drupal site.
2. The referenced block plugin definitions and configuration forms.
3. Block content field storage, field configuration, and form displays.
4. This document.

When these sources disagree, the running site's enabled Layout Builder Browser
entities decide whether a component is offered. The current instance has 2
enabled categories and 26 enabled components: 19 in `moody_site_building` and
7 in `specialty_blocks`.

The runtime currently differs from the install-time configuration in
`moody_layout_builder_browser`: `photo_content_area` exists in default config
but is not offered by this site, while `faculty_bio` and `faculty_emeritus` are
offered by the site but are not in that default config. This guide follows the
runtime and therefore excludes `photo_content_area`.

## LLM operating contract

- Use `browser_id` to identify a browser entry and `plugin_id` to identify the
  Drupal block implementation. Labels are presentation text and may change.
- Recommend only components listed below. Never infer availability from an
  installed module or block type alone.
- Treat media, links, taxonomy terms, and existing content as references that
  must be resolved in Drupal. Do not invent entity IDs, UUIDs, file IDs, paths,
  people, claims, dates, statistics, or testimonials.
- Do not generate raw Layout Builder section YAML from this guide. Use Drupal's
  UI or APIs so revisions, translations, access checks, and inline-block
  ownership remain correct.
- Preserve intentional existing content. Ask for missing editorial facts or
  mark them as unresolved rather than filling a layout with invented copy.
- Prefer the simplest component that expresses the content. Specialty and
  motion-heavy components require a clear content reason.

Represent a proposed page as a component plan before attempting Drupal writes:

```yaml
page_goal: "What the visitor should accomplish"
components:
  - browser_id: moody_hero
    plugin_id: "inline_block:moody_hero"
    intent: "Introduce the page"
    content:
      heading: "Approved heading"
      image: "UNRESOLVED: select existing media with suitable alt text"
    unresolved:
      - "Confirm call-to-action destination"
```

## Global authoring requirements

- Keep one clear page purpose and a logical heading hierarchy. A component's
  visual headline does not automatically justify skipping heading levels.
- Use concise, descriptive link text. Indicate external, authentication-only,
  download, or new-window behavior when it matters to the visitor.
- Every meaningful image needs useful alternative text on its Drupal media
  entity. Decorative images should have empty alt text. Captions do not replace
  alt text.
- Videos need accurate captions; audio-only content needs a transcript; and
  essential visual information needs audio description or equivalent text.
- Charts need a meaningful title and an adjacent text summary or accessible
  data equivalent. Do not rely on color alone to communicate a series or state.
- Use only the component's provided brand options. Official burnt orange is
  `#bf5700`; do not invent tints or use unapproved custom colors.
- Treat animation as optional enhancement. Content and controls must work with
  reduced motion, blocked motion assets, Save-Data, keyboard input, touch, and
  200% zoom.
- Review all interactive components with keyboard-only navigation and visible
  focus. Never put essential information only on hover, flip, scroll, or drag.
- Verify page behavior at 320, 375, 414, and 768 CSS pixels as well as desktop.

## Quick selection guide

| Content need | Prefer | Consider instead |
| --- | --- | --- |
| General formatted prose | `basic_block` | `flex_content_area` when media and repeated rows are central |
| Collapsible questions or grouped details | `accordion` | `basic_block` when hiding content adds no value |
| One highlighted item | `featured_highlight` | `promotion` when media should dominate |
| Repeated media-and-copy rows | `flex_content_area` | `showcase` for a more editorial, image-led treatment |
| Compact linked image | `image_link` | `promo_list` for several linked items |
| Repeated compact promotions | `promo_list` | `promo_unit` for larger promotional items |
| Cards or topic choices | `flex_grid` | `moody_focus_areas` for a small, intentional topic set |
| Four simple color panels | `flex_color_blocks` | `flex_grid` when cards need media or body copy |
| Page introduction | `hero` | `featured_highlight` for an in-page highlight |
| Statistics or short facts | `impact_facts` | `chart` for comparative datasets |
| Contact details | `contact_info` | `basic_block` for unstructured prose |
| Curated image collection | `image_gallery` | `showcase` for image-and-copy storytelling |
| Guided inspection of one image | `moody_focal_point` | `image_gallery` for unrelated images |
| Sequential, scroll-led media story | `scroll_reveal_media` | `showcase` when motion is unnecessary |
| Silent background video introduction | `ambient_video` | `hero` when a still image communicates the same idea |
| In-page or subsection navigation | `moody_mini_nav` | regular page links for a very short page |
| Faculty directory | `faculty_bio` or `faculty_emeritus` | neither for manually curated people |

## Component catalog

All entries below are enabled in the current Layout Builder Browser.

### Site Building

Category ID: `moody_site_building`. It is open by default in the browser.

#### Basic block

- `browser_id`: `basic_block`
- `browser_label`: `Basic block`
- `plugin_id`: `inline_block:basic`
- `kind`: inline block content; bundle `basic`
- Use for: ordinary body copy, short introductions, and content that does not
  need a specialized visual structure.
- Inputs: one optional formatted `Body` field.
- Rules: prefer semantic headings, lists, and links over manual visual styling.
  Do not use it to recreate an existing structured component with ad hoc HTML.

#### Featured Highlight

- `browser_id`: `featured_highlight`
- `browser_label`: `Featured Highlight`
- `plugin_id`: `inline_block:utexas_featured_highlight`
- `kind`: inline block content; bundle `utexas_featured_highlight`
- Use for: one timely or important item with optional media, date, summary, and
  destination.
- Inputs: media, headline, formatted copy, date, URL, optional secondary link
  text, new-window behavior, and approved link icon treatment.
- Rules: recommended image width is at least 600px. If the headline links, it
  must describe the destination; do not repeat it with a redundant CTA.

#### Flex Content Area

- `browser_id`: `flex_content_area`
- `browser_label`: `Flex Content Area`
- `plugin_id`: `inline_block:utexas_flex_content_area`
- `kind`: repeatable inline block content; bundle `utexas_flex_content_area`
- Use for: flexible, repeatable rows combining media, copy, related links, and
  an optional CTA.
- Inputs per row: 3:2 media, headline, formatted copy, a list of links, and a
  CTA with approved link behavior and appearance.
- Rules: use approximately 1000x666 images. Keep each row focused on one idea;
  split unrelated material into separate rows or components.

#### Image Link

- `browser_id`: `image_link`
- `browser_label`: `Image Link`
- `plugin_id`: `inline_block:utexas_image_link`
- `kind`: inline block content; bundle `utexas_image_link`
- Use for: a single image whose primary action is navigation.
- Inputs: one image, URL, screen-reader link text, new-window behavior, and an
  approved link icon treatment.
- Rules: link text must describe the destination even when it is visually
  hidden. The image fills its Layout Builder region, so choose the region and
  crop deliberately.

#### Promo List

- `browser_id`: `promo_list`
- `browser_label`: `Promo LIst` (capitalization is from live config)
- `plugin_id`: `inline_block:utexas_promo_list`
- `kind`: repeatable inline block content; bundle `utexas_promo_list`
- Use for: a list of compact, related promotional items.
- Inputs: list headline; repeatable items with a 1:1 image, item headline,
  formatted copy, URL, link text, and approved link behavior/appearance.
- Rules: use approximately 170x170 images. If a URL is present, the item image
  and headline become links; avoid duplicate links with the same destination.

#### Promo Unit

- `browser_id`: `promo_unit`
- `browser_label`: `Promo Unit`
- `plugin_id`: `inline_block:utexas_promo_unit`
- `kind`: inline block content; bundle `utexas_promo_unit`
- Use for: a prominent group of larger promotional items.
- Inputs: group headline; repeatable items with image, headline, formatted copy,
  URL, optional secondary link text, and approved link behavior/appearance.
- Rules: choose an image aspect ratio for the intended view mode and use images
  at least 1600px wide for high-resolution displays.

#### Moody Accordion

- `browser_id`: `accordion`
- `browser_label`: `Moody Accordion`
- `plugin_id`: `inline_block:moody_accordion`
- `kind`: repeatable inline block content; bundle `moody_accordion`
- Use for: FAQs or grouped details where visitors can predict a panel's content
  from its title.
- Inputs per panel: panel title and formatted panel contents.
- Rules: keep titles unique and descriptive. Do not hide essential introductory
  content or use an accordion solely to shorten a page visually.

#### Moody Ambient Video

- `browser_id`: `ambient_video`
- `browser_label`: `Moody Ambient Video`
- `plugin_id`: `inline_block:ambient_video`
- `kind`: inline block content; bundle `ambient_video`
- Use for: an intentional, mostly decorative video-led introduction where
  motion adds meaning beyond a still hero image.
- Inputs: external video URL; required poster image; optional mobile fallback
  image; headline and optional second line; CTA; text position (`centered`,
  `top-left`, `top-right`, `bottom-left`, `bottom-right`); mask (`none`, burnt
  orange, charcoal, blue, tangerine); opacity from 0 to 1; constrained or
  natural height; fixed-scroll, pinned-text, and short-mode toggles; optional
  WebVTT descriptions file.
- Rules: centered text is recommended with a CTA. The poster should match the
  first video frame. Supply a mobile fallback. Avoid meaningful speech unless
  complete captions and equivalent content are available. Confirm reduced
  motion and blocked-video behavior before use.

#### Moody Contact Info

- `browser_id`: `contact_info`
- `browser_label`: `Moody Contact Info`
- `plugin_id`: `inline_block:moody_contact_info`
- `kind`: inline block content; bundle `moody_contact_info`
- Use for: a concise contact or next-step panel.
- Inputs: headline, subheadline, formatted copy, URL, link text, and approved
  link behavior/appearance.
- Rules: present email addresses and phone numbers as actionable links where
  appropriate. Identify the office or person clearly.

#### Moody Flex Color Blocks

- `browser_id`: `flex_color_blocks`
- `browser_label`: `Moody Flex Color Blocks`
- `plugin_id`: `inline_block:moody_flex_color_blocks`
- `kind`: fixed-cardinality inline block content; bundle
  `moody_flex_color_blocks`
- Use for: up to four short, parallel choices or topic panels distinguished by
  approved color schemes.
- Inputs per panel: headline, subheadline, URL, link text, approved link
  behavior/appearance, and color scheme (`blue`, `gray`, `green`, `orange`).
- Rules: color cannot be the only distinction between panels. Keep the four
  items similar in information depth and use only the supplied colors.

#### Moody Flex Grid

- `browser_id`: `flex_grid`
- `browser_label`: `Moody Flex Grid`
- `plugin_id`: `inline_block:moody_flex_grid`
- `kind`: inline block content; bundle `moody_flex_grid`
- Use for: a responsive collection of cards, topics, programs, resources, or
  destinations.
- Inputs: section headline; one through six items per row; rounded-edge and
  overlay-text toggles; repeatable cards with image, headline, approved headline
  color and alignment, formatted copy, URL, optional button text, and button
  alignment.
- Rules: default images are 1:1 at about 500x500; rectangular treatment uses
  3:2 images. Overlay text needs a contrast check on every image. Do not make a
  whole card and an internal button compete for the same action.

#### Moody Focal Point

- `browser_id`: `moody_focal_point`
- `browser_label`: `Moody Focal Point`
- `plugin_id`: `moody_focal_point_block`
- `kind`: configurable block plugin from `moody_focal_point`
- Use for: a guided, step-by-step inspection of important regions in one image.
- Inputs: one required image; optional slide counter; up to 10 focal points with
  caption title, formatted caption body, focus square visibility/color, arrow
  visibility, and caption border (`none`, `thin`, `thick`, `rounded`, or
  `rounded-thick`).
- Rules: every focal detail must also be explained in text. Drag positioning
  cannot be the only way to understand or configure the sequence.

#### Moody Focus Areas

- `browser_id`: `moody_focus_areas`
- `browser_label`: `Moody Focus Areas`
- `plugin_id`: `inline_block:moody_focus_areas`
- `kind`: inline block content; bundle `moody_focus_areas`
- Use for: a small set of mission areas, disciplines, or high-level topic
  destinations.
- Inputs: section title; two, three, or four items per row; column and row gap
  sizes; optional overall CTA; repeatable items with 1:1 image, headline,
  formatted copy, and link.
- Rules: use approximately 280x280 images. Keep the set conceptually parallel
  and avoid using it as a generic catch-all card grid.

#### Moody Hero

- `browser_id`: `hero`
- `browser_label`: `Moody Hero`
- `plugin_id`: `inline_block:moody_hero`
- `kind`: inline block content; bundle `moody_hero`
- Use for: the primary page introduction with one strong image and concise
  message.
- Inputs: image, heading, subheading, caption, credit, optional CTA, and an
  image-optimization override. Style-specific options include text color and
  image overlay for hero styles 6-8 and text position for styles 7-8.
- Rules: use at least a 2280x1232 image (87:47 crop). The heading is optional in
  Drupal but strongly recommended as the media's textual explanation. Keep the
  subheading under about 140 characters. Disable image optimization only for a
  real GIF or dimension requirement.

#### Moody Image Gallery

- `browser_id`: `image_gallery`
- `browser_label`: `Moody Image Gallery`
- `plugin_id`: `moody_image_gallery_block`
- `kind`: configurable block plugin from `moody_image_gallery`
- Use for: a curated set of images that belong together as a visual collection.
- Inputs: optional headline; gutter (`tight`, `standard`, `large`); up to 12
  images, each with keyboard-adjustable crop focal point and optional caption.
- Rules: desktop layout repeats a 60/40/full-width pattern. Alternative text
  comes from each image media entity; verify it independently from the caption.
  Leave unused slots empty.

#### Moody Impact Facts

- `browser_id`: `impact_facts`
- `browser_label`: `Moody Impact Facts`
- `plugin_id`: `inline_block:moody_impact_facts`
- `kind`: inline block content; bundle `moody_impact_facts`
- Use for: a concise set of verified statistics, outcomes, or short facts.
- Inputs: section headline; two, three, or four items per row; orange-headline or
  gray-headline style; repeatable items with headline and subheadline.
- Rules: do not invent or round facts. Preserve units, timeframe, population,
  and source context in adjacent copy when the short labels cannot carry them.

#### Moody Promotion

- `browser_id`: `promotion`
- `browser_label`: `Moody Promotion`
- `plugin_id`: `inline_block:moody_promotion`
- `kind`: inline block content; bundle `moody_promotion`
- Use for: one image-led event, announcement, opportunity, or campaign.
- Inputs: media, headline, date, formatted copy, URL, link text, and approved
  link behavior/appearance.
- Rules: include a date only when it helps visitors act, and include timezone or
  full event context in copy where needed. Do not use expired promotional copy.

#### Moody Scroll Reveal Media

- `browser_id`: `scroll_reveal_media`
- `browser_label`: `Moody Scroll Reveal Media`
- `plugin_id`: `moody_scroll_reveal_media_block`
- `kind`: configurable block plugin from `moody_scroll_reveal_media`
- Use for: a sequential visual explanation where each media/text step depends
  on the previous step.
- Inputs: optional section headline; animation style (`fade` or `slide`); up to
  6 slides with image/external-video media or Vimeo URL, video autoplay,
  eyebrow, title, formatted body, inline or overlay text, 3x3 overlay position,
  and reveal direction (`top`, `right`, `bottom`, `left`). A Vimeo URL overrides
  selected media for that slide.
- Rules: content order must remain understandable without pinning or animation.
  Avoid autoplay with sound. Verify captions, reduced motion, keyboard access,
  live motion-preference changes, and small-screen reading order.

#### Moody Showcase

- `browser_id`: `showcase`
- `browser_label`: `Moody Showcase`
- `plugin_id`: `inline_block:moody_showcase`
- `kind`: repeatable inline block content; bundle `moody_showcase`
- Use for: editorial image-or-video stories paired with substantial copy.
- Inputs per row: image or external video, headline, formatted copy, CTA, and
  toggles for sticky media, full-area media, or pinned image reveal.
- Rules: default imagery is approximately 900x970; marketing treatment uses
  about 1000x666. Pinned reveal is for images, not video. Use sticky or reveal
  effects only when they improve comprehension and preserve a motion-free path.

### Specialty Blocks

Category ID: `specialty_blocks`. It is closed by default in the browser.

#### Chart

- `browser_id`: `chart`
- `browser_label`: `Chart`
- `plugin_id`: `moody_charts_block`
- `kind`: configurable block plugin from `moody_charts`
- Use for: a verified dataset whose comparison is clearer visually than in
  prose alone.
- Inputs: chart type (`bar`, `line`, `pie`, `doughnut`, `radar`, `polarArea`),
  chart title, pasted CSV or uploaded CSV/XLSX, UT color scheme, legend toggle,
  grid-line toggle, and aspect ratio from 1:1 through 3:1.
- Rules: uploaded data replaces pasted CSV. The first row names datasets and
  later rows begin with an axis/category label. Add a text summary or accessible
  data equivalent and never use visual proximity or color as the only meaning.

#### Moody Mini Nav

- `browser_id`: `moody_mini_nav`
- `browser_label`: `Moody Mini Nav`
- `plugin_id`: `moody_mini_nav`
- `kind`: configurable block plugin from `moody_mini_nav`
- Use for: a compact navigation bar linking to page sections or a small set of
  closely related destinations.
- Inputs: approved text color, background color, font size, font weight, mobile
  toggle label, and up to 8 items. Each item has a label and either a URL or an
  anchor targeting a Layout Builder block on the current page.
- Rules: use concise labels, keep destination order aligned with page order,
  and confirm that every anchor target exists and has a stable accessible name.

#### Shorthand Story

- `browser_id`: `shorthand_story`
- `browser_label`: `Shorthand Story`
- `plugin_id`: `moody_shorthand_zip_shorthand_zip_story`
- `kind`: configurable site-specific block plugin from `moody_shorthand_zip`
- Use for: an approved Shorthand export that must be embedded as a complete
  story package.
- Inputs: Shorthand ZIP upload or an existing managed story folder location.
- Rules: this renders imported HTML and assets. Use only reviewed, trusted
  packages; never invent a filesystem path. The entire story needs manual
  accessibility, responsive, privacy, and external-asset review.

#### Flip Image Grid

- `browser_id`: `flip_image_grid`
- `browser_label`: `Flip Image Grid`
- `plugin_id`: `moody_flip_things_flip_image_grid`
- `kind`: configurable block plugin from `moody_flip_things`
- Use for: exactly three image-backed items whose secondary copy benefits from
  an intentional front/back interaction.
- Inputs: overall headline and 3 item slots, each with image, headline,
  formatted body, and link URL.
- Rules: all content and actions must remain reachable and understandable by
  keyboard, touch, screen reader, reduced motion, and without hover. Prefer a
  normal grid if flipping adds no editorial value.

#### Flipbook

- `browser_id`: `flipbook`
- `browser_label`: `Flipbook`
- `plugin_id`: `moody_flipbook_example`
- `kind`: configurable site-specific block plugin from `moody_flipbook`
- Use for: the current automatic flipbook of existing feature pages, only when
  that exact site-level behavior is wanted.
- Inputs: no content-selection settings.
- Rules: the implementation automatically loads up to 10
  `moody_feature_page` nodes and does not provide curator-controlled selection
  or ordering. Do not use it for a manually specified collection. Review all
  content outside the page-turning interaction before use.

#### Faculty Bio

- `browser_id`: `faculty_bio`
- `browser_label`: `Faculty Bio`
- `plugin_id`: `views_block:faculty_bio_view-block_1`
- `kind`: dynamic Views block from view `faculty_bio_view`, display `block_1`
- Use for: the live directory of published faculty biographies.
- Inputs: no authored block content. Visitors receive Search and Department
  Association filters.
- Rules: results come from `moody_faculty_bio` nodes, sort by last name, and
  have no pager. Update faculty content or the View rather than duplicating
  people in a manual component.

#### Faculty Emeritus

- `browser_id`: `faculty_emeritus`
- `browser_label`: `Faculty Emeritus`
- `plugin_id`: `views_block:faculty_bio_view-block_4`
- `kind`: dynamic Views block from view `faculty_bio_view`, display `block_4`
- Use for: the live emeritus-inclusive directory of published faculty
  biographies.
- Inputs: no authored block content. Visitors receive Search and Department
  Association filters.
- Rules: results come from `moody_faculty_bio` nodes, use the display's faculty
  title filters, sort by last name, and have no pager. Do not hand-maintain a
  competing emeritus list on the page.

## Refreshing this guide

Before relying on the catalog after Layout Builder Browser changes, query the
running site again:

```sh
ddev drush php:eval '
$storage = \Drupal::entityTypeManager()->getStorage("layout_builder_browser_block");
$components = array_filter($storage->loadMultiple(), fn ($item) => $item->status());
uasort($components, fn ($a, $b) => [
  $a->get("category"),
  $a->get("weight") ?? 0,
  $a->label(),
] <=> [
  $b->get("category"),
  $b->get("weight") ?? 0,
  $b->label(),
]);
foreach ($components as $component) {
  printf("%s\t%s\t%s\t%s\n",
    $component->get("category"),
    $component->id(),
    $component->label(),
    $component->get("block_id"),
  );
}
'
```

Then inspect each new or changed plugin form and inline block-content field
schema. Update this document in the same change that alters browser
availability. Coverage is complete only when the number and set of documented
`browser_id` values match the enabled runtime config entities.
