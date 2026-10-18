# Roadmap

How to read this file: **Now** is what to do before adding more, **Next** is the order of the work worth doing, **Later** is wanted but not urgent, **Not planned** says what was decided against. What has been delivered is in `CHANGELOG.md` (by version) and in the documents under `docs/`; the list under "Where we are" is only the shape of it. Items marked *(proposal)* are a suggested order, not a decision.

## Where we are (0.1.154)

- **Theme**: one all-purpose theme (`themes/default`) that grows with blocks and variants; site changes live in `custom/`. Design system (tokens, palettes generated from two colours, Duo pairings, a secondary colour, contrast feedback, own font families, dark mode, shapes and shadows), header (7 layouts), footer (5) with Footer Blocks above it (a set per language), phone menu and bottom bar, page templates (standard, landing, with sidebar), hero layouts, a title area (with parallax) for singles, archives, terms and search, single and archive layouts per content type, branding screen with export and import, custom CSS in Theme settings. Contract: `docs/theming.md`.
- **Blocks**: 36 block types: openers, text, media (image, gallery, video, YouTube playlist), a hero slider, showcase (cards, portfolio with filters, logos, marquee, team, timeline, features, stats, compare, before/after), conversion (pricing, forms, contact, map, banner, CTA), downloads, checklist, table, quote, divider, tabs, FAQ, accordion. Container rows with columns, blocks side by side, drag and drop and a live preview in the block builder. 18 page layouts and 16 ready-made sections, in Greek and English.
- **Content**: Markdown files plus front matter; a visual editor over Markdown (saved as Markdown, always); RSS feeds; content types with declared fields (posts, projects, books, points of interest, routes, businesses); taxonomies including the site's own, categories in a tree; revision history; redirects; slugs. Repeaters in blocks, theme settings and content-type fields, two levels deep (a repeater in a repeater's rows). A WordPress importer (`scripts/import-wordpress.php`, in the repository only) with profiles (`docs/wordpress-import.md`), and a skill for AI assistants (`skills/gnoziocms`).
- **Search**: public search over titles, block text and declared public fields; Greek-aware matching (accents, final sigma), whole words ranked above parts of words, short words optional beside longer ones, excerpts with highlights, type filters and pagination; a disposable cache of extracted and normalized text. A scan over the site's entries, not an index. `docs/search.md`.
- **Places and routes**: route files (GPX, KML, GeoJSON) read for length, climb and a height profile; interactive maps (Leaflet); a Map archive layout and a Map block with filters and a list; a page for each point, route and business with what is near it. `docs/places-and-routes.md`.
- **Admin**: dashboard with what needs attention and the site's own analytics; block editor (compact, Content and Design apart); media library with picker, usage tracking and route files; users, custom roles and permissions; forms builder (built once, translated; file uploads) and submissions; SEO, analytics, redirects, translations, backups, updates, logs, storage and upload limits. Accessibility scanned with axe-core (`docs/admin-accessibility.md`).
- **Operations**: PHP 8.5 or newer. Backups with a checksum manifest, verify, staged restore and S3-compatible remote copies, from cron or after a visit; updates from the admin with a verified backup first, a health check of the new version and an automatic undo; the whole suite on PHP 8.5 on every push (GitHub Actions). Sites can report to Omni, the separate monitoring app (`omni.faroscms.com`).
- **Name**: FarosCMS became **GnozioCMS** in 0.1.147 (repository `chiotis/gnoziocms`); older notes and `CHANGELOG.md` entries use the old name.
- **Sites**: two client sites run on the CMS, both moved from WordPress: akoumetousneous.gr (live since 2026-10-05) and apostolosdoxiadis.com (live since about 2026-10-15).
- **Architecture**: `src/App.php` went from 9,800 lines to about 3,900 and has grown again to about 4,400 with features; each extracted service has unit tests. `docs/architecture.md`.

## Now: before adding more

During the current development phase (decided 2026-10-16), pushes and releases stay immediate; the full PHP 8.5 suite runs asynchronously in CI. Read the latest completed CI result and investigate failures promptly using its summary and retained logs. Cancelled or unfinished runs are not evidence that a release passed. Installs use short smoke checks and retain the existing backup and rollback paths.

1. **Release and check the search work on a real site** (0.1.149–0.1.154). Try a handful of Greek searches on apostolosdoxiadis.com and note how long a search takes. If it is fast there, the scan is good enough for a while; if not, the data and index cache (Next, step 2) comes forward.
2. **Look at what was only built, not seen**: dark mode and phone width of the visual editor, the block editor and the maps; Safari, Firefox and touch; the map with the list over it in an archive; the YouTube Data API with a real key; real GPX files from a device (a long recording, one with no heights, one in KML from Google Earth). Screen readers by hand are still to do.

The client sites accept for now what the work below improves: a book can have several buy links (a list; books saved with one link keep it) and slides keep up to three buttons as flat fields, until repeaters can nest; pictures that floated beside the text sit in the flow until the importer writes them as `{align=left}`. Gaps a site finds go to the requests file in its folder and come here as items, never as code in the site.

## Next: the owner's priorities (2026-10-18)

The order was agreed on 2026-10-18. Relationships and repeaters share the existing field schema (`FieldSchema`), so the schema is extended once and both build on it. Each step starts with a short design the owner approves before code is written.

