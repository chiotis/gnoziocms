# Moving a WordPress site over

`scripts/import-wordpress.php` (on a site installed from a release, from 0.1.270: `php src/Cli/import-wordpress.php` in the site's folder, with the same options) reads a WordPress site through its public REST API (`/wp-json/wp/v2`, no password) and brings its pages, posts, categories, tags and pictures into a GnozioCMS site. It says what it would do first and changes nothing until you add `--apply`.

```bash
php scripts/import-wordpress.php https://example.com            # what would happen
php scripts/import-wordpress.php https://example.com --apply    # do it
```

Options: `--redirects=apply` and `--storage=PATH` (see *Old addresses*), `--fetch-external` (see *Pictures on other sites*), `--resume` (see *Preview, undo and resume*), `--profile=FILE`, `--menus`, `--custom=PATH`, `--lang=en` (language of the content), `--default-lang=en` (the site's default language; when it differs, the content lives under `/en/`), `--home=index` (file name of the home page), `--kinds=pages,posts,types` (`types` are the profile's custom types of the REST API, below), `--only-used-media`, `--overwrite`, `--content=PATH`, `--uploads=PATH`, `--out=PATH`. Run it against a copy first and look through the result in the admin.

## What comes over

| On the old site | Here |
|---|---|
| Pages | Pages. The page WordPress shows as the home page becomes the home page file. |
| Posts | Posts, with date, category and tag terms, the featured picture as the main image, and an excerpt only when the author wrote one (the cut WordPress makes by itself is left out). |
| Categories and tags | Terms. The category tree is kept: a category keeps its name and its parent, and its address is the one it had (`/category/news/child`). A name used under several parents ("Logicomix" under News and under Reviews) stays "Logicomix" under each; the terms are told apart by their parent (their ids get a number, `logicomix` and `logicomix-2`, the same on every run). A post is filed under the categories it has, and the page of a parent also lists what is filed under its children, as WordPress shows it. Tags have no tree. Two terms under one parent whose names differ only in case, accents or final sigma (`ς`/`σ`) are one (see *Duplicate terms*). Each term keeps its source in `imported_from`. |
| The media library | Files in the library with their alternative text (a file with none takes its title, unless the title is a file name or a camera's name such as `IMG_0042`), and every picture or file the text uses. A size WordPress made (`photo-300x200.jpg`) is the picture itself. |
| The text (HTML) | Markdown: paragraphs, headings, lists, quotes, links, pictures, simple tables and code. A picture with no alternative text is described by its title, else by its caption (a figure's `figcaption`, WordPress's `wp-caption-text`); a file name or a camera's name is never used. A picture that floated to a side (`alignleft`, `alignright`, a `float` style or the `align` attribute, also on its link or the box of its caption) is written `![A cat](/uploads/media/cat.jpg){align=left} text…`, in the paragraph that goes around it, as the editor writes it. Styles, Word's classes and empty paragraphs are dropped; a table used to place a picture beside text is taken apart; video embeds (YouTube, Vimeo, SoundCloud and a few more, over https) stay as a small piece of HTML. A link to a YouTube video that stands alone in a paragraph (what WordPress shows as a player) cuts the page there: the text before and after become Text blocks and the video a Video block, so the page has blocks and no body; a link inside a sentence, a list or a quote stays a link. |
| Links between pages | Rewritten to the new addresses. |

## Old addresses

The run writes `redirects.txt` (default `storage/import/`): one line per old address that is not the same on the new site (a post at `/2020/05/slug/` is now at `/slug`, a picture at `/wp-content/uploads/...` is in the library, a merged term at the term it went into). An old address that is a page of the new site (an entry, or the page of a term) is never redirected. Old addresses with Greek letters are written as letters (`/activity/παρατήρηση-πουλιών`); a visitor's browser asks for them encoded, and both are the same address.

Two ways to put them on the site:

- **`--redirects=apply`** (with `--apply`) puts them straight into the site's Redirects, in the database of the site's storage folder (`--storage=PATH`, by default the `storage` beside `--content`). The dry run with `--redirects=apply` says how many it would add and which it would leave out. Running it again adds nothing: a redirect that is already there with the same target is kept; one an earlier import made (or a list pasted in the admin, both noted *Imported*) is pointed at the new target; one the site made itself (by hand, or when an address changed here) is left alone and listed as `taken`. A line that would loop back, from an admin address, or a second line for the same address (two that differ only in case) is listed and left out.
- **Admin → Redirects → Import**: paste the file (500 lines at a time) on the site that will serve them.

For the terms of a profile with `language_prefixes`, each old address leads to the term's page in its language: the main language's (`/activity/bird-watchin` to `/activity/paratirisi-ptinon`), the same path under the prefix (`/en/activity/bird-watchin`), and the old English address with its own slug, as the old site's English pages give it (`/en/activity/bird-watching`, and those of the terms merged into it), to `/en/activity/paratirisi-ptinon`.

## Pictures on other sites

A picture the text shows from another site (a CDN, an old blog, Flickr) stays where it is unless `--fetch-external` is given; the dry run lists the hosts and how many pictures each has. With `--fetch-external` they come into the library like the old site's own, and the text points at the library; `--fetch-external=cdn.example,flickr.com` brings only those hosts' (a subdomain counts). They are read through the same safe fetcher as the rest (public addresses only, 20 MB at most). An address with no extension (a picture service's, `…/photo?id=9&w=800`) is kept as the kind of picture the file turns out to be, and one that is not a picture is left out and listed. A picture that could not be fetched stays pointing where it was. No redirect is made for a path of another site.

## Checking the old addresses

After the move (or before it, against a copy with `--allow-local`), `src/Cli/check-redirects.php` asks the new site for every address the old one had and says which do not lead to a page:

```bash
php src/Cli/check-redirects.php https://new.example --old=https://old.example          # the old site's sitemap, while it is up
php src/Cli/check-redirects.php https://new.example --sitemap=old-sitemap.xml          # a sitemap saved before the move
php src/Cli/check-redirects.php https://new.example --list=storage/import/redirects.txt   # the import's list: where each should lead is checked too
```

The old addresses come from the sitemap (`/wp-sitemap.xml`, Yoast's `/sitemap_index.xml` or `/sitemap.xml`, with the sitemaps an index lists) or from a list (`old new` lines, or one address a line); several sources can be given together. Each is asked one step at a time, following no redirect on its own: a page that is the same here answers **200**, one that moved answers **301** (or 308) and the address it points at answers 200. Everything else is printed as it is found and counted at the end: a chain of several redirects (it works, but the first should point at the last), a temporary redirect (302, 307), a redirect to another site, a list line that leads to another page than it says, an address that leads nowhere (404, 410), a loop, an error. The whole result is written to `storage/import/redirect-check.csv` (`--out=FILE`). `--limit=N` checks the first N, `--delay=MS` waits between addresses (100 ms by default). The exit code is 0 when every address leads to a page.

## Preview, undo and resume

These work the same for every import: WordPress, WooCommerce (the command and Shop settings › Import) and a CSV of a content type.

- **The dry run's preview.** The dry run writes `storage/import/preview.txt`: every page and post, marked new, change, same (an update that would leave the file as it is) or left alone (and why), and for a change the lines it would change. The counts say how many updates are the same as now. The WooCommerce dry run marks the same on each product (*No change*, or the lines added and removed), and the CSV import has always shown its rows before applying them.
- **Undo for one row.** Every file an import writes in the content folder is a row of its run in **Admin › Imports** (`storage/import/runs/`, the last 30 runs), with a copy of what was there before. *Undo* puts that one entry back as it was before the import, or removes it when the import made it; an entry that was edited after the import is said so and is undone only when that is confirmed. Pictures brought into the library and terms are kept. An entry the CSV import moved to another address is two rows (the new file, and the one it replaced). Undoing a product needs the shop's permission. Opening Imports needs *Import content* (`imports.manage`).
- **Resume.** A run that stopped before it finished (the command was stopped, the connection or the server ended it) stays *Not finished*. `--resume` on the same command (WordPress, WooCommerce) goes on with it: the pages, posts or products it wrote are skipped and the pictures already in the library are not fetched again. On Shop settings › Import, an import that stopped while writing has *Go on with the import*. The CSV import writes all of its rows or none, so it has nothing to resume.

## Running it again

Every id is made from the address it came from, and every file it writes has `imported_from` in its front matter. Running it again updates those files and fetches only the pictures that are not in the library yet. A file of the site that an import did not write (you made it, or edited and removed the line) is left alone unless `--overwrite` is given.

## What the API does not give: profiles

A theme's custom post types ("books", "events", "slides" are often kept out of the REST API), the home page slider and the menus are only in the pages themselves. A **profile** (`--profile=FILE`) tells the importer where to look, with XPath, because every theme draws them differently. `docs/wordpress-profiles/themelio.yaml` is the one for apostolosdoxiadis.com and shows every key:

| Key | What it does |
|---|---|
| `types` | A custom post type: its pages are found in the site's sitemap (`/wp-sitemap-posts-<type>-1.xml`); XPath for the `title`, the cover `image`, the `summary` and the `tabs` (`labels`, `panels`); `content_type` is where they go here, `front` is front matter added to each. A page becomes a Text and image block (cover beside the description) and a Tabs block; a tab with nothing in it is dropped. |
| `types.<name>.summary_in` | `excerpt` puts the description in the page's excerpt (the Books type shows it under the title, beside the cover) instead of a Text and image block. |
| `types.<name>.fields` | The content type's own fields, read from the page (the Books type's author, publisher, year, ISBN and buy links), instead of only being in its text; see below. A field named `date` is the entry's date. |
| `types.<name>.tabs.skip` | Labels of tabs left out of the Tabs block (any case), for a tab whose facts `fields` now reads (`skip: [Details, Buy]`). |
| `category_taxonomies` | A category, with the categories under it, goes to a taxonomy of its own; see below. |
| `content_types` | Definitions written to `custom/content-types/` when the site has none for that type. |
| `categories_of_custom_types` | `true` to find which categories each page of those types is in, by reading the archive page of every category (the API does not say). Each page is also put in the categories above its own. |
| `archives` | The old address of a list and the content type that lists it now (`book: books`): links and redirects follow. |
| `slides` | The slides of the home page: where each is, how its picture (a CSS background) and title, text and buttons are found. |
| `menus` | A menu read from the home page (`list` is the XPath of its `<ul>`); written to `content/menus/<key>.yaml` only with `--menus`, because it replaces a menu of that name. |
| `home` | The home page as blocks, with `@slides`, `@image` (the picture its text begins with) and `@body` filled in. Without it the home page is its text. |

### The fields of a type, read from its pages

```yaml
types:
  book:
    content_type: books
    # ... title, image, summary, tabs as above
    tabs: { labels: "//dl[contains(@class,'tabs')]//a", panels: "//ul[contains(@class,'tabs-content')]/li", skip: [Details] }
    fields:
      author: "//p[@class='author']"                                       # the text of the first node
      publisher: { xpath: "//li[contains(.,'Publisher')]", match: 'Publisher:\s*(.+)' }
      year: { xpath: "//li[contains(.,'Year')]", type: number }
      isbn: { xpath: "//li[contains(.,'ISBN')]", match: 'ISBN[:\s]*([0-9Xx-]+)' }
      date: "//time"                                                       # the entry's date (any form strtotime reads)
      buy_links: { xpath: "//div[@class='buy']//a", rows: { label: text, url: href } }
```

A string is the XPath of the field's node, and the value is its text. `match` keeps what the regular expression's first group catches (or all it matches); `type: number` takes the number out of the text; `type: link` and `type: image` point an address at the new site (an image is brought into the library); `list: true` takes every node, as a list. `rows` fills a repeater: a row for each node, and each column is the node's `text`, one of its attributes (`href`, `@title`), or an XPath from the node (`.//span`). Addresses in the rows that are of the old site are pointed at the new one. A field that finds nothing is left out. The values go into the entry's `custom_fields`, which is where the fields of a type live, so they show on the page and in the admin like the ones typed there.

### Categories as a taxonomy of their own

Old sites often use one category tree for several things (Books, Places, News). `category_taxonomies` sends a category and everything under it to a taxonomy of its own:

```yaml
category_taxonomies:
  books: genres                                           # by slug (or id): "Books" stays a term at the top of Genres
  places: { to: regions, title: Regions, root: drop, root_to: /places }
```

The terms keep their names, tree and ids; their addresses start at the moved category (`/category/books/novels` becomes `/genres/books/novels`), and the old addresses lead there in `redirects.txt`. With `root: drop` the category itself is the taxonomy: what was under it is at the top (`/regions/crete`), the entries filed only under it are filed under none, and its old address leads to `root_to` (or, without it, nowhere, which the report says). A new taxonomy takes the category's name (or `title`), keeps a tree, and its pages list the types whose entries were filed under it. Posts, pages of custom types and REST types that have the old categories are filed under the new taxonomy's terms.

## Custom types in the REST API

Many sites show their custom post types in the API (`/wp-json/wp/v2/route`), with their ACF fields under `acf` and the term ids of each taxonomy. The profile's `rest_types` say what each becomes here; `docs/wordpress-profiles/ruralgreece.yaml` is the first such profile (three types, sixteen taxonomies, about thirty merges) and shows every key:

```bash
php scripts/import-wordpress.php https://ruralgreece.gr --lang=el --default-lang=el --kinds=types --only-used-media \
  --profile=docs/wordpress-profiles/ruralgreece.yaml                 # the dry run, with the report
```

| Key | What it does |
|---|---|
| `rest_types.<name>.endpoint` | The REST base of the type (`route`); the name itself when left out. |
| `rest_types.<name>.content_type` | The content type here (`routes`). Switch it on in **Admin > Content types** before importing. |
| `rest_types.<name>.fields` | ACF key → where its value goes, with the key spelled exactly as the API returns it (`υπότιτλος_eν`, with a Greek ν, stays so). `title`, `excerpt` and `body` are the entry's own; any other name is a field of the type. `@el`, `@en` says which language's entry it belongs to (`title_en: title@en`); without it the value goes to every language. `{to: distance_km, type: number}` takes the number out of a text (`12,5 χλμ.` gives 12.5). A text loses its markup but keeps its line breaks; a field that takes several values comes as one text with commas. `{to: buy_links, rows: {label: store, url: link}}` brings an ACF repeater into a repeater field: a row for each of its rows, each column taking the value of an ACF sub-key (an empty row is left out; a value that is not a list of rows is listed). |
| `rest_types.<name>.coordinates` | `{lat: latitude, lng: longitude, to: location}`: the two values become the field `location` (`37.4501, 22.3712`). When only one of them is filled in, or the pair is not a place, the field stays empty and the entry is listed in the report. |
| `rest_types.<name>.media` | ACF keys that point at files (an id of the old library, an address, or a list of them). `main_image` is the entry's main image (instead of WordPress's featured image); `video` is a Video block in the entry (a YouTube or Vimeo address, also out of the embed code an oEmbed field gives); any other name is a field: a library path, or a list of library paths when the old field held a list (`gallery: ["/uploads/media/…", …]`). A route file (GPX, KML, GeoJSON) is fetched into the library and kept only when it reads as a route; one that cannot be read is left out of the entry and listed. `{to: track, length: distance_km}` also puts the file's own length in `distance_km` when the old site left that empty. |
| `rest_types.<name>.taxonomies` | REST base of the old taxonomy → taxonomy here (`activity: activity`, `categories: categories`). A taxonomy shared by several types is one taxonomy here; it is made when the site has none of that name, with the old site's name for it, a tree only when its terms have parents, and its pages listing the types the profile files under it (so a taxonomy of places opens as a map, see [Pages of terms](places-and-routes.md#pages-of-terms)). |
| `rest_types.<name>.ignore` | ACF keys left out without a word (old pictures of maps). Any other key that has a value and is not named is listed in the report. |
| `rest_types.<name>.front` | Front matter added to every entry of the type. |
| `term_map` | Duplicate terms: `<REST base>: {<source id>: <source id>}`, one line per merge. |
| `merge_same_names` | `true`: terms under one parent whose names differ only in case, accents or ς/σ are one (rule 2 below). Off by default, so an import without it merges only what `term_map` says. |
| `skip_empty_terms` | `true`: a term no entry uses (count 0) is left out, unless something was merged into it or a used term sits below it. |
| `language_prefixes` | `{en: /en}`: where the old site keeps another language. The English names and descriptions of the terms are read from `/en/wp-json/wp/v2/<taxonomy>` (matched by id, then by address; a name the same as the Greek one is not taken as English), and the old English addresses (`/en/route/…`, `/en/activity/…`, `/en/route/`) get their redirects. |
| `content_types` | As above: the site's additions to the types (a subtitle, services, hidden select lists), written only when the site has no file for that type. |

Nothing is required: an empty value is no value, and a record with nothing filled in still becomes an entry with its title.

**Two languages.** The entry in the language of `--lang` is always made. An entry in another language is made when the record has a title, excerpt, text or field in it; it sits beside the first (`slug.en.md`, `/en/routes/slug`), shares its terms, place, pictures and files, and both carry the same `translation_id` (made from the old address, so it is the same on every run). An English entry without an English title takes the Greek one and is listed.

### Duplicate terms

Old sites collect the same term twice. Before anything is written:

1. Each line of `term_map` merges a term into another, by source id. A chain (a into b, b into c) ends at its last term; a line that names a term the site does not have, or that makes a circle, does nothing and is listed.
2. With `merge_same_names`, two terms under the same parent whose names differ only in case, accents or `ς`/`σ` are one; the one with more entries stays (the lower id when they have as many). Terms under different parents stay apart; a line merges them.
3. With `skip_empty_terms`, empty terms without a twin are left out.

Every merge is printed with its reason; one made by rule 2 is printed with the `term_map` line that pins it, because which of two terms has more entries can change between runs. An entry filed under a merged term is filed under the term it went into, and the old address of the merged term (in each language) leads there in `redirects.txt`. Terms an earlier import brought (the same `imported_from`) get the names and descriptions they have no text for yet; a name typed here is never replaced.

### The report

The dry run prints, and `--apply` also writes to `report.txt` beside `redirects.txt`: the terms merged and why, the `term_map` lines that did nothing, the terms left out, the entries without a place or with only one coordinate, the entries without a translation (or without a translated title), the ACF keys the profile does not name, values that were left out (a number field with no number in it, a file the old library does not have), and, after `--apply`, the route files that could not be read and the entries that pointed at them. The dry run counts the route files it would fetch; it fetches nothing, so a route file is checked only when it arrives.

## What it does not do (yet)

- Widgets, forms, comments and theme settings: the API does not give them without a sign-in.
- Page-builder content (Elementor, WPBakery, Divi): shortcodes and builder markup come over as text or are dropped; look at the report.
- Embedded frames from sites other than the few known video and audio hosts are left out and listed.
- Pictures hosted on other sites stay where they are unless `--fetch-external` is given.
- Hierarchical pages (a page under a page): every page is a page of its own at the top.
- Custom types read from the pages (`types`) bring their dates and fields only through `types.<name>.fields`, and their categories only with `categories_of_custom_types`. Custom types of the REST API (`rest_types`) bring their dates and terms.
- Relationships between entries (an ACF relationship or post object field) are not resolved: such a key is listed in the report as one the profile does not name.
- ACF groups are not brought over, and a repeater only with `rows`; a field that holds one without it is left out and listed.
