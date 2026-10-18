# Importing content

"Import" covers four different jobs. Decide which one before touching anything.

| The source | The way |
|---|---|
| A **WordPress** site that is online | `php scripts/import-wordpress.php https://old-site.example` from a clone of the repository (the script is not in the release package); reads the public REST API, no password. It prints a plan first, `--apply` writes. Pages, posts, categories, tags, media, links rewritten, and `redirects.txt` for the old addresses. Read `docs/wordpress-import.md` of the repository before running it. Needs PHP and network access; run it on a copy and look at the result. |
| **Text and pictures you were given** (Word, Google Docs, a spreadsheet, PDFs, a list of URLs, copy pasted in chat) | Turn each item into an entry, then write files (`new_entry.py`) or make a CSV for the admin (`entries_to_csv.py`). This is the common case; the steps are below. |
| **Another website** that is not WordPress | Read its pages, keep the text structure (headings, lists, links, images), drop navigation, footers, cookie banners, scripts and styles, then proceed as above. Respect copyright: the person must own or be licensed to use the material. |
| A **CSV from GnozioCMS itself** (Admin > Content > type > Export) | Edit and import it back (`importing` below). Columns: `content_type, language, slug, title, status, visible, date, author, tags, categories, translation_id, main_image, excerpt, body, updated_at` plus `meta.*` for the rest of the front matter (`meta.blocks` is a JSON list). |

**When the site was installed from the release zip** (no `scripts/import-wordpress.php` in it), do not fall back to the scripts of this skill for a WordPress site. Look for a clone of the repository on the machine (a `gnoziocms` or older `faroscms` folder with `scripts/import-wordpress.php`), ask the person for its path, or clone it into a scratch folder (`git clone https://github.com/chiotis/gnoziocms`). Run the script from there and pass every path of the site, because its defaults point at the clone's own folders: `--content=<site>/content --uploads=<site>/public/uploads --custom=<site>/custom --out=<site>/storage/import`. Never copy the script into the site: an update would refuse a package that holds it.

