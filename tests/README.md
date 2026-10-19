# Tests

One command runs everything:

```bash
tests/run.sh          # all checks (several minutes, depending on the machine)
tests/run.sh unit     # PHP checks only, no server
tests/run.sh js       # JavaScript checks: editors, branding controls and previews
tests/run.sh http     # the browser-level tests
tests/run.sh redirects   # one HTTP test: editor, roles, import, redirects, save, history, taxonomies, links, delete
```

It needs the PHP version in `composer.json` (8.5 or newer), Python 3 (standard library only) and, for the JavaScript checks, Node 20 or later; the first run installs the one tool they use (jsdom) into `tests/js/node_modules` from `tests/js/package-lock.json`. Nothing touches your site: the HTTP tests run against a temporary copy of the code on a free local port, seeded from `tests/fixtures/content`, and remove it afterwards. The exit code is non-zero when anything fails, including a test process that prints a success message but exits with an error.

## When the environment is wrong, or a test hangs

`tests/run.sh` checks the environment before it runs anything and stops with **exit code 3** and one line per problem, so a wrong machine fails clearly instead of producing dozens of cascading failures:

```text
Environment check failed, nothing was run (GNOZIO_TESTS_SKIP_PREFLIGHT=1 skips this check):
  PHP 8.5 or newer is needed; this is 8.3.6 (/usr/bin/php, see php -v)
```

It reads the lowest PHP from `composer.json` (`require.php`, never hardcoded), the required PHP extensions from composer's `ext-*` entries and the installer's required list, and it needs `python3` and `curl` for the HTTP tests and Node 20 or newer (with npm) for the `js` group. Only the groups you run are checked. For a deliberate experiment (a different PHP on purpose), set `GNOZIO_TESTS_SKIP_PREFLIGHT=1`.

Nothing waits for ever. Every HTTP test has a time limit (180 seconds; `GNOZIO_TEST_TIMEOUT=300 tests/run.sh http` changes it, and `test_timeout()` in `run.sh` can give one test more). A test that runs out is reported as `FAIL  <name>  TIMEOUT <name> (180 s)` and the suite carries on with the next. Inside the tests, `tests/http/client.py` gives every request a timeout (`GNOZIO_TEST_REQUEST_TIMEOUT`, 60 seconds) and has `wait_until(fn, timeout, interval, what)` for polling; it prints a `FAIL timed out after N s waiting for <what>` line instead of looping. Use it for every wait, never `while True`.

In tests that walk a sequence (the installer, update install, fresh install), `client.Flow` records the first failed step once (`critical=True`) and prints the checks that depend on it as `skip <label>  (skipped: <step> failed)`. The runner counts them apart from failures (`pass  name  N checks, M skipped`, `FAIL  name  1 failed, 12 skipped`, and a line with the total at the end); `flow.clear()` starts an independent part of the test.

## CI results without delaying releases

GitHub Actions runs this suite on PHP 8.5 after pushes to `main` and for pull requests. A newer push cancels the superseded run. Pushes and the release workflow do not wait for the test result.

Open **Actions → Tests → the latest run** to see the failed groups in the job summary. The **test-logs-php-8.5** artifact keeps each test's complete output, npm installation output when it runs, and the PHP server log for seven days. Tests still fail the job normally; collecting diagnostics never turns a failure into a pass. Cancelled runs are superseded checks, not passing checks.

When a run fails, check the log for the failing group and reproduce it locally (`tests/run.sh save`, `tests/run.sh unit` or `tests/run.sh js`). Distinguish a regression from an intentionally changed expectation before changing a fixture. Send the fix in the next push, then verify the newest run succeeds. For a live regression, use the updater's rollback if appropriate; reverting code alone does not undo content or database changes.

Keep the same full logs locally with an absolute path outside the installation:

```bash
FAROS_TEST_LOG_DIR=/tmp/faros-test-logs tests/run.sh save
python3 tests/runner_test.py  # verifies that failing subprocesses cannot report a green suite
```

