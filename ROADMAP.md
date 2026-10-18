# Roadmap

How to read this file: **Now** is what to do before adding more, **Next** is the order of the work worth doing, **Later** is wanted but not urgent, **Not planned** says what was decided against. What has been delivered is in `CHANGELOG.md` (by version) and in the documents under `docs/`; the list under "Where we are" is only the shape of it. Items marked *(proposal)* are a suggested order, not a decision.

## Where we are (0.1.95)

- **Theme**: one all-purpose theme (`themes/default`) that grows with blocks and variants; site changes live in `custom/`. Design system (tokens, palettes, dark mode, Inter, shapes), header (7 layouts), footer (5) with Footer Blocks above it (a set per language), phone menu and bottom bar, page templates (standard, landing, with sidebar), hero layouts, a title area (with parallax) for singles, archives and search, single and archive layouts per content type, branding screen. Contract: `docs/theming.md`.
- **Blocks**: 36 block types: openers, text, media (image, gallery, video, YouTube playlist), a hero slider, showcase (cards, portfolio with filters, logos, marquee, team, timeline, features, stats, compare, before/after), conversion (pricing with a monthly/yearly switch, forms, contact, map, banner, CTA), downloads, checklist, table, quote, divider, tabs, FAQ, accordion. 18 page layouts and 16 ready-made sections, in Greek and English.
- **Content**: Markdown files plus front matter; a visual editor over Markdown (saved as Markdown, always), with pictures beside the text (`{align=left}`); an RSS feed of the newest posts, of a type and of a term; the pages of a list as WordPress wrote them (`/page/2`) sent to `?page=2`; content types with declared fields (posts, projects, books, points of interest, routes, businesses); taxonomies including the site's own; revision history; redirects; slugs. A WordPress importer (`scripts/import-wordpress.php`, in the repository only) with profiles for what the REST API does not give (`docs/wordpress-import.md`), and a skill for AI assistants (`skills/gnoziocms`).
- **Places and routes**: route files (GPX, KML, GeoJSON) read for length, climb and a height profile; interactive maps (Leaflet, no third party but the tiles); a Map archive layout and a Map block that show content with filters and a list, also with the list over a full-width map; a page for each point, route and business with what is near it. `docs/places-and-routes.md`.
- **Admin**: dashboard with what needs attention and the last 90 days of the site's own analytics; block editor (compact, Content and Design apart); media library with picker, usage tracking and route files; users, custom roles and permissions; forms builder and submissions (filters, paging, bulk delete); SEO, analytics, redirects, translations, backups, updates, logs, storage and upload limits. Accessibility scanned with axe-core in light, dark and phone width (`docs/admin-accessibility.md`).
- **Operations**: backups with a checksum manifest, verify, staged restore and S3-compatible remote copies, scheduled from a cron script (`src/cron-backup.php`, shown in Admin > System) or after a visit; updates installed from the admin with a verified backup first, a check of the new version and an automatic undo; the whole suite on PHP 8.5 on every push (GitHub Actions).
- **Sites**: the first client site runs on FarosCMS (akoumetousneous.gr, live since 2026-10-05, moved from WordPress). The second, apostolosdoxiadis.com, is being moved (below).
- **Architecture**: `src/App.php` went from 9,800 lines to about 3,900 (it grew again with features); each extracted service has unit tests. `docs/architecture.md`.

## Now: the second client site (apostolosdoxiadis.com)

The site is moved with the CMS **as it is** (decided 2026-10-12): a clean install from the release zip in its own folder, the WordPress importer run from the repository with the `themelio.yaml` profile, the look from settings plus `custom/assets/css/custom.css`. The site's brief, decisions and rules are in the `CLAUDE.md` of its folder, not here. What it accepts for now, and what the CMS work below would later improve:

