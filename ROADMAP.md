# Roadmap

How to read this file: **Now** is what to do before adding more, **Next** is the order of the work worth doing, **Later** is wanted but not urgent, **Not planned** says what was decided against. What has been delivered is in `CHANGELOG.md` (by version) and in the documents under `docs/`; the list under "Where we are" is only the shape of it. Items marked *(proposal)* are a suggested order, not a decision.

## Where we are (0.1.182)

- **Theme**: one all-purpose theme (`themes/default`) that grows with blocks and variants; site changes live in `custom/`. Design system (tokens, palettes generated from two colours, Duo pairings, a secondary colour, contrast feedback, own font families, dark mode, shapes and shadows), header (7 layouts), footer (5) with Footer Blocks above it (a set per language), phone menu and bottom bar, page templates (standard, landing, with sidebar), hero layouts, a title area (with parallax) for singles, archives, terms and search, single and archive layouts per content type, branding screen with export and import, custom CSS in Theme settings. Contract: `docs/theming.md`.
- **Blocks**: 36 block types: openers, text, media (image, gallery, video, YouTube playlist), a hero slider, showcase (cards, portfolio with filters, logos, marquee, team, timeline, features, stats, compare, before/after), conversion (pricing, forms, contact, map, banner, CTA), downloads, checklist, table, quote, divider, tabs, FAQ, accordion. Container rows with columns, blocks side by side, drag and drop and a live preview in the block builder. 18 page layouts and 16 ready-made sections, in Greek and English.
- **Content**: Markdown files plus front matter; a visual editor over Markdown (saved as Markdown, always); RSS feeds; content types with declared fields (posts, projects, books, points of interest, routes, businesses); taxonomies including the site's own, categories in a tree, term pictures and search text, and the content types a taxonomy is filed by; revision history; redirects; slugs. Repeaters in blocks, theme settings and content-type fields, two levels deep (a repeater in a repeater's rows). A WordPress importer (`scripts/import-wordpress.php`, in the repository only) with profiles (`docs/wordpress-import.md`), and a skill for AI assistants (`skills/gnoziocms`).
- **Search**: public search over titles, block text, declared public fields and the titles of related entries; Greek-aware matching (accents, final sigma, other forms of a word), whole words ranked above parts of words, short words optional beside longer ones, excerpts with highlights, type filters and pagination; a disposable cache of extracted and normalized text. A scan over the site's entries, not an index. `docs/search.md`.
- **Places and routes**: route files (GPX, KML, GeoJSON) read for length, climb and a height profile; interactive maps (Leaflet); a Map archive layout and a Map block with filters and a list; a page for each point, route and business with what is near it; the data of the maps kept in a disposable cache that is made again when anything it depends on changes. `docs/places-and-routes.md`.
- **Admin**: dashboard with what needs attention and the site's own analytics; block editor (compact, Content and Design apart); media library with picker, usage tracking and route files; users, custom roles and permissions; forms builder (built once, translated; file uploads) and submissions; SEO, analytics, redirects, translations, backups, updates, logs, storage and upload limits. Accessibility scanned with axe-core (`docs/admin-accessibility.md`).
- **Operations**: PHP 8.5 or newer. Backups with a checksum manifest, verify, staged restore and S3-compatible remote copies, from cron or after a visit; updates from the admin with a verified backup first, a health check of the new version and an automatic undo; the whole suite on PHP 8.5 on every push (GitHub Actions). Sites can report to Omni, the separate monitoring app (`omni.faroscms.com`).
- **Since 0.1.154**: a block settings panel for every block, the template builder (single templates and archive templates built with blocks and bound to an entry's fields), content relationships with the content index and its caches (`docs/varnish.md`, *Caching*), places and routes second phase (filters from taxonomies on maps, galleries, the page of one, pages of terms), the site's own copy of its pages, a remote update from Omni, the fixes of a security review (0.1.178), importer descriptions and floated pictures (0.1.180), menu items limited to languages with an icon, badges and link relations, and the key helper for theme text keys (0.1.182).
- **Name**: FarosCMS became **GnozioCMS** in 0.1.147 (repository `chiotis/gnoziocms`); older notes and `CHANGELOG.md` entries use the old name.
- **Sites**: two client sites run on the CMS, both moved from WordPress: akoumetousneous.gr (live since 2026-10-05) and apostolosdoxiadis.com (live since about 2026-10-15).
- **Architecture**: `src/App.php` went from 9,800 lines to about 3,900 and has grown again to about 4,500 with features; each extracted service has unit tests. `docs/architecture.md`.

## Now: before adding more

During the current development phase (decided 2026-10-16), pushes and releases stay immediate; the full PHP 8.5 suite runs asynchronously in CI. Read the latest completed CI result and investigate failures promptly using its summary and retained logs. Cancelled or unfinished runs are not evidence that a release passed. Installs use short smoke checks and retain the existing backup and rollback paths.

1. **Release and check the search work on a real site** (0.1.149–0.1.154, and the Greek forms of 0.1.181). Try a handful of Greek searches on apostolosdoxiadis.com, some with other forms of a word (a search for «πόλη» should find «πόλεις»), and note how long a search takes. If it is fast there, the scan is good enough for a while; if not, the data and index cache comes forward.
2. **Look at what was only built, not seen**: dark mode and phone width of the visual editor, the block editor and the maps; Safari, Firefox and touch; the map with the list over it in an archive; the YouTube Data API with a real key; real GPX files from a device (a long recording, one with no heights, one in KML from Google Earth). Screen readers by hand are still to do.

The client sites accept for now what the work below improves: the importer now writes pictures that floated beside the text as `{align=left}` or `{align=right}`, but a site imported earlier needs importing again with `--overwrite` to get them. Gaps a site finds go to the requests file in its folder and come here as items, never as code in the site.

## Next

The owner's priorities, in the order set on 2026-10-19 by what builds on what. Done: general and nested repeaters, the block settings panel, the split of `src/App.php`, the template builder for single and archive templates, and content relationships with the data and index cache (0.1.156–0.1.172; see `CHANGELOG.md`).

- **A shop, later**: the catalogue can use repeaters, relationships and the template builder; transactions are a separate future scope. It starts with a short design the owner approves before code is written.

Saved working drafts for published pages are not a current priority.

## Site-specific follow-ups *(proposal)*

1. **Theme options: what is left.** *Slides of one height on phones*. A row of slides already takes the height of the tallest, so what was meant needs to be seen on the site (a fixed ratio for the pictures, or text that is cut, or the dark panel under the picture that grows with the text).
2. **Importer, second version.** Built in 0.1.176 and 0.1.180: redirects written to the site, a bare YouTube link as a Video block, floated pictures, alternative texts from the picture's title or caption (`docs/wordpress-import.md`). **Left:** the profile fills the declared fields of a type (author, publisher, ISBN, buy links of the Books type, as a repeater) instead of writing them into the text; a category can be sent to a taxonomy of its own; pictures on other servers can be fetched into the library (`--fetch-external`); a check that reads the old site's sitemap and asks the new site for each address (301 then 200). Optional: a WXR reader, and *Works* as a ready-made type of the catalogue.
3. **Places and routes, second phase**: built in 0.1.173–0.1.176 (plan: `docs/places-and-routes-v2-plan.md`, from the Rural Greece model in `docs/ruralgreece-content-model.md`). **Left:** the first dry run on a copy of the real site. A point that belongs to a route stays by coordinates; explicit relationships are left out for now.
4. **Search, next steps**: Greek inflections are done (0.1.181, a light stemmer: «πόλη» also finds «πόλεις», `docs/search.md`). **Left:** typo tolerance, area/category filters for places, and an index only if measured site sizes justify it.

## Later

- **Forms**: showing a field only when another has an answer (conditional logic), forms in several steps, and a rating field. The builder, the site's form template and `FormProcessor` would all need to know about a condition, so it is a feature of its own. Submissions: archive actions. Optional: frontend form theme variants (compact/stacked) without changing the form schema.
- **Import UX**: dry-run preview, row-level undo, resumable imports.
- **Theme**: a switcher in settings with a preview mode; draft preview links; admin-level user management with limits; updates for private repositories. Style references from the owner can refine the design system at any point (tokens and block CSS).
- **Menus**: per-item visibility by role (by language is done: `languages` on an item, `docs/theming.md`, *Menu items*).
- **Backups**: a one-click restore-to-staging drill with a periodic restore report; Google Drive as a remote target.
- **Settings > APIs**: credentials for services the site may use for generated excerpts and content (nothing uses them yet); other map providers' presets.

## Caching

**Closed in 0.1.179.** Runtime cache only (no static export pipeline), filesystem backend by default (portable, no extra services).

- Layer 1: parsed-content cache (front matter and rendered Markdown). Done (0.1.115, `storage/cache/content`); search keeps its own extracted-text cache (`storage/cache/search`).
- Layer 2: data and index cache. Done for reverse relationships (0.1.167), for archives, taxonomy pages and the Latest content block (lists are answered by the content index, `ArchiveQuery`, `docs/theming.md`, reading only the entries shown) and for the maps: the facts and short lines of each route file (`*.sum.json`), and the marker data of a map, the map of a page of one and the entries near one, in `storage/cache/geo-data`, per visitor or editor, under a token the content index keeps and checked against the route files' time and size. A page with 150 routes and 550 points went from about 3 s to under 0.1 s, with identical output (`docs/places-and-routes.md`).
- Layer 3: full-page cache. Public pages carry `s-maxage` for a cache in front of the site (Varnish on CloudPanel, or any Varnish at an address set in System), and an admin change empties it (0.1.116). For a host with no cache in front the site keeps its own copy of the pages a visitor sees without a session (`PageCache`, `storage/cache/pages`, System > Page cache, off until turned on): a GET with no query, a 200 page, sitemap or feed, no cookie, nothing that said it must not be kept. `docs/varnish.md`.
- Keys by site address, scheme, path (the language is in it) and the version of the code; a copy is emptied by the same call that empties the cache in front, on every change saved in the admin, with a generation number so a visit that overlaps a save cannot put the old page back; a copy is not used for more than 10 minutes, for what changes outside the admin.
- Invalidation is the whole site, not page by page (see *Not planned*).

## Not planned

- A static export pipeline.
- Editing route files in the admin: a file is uploaded and chosen (`docs/places-and-routes.md`).
- Invalidating the cache page by page. A change empties every copy: that is always right and costs one render of each page. Emptying only the pages that show a changed entry needs a map of what each page depends on (lists, relations, maps, menus, widgets, the home page, translations); a mistake in it shows a visitor an old page, and a site of this size does not need it.
