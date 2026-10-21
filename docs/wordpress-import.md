# Moving a WordPress site over

`scripts/import-wordpress.php` (it is in the repository, not in the update package: run it from a copy of the repository, `git clone`, pointing `--content` and `--uploads` at the site) reads a WordPress site through its public REST API (`/wp-json/wp/v2`, no password) and brings its pages, posts, categories, tags and pictures into a GnozioCMS site. It says what it would do first and changes nothing until you add `--apply`.

```bash
php scripts/import-wordpress.php https://example.com            # what would happen
php scripts/import-wordpress.php https://example.com --apply    # do it
```

Options: `--redirects=apply` and `--storage=PATH` (see *Old addresses*), `--profile=FILE`, `--menus`, `--custom=PATH`, `--lang=en` (language of the content), `--default-lang=en` (the site's default language; when it differs, the content lives under `/en/`), `--home=index` (file name of the home page), `--kinds=pages,posts,types` (`types` are the profile's custom types of the REST API, below), `--only-used-media`, `--overwrite`, `--content=PATH`, `--uploads=PATH`, `--out=PATH`. Run it against a copy first and look through the result in the admin.

## What comes over

| On the old site | Here |
|---|---|
| Pages | Pages. The page WordPress shows as the home page becomes the home page file. |
| Posts | Posts, with date, category and tag terms, the featured picture as the main image, and an excerpt only when the author wrote one (the cut WordPress makes by itself is left out). |
| Categories and tags | Terms. The category tree is kept: a category keeps its name and its parent, and its address is the one it had (`/category/news/child`). A name used under several parents ("Logicomix" under News and under Reviews) stays "Logicomix" under each; the terms are told apart by their parent (their ids get a number, `logicomix` and `logicomix-2`, the same on every run). A post is filed under the categories it has, and the page of a parent also lists what is filed under its children, as WordPress shows it. Tags have no tree. Two terms under one parent whose names differ only in case, accents or final sigma (`ς`/`σ`) are one (see *Duplicate terms*). Each term keeps its source in `imported_from`. |
| The media library | Files in the library with their alternative text, and every picture or file the text uses. A size WordPress made (`photo-300x200.jpg`) is the picture itself. |
| The text (HTML) | Markdown: paragraphs, headings, lists, quotes, links, pictures, simple tables and code. Styles, Word's classes, float classes and empty paragraphs are dropped; a table used to place a picture beside text is taken apart; video embeds (YouTube, Vimeo, SoundCloud and a few more, over https) stay as a small piece of HTML. |
| Links between pages | Rewritten to the new addresses. |

## Old addresses

The run writes `redirects.txt` (default `storage/import/`): one line per old address that is not the same on the new site (a post at `/2020/05/slug/` is now at `/slug`, a picture at `/wp-content/uploads/...` is in the library, a merged term at the term it went into). An old address that is a page of the new site (an entry, or the page of a term) is never redirected. Old addresses with Greek letters are written as letters (`/activity/παρατήρηση-πουλιών`); a visitor's browser asks for them encoded, and both are the same address.

Two ways to put them on the site:

- **`--redirects=apply`** (with `--apply`) puts them straight into the site's Redirects, in the database of the site's storage folder (`--storage=PATH`, by default the `storage` beside `--content`). The dry run with `--redirects=apply` says how many it would add and which it would leave out. Running it again adds nothing: a redirect that is already there with the same target is kept; one an earlier import made (or a list pasted in the admin, both noted *Imported*) is pointed at the new target; one the site made itself (by hand, or when an address changed here) is left alone and listed as `taken`. A line that would loop back, from an admin address, or a second line for the same address (two that differ only in case) is listed and left out.
- **Admin → Redirects → Import**: paste the file (500 lines at a time) on the site that will serve them.

For the terms of a profile with `language_prefixes`, each old address leads to the term's page in its language: the main language's (`/activity/bird-watchin` to `/activity/paratirisi-ptinon`), the same path under the prefix (`/en/activity/bird-watchin`), and the old English address with its own slug, as the old site's English pages give it (`/en/activity/bird-watching`, and those of the terms merged into it), to `/en/activity/paratirisi-ptinon`.

## Running it again

Every id is made from the address it came from, and every file it writes has `imported_from` in its front matter. Running it again updates those files and fetches only the pictures that are not in the library yet. A file of the site that an import did not write (you made it, or edited and removed the line) is left alone unless `--overwrite` is given.

## What the API does not give: profiles

A theme's custom post types ("books", "events", "slides" are often kept out of the REST API), the home page slider and the menus are only in the pages themselves. A **profile** (`--profile=FILE`) tells the importer where to look, with XPath, because every theme draws them differently. `docs/wordpress-profiles/themelio.yaml` is the one for apostolosdoxiadis.com and shows every key:

| Key | What it does |
|---|---|
| `types` | A custom post type: its pages are found in the site's sitemap (`/wp-sitemap-posts-<type>-1.xml`); XPath for the `title`, the cover `image`, the `summary` and the `tabs` (`labels`, `panels`); `content_type` is where they go here, `front` is front matter added to each. A page becomes a Text and image block (cover beside the description) and a Tabs block; a tab with nothing in it is dropped. |
| `types.<name>.summary_in` | `excerpt` puts the description in the page's excerpt (the Books type shows it under the title, beside the cover) instead of a Text and image block. |
| `content_types` | Definitions written to `custom/content-types/` when the site has none for that type. |
| `categories_of_custom_types` | `true` to find which categories each page of those types is in, by reading the archive page of every category (the API does not say). Each page is also put in the categories above its own. |
| `archives` | The old address of a list and the content type that lists it now (`book: books`): links and redirects follow. |
| `slides` | The slides of the home page: where each is, how its picture (a CSS background) and title, text and buttons are found. |
| `menus` | A menu read from the home page (`list` is the XPath of its `<ul>`); written to `content/menus/<key>.yaml` only with `--menus`, because it replaces a menu of that name. |
| `home` | The home page as blocks, with `@slides`, `@image` (the picture its text begins with) and `@body` filled in. Without it the home page is its text. |

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
| `rest_types.<name>.fields` | ACF key → where its value goes, with the key spelled exactly as the API returns it (`υπότιτλος_eν`, with a Greek ν, stays so). `title`, `excerpt` and `body` are the entry's own; any other name is a field of the type. `@el`, `@en` says which language's entry it belongs to (`title_en: title@en`); without it the value goes to every language. `{to: distance_km, type: number}` takes the number out of a text (`12,5 χλμ.` gives 12.5). A text loses its markup but keeps its line breaks; a field that takes several values comes as one text with commas. |
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
- Pictures hosted on other sites stay where they are.
- Hierarchical pages (a page under a page): every page is a page of its own at the top.
- Dates, authors and categories of custom types read from the pages (`types`) are not read; a theme that shows them needs `types` rules for them (not built yet). Custom types of the REST API (`rest_types`) bring their dates and terms.
- Relationships between entries (an ACF relationship or post object field) are not resolved: such a key is listed in the report as one the profile does not name.
- ACF repeaters and groups are not brought into repeater fields; a field that holds one is left out and listed.