- categories keep their tree (since 0.1.118: `/category/books/english`, the importer keeps WordPress's parent); the importer is run again on this site;
- a book has one buy button (the other shops go in its Links tab); slides keep up to three buttons as flat fields;
- the red headings and the cream gradient of the old header are now settings (Branding: *Heading colour*; Header: *Background* with a fade), so the site's `custom.css` can drop them; pictures that floated beside the text sit in the flow until the importer writes them as `{align=left}`;
- the WPForms contact form is remade from the Contact template.

Gaps the site finds go to `faroscms-requests.md` in its folder and come here as items, never as code in the site.

## Now: before adding more

Several releases were merged with only the tests of the change run, and CI was red on ten of them without anyone reading it. The suite and CI were read and mended in 0.1.62 (see `CHANGELOG.md`), and the visual editor's serializer has its own test. During the current development phase (decided 2026-10-16), pushes and releases stay immediate; the full PHP 8.5 suite runs asynchronously. Read the latest completed CI result and investigate failures promptly using its summary and retained logs. Cancelled or unfinished runs are not evidence that a release passed. Installs use short smoke checks and retain the existing backup and rollback paths.

1. **Look at what was only built, not seen**: dark mode and phone width of the visual editor, the block editor and the maps; Safari, Firefox and touch; the map with the list over it in an archive; the YouTube Data API with a real key; real GPX files from a device (a long recording, one with no heights, one in KML from Google Earth). Screen readers by hand are still to do.

## Current product priorities (2026-10-16)

Public search was chosen as the first delivery: 0.1.146 adds block/custom-field text, Greek-aware matching, relevance, excerpts, type filters and pagination (`docs/search.md`). Suggestions while typing are not requested. Follow-up search work can add area/category filters and an index if measured site sizes justify it.

The owner's next priorities are content relationships, general repeaters (including content-type fields and nesting), a block builder for single and archive templates, unified block settings in an off-canvas panel, and a shop later. Relationships and repeaters should share the existing field schema. The common settings panel should be available before the template builder, which also needs dynamic field bindings and archive/loop context. The shop's catalogue can use these foundations; transactions are a separate future scope. Saved working drafts for published pages are not a current priority.

## Site-specific follow-ups *(proposal)*

1. **Nested repeaters** (from *Later*). A repeater inside a repeater item: several buy links for a book, several buttons for each slide. Today `FieldSchema` keeps repeater items flat and drops a repeater field inside one, so the slider's buttons are flat fields (`link_label`, `link_label_2`, `link_label_3`). Needs: the schema and its checks, the editor (add, remove and reorder inner rows), storage, the CSV and revision handling, and the block templates.
2. **Theme options a migration asked for: what is left.** Done in 0.1.95: headings in the palette colour (or colours of one's own), a fading header, a title area for each term, visible breadcrumbs, a picture beside the text. Left: *slides of one height on phones*. A row of slides already takes the height of the tallest, so what was meant needs to be seen on the second site (a fixed ratio for the pictures, or text that is cut, or the dark panel under the picture that grows with the text).
3. **Importer, second version.** The profile fills the declared fields of a type (author, publisher, ISBN, buy links of the Books type) instead of writing them into the text; a category can be sent to a taxonomy of its own (as with *Διακριθέντες*); redirects are worked out after that mapping and can be written to the site (`--redirects=apply`) instead of only to a file; a bare YouTube link on a line becomes a Video block, a picture that floated becomes `{align=left}` or `{align=right}`; pictures on other servers can be fetched into the library (`--fetch-external`), and a missing alternative text falls back to the picture's title or caption; a check that reads the old site's sitemap and asks the new site for each address (301 then 200). Optional: a reader for a WXR export (more reliable for custom post types and menus when there is an admin sign-in), and *Works* (plays, productions) as a ready-made type of the catalogue.
4. **Places and routes, second phase.** What the first version left out, in the order it matters for the sites it was made for:
   - a gallery for an entry (a list of pictures, with a Library that adds several at once) and a strip of it under the title, as on the reference business page;
   - a way to say that a point belongs to a route (a list of related entries that wins over distance);
   - filters of the type (activity, difficulty, price range) inside the map's own bar, instantly, with counts;
   - the profile of the height linked to the map (a mark on the line under the pointer), and the places a route file marks as entries of their own on request;
   - custom single templates for a site (the three templates are three lines each; document how to start from them).
5. **Runtime cache** (see Caching below). The map pages compute what is near what on every visit and read the route cache of every route; with hundreds of places that becomes the slowest page of the site. The marker datasets are named in layer 2 of the plan, so build the layers with that in mind.
6. **Search for places**: area/category filters beyond the general search delivered in 0.1.146.

## Later

- **Forms**: showing a field only when another has an answer (conditional logic), forms in several steps, and a rating field. The builder, the site's form template and `FormProcessor` would all need to know about a condition, so it is a feature of its own. Submissions: archive actions. Optional: frontend form theme variants (compact/stacked) without changing the form schema.
- **Import UX**: dry-run preview, row-level undo, resumable imports.
- **Theme**: a switcher in settings with a preview mode; draft preview links; admin-level user management with limits; updates for private repositories. Style references from the owner can refine the design system at any point (tokens and block CSS).
- **Taxonomies**: term images and meta, per-content-type assignment rules.
- **Menus**: per-item visibility (by language, and by role in future); item metadata (icon, badge, `rel`); a key helper for the `nav.<menu>.<item>` naming.
- **Backups**: a one-click restore-to-staging drill with a periodic restore report; Google Drive as a remote target.
- **Settings > APIs**: credentials for services the site may use for generated excerpts and content (nothing uses them yet); other map providers' presets.
- **Architecture**: keep splitting `src/App.php` (wiring, permission checks, rendering and the Twig functions are what is left), behaviour identical, with a regression check for each step.

## Caching

Runtime cache only (no static export pipeline), filesystem backend by default (portable, no extra services).

- Layer 1: parsed-content cache (front matter and rendered Markdown)
- Layer 2: data and index cache (archives, taxonomies, map marker datasets, what is near what)
- Layer 3: full-page HTML cache by route and language (and query variants where needed)
- Deterministic keys including route, language, content type and theme
- Targeted invalidation on save, delete and import: the item page, related archives and taxonomies, the home page and widgets, the sitemap and feed, and linked translations (`translation_id`); a route file replaced in Media invalidates the routes that use it and the maps that show them
- A TTL as a safety net; invalidation stays the main way to stay fresh

## Not planned

- A static export pipeline.
- Editing route files in the admin: a file is uploaded and chosen (`docs/places-and-routes.md`).
