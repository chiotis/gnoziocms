# SEO

Admin > **SEO** (Manage) holds what is the same for the whole site; each page, post and project keeps its own SEO tab for what is special to it. A page's own choice always wins over the site's.

Everything is stored under `seo` in the site settings, and every value has a default, so a site that never opens the screen behaves as it always did. A tab saves only its own settings. The screen needs the `seo.manage` capability (administrators; a custom role can be given it).

## The tabs

| Tab | What it holds |
|---|---|
| **Overview** | The checks of the site (open to search engines, site address and https, tagline, sitemap, default share image, verification, addresses not found) and of every published entry in every language: missing description, a description that is too short or too long, a title that is too long, a title or description that two entries share, no picture for a shared link, and what is kept out of search. Each finding names the entry and links to its editor. |
| **Search appearance** | The title separator and format (`{title}`, `{site}`, `{tagline}`, `{sep}`), the home page's title and description, the default description, a title format, "No index" and "Sitemap" for each content type, and whether the search results and the category and tag pages are kept out of search. A live preview draws the result. |
| **Social** | The default share image (used by a page that has none, then the one of Theme > Branding), the card on X, the X account (`twitter:site`) and the Facebook app ID, with a preview of a shared link; and the **Preferred source on Google** button (below). |
| **Crawling & sitemap** | Ask search engines to stay out of the whole site, allow large previews (`max-image-preview`), the paths of robots.txt, blocking the AI crawlers, the sitemap: on or off, the main picture of each entry, the pages of the terms, and the addresses to leave out; and the feed: on or off, and how many entries it holds. |
| **Identity & schema** | Whether the site is an organization, a local business (with its address and price range) or a person, its name, other name and description; the type of a post (blog posting, article, news article); breadcrumbs and the site search in the structured data. The logo, phone, email and profiles come from the theme. |
| **Verification** | The ownership codes of Google, Bing, Yandex, Pinterest, Baidu and Facebook. A whole meta tag can be pasted: its code is taken. |

## How a page gets its title, description and robots tag

* **Title**: the "title written for search engines" in the page's SEO tab, as it is; the home page's own title; otherwise the page's title in the format of its content type, or the site's (`{title} | {site}` by default). A word that has no value is dropped with the separator beside it.
* **Description**: the page's own, the home page's (for the home page), the summary (excerpt), what the template gives (a list, the search), the default description, the tagline.
* **Robots**: `noindex, nofollow` on every page of a site that asked to stay out; `noindex, follow` for a page with "No index", a content type that is kept out, the search results or the term pages when chosen; `max-image-preview:large, max-snippet:-1, max-video-preview:-1` for the rest (unless large previews are off).
* **Share image**: the page's own, its main image, the first picture of its blocks, the default of this screen, the one of Theme > Branding.

## The feed

`/feed` is an RSS 2.0 feed of the newest posts of the site, `/en/feed` the same in another language, `/projects/feed` the entries of any other content type, and `/category/news/feed` (or `/category/books/english/feed` for a child, `/tag/…`, or the term of a taxonomy of the site's own; a parent's feed also carries its children's entries unless its archive says not to) the entries of every type filed under a term. Each item has its title, address, date, its categories and its excerpt, or the start of its text (without the code of blocks). Drafts and hidden entries are never listed; pages and forms have no feed. Every page announces the site's feed in its head (`<link rel="alternate" type="application/rss+xml">`). The feed holds 10 to 50 entries (Crawling & sitemap); it can be switched off, answers 404 then, and a site that asked to stay out of search has none. A page or an entry called "feed" keeps its address.

A list that WordPress paged as `/category/news/page/2/` is sent for good to `/category/news?page=2` (the first page to the list itself), so old links and search results keep working.

## Preferred source on Google

Google lets a reader who is signed in pick sites as *preferred sources*: their articles then show up more often for that reader in Top Stories. The button is a plain link to `https://www.google.com/preferences/source?q=<domain>`, where the reader confirms; no script of Google's is loaded. Google takes only whole domains and subdomains, so the domain is the host of the site's address (Settings) without `www.`, and a site in a folder of a domain, or with no address or a local one, gets no button.

SEO > Social turns it on (off by default) and chooses its places: **after the article** (a card with a line of text, on by default), **above the title** (a small chip in the line with the author and date) and the **footer** (a quiet link on every page, on by default). The first two show on the content types chosen there (Posts when none is). The **Preferred source on Google** block puts it anywhere else, an About or "Follow us" page, as a card or a button alone, whether the setting is on or off. The texts are the theme's (`preferred.*` in `lang/`), in the language of the page.

## The sitemap

`/sitemap.xml` lists the published entries and the term pages that have entries, in every language, each with its last change and, when chosen, its main picture. Left out: drafts and hidden entries, entries with "No index", content types that are kept out or taken out of the sitemap, the addresses listed in "Leave out" (a rule matches the start of an address, with or without the language it starts with, and understands `*` and a closing `$`), and everything on a site that asked to stay out of search. A sitemap that is switched off answers 404 and robots.txt does not name it.

## For theme developers

The default theme's `layouts/base.twig` uses these Twig functions; a theme with its own base layout can use them to follow the screen:

| Function | Gives |
|---|---|
| `seo_title(own, page, is_home, type)` | The title of the page. |
| `seo_description(own, excerpt, document, is_home)` | The description. |
| `seo_robots(page_noindex, kind)` | The content of the robots meta tag, or an empty string. `kind` is `search`, `taxonomy`, or a content type. A template sets `seo_kind` for the kinds that are not an entry. |
| `seo_head()` | The meta tags that are the same on every page (ownership codes, `fb:app_id`, `twitter:site`). Safe HTML. |
| `seo_share_image()` | The default share image of the screen. |
| `preferred_source(place, type)` | The address of Google's preferred source page when the button goes in `place` (`after`, `header`, `footer`) for an entry of `type`, or an empty string. `components/preferred-source.twig` draws it (`style`: card, button, chip, link). |
| `preferred_source_url()` | The same address whatever the setting, for a template that places the button itself. |
| `seo_twitter_card(has_image)` | The value of `twitter:card`. |