0. **A small split of `src/App.php` first** *(proposal)*. All three steps below touch it; moving the Twig functions and the rendering into a service of their own, behaviour identical and checked, lowers the conflicts when work runs in parallel sessions.
1. **General repeaters**: the foundation is done (two levels in `FieldSchema`, content-type fields, both editors, storage, CSV, search, the default fact sheet; see *Repeaters* in `docs/theming.md`). Left for the second delivery: the hero slider's flat button fields (`link_label`, `link_label_2`, `link_label_3`) migrated to a list of buttons, and the Books buy links as a repeater.
2. **Content relationships**: a field that points at other entries (a book's author, a route's points, related posts), declared in the same schema and usable inside a repeater (related books, each with a note). Needs: a picker in the editor, stored references by type and slug that survive a slug change (with the existing redirects), what happens when the target is deleted or unpublished, the reverse question ("all books of this author") for templates, and output in single and archive templates. The reverse lookups need the **data and index cache** (layer 2 under Caching), designed together with this step rather than later; the same cache serves the maps' "what is near what" and search on larger sites.
3. **Block settings in a fixed off-canvas panel** for every block: one place for a block's Content and Design settings instead of settings that open inside the block list. Mostly the block editor's JS, CSS and admin templates, so it can run in parallel with step 2. It comes before the template builder, which reuses the panel. Nested repeaters from step 1 get their editing UI inside this panel.
4. **A block builder for single and archive templates**, after the panel: needs dynamic field bindings (a block shows a field of the current entry) and an archive/loop context.
5. **A shop, later**: the catalogue can use repeaters, relationships and the template builder; transactions are a separate future scope.

Saved working drafts for published pages are not a current priority.

## Site-specific follow-ups *(proposal)*

1. **Theme options: what is left.** *Slides of one height on phones*. A row of slides already takes the height of the tallest, so what was meant needs to be seen on the site (a fixed ratio for the pictures, or text that is cut, or the dark panel under the picture that grows with the text).
2. **Importer, second version.** The profile fills the declared fields of a type (author, publisher, ISBN, buy links of the Books type, as a repeater once step 1 exists) instead of writing them into the text; a category can be sent to a taxonomy of its own; redirects can be written to the site (`--redirects=apply`) instead of only to a file; a bare YouTube link on a line becomes a Video block, a picture that floated becomes `{align=left}` or `{align=right}`; pictures on other servers can be fetched into the library (`--fetch-external`), and a missing alternative text falls back to the picture's title or caption; a check that reads the old site's sitemap and asks the new site for each address (301 then 200). Optional: a WXR reader, and *Works* as a ready-made type of the catalogue.
3. **Places and routes, second phase**: a gallery for an entry and a strip of it under the title; a point that belongs to a route (a relationship from step 2, winning over distance); filters of the type (activity, difficulty, price range) inside the map's bar, with counts; the height profile linked to the map; custom single templates documented.
4. **Search, next steps**: Greek inflections (a light stemmer, so «πόλη» also finds «πόλεις»), typo tolerance, area/category filters for places, and an index only if measured site sizes justify it.

## Later

- **Forms**: showing a field only when another has an answer (conditional logic), forms in several steps, and a rating field. The builder, the site's form template and `FormProcessor` would all need to know about a condition, so it is a feature of its own. Submissions: archive actions. Optional: frontend form theme variants (compact/stacked) without changing the form schema.
- **Import UX**: dry-run preview, row-level undo, resumable imports.
- **Theme**: a switcher in settings with a preview mode; draft preview links; admin-level user management with limits; updates for private repositories. Style references from the owner can refine the design system at any point (tokens and block CSS).
- **Taxonomies**: term images and meta, per-content-type assignment rules.
- **Menus**: per-item visibility (by language, and by role in future); item metadata (icon, badge, `rel`); a key helper for the `nav.<menu>.<item>` naming.
- **Backups**: a one-click restore-to-staging drill with a periodic restore report; Google Drive as a remote target.
- **Settings > APIs**: credentials for services the site may use for generated excerpts and content (nothing uses them yet); other map providers' presets.

## Caching

Runtime cache only (no static export pipeline), filesystem backend by default (portable, no extra services).

- Layer 1: parsed-content cache (front matter and rendered Markdown). **Done** (0.1.115, `storage/cache/content`); search keeps its own extracted-text cache (`storage/cache/search`).
- Layer 2: data and index cache (archives, taxonomies, map marker datasets, what is near what, reverse relationships). **Next**, with content relationships.
- Layer 3: full-page HTML cache by route and language (and query variants where needed). Partly in place: public pages carry `s-maxage` for a cache in front of the site (Varnish on CloudPanel), and an admin change purges it (0.1.116).
- Deterministic keys including route, language, content type and theme
- Targeted invalidation on save, delete and import: the item page, related archives and taxonomies, the home page and widgets, the sitemap and feed, and linked translations (`translation_id`); a route file replaced in Media invalidates the routes that use it and the maps that show them
- A TTL as a safety net; invalidation stays the main way to stay fresh

## Not planned

- A static export pipeline.
- Editing route files in the admin: a file is uploaded and chosen (`docs/places-and-routes.md`).