WordPress importer, the parts that matter for a whole site: `--profile=FILE` reads what the REST API does not give (a theme's own post types, the home page slider, menus) with XPath; `docs/wordpress-profiles/themelio.yaml` is a worked example with every key, and a profile's home slider becomes a `hero-slider` block. `--menus` brings the menus, `--lang`/`--default-lang` decide the language of the content, `--only-used-media` skips unused library files. It writes `redirects.txt` for *Admin > Redirects > Import*.

## Moving a whole website (migration)

The aim is the same site on GnozioCMS with the CMS **as it is**: content, look and addresses carried over, nothing changed in `src/`, `admin/` or `themes/`.

1. **Survey the old site** before writing anything: every page and post (its sitemap is the fastest list), the content types (events, books, projects…), categories and tags, menus, forms, the languages and how their addresses look, the home page sections, the footer, the palette, fonts and logo. Write the list down; it is also the checklist at the end.
2. **Map each part onto what the CMS has**: a page section onto a block (`references/blocks.md`; the home slider onto `hero-slider`, a logo row onto `logos`, a contact strip onto `contact` or `form`), a post type onto a content type (on in *Admin > Content types*, or a new one with its fields), the look onto Theme settings (`theme-settings.md`: palette or your own colours, heading colour, fonts, header layout and background, footer layout, Footer Blocks, title areas per type and per term, breadcrumbs). Use `custom/assets/css/custom.css` only for what no setting does.
3. **When something has no place** (a widget, a layout the blocks cannot make, a plugin's feature), do not imitate it with raw HTML in the body or edit the CMS's code: note it with the page it is on and tell the person. It is a request for the CMS, not something to patch on the site.
4. **Keep the addresses.** Prefer the old slug for every entry; pages and posts share one namespace. Write a redirect for every address that changes (a WordPress post at `/2024/05/title/` → `/title`, a page under a parent → its slug). The CMS already answers `/category/x/page/2/` (301 to `?page=2`), `/feed` and the feeds of lists, so those need no redirect.
5. **Import** (the WordPress importer, or the steps below), pictures first, everything as `draft` unless the person said otherwise.
6. **Check**: `validate_content.py` on all of `content/`, then open one entry of each kind and each list in each language next to the old one. On a site behind Varnish/CloudPanel empty the page cache first (*Admin > System > Page cache*), or you are looking at old pages.
7. **After launch**, watch *Admin > Redirects > Not found* for a few days: redirect the real old addresses, *Ignore all* the bot noise.
8. **Report** against the list from step 1: carried over, carried over differently, not carried over (and why), what only the person can do (mail, users, the cron job for backups, DNS).

## The steps for given material

1. **Inventory.** List the items: what each is (page, post, project…), its language(s), title, its old address if any, its pictures, its place in the menu. Decide the content type for each (`pages` for standing pages, `posts` for dated articles, another type only if the site has it: `python3 scripts/site_info.py`).
2. **Plan the addresses.** Slugs come from titles (`slugify`), Latin lowercase. Keep the old path if the site is moving and the path is clean; otherwise map old → new and prepare redirect lines (`old-path new-path`). Pages and posts share one address space; check for duplicates.
3. **Back up.** Ask the person to make *Admin > Backups > Create full backup*, or copy `content/`, `public/uploads/` and `storage/db/app.sqlite`. A bulk import is the one moment a backup is non-negotiable.
4. **Pictures first.** `python3 scripts/add_media.py photo.jpg https://old/logo.png --alt "…"` (a description of what each shows; the file name is only a fallback). It prints `/uploads/media/<id>.<ext>`; use that everywhere, never the old site's address. The same file twice is stored once.
5. **Write the entries.**
   - With files: one `new_entry.py` per entry per language (`--translation-of <slug>` for the second language, `--meta imported_from=<old url>` so a rerun can find them, `--status draft` while reviewing).
   - Without files: put all entries in one YAML list and run `entries_to_csv.py entries.yaml --type pages -o pages.csv`; the person opens *Admin > Content > Pages > Import*, uploads it, reads the **preview** (create / update / skip / errors per row) and presses **Apply**. One CSV is one content type; forms cannot be imported.
6. **Convert the text to Markdown** the way an editor would: headings from `##`, lists as lists, links as links (internal ones as `/path`), pictures as `![alt](/uploads/…)` or in an `image` block field, tables as Markdown tables. No inline styles, no `<font>`, no empty paragraphs, no layout tables, no duplicate title as a heading. Keep the author's words; fix only obvious typos if asked.
7. **Add blocks only where they help**: an opening `hero` from the page's lead, a closing `cta`, FAQ from a question list, `team`/`pricing`/`timeline` where the source is clearly that. A long article is just a body.
8. **Validate.** `python3 scripts/validate_content.py` (all) or the files you wrote. Fix every ERROR; read every WARN (missing pictures, unknown terms, invalid values).
9. **Link it up.** Add the pages to `content/menus/*.yaml` or Admin > Menus. Put the redirects in (*Admin > Redirects > Import*, 500 lines per paste). File posts under categories/tags that exist (`content/taxonomies`), or add the terms first.
10. **Look.** Open a sample of the pages in each language (and the list pages). The content index and the sitemap follow by themselves. Report: how many entries were created/updated/skipped, what you could not carry over (embedded frames, forms, scripts, comments), what needs a person (mail settings, real photos, missing translations), and the addresses to review.

## Rerunning

An import should be safe to repeat. Files are matched by slug + language (and by `translation_id` in the admin importer); `new_entry.py` refuses to overwrite without `--force`; the CSV importer updates and says `skip` for unchanged rows. Keep the old-address → slug map in a file so a second pass reuses the same slugs.

## What the CSV import does and does not do

- Matches existing entries by `translation_id` + language, else by slug + language, and updates them; keeps front matter keys you did not give a column for.
- Makes the slug from `slug`, else from the title **letter by letter** (not the nicer ELOT rules the admin editor uses), so always give `slug` (the script does).
- Backs up the files it touches and puts them back if a write fails.
- Does not check blocks. A wrong block value is silently replaced by its default later, so validate before you hand the CSV over.
- A body cell keeps its line breaks; a quoted cell may contain commas and newlines.
