# Moving a WordPress site over

`scripts/import-wordpress.php` (it is in the repository, not in the update package: run it from a copy of the repository, `git clone`, pointing `--content` and `--uploads` at the site) reads a WordPress site through its public REST API (`/wp-json/wp/v2`, no password) and brings its pages, posts, categories, tags and pictures into a GnozioCMS site. It says what it would do first and changes nothing until you add `--apply`.

```bash
php scripts/import-wordpress.php https://example.com            # what would happen
php scripts/import-wordpress.php https://example.com --apply    # do it
```

Options: `--profile=FILE`, `--menus`, `--custom=PATH`, `--lang=en` (language of the content), `--default-lang=en` (the site's default language; when it differs, the content lives under `/en/`), `--home=index` (file name of the home page), `--kinds=pages,posts`, `--only-used-media`, `--overwrite`, `--content=PATH`, `--uploads=PATH`, `--out=PATH`. Run it against a copy first and look through the result in the admin.

## What comes over

| On the old site | Here |
|---|---|
| Pages | Pages. The page WordPress shows as the home page becomes the home page file. |
| Posts | Posts, with date, category and tag terms, the featured picture as the main image, and an excerpt only when the author wrote one (the cut WordPress makes by itself is left out). |
| Categories and tags | Terms. The category tree is kept: a category keeps its name and its parent, and its address is the one it had (`/category/news/child`). A name used under several parents ("Logicomix" under News and under Reviews) stays "Logicomix" under each; the terms are told apart by their parent (their ids get a number, `logicomix` and `logicomix-2`, the same on every run). A post is filed under the categories it has, and the page of a parent also lists what is filed under its children, as WordPress shows it. Tags have no tree. |
| The media library | Files in the library with their alternative text, and every picture or file the text uses. A size WordPress made (`photo-300x200.jpg`) is the picture itself. |
| The text (HTML) | Markdown: paragraphs, headings, lists, quotes, links, pictures, simple tables and code. Styles, Word's classes, float classes and empty paragraphs are dropped; a table used to place a picture beside text is taken apart; video embeds (YouTube, Vimeo, SoundCloud and a few more, over https) stay as a small piece of HTML. |
| Links between pages | Rewritten to the new addresses. |

## Old addresses

The run writes `redirects.txt` (default `storage/import/`): one line per old address that is not the same on the new site (a post at `/2020/05/slug/` is now at `/slug`, a picture at `/wp-content/uploads/...` is in the library). Paste it into **Admin → Redirects → Import** on the site that will serve them. An old address that is a page of the new site is never redirected.

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

## What it does not do (yet)

- Widgets, forms, comments and theme settings: the API does not give them without a sign-in.
- Page-builder content (Elementor, WPBakery, Divi): shortcodes and builder markup come over as text or are dropped; look at the report.
- Embedded frames from sites other than the few known video and audio hosts are left out and listed.
- Pictures hosted on other sites stay where they are.
- Hierarchical pages (a page under a page): every page is a page of its own at the top.
- Dates, authors and categories of custom types are not read; a theme that shows them needs `types` rules for them (not built yet).