## What is covered

| Check | What it proves |
|---|---|
| `tests/unit/repeaters.php` | Repeaters two levels deep: the schema (a third level left out and named), checking rows at both levels (limits, empty rows, texts per language), defaults and icons; a content type with a nested repeater (texts per language, form input, tidy front matter, display rows); block storage and the Markdown guard |
| `tests/js/repeaters.test.js` | Nested repeaters in both editors, as shipped in jsdom: the fields.twig form with admin.js (each list renames only its own rows, templates follow their row, each list's maximum) and the block builder (inner rows added, moved, removed) |
| `tests/unit/installer.php` | The one-file installer's pure parts: its paths equal the updater's, the manifest checks, the allowed addresses, English and Greek strings alike, an existing site recognised, `installer.php` built beside the package and not inside it |
| `tests/http/installer_test.py` | The installer end to end against a fake release: no key, a short key, a wrong key, an existing site, a bad checksum (and the retry), a package with a path outside the code paths or a link, the download in ranges, unpack, the wait for the document root until `public/` is really served, the finish that removes every copy, and the first-administrator screen |
| `tests/unit/search-document.php` | Text extraction from public fields, blocks, repeaters (two levels) and containers; exclusion of technical/private fields; extracted-text cache invalidation and fallback |
| `tests/unit/search.php` | Greek matching and original spelling, relevance, cross-field multiword matching, snippets, visibility/language boundaries, paging and custom-template compatibility |
| `tests/http/search_test.py` | Public results through real requests: counts, filters, paging, block/custom field matches, visibility, language isolation, malformed parameters and HTML escaping |
| `tests/unit/blocks.php` | Block definitions, defaults, tampered values, video links, storage sanitising |
| `tests/unit/revisions.php` | The line diff and the history store: capture, dedupe, outside changes, renames, pruning, deleted items |
| `tests/unit/taxonomies.php` | Term addresses from names, address changes, removals, the layout choices a taxonomy stores |
| `tests/js/serializer.test.js` | The visual editor's serializer (`public/assets/js/admin-editor.js`, the page back to Markdown), the real code run in jsdom over the texts in `tests/js/serializer-samples.js`, drawn by the server's own Markdown code. For each text: opened and left it is written back byte for byte; every block written again reads back as the same page; written twice it does not change. Also the HTML a browser leaves while typing (div, b, nbsp, empty lines), and an edit that must change one block and keep the others. When a text is found that the editor damages, add it to the samples |
| `tests/js/editor-tools.test.js` | The visual editor's toolbar: the whole `admin-editor.js` run in jsdom. The picture dialog and the library it opens (a click in the library must not close the dialog), Insert, and the alignment buttons: one block, a selection over several, left taking the choice away, list items left alone, what is written in the Markdown |
| `tests/unit/branding.php` | Branding CSS and attributes: validated colours and type, optional component tokens, Slate's custom accent background, and no overrides when theme defaults are restored |
| `tests/unit/branding-extras.php` | Branding part C: the CSS of each type override (steps of the scale, never pixels), defaults adding no bytes, the shadow presets with the old `none` and `strong` unchanged, card, image and field corners, card borders, phone overrides, form styling (focus rings, error styles, contrast of the presets), export then import on a clean site giving the same CSS and attributes, the import preview, backup and undo, hostile files, and the reset of each section |
| `tests/http/branding_transfer_test.py` | The new Branding cards on the screen, the settings on the public page and in the live preview, the export download, preview, import, undo and reset over HTTP, and that an editor, a visitor and a request without the form token are refused |
| `tests/js/branding-controls.test.js` | Theme/own value indicators, inherited colour swatches without storing overrides, schema-based field and group resets, preset choices kept, and preview refresh after reset |
| `tests/js/branding-preview.test.js` | CSS-only Branding responses, custom CSS precedence, logo removal, mode resets, style samples and return to Site, with Header/Footer HTML previews still accepted |
| `tests/http/branding_test.py` | Branding settings, saving and validation, schema reset data, control placement, style sample markup and access, preview responses storing nothing, and admin frame protection retained |
| `tests/unit/twig-site-functions.php` | The template functions of `PageTwigFunctions` (analytics, SEO, branding) and `GeoTwigFunctions` (maps): present, settings and language read when a function runs and not before, HTML let through only where meant, nothing built for a page with no map |
| `tests/unit/block-runtime.php` | What blocks need from the site (`BlockRuntime`): the registry and the choices it offers (types of content, types with a place, forms), which types can have a place, the maps and their addresses by language, the YouTube services, who is given a kept picture, nothing built before it is asked for |
| `tests/unit/login-controller.php` | The addresses that need no sign-in (`LoginController`): the login screen, a password that is wrong, right, shipped or blocked, signing out (only by the form), signing in with Google at every step it can stop at (state, code, token, profile, domain, no user, switched-off user, success), and the first administrator |
| `tests/unit/html-to-markdown.php` | HTML turned into Markdown: text, lists, tables, embeds, what is dropped, what is reported |
| `tests/unit/wordpress-import.php` | Reading a WordPress site, the plan that changes nothing, writing pages, posts, terms and media, redirects, doing it again, files of the site's own |
| `tests/unit/skill.php` | The skill for other chats (`skills/gnoziocms`): its snapshot of the blocks, theme settings and ready-made layouts equals the theme's, its references cover every block, its files point to files that exist, and (where Python and PyYAML are present) its scripts validate the demo site and write a page the CMS reads |
| `tests/unit/content-type-catalogue.php` | The types the theme ships, which are on, switching on and off, files kept, pages and forms always on |
| `tests/unit/form-templates.php` | The ready-made forms: each whole (fields survive storing, emails use fields it has), in English, Greek and another language |
| `tests/unit/form-uploads.php` | File fields: size, number, extension and content checks (a PDF named .jpg, text named .pdf, programs, pages and SVG refused, a double extension), the rename pattern (tokens, Greek, collisions, empty fallback, length), the private folder (no way out of it, ZIP, removal with the submission), the email with the files attached or only the links, a real SMTP message with an attachment, and a backup that holds the files and a restore that puts them back |
| `tests/http/form_upload_test.py` | File fields end to end: the form page and its picker, a submit without JavaScript, every refusal writing nothing, no direct address (403 or 404), the admin download and ZIP (and a signed-out user, an editor and a plain user refused), the submissions screens, the CSV, the builder's data and saving, deleting a submission (single and bulk) removing its files |
| `tests/http/form_builder_test.py` | The form builder end to end: the New form screen and its templates, the builder's data, saving and cleaning what it sends, headings and widths on the site, a translation as a copy, an editor kept out |
| `tests/unit/block-containers.php` | Container rows (rows with columns that hold blocks): the settings and their choices, column widths (fill, auto, fraction, percentage, size) and the tampered ones, the shape written to the front matter (only what differs, one to six columns, no container inside a column), the classes and variables of a row and a column, and every reader of `blocks` seeing the blocks in the columns (the Markdown guard, media usage, the link scanner, the editor's migration) |
| `tests/http/block_containers_test.py` | Containers on the page and in the editor: the markup, the classes of every flex setting and breakpoint, the stylesheet carrying the container rules only where there is a container (and the rules of the blocks inside), pages without containers unchanged, a picture with an overlay, nothing for an empty or hidden container, the page text placed once, the editor's data, saving and reading back (also from raw front matter), history, search, feed and the media screen with nested blocks |
| `tests/js/block-containers.test.js` | The builder with containers in jsdom: what is written (blocks without a container come out as they went in), moving blocks by the pointer between columns and in and out of a container, a container refused by a column, the keyboard (Shift and the arrows, what is said, Escape), Wrap in container and Unwrap, presets, adding and removing columns (blocks kept, at most six), no Width for a block in a column, and a save round trip |
| `tests/http/entry_preview_test.py` | The live preview of the content editor end to end: the screen and its toggle, unsaved blocks, text, front matter and new entries drawn through the theme, blocks marked by place (preview only), unreadable YAML answered with an error, nothing written (no file, no history row), signed-out and plain users refused, an editor not getting raw HTML while an administrator does |
| `tests/js/entry-preview.test.js` | The preview's scripts in jsdom: nothing asked while hidden, the 600ms debounce on form input and `bk:change`, the file field left out, an error answer keeping the picture, the remembered open state and width, the arrow keys on the grip, block clicks both ways |
| `tests/http/menu_editor_test.py` | The menu editor end to end: the data it starts with, saving the items, a hidden item, a field that cannot be read, putting a menu in a place, an editor without the right to change settings, deleting |
| `tests/http/catalogue_test.py` | The Content types screen end to end: books off then on, its archive, a book page, the sitemap, an editor kept out |
| `tests/unit/links.php` | Which links to an address are found and rewritten, and which are left alone |
| `tests/unit/content-types.php` | Content type definitions, merging with the site's file, values, ordering |
| `scripts/check-permissions.php` | Every admin action against every role, custom permissions, the capability catalogue |
| `scripts/check-slugs.php` | Greek to Latin addresses, the redirect store, loops, validation |
| `scripts/check-blocks.php` | Every block in the site's own content uses known fields and valid values |
| `tests/http/editor_test.py` | The editor role end to end: what it can reach, raw HTML, forms, dashboard |
| `tests/http/roles_test.py` | Admin > Roles: who can change permissions, tampering, resets, corrupt data |
| `tests/http/import_test.py` | CSV import follows the raw HTML rule |
| `tests/http/save_test.py` | Saving every editor field, compared with recorded files in `tests/fixtures/golden` (`UPDATE_GOLDEN=1 tests/run.sh save` accepts a deliberate change) |
| `tests/http/history_test.py` | What history records, comparing, restoring, deleted items, renames, and who may do what |
| `tests/http/taxonomies_test.py` | Each taxonomy's own layout, title, paging, filters, and content types; term addresses; redirects when an address changes; who may change them |
| `tests/http/links_test.py` | Links to old addresses: the notice after a change, the review screen, updating, chains, other websites, history, permissions |
| `tests/http/delete_test.py` | Deleting with a redirect: the choices, refused targets, drafts, permissions, restore, bulk delete |
| `tests/http/blocks_test.py` | The comparison table and before/after blocks on the showcase page: markup, accessibility attributes, script bundling, the editor |
| `tests/http/custom_roles_test.py` | Making, changing, giving, and deleting roles of your own; what a person with such a role can reach; the limits and tampering |
| `tests/http/storage_test.py` | The storage limit: who can set it, what is stored, the colours at 80% and 90%, the message and contact button, refused uploads, no limit |
| `tests/http/redirects_test.py` | Automatic addresses, address changes, redirects, the not-found list, permissions |

Not covered: real browsers and screen readers, hosting and web server rules, sending email, backups and restores, Google sign-in. See `scripts/theme-audit.js` for the in-browser accessibility scan.

## Adding a test

- **Logic** (PHP): add a `check('what', $actual, $expected)` to a file in `tests/unit/`, or a new file and one line in `run_unit()` of `run.sh`.
- **A screen or a rule**: add checks to a file in `tests/http/`; `client.py` is a tiny cookie-keeping client (`login`, `get`, `submit` a form found on a page). Each test starts from a fresh copy of the fixtures with the default `admin` account. To add a new file, list it in `run_http()` of `run.sh`.
- **When you add an admin action**, add it to the table in `scripts/check-permissions.php`; that is what stops a new screen from being open to the wrong role.
