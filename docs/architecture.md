# GnozioCMS Code Map

GnozioCMS is a single-entry PHP application (`public/index.php` → `GnozioCMS\App::handle()`), with Markdown/YAML content files, Twig templates, and a SQLite system database.

## Request flow

1. `App::handle()` configures the session, sends security headers, and routes `/admin/*` to `handleAdmin()` and everything else to `handleFront()`.
2. `handleAdmin()` checks the CSRF token on every POST, handles sign-in/out, re-reads the signed-in user, checks the route capability (`PermissionService::canAccessAction()`), runs a due scheduled backup, and dispatches to a `handle*()` method.
3. Handlers render Twig templates from `admin/templates/` (namespace `@admin`) or the frontend theme: `custom/` first, then `themes/default/` (see `theming.md`).

Before the application boots, `public/index.php` hands `/_themes/…` and `/_custom/…` requests to `ThemeAssets` and missing `/uploads/_v/…` image variants to `Images`.

## Classes in `src/`

| Class | Responsibility |
|-------|----------------|
| `App` | Routing, request handlers, and the wiring of the classes below. Still the largest file (about 3,500 lines); new behaviour should go into a focused class, as content saving did (`ContentEditor`). |
| `ContentRepository` / `ContentItem` | Reads Markdown files with YAML front matter; listing, lookup and visibility, backed by the parsed-content cache. |
| `SearchDocument` / `ContentSearch` / `SearchText` | Public search: schema-based text extraction and caching, Greek-aware matching, relevance, filters, pagination and escaped display parts. `docs/search.md`. |
| `ContentIndex` | SQLite `content_index` kept in sync with the files; admin search and staleness checks. |
| `Auth` | Session sign-in (SQLite users, YAML fallback), session rotation, per-request user refresh, shipped-password detection. |
| `UserRepository` | SQLite users: CRUD, YAML import, superadmin guarantee. |
| `PermissionService` | Role capabilities (`superadmin`, `admin`, `editor`, `user`), the capability catalogue, the super admin's per-role changes (`system_meta.role_permissions`) and roles of the site's own (`system_meta.custom_roles`), and route-to-capability mapping; unmapped routes are administrator-only. |
| `ContentEditor` | Saving a content item from the editor form: address (from the title, made unique, kept or changed), front matter from the submitted fields, raw HTML guard, writing the file, moving translations, redirects. Takes the submitted fields and returns what happened; knows nothing about requests, sessions, or menus. |
| `PageTwigFunctions`, `GeoTwigFunctions` | The template functions that read the site's choices as they are when called: analytics, SEO and branding for a page's head and tail, and the maps of content. |
| `BlockRuntime` | What the blocks of a page need from the site: the block registry and the choices it offers, the renderer for a page's blocks (entries, forms, playlists, maps), the maps of content (`GeoView`) and the YouTube services (`YouTubePlaylist`, `YouTubeThumbs`), each built when first asked for. |
| `LoginController` | The addresses of the admin that need no sign-in: the login screen and a password sign-in, signing in with Google, signing out, and the first administrator of a site with no account. It builds `SignIn`, `GoogleSignIn` and `FirstAdmin`, and draws and redirects only through closures. |
| `HtmlGuard` | Neutralises raw HTML for people without `content.raw_html`, leaving HTML already stored in a file alone. |
| `FormFields` | Form field types (including the two that only show something), widths, the choices of a field, and cleaning of what the form builder sends (no two fields share a name). |
| `FormTranslations`, `FormTranslationMigration` | A form is built once in the first language and every other language is a set of translations stored with it (`translations:` on each field and on the form): cleaning what the builder sends, laying a language over a form (with the fallback to the first language), counting what is missing, finding a form by an old address, and the one-time folding of older per-language copies into the form (`docs/forms.md`). |
| `ContentPaths` | File names and public paths of content from the language and home page settings. |
| `FrontMatter` | Splits a content file into its YAML and body. |
| `RevisionRepository`, `LineDiff` | The history of content files in `content_revisions` (capture, baseline, rename, prune, deleted items) and the line comparison shown in Admin > History. `ContentEditor` records a version around every save and can restore one. |
| `Slug` | Turns text into a web address: Greek to Latin (ELOT 743 rules), accents dropped, length cap, reserved root words. `admin/templates/edit.twig` has a JavaScript copy for the live preview only; the server decides. |
| `RedirectRepository` | `redirects` (old path to new path or full address, 301/302, origin, hits) and `not_found_log`; path normalising, validation (no loops, no `javascript:`), following redirects that lead to redirects. Consulted only when a request matches nothing. |
| `LoginThrottle` | Failed sign-in counting and blocking (`login_attempts`). |
| `SystemDatabase` | SQLite connection, migrations, and close/reopen for restores. |
| `SystemMetaRepository` | `system_meta` key/value storage (settings YAML, cached status JSON). |
| `ActivityLogRepository`, `EmailLogRepository`, `NotificationRepository`, `BackupRunRepository` | Log, notification, and backup-history tables. |
| `BackupService` | Archive creation with checksum manifest, listing, retention, verification, and staged restore. |
| `S3BackupStorage` | S3-compatible upload, listing, and pruning with SigV4 signing. |
| `UpdateService` | Local version, GitHub `VERSION`/`CHANGELOG` source, cached update status. |
| `FormUploads` | The files attached to a form: what a file field allows (kinds, sizes, numbers, capped by the server and the site), the check of the real content with finfo, the refused kinds, the file name a pattern makes, and the private folder `storage/form-uploads/<form>/<submission>/` (saving, finding a file safely, the ZIP, removing). |
| `FormSubmissionRepository` | JSON form submissions per form slug: listing, filtering, storing, deleting. |
| `Mailer` | SMTP and Amazon SES sending. |
| `MediaLibrary` | Media uploads (type allowlist, SVG checks), YAML metadata, tags, listing, legacy adoption. |
| `Format` | Shared small helpers: byte sizes, truthy values, lists from front matter. |
| `ArrayPath` | Reading, writing, and removing a value deep inside nested arrays by its path. |
| `Menus` | The menu files in `content/menus`: reading and writing, checking items (three levels, labels per language, hidden items), nesting the rows the editor sends, which menu sits in which theme location (`locations()`), translated labels, the active item and trail for the page shown, and following an address change. |
| `HtmlToMarkdown` | The HTML of another site as Markdown: structure kept, styles and Word leftovers dropped, layout tables taken apart, known video embeds kept, and a list of what could not be carried over. |
| `WordPressReader`, `WordPressHttp` | The public REST API of a WordPress site (pages, posts, media, categories, tags, sitemap addresses) and the web calls it makes (http/https only, size limits). |
| `WordPressScraper` | What the API does not give, from the pages themselves with the XPath of a profile: the pages of a custom post type (found in the sitemap), the home page slides, and a menu. |
| `WordPressImporter` | Plan, then apply: pages and posts as Markdown, flat terms, media into the library under ids made from their address, links rewritten, and the list of old addresses to redirect (`docs/wordpress-import.md`). |
| `ContentCsv` | The CSV export of a content type and the two-step import (preview, then apply with backups and rollback). |
| `SiteSettings` | The site settings document: defaults, load (with the old YAML read once), the values the settings form shows, saving a submitted form, and the secrets rule. |
| `BackupManager` | Taking backups on top of `BackupService`: the schedule and its lock, full and database snapshots with retention and remote upload, the run history, the notification, and the words for a result. |
| `TaxonomyEditor` | What the Taxonomies screen does with a submitted form: reads the rows, saves terms and layout, leaves redirects and updates menu links for a moved address, and counts the entries filed under each term. |
| `RedirectAdmin` | What the Redirects screen does: checks and saves a redirect, switches, deletes, imports a pasted list, lists the addresses visitors missed with suggestions, describes each redirect for the list, and works out which links in content can be pointed at a redirect's target. |
| `PublicPaths` | Which public addresses lead to a page, the list of published addresses, the closest address to a missed one, and a pasted address of the site turned into a path. |
| `ThemeStrings` | The theme's words as the Translations screen edits them: what a language shows, and saving overrides to `custom/lang/<lang>.yaml` (kept across updates). |
| `EntryTranslations` | The translations of an entry as the admin lists them: the languages each entry exists in, and a row per language with a link to edit or start it. |
| `LanguageAlternates` | The other languages of a public page: the language switcher's links and the `hreflang` addresses of an entry, a content type's list, and a category or tag page. |
| `MediaAdmin` | What the Media screen does on top of `MediaLibrary`: the list asked for and kept across actions, uploads within the storage and size limits, tags, deleting (files in use are protected), bulk actions, the list with where each file is used, and the page of pictures for the image picker. |
| `SiteLimits` | What the site uses against the storage limit (measured, kept for twelve hours, adjusted by uploads and deletions), whether a file fits, the size of one upload, and the readers of the limits on the settings form. |
| `SystemStatus` | The health checks of the dashboard and the System screen, their one-line verdict, the PHP extensions, and the environment table. |
| `DashboardData` | What the dashboard shows each role. |
| `YouTubePlaylist`, `YouTubeThumbs` | The videos of a YouTube playlist for the Playlist block (the public feed, or the Data API with a key), kept for a number of hours, and their pictures kept on this site. See `docs/youtube-playlist.md`. |
| `VisualMarkdown` | The Markdown of the content editor as the visual editor draws it: block by block with the lines each came from, raw HTML as a box that is only shown. See `docs/editor.md`. |
| `DashboardAttention` | Turns the facts the dashboard gathers into the short list of things that need attention (update, storage, system checks, backups, mail, SEO, links, drafts, languages, analytics), most serious first. |
| `FormProcessor` | What happens to a form someone fills in on the site: the starting values, checking what was sent against the fields, the record kept, and the emails it causes (notification and automatic reply). Sending and storing are the caller's. |
| `FormsAdmin` | The Forms screens: the list with submission counts, one form's submissions filtered and paged, deleting submissions, and the CSV export. |
| `SignIn` | Password sign-in: a block after repeated failures, the log, and a flag when the password is the one shipped with the CMS. |
| `GoogleSignIn` | Signing in with Google: its settings, the address to send the person to, the two calls for a profile, and whether a profile may sign in. The calls can be replaced in tests. |
| `UserAdmin` | Adding and changing a user: reading the form within what the person may change, the password checks, saving. |
| `RoleAdmin` | The Roles screen: the permission table, custom roles (make, rename, delete), and the log of permission changes. |
| `ContentTypeAdmin` | The Content types screen: the catalogue of types the theme ships and switching each on or off, making a type, saving a definition (only differences from the theme are written), and the rows it shows. |
| `ArchiveBuilder` | The archive of any list of entries: the filters it offers (only real values), the order, and the page. |
| `BackupAdmin` | The Backups screens: verify, create, delete, restore (a safety snapshot first, only the areas chosen), the backup before an update, and the data of the screens. |
| `ContentAdmin` | The content list (search, status and term filters), the bulk actions (publish, move to draft, delete) and deleting one entry (a public one first asks where visitors should go instead). |
| `EntryForm` | What the editor screen shows for one entry: fields from the front matter, the blocks, how it opens, translations, address and old addresses, the links that still use an address that just changed, the latest versions, and a form's fields and submissions. Only reads; saving is `ContentEditor`. |
| `RevisionAdmin` | The History screens (recent changes, one entry's versions, one version compared) and bringing back a version or a deleted entry. |
| `ContentTransfer` | The CSV file of a content type and the two-step import (preview kept in the session for an hour, then applied). |
| `MenuAdmin`, `MenuSources`, `LogAdmin`, `UpdateAdmin`, `AdminNotices` | The Menus screens (the editor is drawn by `public/assets/js/admin-menus.js` from one JSON block and saved as one field, `menu_json`; `MenuSources` lists what a menu can link to, with titles in each language); the activity and email logs; the Updates screen with its checks and the backup before updating; and the notices the site raises by itself (a new version, a system check that needs attention). |
| `Sitemap`, `TaxonomyPage`, `PublicForms`, `TwigFunctions` | The public side that is not an entry's own page: the sitemap, a category or tag page, forms (being sent, shown, placed by the `[form]` shortcode), and the template functions that need nothing from the request. |
| `SettingsAdmin`, `AdminChrome`, `FrontRoute` | The Settings screen (saving the form, the limit changes in the log, the test email, the remote backup test, a backup now); what every admin screen gets besides its own data (notifications, version, storage warning); and which kind of public page an address asks for. |
| `UpdateInstaller`, `UpdateNetwork`, `MaintenanceMode` | Installing a release package (checked against its SHA-256, only code paths, swapped in with a way back, the new version asked whether it starts), the download and the check of the site, and the "back in a moment" page. `UpdateService` reads the release manifest. See [update-workflow.md](update-workflow.md). |
| `FirstAdmin` | A site with no accounts asks for its first administrator on the sign-in page. |
| `StructuredData` | The JSON-LD graph of a page (organization, website and search, article or page, breadcrumb) and its script tag. |
| `RobotsTxt` | The rules typed in Admin > SEO > Crawling, checked, and the robots.txt they make (closed for a site that asked to stay out of search, with the AI crawlers named when they are blocked). |
| `AnalyticsSettings`, `AnalyticsCollector`, `AnalyticsStore`, `AnalyticsReport`, `AnalyticsAdmin` | Admin > Analytics. The choice (none, the owner's code, the platform's) and the code it puts in the pages; what a reported visit is made into or refused as (robots, Do Not Track, signed-in people, a visitor that is a daily hash); the SQLite store that keeps two days of rows and sums finished days; the data of the reports (periods, totals and their change, the chart, the lists, CSV); the screen. See `docs/analytics.md`. |
| `SeoSettings`, `SeoAdmin`, `SeoAudit` | Admin > SEO. `SeoSettings` reads the site-wide choices under `seo` in the site settings (each with a default, each checked), cleans what one tab of the form sent, and builds what a page needs: its title (the format), description, robots tag, the tags of the head. `SeoAdmin` is the screen (a tab each, saved separately) and the checks of the site; `SeoAudit` looks at every published entry. |
| `MediaUsage` | Where each uploaded file is used (content and settings), kept between visits under a fingerprint of what it read. |
| `FormFields`, `FormTemplates` | Form field kinds, and how stored fields are read for the site and the builder and cleaned when the builder sends them; and the ready-made forms the New form screen offers (English and Greek). The builder itself is `public/assets/js/admin-form-builder.js`, drawn from one JSON block in `form-edit.twig`. |
| `Theme` | Frontend theme manifest (`theme.yaml`), settings schema and resolution, template lookup across `custom/` and the theme, asset URLs, layered translations and `custom/lang` overrides. |
| `ThemeAssets` | Serves theme and `custom/` assets from outside `public/` with type allowlist, path checks, and caching. |
| `FieldSchema` | Declarative field definitions (text, markdown, link, image, select, toggle, number, repeater, …): defaults, validation of stored values, and form input handling. Used by theme settings, blocks and content types. A repeater may hold one repeater level in its rows (`MAX_DEPTH`); `problems()` names deeper ones, which are left out. |
| `ContentTypes` | Content type definitions from `themes/<theme>/content-types/` and `custom/content-types/` (merged per field): declared fields, checked values, display lists, archive settings, and writing the site's file from Admin > Content types. |
| `Taxonomies` | Categories, tags, and other taxonomies in `content/taxonomies/`: terms with a stable id, a changeable address made from the name, labels and descriptions per language, and the archive settings of each taxonomy. A term can have a parent (taxonomies that are a tree, categories by default): its address is its parents' slugs and its own, a slug is unique among the terms of one parent, and a loop is refused. Works out what a submitted terms form changes (new terms, moved addresses of a term and of everything below it, removed terms whose children move up). |
| `LinkScanner` | Finds links to addresses of the site inside content files (body, HTML, front matter) and rewrites them; used to point links at a redirect's target. |
| `BlockRegistry` | Block definitions from `themes/<theme>/blocks/*/block.yaml` and `custom/blocks/`, with shared presentation fields (variant, tone, spacing, width, anchor, hidden); editor definitions and storage sanitising for the admin block editor (`public/assets/js/admin-blocks.js`). |
| `BlockRenderer` | Renders a page's `blocks:` list: checks values, heading levels, Markdown, dynamic data (latest items, forms), stylesheet bundle, and FAQ structured data. |
| `PresetLibrary` | Ready-made sections and page layouts from `themes/<theme>/presets/` and `custom/presets/`, localised per language; saves and deletes site sections. |
| `Toc` | Heading ids and "On this page" contents for the sidebar template. |
| `Images` | Responsive `<picture>` markup for uploads and on-demand WebP variants under `/uploads/_v/`. |

## Admin front end

- `admin/templates/base.twig` is the shell (sidebar, header, notifications, toast host).
- `admin/templates/partials/ui.twig` holds shared macros: `flash`, `badge`, `status_badge`, `filter_button`, `empty_state`, `pagination`.
- `public/assets/js/admin.js` provides data-attribute behaviours: dropdowns, tabs, filters, off-canvas, modals, toasts, sortable tables, confirmation modal, copy-to-clipboard, select-all, and the CSRF safety net.
- Styles: `public/assets/css/admin.build.css` is the compiled Tailwind build (see README), `public/assets/css/app.css` holds the few custom rules.

## Where data lives

See `system-database.md` for SQLite and `backups.md` for what backups contain. Content, uploads, and `custom/` (site overrides) are files; settings (including theme settings), users, logs, and indexes are in SQLite.

## Refactoring direction

`App.php` is being split incrementally, keeping behaviour identical: extract a cohesive group of private methods into a class that takes what it needs through its constructor (paths, and closures for things that change during a request, such as the settings), delegate from `App`, and give the class unit tests before and after moving it. Done so far: menus (`Menus`), CSV import and export (`ContentCsv`), structured data (`StructuredData`), robots.txt (`RobotsTxt`), the SEO screen (`SeoSettings`, `SeoAdmin`, `SeoAudit`), analytics (`AnalyticsSettings`, `AnalyticsCollector`, `AnalyticsStore`, `AnalyticsReport`, `AnalyticsAdmin`), form fields (`FormFields`), the site settings (`SiteSettings`), taking backups (`BackupManager`), what the taxonomies screen does (`TaxonomyEditor`), redirects (`RedirectAdmin`, `PublicPaths`), translations (`ThemeStrings`, `EntryTranslations`, `LanguageAlternates`), the media screen (`MediaAdmin`), storage limits, system checks and the dashboard (`SiteLimits`, `SystemStatus`, `DashboardData`), forms (`FormProcessor`, `FormsAdmin`), sign-in, users and roles (`SignIn`, `GoogleSignIn`, `UserAdmin`, `RoleAdmin`), content types (`ContentTypeAdmin`), archives (`ArchiveBuilder`), backups (`BackupAdmin`), the content screens (`ContentAdmin`, `EntryForm`, `RevisionAdmin`, `ContentTransfer`), menus, logs and updates (`MenuAdmin`, `LogAdmin`, `UpdateAdmin`, `AdminNotices`), the public side (`Sitemap`, `TaxonomyPage`, `PublicForms`, `TwigFunctions`, `FrontRoute`), the settings screen and the admin extras (`SettingsAdmin`, `AdminChrome`), the admin router as a table, media usage (`MediaUsage`), the media library, backups, updates, mail, and the content editor. What is left in `App` is the wiring (the constructor and the factories of the classes), the permission checks and redirects of each handler, rendering, the template functions that need the language, the signed-in person and the form token, and the public page of each kind (finding the entry and drawing it). Further splitting would move the last of these.
