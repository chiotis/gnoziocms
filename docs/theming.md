# Theming

GnozioCMS has one all-purpose theme, `themes/default`. It grows over time: new blocks, header and footer variants, and page templates are added to it, and every site picks what it needs through settings. Sites are not meant to switch themes.

For step-by-step recipes (a new block, a page template, a brand colour, a content type) and the testing checklist, see [theme-developer-guide.md](theme-developer-guide.md). This page is the reference.

Two rules follow from that:

1. **Updates must never break a site.** The theme is replaced on every update, so anything a site stores or overrides has to keep working with newer theme versions.
2. **Site-specific work lives in `custom/`**, which updates never touch.

## Layers

| Layer | Where | Status |
|-------|-------|--------|
| Theme contract | `themes/default/theme.yaml` | Done |
| Design system: tokens, palettes, dark mode, fonts, corner shapes | `assets/css/site.css` | Done |
| Content blocks per page or post | `themes/default/blocks/<block>/` + `blocks:` in front matter | 23 blocks in three families; admin block editor |
| Site-level component variants (header, footer) | `components/`, Theme settings | Header: 7 layouts, floating or full width, height, edge, background, menu link style, transparent (per content type and per entry), sticky modes (including smart), top bar, CTA, search, phone bottom bar with its own list of icon links, 5 phone menu styles. Footer: 5 layouts, 4 backgrounds, a call to action band, bottom links, language switcher. Both are cards with a picture of the result in Theme |
| Page templates (standard, landing, with sidebar) | `templates/`, `page_templates` in the manifest | Done |
| Points, routes, businesses and maps | `content-types/`, `blocks/map/`, `components/place-single.twig`, `assets/vendor/leaflet/` | Done (docs/places-and-routes.md) |
| Ready-made sections and page layouts | `presets/`, `custom/presets/` | Done |
| Field definitions and archive settings per content type | `themes/default/content-types/`, `custom/content-types/`, Admin > Content types | Done |

## Folder layout

```text
themes/default/
├── theme.yaml            manifest: label, version, menu locations, settings schema
├── layouts/base.twig     HTML shell; blocks: head, header, content, footer, scripts
├── templates/            one template per page kind (hierarchy below)
├── components/           header, footer, card, form, menu macros, block wrapper, ui macros
├── blocks/<type>/        block.yaml (fields), block.twig (markup), block.css (styles)
├── content-types/<type>.yaml  fields and archive settings of a content type
├── single-templates/<type>.yaml  the blocks a type starts from when its page is built with blocks
├── icons/<name>.svg      icon set used by blocks (icon('name') in Twig)
├── assets/css, assets/js served at /_themes/default/<path>
└── lang/<lang>.php       shipped strings

custom/                   site-specific, never touched by updates
├── assets/css/custom.css loaded after the theme CSS when present; Admin > Theme > Custom CSS edits it as a text area
├── assets/js/custom.js   loaded after the theme JS when present
├── page-templates.yaml   the site's own page templates (each needs custom/templates/<name>.twig)
├── lang/<lang>.yaml      string overrides (written by Admin > Translations)
├── blocks/<type>/        new block types, or block.css added after a theme block's styles
├── content-types/<type>.yaml  fields and archive settings of a type (written by Admin > Content types)
├── single-templates/<type>.yaml  the starting template of a type, over the theme's
├── icons/<name>.svg      extra icons (or replacements for theme icons)
└── templates/, components/, layouts/   overrides by relative path
```

## Template lookup

Twig searches `custom/` first, then `themes/default/`, by the same relative path. The theme itself is also available as the `@theme` namespace, so an override can extend the original and change one block:

```twig
{% extends '@theme/templates/single-post.twig' %}
{% block content %}
  {{ parent() }}
  <aside>…</aside>
{% endblock %}
```

Prefer this to copying a whole file: a copied file stops receiving theme fixes.

Template hierarchy (first existing file wins):

| Page | Candidates |
|------|------------|
| Home page | `templates/home.twig` |
| Single item | front matter `template:` → `templates/single-<type singular>.twig` → `templates/single.twig` → `templates/<type>.twig` → `templates/page.twig` / `templates/post.twig` |
| Type archive | `templates/archive-<type>.twig` → `templates/archive-<singular>.twig` → `templates/<type>_archive.twig` → `templates/archive.twig` |
| Taxonomy term | `templates/archive-<taxonomy>-<term>.twig` → `templates/archive-<taxonomy>.twig` → `templates/archive.twig` |
| Search | `templates/search.twig` |
| Not found | `templates/404.twig` |

Front matter `template:` accepts `landing`, `landing.twig`, or `templates/landing.twig`.

## Settings schema

`theme.yaml` declares the settings sections and fields. The admin Theme tab is generated from it, and stored values are checked against it on every request:

- a missing value takes the field default, so new fields need no migration;
- an invalid stored value (unknown option, out-of-range number, unsafe colour or URL) falls back to the default;
- an invalid submitted value keeps the current value;
- keys the schema does not declare are kept, so hand-added options still reach templates.

Field types: `text`, `textarea`, `email`, `url`, `image`, `video` (a video address: the media library's videos, a YouTube or Vimeo link, or a file elsewhere), `file` (a file to download: the media library's files of any kind, or a file elsewhere), `color` (`hex: true` accepts only `#rgb` or `#rrggbb`, stored as `#rrggbb`), `number` (`min`, `max`; `blank: true` lets it be empty, meaning "not set"; `units: other_field` with `ranges: {'%': [10, 100], px: [40, 2000]}` keeps it inside the range of the unit chosen in that select field, and 100 % is stored as empty; `presets: [25, 50]` adds one-click chips in the block editor, and `needs_size: size_field` on any field shows it only while that size is narrower than its column), `decimal`, `select` (`options`), `toggle`. Common keys: `label`, `help`, `placeholder`, `default`, `span: full`, `hidden: true`, and `when: {other_field: value}` (or a list of values), which makes the block editor show the field only while the other has one of those values.

Colours accept `#hex`, `rgb()/hsl()`, `var(--token)`, or a colour name. URLs and images reject quotes, brackets, whitespace, and non-http schemes. Both end up in inline styles, which is why they are strict.

Templates read settings as `theme_settings.<section>.<field>`.

## Compatibility rules for theme changes

These rules keep existing sites working after an update:

- Add fields, options, variants, and blocks freely, always with a default that reproduces the current look.
- Never rename or remove a field, option value, block type, or block field that sites may have stored. Retire a field with `hidden: true`; keep rendering old option values.
- Keep template, component, and block names stable. `custom/` overrides refer to them by path.
- Keep translation keys stable. Sites override them in `custom/lang/`.
- When a real break is unavoidable, ship a migration that rewrites stored data, and note it in `CHANGELOG.md`.

## Blocks

A page, post, or any content item can list blocks in its front matter. Without `blocks:` the item renders as before.

```yaml
blocks:
  - type: hero
    variant: split          # layout, from the block's variants
    tone: default           # default | muted | contrast | accent (background)
    spacing: default        # default | compact | spacious | none
    width: 50               # 25 | 33 | 50 | 66 | 75; left out for the whole width (100)
    anchor: intro           # optional id for in-page links (#intro)
    heading: Websites that work as hard as you do.
    actions:
      - { label: Start a project, url: contact }
  - type: content           # where the item's Markdown body appears
  - type: faq
    hidden: true            # kept, not shown
```

- The first block may be a `hero`; it then renders the page's `<h1>` and the template skips its own title header. All other block headings start at `<h2>`, and their items at `<h3>`.
- If the body has text and no `content` block places it, it appears right after an opening hero (or first).
- Unknown block types and invalid values are ignored; fields take their defaults.
- Links (`type: link`): a bare path (`contact`) is relative to the current language (`/en/contact` on English pages); `/path` is from the site root; full URLs, `#anchor`, `mailto:`, and `tel:` are used as given.

First block family:

| Block | Variants | Notes |
|-------|----------|-------|
| `hero` | split, centered, cover, steps, minimal | Up to 2 buttons and 3 highlights. Opening hero images load first (`fetchpriority=high`). The `steps` layout is the cover layout with up to four numbered steps (title and text) under the buttons (each can link somewhere), like Features > Numbered steps. The picture can be a **video** instead of an image (Picture > Video): a file from the media library (`.mp4`, `.webm`), a file on another site, or a YouTube or Vimeo link. It plays silently, in a loop, behind the text of the cover and steps layouts and in the frame of the split and centered ones, with a pause button, a still image under it (and when a visitor's device asks for less motion or less data, where it starts only on request), and it rests while off screen. YouTube and Vimeo are put in a frame by the page's script after it has loaded (YouTube through youtube-nocookie.com, Vimeo in its background mode), so a page asks nothing of them until then; a video file is a plain `<video>`. |
| `content` | narrow, wide | The item's Markdown body. |
| `text` | default, split, lead, contents | Markdown text with optional buttons. `contents` puts a list of links made from the text's `##` headings beside it (headings get addresses), for terms, privacy pages and long guides; with fewer than two headings there is no list. |
| `text-image` | image-right, image-left, image-top, image-bottom | Bullet lists show check marks. Image shape: landscape, wide (16:9), portrait, square. With the image above or below the text, the picture runs across the whole width (no taller than 34rem) and the text keeps a readable measure. |
| `features` | cards, plain, numbered | Icon, title, text, and link per item; 2–4 columns. Services use the same block. |
| `stats` | row, cards | Numbers in a `<dl>`. |
| `testimonials` | grid, featured | `<figure>`/`<blockquote>`; initials when there is no photo. |
| `logos` | row, grid | Image logos or text wordmarks, spread over the whole width of the content however many there are (one row up to the most that fit; more in rows of the same length). `size`: the height of the logos, small (2.5 rem), medium (4 rem, the default), large or extra large; the taller they are the fewer fit in a row (7, 5, 4 or 3), and on a phone the heights are smaller. `colors`: black and white with colour on hover (the default), always black and white, or their own colours. |
| `columns` | image-side, image-top | Two or three columns side by side, each with a label, a heading (a link when it has an address), a picture, text and a link; stacks on a phone. |
| `faq` | stacked, split | Native `<details>`; adds `FAQPage` structured data. The page can open with all answers closed (default) or the first one open (`initial: first`, the same choice as the Accordion; the old `open_first: true` still works). |
| `accordion` | (one layout) | Compact `<details>` items for narrow columns, with titles one small step above the body text; no structured data (use `faq` for a page of questions). Options in the Design tab: several open at once (default on; off adds a small script and a shared `name`), chevron or plus/minus icon, lines or separate cards. The page can open with the first item open. The height change is animated by a small script and skipped when a visitor asks for less motion. |
| `cta` | band, card, split | Default tone: accent. |
| `buttons` | left, centered | A row of buttons and nothing else, up to twelve (label, link, style). Typed, or bound to a list of the entry in a template built with blocks (the buy links of a book). |
| `cards` | grid, list | Latest items of a content type, or hand-written cards. |
| `form` | split, stacked | Any form from Admin > Forms. |
| `gallery` | grid, masonry, strip | Opens images in an accessible viewer (`<dialog>`, arrow keys, Escape); without JavaScript the images are plain links. |
| `team` | grid, compact | Photo or initials, role, bio, email and LinkedIn links. |
| `timeline` | vertical, alternating, steps | Ordered list of dated entries. |
| `contact` | split, cards | Contact details with icons and links, optionally beside a form. |
| `map` | contained, full, split | OpenStreetMap embed with marker, no API key. By default it loads only after the visitor clicks "Show map" (no third-party request on page load); links to OpenStreetMap and directions always work. With **Show** set to a content type (or everything with a place) it is a map of those entries with filters, search, a list and optional grouping of close markers (see `docs/places-and-routes.md`). |

Third block family (dynamic and interactive):

| Block | Variants | Notes |
|-------|----------|-------|
| `latest` | cards, list, compact, overlay, strip, featured, magazine, editorial | The newest posts, projects, or any content type, from a quiet text list to a magazine layout. Options: content type, an optional category or tag (`term`), number of items, columns, and toggles for images, excerpt, and date/category. It updates by itself, and renders nothing when there is nothing to show. `cards` (above) stays for hand-written cards. |
| `video` | featured, grid, split | YouTube, Vimeo, or a direct `.mp4`/`.webm` file. By default a video opens in a large viewer (`<dialog>`, Escape or backdrop to close, focus returns to the play button); `play: inline` plays in place. Nothing is requested from YouTube or Vimeo until the visitor plays (YouTube through `youtube-nocookie.com`, Vimeo with do-not-track), and no thumbnail is fetched, so give videos a preview image. Without JavaScript the play button is a link to the video's own page. Only YouTube, Vimeo, and video files are accepted. |
| `playlist` | grid, list, featured, strip | The videos of a YouTube playlist, drawn by the site from the playlist's public feed (15 newest videos) or, with a key in Settings > APIs, the YouTube Data API (up to 50, with lengths). The editor chooses the layout (grid with 2 to 4 columns, list with descriptions, a player with the list beside it, a scrolling strip), the order, how many, what to show (description, length, views, date, numbers), the picture shape, how a video plays (viewer, in place, or on YouTube), and a link to the playlist. Pictures are kept on this site by default. It shares the picture, play button and viewer of `video`. See `docs/youtube-playlist.md`. |
| `hero-slider` | default | Slides that each are a Hero (it replaces the Slider block of before 0.1.110: a page saved with that block is shown and saved as a Hero slider): every slide picks its own hero layout (split, centered, cover, centered cover, steps, centered steps, minimal) and has everything a hero has - eyebrow, heading, text, up to three buttons with a style (a list, `buttons`; a slide saved with the two fixed buttons of before, `label`/`url`/`style` and `label_2`/`url_2`/`style_2`, is read as that list and saved as it on the next save), three highlights, up to four steps, and an image or a video behind the text. The markup and styles are the hero's (`components/hero-content.twig`, `blocks/hero`), so a slide looks as the hero does on a phone too; the slider's script adds previous/next buttons, dots and arrow keys, and optional automatic movement (off by default, with a pause button). Arrows and dots go below or over the slides. When the block opens the page, the header can sit over it like over an opening hero (transparent header), and it turns light or dark with the slide that shows. |
| `compare` | lines, striped | Comparison table: up to four columns (one can be highlighted, with a badge and a button) and up to 30 rows, some of them group headings. A cell that says `yes` or `no` (`ναι`, `όχι`) shows a check or a cross with text for screen readers; anything else is text. A real `<table>` in a focusable, named, scrolling region. |
| `before-after` | slider, side | Two pictures of one subject. `slider` lays them over each other with a handle (a native range input, so mouse, touch, and arrow keys work; without JavaScript they sit side by side). `side` shows them side by side. Labels, shape (4:3, 16:9, 4:5, square), and a caption are editable. |
| `pricing` | cards, list | Plans with badge, price, period, an "Included" list (one per line), and a button; one plan can be highlighted. `list` suits price lists. **Monthly / yearly switch** (`billing_switch`): each plan can have a `yearly_price` and `yearly_period` ("per year" when empty); the switch, with an optional note such as "Save 20%" on the yearly button, is shown by the page's script, and without JavaScript a plan shows both prices. A plan with no yearly price is the same either way. |
| `tabs` | horizontal, vertical | WAI-ARIA tabs (arrow keys, Home, End). Without JavaScript every panel is shown one after another. |
| `banner` | strip, callout | An announcement with an icon and a link. It can be dismissible; the choice is remembered in the visitor's browser for 30 days and resets when the text changes. |
| `image` | wide, narrow, full | One picture with a caption and an optional link (which can open in a new tab). The shape is Original, 16:9, 4:3, square, 4:5 or 21:9, with rounded corners or not; `full` runs edge to edge with the caption inside the page's margins. A block with no picture shows nothing. *Picture width* (Design tab) makes the picture narrower than its column: a percentage of the column (chips for 25 / 33 / 50 / 66 / 75 / 100 % or any value from 10 to 100) or a fixed size in pixels (40 to 2000, never wider than the column); the height follows, so nothing is cropped. Empty or 100 % is the whole column, as before, and is not written. A narrower picture is placed left, centered (the default) or right, the caption follows its width and position, and *Full width on a phone* (on by default) fills the column on a screen up to 640px. Stored as `image_width`, `image_width_unit` (`%` or `px`), `image_align` and `image_full_mobile`; the picture's `sizes` follow the width so a small picture does not load a large file. |
| `divider` | space, line, label | Space between sections (small to extra large), a rule (solid, dashed or dotted; page, text or short width) or a rule with a label. A space is hidden from screen readers. |
| `quote` | centered, bar, photo | One large quotation with a name, a role and (in `photo`) a picture. `<figure>` with a `<blockquote>`; the quotation mark is decoration. |
| `downloads` | list, cards | Documents to download. A row is a file picked from the media library (the **file** field), a title (the file name when empty), a short description, and a size (read from the file when it is one of the site's uploads, or typed). The kind of file is its extension. A file on another site opens in a new tab. A row with no file is hidden from visitors; someone who is signed in sees it dashed, saying why (so a ready-made page can be filled in). |
| `checklist` | plain, cards, split | Points marked with a tick or any icon of the set, each with an optional detail line; a point can be **not included** (a cross, muted, struck through, and announced as such). 1 to 3 columns. |
| `portfolio` | grid, overlay | A grid of work with **filter buttons** for the categories. Type the pieces in (title, categories separated by commas, picture, text, link) or show the entries of a content type, filtered by the categories they are filed under. The buttons are made of the categories in the order they first appear, are shown by the page's script, and do nothing to a page without it (everything shows). One category, or the switch off, means no buttons. |
| `table` | lines, striped, boxed | A data table. Column titles in one line, rows one to a line, cells divided by `\|` or by a tab (a range copied from a spreadsheet can be pasted as it is). `**bold**` and `[links](/address)` work in a cell, other markup is shown as text. A column that holds only figures (money, percentages, signs) is aligned to the right. Up to 100 rows and 12 columns; wide tables scroll inside a frame that the keyboard can reach. |
| `marquee` | text, logos | A band that scrolls by itself: big words with a dot between them, or logos (their names when there is no image). Slow, normal or fast, to the left or the right. It runs on CSS alone, stops while the mouse or the keyboard is on it, has a pause button, and for visitors who ask for less motion it is still and wraps. |

The blocks showcase page (`/blocks`, hidden, admins only) shows every block and variant.

### Block widths and rows

Every block has a **Width** in its Design tab (25, 33, 50, 66, 75 or 100%, stored as `width: 25|33|50|66|75`; 100 is the default and is never written, so content without widths renders exactly as before). It is one of the settings every block gets from `BlockRegistry`, not from `block.yaml`.

Blocks under 100% that follow each other are gathered by `BlockRenderer` into one `<div class="block-row space-…">` (plus `block-row--plain` when none has a background tone) and each section gets `block--w-25|33|50|66|75`. A 100% block, a hidden block excepted, ends the row. The row is a twelve-column grid as wide as a container (so it lines up with the standard, landing and sidebar templates) with the `--space-l` gap; a block takes 3, 4, 6, 8 or 9 columns (so a third is an exact third of the row minus the gaps) and wraps when the row is full. The row carries the vertical space of **one** section (the spacing of its first block); the blocks inside add none. Phone (up to 640px): every block is full width. Tablet (up to 960px): 25% and 33% become 50%, 66% and 75% become 100%. The block that opens the page (a hero or hero slider) is always full width, because the header, the title area and the page's `<h1>` belong to it. Heading levels do not change.

The styles are `assets/css/block-row.css` of the theme. They are added to the page's block stylesheet (`_blocks.css?b=…,_row`) **only when the page has a narrow block**, and there is no JavaScript. A theme that has no such file simply shows the blocks one under another.

A narrow block cannot use the layouts made for a full row, so `block-row.css` has a small set of rules for narrow blocks (a quarter, a third or a half of the row, and both on a tablet): side-by-side layouts (Text split, contents and image beside text, FAQ/Form/Map split, Hero split, Tabs vertical, Latest featured and editorial, Pricing list, Quote with photo, Video split, Playlist featured) and grids of items (Features, Gallery, Team, Latest, Cards, Video, Testimonials, Pricing) fall back to one column, or two in a half row on a desktop; the large headings come down a step; Logos show one per row in a quarter. A block with a background of its own (a tone other than default, a cover Hero, a Hero slider) becomes a **contained card** in its column (rounded, with padding), and a cover is only as tall as its content (minimum 24rem), not as tall as the screen. Blocks that already fit their column by width (Stats, Checklist, Downloads, Portfolio, Timeline steps) are left to adapt. When you write a block with a grid of its own that has fixed columns, add a rule for it in `block-row.css` (or your own `custom/` override of that file); in the CSS the narrow blocks are `.block-row > .block--w-25` and `.block--w-50`.

A block that had a field called `width` of its own (the Divider's line) names the new field in `block.yaml` (`width_moved_to: line_width`): a stored `width` that is not 25/33/50/66/75/100 is read as that field, so old content renders as before.

`scripts/theme-audit.js` has `themeAudit.rows(paths)` to check a page with rows at desktop, tablet and phone widths.

### Containers (rows with columns)

A **container** is an entry of `blocks:` that is not a block: a section with one to six columns, and blocks inside each column. It is what makes a layout the Width of a block cannot (a text beside a picture beside a form, a 25 / 50 / 25 row, a hero-like band with a picture behind the columns). Stored in the front matter, backward compatible (a page without one renders exactly as before):

```yaml
blocks:
  - type: container
    settings: {gap: l, justify: center}       # only what differs from the default
    columns:
      - settings: {width: 1/3, padding: m}
        blocks: [ {type: text, heading: One} ]
      - blocks: [ {type: image, image: /uploads/x.jpg}, {type: cta, heading: Go} ]
```

**One level only**: a container inside a column is dropped (stored pages are cleaned the same way, `scripts/check-blocks.php` and the skill's validator name it). `container` is a reserved name: `BlockRegistry` ignores a `blocks/container/` of a theme or of `custom/`. The shape (fields, checking, the classes and variables a column gets) is `src/BlockContainer.php`; the editor, `BlockRenderer`, `HtmlGuard`, the registry's storage check, `scripts/check-blocks.php`, `scripts/theme-audit.js` and the skill know it.

**Settings of the container** (all optional): `direction` (`row`, `row-reverse`), `justify` (justify-content: `start center end between around evenly`), `align` (align-items: `stretch start center end baseline`), `align_content` (align-content), `wrap` (`wrap nowrap wrap-reverse`), `gap` and `row_gap` (the theme's spacing steps `none xs s m l xl`, default `m`; never free sizes), `stack_desktop`, `stack_tablet`, `stack_phone` (how the columns stack on that screen: `row` stays in a row, `two` wraps to halves, `one` stacks to one column; tablet also has `auto`, the default: two columns stay in a row, more wrap to two; phone defaults to `one`), `reverse_phone` (reverse the order when stacked on a phone), `tone` (the section background, as a block's), `image` and `overlay` (`none light dark`; a picture behind the columns, decorative, with a dark overlay the section takes the `contrast` tone so the text is light), `inner` (`container`, `narrow`, `full` for the width of the content), `spacing` (as a block's), `min_height` (`none s m l screen`: 15, 25, 35 rem or the screen), `anchor`, `class` (letters, digits, `-` and `_`) and `hidden`.

**Settings of a column**: `width` (`fill`, the default, an equal share; `auto`, its content; a fraction `1/3`; a percentage `37%`; or a fixed size `20rem`, `320px`), `min_width`, `max_width`, `grow`, `shrink` and `basis` (when one is set the column is `flex: grow shrink basis` and the width is not used), `align_self` (`auto start center end stretch baseline`), `valign` (`top center bottom`: where the blocks sit in a column taller than they are), `order` (-6 to 6), `padding` (a spacing step), `tone` (the column's own background) and `radius` (`none s m l`). The columns of a row add up to exactly the row: the stylesheet takes the gaps off a fraction (`calc(100% * f - gap * (1 - f))`).

**Markup and styles.** `BlockRenderer` draws a container with the theme's `components/block-container.twig` (a `<section class="block block-container bkc tone-… space-…">`, an optional `.bkc-media` picture, `.bkc-inner`, the `.bkc-row` and a `.bkc-col` for each column; the blocks inside are the usual `components/block.twig` sections). Everything is a class (`bkc-j-center`, `bkc-gap-l`, `bkc-d-one`, `bkc-t-two`, `bkc-p-one`, `bkc-col--part`, `bkc-col--pad-m`…) or a custom property set inline and nothing else: `--bkc-f` (a part of the row), `--bkc-w`, `--bkc-min`, `--bkc-max`, `--bkc-grow`, `--bkc-shrink`, `--bkc-basis`, `--bkc-order`. The styles are `assets/css/block-container.css`, added to the page's block stylesheet (`_blocks.css?b=…,_row,_container`) **only when the page has a container**, together with the stylesheets of the block types inside the columns and with `block-row.css`, whose rules for narrow blocks also cover a block in a column (`:is(.block-row, .bkc-col) > .block`). No JavaScript. Stacking is by media query: desktop above 960px, tablet 641 to 960px, phone up to 640px. A theme without `components/block-container.twig` shows the blocks of the columns one under another: copy the file and the stylesheet from the default theme to give it containers.

**Width inside a column is ignored.** A block in a column takes the room of the column: its own Width is neither shown in the editor there nor used when drawing; instead `BlockRenderer` gives the block the narrow class that fits the column (a quarter, a third or a half: `block--w-25|33|50`, none for a wide column), so the narrow-block rules above apply and a Features grid, a Text split or a Form lay themselves out for the column. The stored `width` of a moved block is kept, so unwrapping a container gives the block its width back. Blocks inside a column have no vertical space of their own and are separated by `--space-l`; a column's blocks are sections, so headings stay `h2` and a block can never be the first block of the page (the page keeps its own title). A `content` block inside a column places the page's text.

**Live preview and the editor.** Each container and each block inside it carries `data-bk-block="<container>.<column>.<block>"` in the preview only, so a click in the preview opens the right card.

`themeAudit.containers(paths)` (`scripts/theme-audit.js`) checks a page with containers at desktop, tablet and phone widths: no column outside the container, a column set to stack to one fills the row, nothing wider than its column, no sideways scrolling.

### Writing a block

Create `blocks/<type>/` with three files:

- `block.yaml`: `label`, `description`, `variants`, optional default `tone` and `spacing`, and `fields` (FieldSchema types plus `markdown`, `link`, `repeater` with `fields`/`min`/`max`, `icon`, and `options_from: content_types | forms`). See *Repeaters* below.
- `preview.svg` (optional): the one-colour wireframe the editor's block picker shows beside the block's name. Draw it on a 64 by 48 grid with `fill="none" stroke="currentColor"` shapes (`rect`, `path`, `circle`, `line`, `polygon`; `fill="currentColor" fill-opacity=".14"` for tinted areas). Only those shapes and a few presentation attributes are kept; a block without one shows a placeholder.
- `variants/<layout>.svg` (optional): a drawing of each layout, in the format of `preview.svg`, shown as tiles in the Design tab where the layout is chosen (without them the layouts are plain buttons). `design_fields: [key, …]` in `block.yaml` moves fields of the block that change how a layout looks (the hero's `align`) to the Design tab, beside the layout; for such a field a drawing named `<layout>-<value>.svg` (`cover-center.svg`) is used while the field has that value. The hero has all of them. `variant_extends: {centred-cover: cover}` makes a layout a variant of another (its section also gets that one's class, so its styles apply), and `variant_migrations` (`when`, `to`, `drop`) moves blocks saved in a layout that has since become another (the hero's old text alignment is now two layouts).
- `block.twig`: receives `block` (checked values; Markdown fields also as `<key>_html`), `heading_tag`, `item_heading_tag`, `block_uid` (use `{{ block_uid }}-title` as the heading id), `block_first`, and the page context. Import `components/ui.twig` for `section_header`, `actions`, and `initials`.
- `block.css`: styles scoped to `.block-<type>`, using the tokens from `site.css`. It is loaded only on pages that use the block, bundled with the other blocks of the page into one request.
- `block.js` (optional): progressive enhancement only; the block must work without it. Loaded deferred and bundled the same way (`/_themes/default/_blocks.js?b=…`).

### Texts in several languages

A theme field of type `text` or `textarea` marked `translatable: true` can have a text for each language of the site. The Header and Footer tabs do this for the footer's summary, copyright, credits, address and hours, and the header's button text, top bar message and bottom bar link labels: while the site has more than one language, language chips appear on the card and every such text has a box for each language. It is stored as a plain text (the site's own language) or a map (`default` and a language code for each translation). In a template, `{{ value|localized }}` gives the text for the page's language, else the default, else the first there is; `components/header.twig` and `components/footer.twig` do it once at the top for their settings, so the rest of the file reads them as before. A text that is not marked stays one text for every language. The words the theme prints itself (Read more, Back to top, the headings of the footer's columns) are in Admin > Translations.

### Footer blocks

**Theme > Footer Blocks** holds blocks that show **above the footer on every page**, with **a set for each language** when the site has several (a language with no set of its own shows the site's own, and the editor offers a copy to start from): a row of logos, a call to action, a form (the footer has no call to action band of its own: use the CTA block). It is the same block editor as an entry's Blocks tab (without the whole-page layouts), and the blocks are stored in the theme settings (`footer_blocks`), not in a content file, as a list or as a map of `default` (the site's own language) and language codes (`src/FooterBlocks.php`). `footer_blocks()` in Twig gives `html`, `styles` and `scripts` for the current language, drawn once per request: `layouts/base.twig` asks for the styles and scripts in the head, and `components/footer.twig` puts the markup in a `.footer-blocks` wrapper before the `<footer>`. A site that overrides either file keeps the blocks by keeping those lines. Someone without the *Raw HTML in content* permission sees HTML they type as text, as in an entry.

### Editing blocks

Admin > Edit > **Blocks** lists the page's blocks: add (from a picker with each block's description), move, duplicate, hide, remove (with undo), and edit the fields generated from `block.yaml`, including repeaters and image fields with the media library. On save the server checks every value again (`BlockRegistry::sanitizeForStorage`) and stores only values that differ from the defaults. Unknown block types are kept unchanged. Without JavaScript the page's existing blocks are kept when it is saved.

## Content types

A content type is a folder in `content/` (`posts`, `projects`, or one you create). A **definition** adds two things: the fields an editor fills in, and how the type's archive page looks. Definitions are optional; a type without one behaves as before.

**The catalogue.** Every definition the theme ships is a ready-made type a site can have (today: posts, projects, books, points, routes, businesses). **Admin → Content types** shows every type as a card (its fields, entries and list layout, who defines it) with a switch for each ready-made type; pages (and forms, which belong to the CMS) are always on. A type that is off is hidden from the admin, the site, the sitemap and search, and its files are kept; switching it on again brings it back. A type counts as on when it is listed in the site settings (`content_types`) or already has files in its folder, unless it was switched off (`content_types_off`); an empty folder does not switch a prebuilt type on. To add a type to the catalogue, add `themes/<theme>/content-types/<type>.yaml` (and `templates/single-<singular>.twig` if its pages need their own layout). Types a site creates itself are not in the catalogue and are always on.

**Points of interest, Routes and Businesses** are ready-made too, for sites about places and tourist routes: a position on every entry, route files (GPX, KML, GeoJSON) with their length, climb and profile of the height, an archive layout that is a **map**, a **Map block** that shows them, and a page for each with a map and what is near. See `docs/places-and-routes.md`.

The **Books** type has the fields author, publisher, year, ISBN, language and buy links (a list of shops, up to six), an archive of cards (A to Z, filtered by category), and its own page layout (`templates/single-book.twig`): the cover beside the title, author, summary (the excerpt), facts and one buy button per shop (the first is the main one), then the text and blocks (Tabs suit reviews and editions).

Definitions live in `themes/default/content-types/<type>.yaml` (shipped with the theme) and `custom/content-types/<type>.yaml` (the site's own, kept across updates). When both exist they are merged: fields are added or changed one by one, and archive settings replace the theme's one by one. **Admin > Content types** edits the site file and writes only what differs from the theme, so theme improvements keep arriving. The editor of a type has a row for each field: a key that never changes, a label, a kind and, for a field you added, a help text and (for a select) its options, with the ways it is used (*Filter*, *Card*, *Page*, *Retired*) as chips. A field you added can be removed (the row is dimmed until you save, and *Undo* brings it back); the values already saved in content are kept.

```yaml
label: { el: Έργα, en: Projects }        # text, or a map per language
singular: { el: Έργο, en: Project }
fields:
  client:  { type: text, label: { el: Πελάτης, en: Client }, card: true }
  sector:
    type: select
    label: { el: Κλάδος, en: Sector }
    filterable: true                       # offered as a filter in the archive
    options: { '': '—', office: { el: Γραφεία, en: Office }, retail: Retail }
  year:    { type: number, label: Year, min: 1990, max: 2100 }
archive:
  layout: cards            # cards, list, compact, overlay, featured, magazine, editorial
  columns: '3'
  per_page: 12             # 0 shows everything on one page
  order: date_desc         # date_desc, date_asc, title_asc, title_desc, random, or field:<key>:asc|desc
  taxonomies: [categories] # taxonomies offered as filters
  show_image: true
  show_excerpt: true
  show_date: true
  show_meta: true          # category and card fields
```

- **Field types:** `text`, `textarea`, `markdown`, `email`, `url`, `link`, `image`, `color`, `number`, `decimal`, `date`, `select`, `toggle`, `repeater` (a list of rows, see *Repeaters* below) and `relation` (a pointer to other entries, see *Relationships* below). Per field: `card: true` shows it on the item's card (a repeater as one line naming its rows), `show: false` hides it on the item's own page, `filterable: true` (select fields only, never a repeater) adds an archive filter, `hidden: true` retires it. Admin > Content types creates every kind but a repeater: its row fields are written in the type's YAML file, and the screen shows it read-only (how it is used can still change).
- **Storage:** values are saved in the item's front matter under `custom_fields`, checked against the definition on every read and write, so a stored value can never push markup into a template. Fields you declare get their own inputs in the editor (a "<Type> details" tab) and no longer appear under free-form Custom Fields.
- **Compatibility:** follow the same rules as theme settings. Never rename a field key, retire it with `hidden: true` instead (stored values are kept), and give a `select` a stable set of option values.
- **On the page:** every single page shows its declared fields as a fact sheet (`components/type-fields.twig`, included by `components/page-body.twig`); a repeater is a list there, each row its fields with their labels, an inner list nested in its row. In templates, `content_fields(item)` returns the fields to print and `content_fields(item, 'card')` those marked for cards (each `{key, label, type, value, display}`; a repeater's entry also has `rows`, a list of rows that are lists of entries of the same shape); `content_type('projects')` returns the definition. `item.meta.custom_fields.<key>` holds the raw stored rows, unchecked.
- **Archive:** `templates/archive.twig` draws the list with the same eight layouts as the Latest content block (`components/entry-list.twig`). Filters are plain links and a `<form method="get">` (no JavaScript): `?filter[sector]=retail&page=2`. Filter values that no item has are never offered. Filtered pages are `noindex`; each page of a paginated listing has its own canonical URL. Category and tag pages use the same layout, with settings of their own per taxonomy (see below). **How a list is answered.** The archive of a type and the page of a term are answered by the content index in SQLite (`ArchiveQuery`): which entries, in what order, which page and how many entries each filter value has are queries, and only the entries on the page are read from their files. The index keeps, for every entry, the date it is ordered by (`sort_date`), the terms it is filed under (`content_terms`, for the site's taxonomies) and the plain values of its fields (`content_values`), so a new filter or order needs no rebuild and a type added later needs no code. It gives the same entries, order, filters and pages as the scan of every entry, which remains as the fallback whenever the index cannot be trusted (no SQLite, a file that is gone, the editor's live preview), so output and templates do not change. The index is disposable; it is made again by itself when a type's fields or the site's taxonomies change, and files edited outside the admin are seen within 30 seconds.
- **Taxonomies of the site's own:** **Admin > Taxonomies > New taxonomy** makes one (a name, an address, optionally the content types its pages list): `content/taxonomies/<name>.yaml`, the same file as categories and tags. Its terms have pages at `/<name>/<term>` (and `/en/<name>/<term>`), drawn by `templates/archive-<name>-<term>.twig` → `archive-<name>.twig` → `archive.twig`, titled "Name: Term", with the same layout settings (Theme > Archive Layouts) and hreflang links, and a term that changes address leaves a redirect behind. `/<name>` alone is an ordinary page address, so a page can be made there. The name cannot be a reserved word, a language, a content type, another taxonomy, the home page or a page; a content type cannot take the name of a taxonomy. Entries keep their terms in the field named like the taxonomy (`project-types: [web]`), the menu editor offers the terms, and the default single templates of posts and projects list them under the title; a template draws them with `item_terms(item, prefix)` (`[{name, title, terms: [{label, url}]}]`, for the taxonomies a site added) or `taxonomy_url('<name>', term, prefix)` and `taxonomy_label(...)`.
- **Taxonomy archives:** each taxonomy (categories, tags) has its own archive settings in `content/taxonomies/<name>.yaml` under `archive:`, edited in Admin > Theme > Archive Layouts. The keys are those of a content type's archive (`layout`, `columns`, `per_page`, `order`, `show_*`, `title`, `subtitle`, `taxonomies` for filters) plus `types: [posts, projects]` for the content types listed (none means all). `{term}` in `title` and `subtitle` is replaced by the term's name. A term can also have a description per language (Admin > Taxonomies): it is shown as the subtitle unless the taxonomy sets one, and templates receive it as `term_description`. Only what differs from the defaults is stored. The templates lookup is unchanged, so `archive-category.twig` or `archive-tag-<term>.twig` can still take over.

  **A tree of terms.** Categories are a tree by default (tags and a site's own taxonomies are not, unless `hierarchical: true` is in the file or *Terms can have a parent* is ticked in Admin > Taxonomies). A term has an optional `parent` (the id of another term). Its address holds its parents' slugs: `/category/books/english`, drawn by `archive-category-<own slug>.twig` → `archive-category.twig` → `archive.twig`. Moving a term or changing a parent's slug leaves redirects behind for the old address of the term and of every term below it, in every language, and updates menu links. The archive card of a tree has a *Sub-terms* chip (`include_children`, on by default): the page, the feed and the language links of a parent then also cover what is filed under its children. Deleting a parent moves its children up a level. The template receives `term_trail`, the parents above the term as `[{label, url}]`, and with *Breadcrumbs* on the trail is Home, the parents, the term.
- **Per-type templates** still work: `custom/templates/archive-<type>.twig` and `single-<type>.twig` win over the defaults.
- **Latest content block:** the block lists any type, so a new type is available in it straight away.

## Repeaters

A `repeater` is a list of rows, each row with the same `fields`. It works in block fields, theme settings and content-type fields:

```yaml
editions:
  type: repeater
  label: Editions
  item_label: Edition        # names a row in the editor ("Edition 2")
  min: 0                     # rows that cannot be removed in the editor (optional)
  max: 6                     # rows kept; more are dropped (optional)
  fields:
    publisher: {type: text}
    year: {type: number}
    buy:                     # a list inside each row
      type: repeater
      item_label: Shop
      max: 4
      fields:
        shop: {type: text, translatable: true}
        url: {type: url}
```

- **Two levels at most.** A row of a top-level repeater may hold a repeater of its own; a repeater inside that one is left out of the definition, and Admin > Content types (for a type) and the skill's `validate_content.py` (for a block) name it. `min` and `max` belong to each list on its own. `inline: true` (one short text per row, edited as a row of boxes) only works for a list of one text, never one that holds a list.
- **Checked like any field.** Stored rows are checked against the fields at both levels before they reach a template: unknown keys dropped, invalid values replaced by the default, empty rows (also a row whose inner rows are all empty) dropped, `max` applied to each list. A translatable text inside a row keeps its text per language.
- **Stored** as YAML lists of maps: in a block, values equal to the default are left out; under `custom_fields`, empty values are left out. Reading fills them in again.
- **Templates** of blocks loop over the rows (`{% for slide in slides %}{% for button in slide.buttons %}`); a Markdown field inside a row, also in an inner row, has its `<key>_html` beside it.
- **CSV export/import** of content puts a repeater in one cell as JSON (`meta.custom_fields.editions`), which reads back to the same rows.
- **Search** reads the text of rows and inner rows like other fields (`searchable: false` works inside rows too).
- **Replacing flat fields (`legacy`).** A content-type repeater can say which flat keys it replaces: `legacy: {url: buy_url, label: buy_label}` (a row field => the old `custom_fields` key). Content saved before the list existed has no rows but holds the old keys, so reading gives the list one row made of them (rows that exist always win), the editor shows it as the first row, and saving the form writes the list and drops the old keys. Keep the old fields declared with `hidden: true` so they stay readable and never appear under free-form Custom Fields. The Books type does this for `buy_url` / `buy_label` → `buy_links` (`label` is the shop or button text, `url` the link); a template reads the checked rows with `content_values(item).buy_links`, which has the old link as one row too.
- **Editors.** The block builder and the forms of content types and theme settings add, remove and reorder rows of either list; an action acts on the nearest list only.

## Relationships

A `relation` field points at other entries: a book's author, a route's points, the editions a book was reprinted from. It is declared in a content type's YAML like any field, so it works for every type, also one that exists only as a file in `custom/content-types`, and it can sit inside a repeater's rows (two levels):

```yaml
author:   { type: relation, types: [authors], label: Author }
team:     { type: relation, types: [authors], multiple: true, max: 5, label: Also by }
editions:
  type: repeater
  item_label: Edition
  fields:
    year: { type: number }
    earlier: { type: relation, types: [books] }   # a relation inside a row
```

- `types` lists the content types the field may point at (none: any that is on). `multiple: true` takes a list in the order the editor chose it, up to `max`.
- **What is stored** is the `translation_id` of the entry pointed at (16 characters, written when the entry is first saved), not its address: `author: 8f3a4c19d20b7e55`, or a list. The id does not change when the entry gets another address, and it is shared by every language of the entry, so a reference shows the entry in the language of the page, else in the site's own. Nothing has to be rewritten when an address changes. An entry written before ids existed gets one, as a single `translation_id` line, when it is first pointed at (the old text is kept in its history).
- **An entry that is a draft, hidden, deleted or of a type that is switched off** is left out for visitors. The reference stays in the file, so restoring or publishing the entry brings it back; the editor still lists it, marked as a draft or missing.
- **Editor.** A relation is chips (the entries chosen, each with its title, a draft or missing mark, a link to open it in a new tab, and, for a field that takes several, buttons to move it earlier or later) and a search box that offers the entries of the allowed types whose title or address contains the text (any case, Greek too), the ones starting with it first; a field at its `max` closes the box. It works the same inside a row of a list. Entries the person may not open are never offered. An entry that has no id yet is sent as `type/slug` and gets its id when the entry is saved; an address typed in a CSV cell is turned into the id the same way.
- **Warnings.** An entry's editor has a *Referenced by* tab when other entries point at it (and says when it is not published, so they do not show it); the page that asks before deleting an entry says who points at it, and a bulk delete or move to draft says how many of the entries were pointed at. A reference to an entry that is gone is listed in **Admin > Broken references** and on the dashboard; restoring the entry from its history brings it back.
- **Fact sheet.** A relation shows the titles as links. In a template: `related(item, 'author')` returns the entries a field points at, each `{id, type, slug, lang, title, excerpt, main_image, date, url, hidden}`; for an id kept in a row of a list use `related(row.earlier)`. `referenced_by(item, 'books', 'author')` is the other way round: the entries of a type that point at this one through a field (`editions.earlier` for a field in a list), newest first, each the version in the page's language that holds the reference.
- **CSV.** An export writes each reference as `type/slug`; an import takes an address or an id, a list as JSON, and an address of an entry created by the same file works.
- **The index.** The references are kept in the system database next to the content index (`content_index` has each entry's id and a small card; `content_refs` has the pointers), so "all books of this author" is one query on an indexed id and never reads the files of the books. It is built from the files, kept up to date by every save, delete, restore, import and link update, looked at again at most every 30 seconds for files changed outside the admin, and built again when a type's relation fields change. Deleting the database only costs speed: Admin > System rebuilds the content index with it, and the first visit that needs it does the same.

## Search results

The public search page keeps its title-area settings and uses `templates/search.twig` for the results. It searches block text and public custom fields, matches Greek accents/case/final sigma, and shows ranked results, excerpts, type filters and pagination. Existing custom templates keep the complete `results` list; the new presentation uses `search.hits` and `search.pagination`. Field definitions can exclude text with `searchable: false`. See [Public search](search.md) for matching, visibility, schema options and the template contract.

## Single layouts and archive layouts

**Admin > Theme > Single Layouts** has one card for each content type the site has (pages, posts, projects, forms, books, and any type added later gets its card by itself). A card sets, for the page of one entry of that type:

- the **page layout**: one of the page templates below (Standard, Landing, or one a site adds). A type whose own template is not the standard one (the book page, `single-book.twig`) has no page layout and no title area to style; its card says so and links to the fields of the type;
- the **title area**: Default, Centered, Split, Cover or Minimal (the same five as the Hero block), and whether the header sits over it (Site default, Over the title, Solid);
- what shows: the image, the excerpt, and for the templates that print a line above the title (posts and projects) the date and terms;
- the **sidebar**: None, Right or Left, whatever the page layout (a landing page has none). With one, the page uses the With sidebar template and the card says which parts it holds: contents, related pages, the contact card. The text of the card is the same for every type, in a panel below the cards.

**Parallax** (the *Effect* row of a card, off by default) makes the picture of a Default or Cover title area move at about a third of the speed of the page. `site.js` does it while the area is on screen (a `data-parallax` attribute starts it, `--parallax-y` moves the picture, which is made 15% taller above and below so no edge shows), and does nothing for people who ask for less motion or when scripts do not run. Centered and Split show the picture as a picture, so they keep it still. The choices are stored in the theme settings under `single_layouts.<type>`, so a type with no choice yet has the defaults. A site that saved the older Hero Layouts, Transparent Header and Sidebar settings starts from them. A single entry can still choose its own title area, header and parallax in its editor (`hero_layout`, `header_transparent`, `hero_parallax: on|off`), and its own page layout (`template:`; `standard` asks for the plain one when its type has another).

A theme declares what a card offers in its manifest:

```yaml
single_layouts:
  fields:
    title: {type: select, label: Title area, default: default, options: {default: Default, split: Split}}
    header: {type: select, label: Header, default: site, options: {site: Site default, 'on': Over the title, 'off': Solid}}
    # sidebar (none, right, left), image, excerpt, byline, toc, related, card
```

**Breadcrumbs** (the *Show* row, off by default) draw a trail above the title: Home, the list of the type, the entry. The same chip is on every archive card (*Show*, `breadcrumbs`): Home and the list, or the category or tag by its own title. `components/breadcrumbs.twig` draws it inside the title area (`crumbs` of `title-area.twig`: a list of `{label, url}`, the last one the page itself).

**A title area for a term.** In Admin > Taxonomies a term has a *Title area of its entries* (Default, Centered, Split, Cover, Minimal, or none): the entries filed under it open with it unless an entry chooses its own; an entry in several terms takes the first term (categories, then tags, then the site's own taxonomies) that has a choice, and an entry's own choice still wins. It is stored as `hero_layout:` on the term in `content/taxonomies/<name>.yaml`, and `components/hero-layout.twig` reads it with `term_hero_layout(item)`.

Templates read the choices of a type with `single_layout(type)` (a map of `template`, `title`, `header`, `sidebar`, `image`, `excerpt`, `breadcrumbs`, `byline`, `toc`, `related`, `card`). The page layout and the title area follow from the template files: `usesTitleArea` is true for a template that includes `components/page-header.twig`, and the line above the title is offered for one that passes `hero_meta`.

**Admin > Theme > Archive Layouts** has one card for each list of entries: every content type that has one, then each taxonomy (the pages of a category or tag). A card sets the layout (seven pictures to choose from), columns, order, items per page, the parts shown, the taxonomies offered as filters, the title and the subtitle, and the **title area** above the list: one of the five layouts of an entry's title area (`title_layout`: default, centered, split, cover, minimal), a picture for it (`title_image`, from the Library) and parallax (`title_parallax`) for the default and cover layouts, as in Single Layouts. A cover with no picture is the default one. `components/title-area.twig` draws it for entries (through `page-header.twig`) and for lists (`archive.twig`). The **Search** card (under *Other pages*) gives the search page the same title area, and its own title and subtitle for each language (`search_page` in the theme settings; without them the page says what the theme says, "Search" and its line in Admin > Translations); the search box sits inside the title area, in any layout (`hero_extra` of the component). A taxonomy also chooses which content types it lists. Only what differs from the theme's defaults is written, to `custom/content-types/<type>.yaml` and `content/taxonomies/<name>.yaml` as before. The Content types and Taxonomies screens link here instead of holding these settings.

### Options of a content type

A content type with a page or a list of its own can declare **options** in its definition (`themes/<theme>/content-types/<type>.yaml`, or `custom/content-types/<type>.yaml` for a type of the site's own, merged one by one with the theme's). They appear on the type's card in Theme > Single Layouts and Archive Layouts, so every special type brings its own choices to the one place where looks are set:

```yaml
single:
  sidebar: true        # its template draws a sidebar when the card asks (None, Right, Left): the card offers the choice
  header: true         # its template opens with a section the header can sit over: the card offers the choice
  options:             # options of its page: key => definition (select, toggle, number, decimal, text, color)
    cover: {type: select, label: Cover, default: left, options: {left: Left, right: Right, top: Above}}
    show_buy: {type: toggle, label: Buy button, default: true}
archive_options:       # options of its list
  cover_shape: {type: select, label: Covers, default: portrait, options: {portrait: Portrait, square: Square}}
```

Values are checked against the declaration: a page's are stored with the theme settings (`single_layouts.<type>.options`) and read in the template with `single_layout(item.type).options`; a list's are stored in the site's file of the type (`archive.options`, only what differs from the theme's) and read as `archive.settings.options`. The default archive template also adds a class for each (`opt-cover-shape-portrait` for a choice, `opt-some-toggle` for a toggle that is on) to the list, for the style sheet. A template that declares `sidebar: true` draws the sidebar itself (as `templates/sidebar.twig` does), and one that declares `header: true` sets `{% set opens_under_header = true %}` so the layout lets the header go transparent over its first section. The Books type (`single-book.twig`) is the example: where the cover goes, what shows (author, summary, details, buy button), a sidebar, a header over the book, and the shape of the covers in the list.

## Templates built with blocks

The page of an entry can be **built with blocks** instead of drawn by a Twig file. **Admin > Theme > Single Layouts** gives each content type's card a *Page* choice: **The theme's** (the template files above, as always) or **Built with blocks**, with *Edit template* and *Reset*. A site that does not use it changes in nothing. Nothing in the code knows a content type: the card, the editor, the list of fields a block can show and the page all follow the type's definition when they are drawn, so a type the site adds later (a Blog, an Education type, with fields of its own) gets the same with no code.

**Edit template** opens the block editor (the same as an entry's Blocks tab, with the settings panel) beside a live preview of a real entry of the type: the latest by default, any other from the *Preview with* list. Nothing is stored until *Save template*. The blocks are stored in the theme settings as `single_templates.<type>`: `use` (whether the type draws its pages from the template) and `blocks`, in the shape of the Footer Blocks (a plain list, or a map of `default` and a set for each language; a language with no set of its own uses the site's own). The template is not entry content: entries are untouched, and search does not index it. **Reset** (or choosing *The theme's*) draws the pages with the theme's template again and keeps the blocks, so *Built with blocks* brings them back. The card's own choices (page layout, title area, sidebar, what shows) are for the theme's template only.

### Which template draws the page

`TemplateResolver::forItem()` decides, in this order: **1.** the entry's own `template:` (and `template: standard`, which asks for the type's plain layout); **2.** when its type is *Built with blocks* and the template has blocks in the entry's language (or the site's own), `templates/single-built.twig`, which draws the template's blocks with the entry as context (their style sheets, scripts and structured data are merged into the page as for an entry's blocks); **3.** the page layout chosen for the type; **4.** the type's own template. A form never takes a template. A theme without `templates/single-built.twig` offers no *Built with blocks*; one that overrides the file keeps the contract: draw `page_blocks.html`, and set `title_header` when `page_blocks.lead.type` is `entry-title`, so the header can sit over the title area.

### Blocks that draw the entry

These blocks (`scope: template` in `block.yaml`) are offered only in a template, under the *Entry* kind. Any other block can be used too (hero, text, image, text and image, buttons, call to action, containers for columns…).

| Block | Draws |
|---|---|
| **Entry title** | The title area of the entry (`components/title-area.twig`): layout (*As set for the content type*, Default, Centered, Split, Cover, Minimal), the main image, the excerpt, the line with terms, author and date, the trail. |
| **Fact sheet** | The declared fields of the entry as a list of labels and values. Choose which fields and in what order; with none chosen it shows the fields the page of the type shows. The list of choices is the type's fields: a field added later appears, one that is gone is shown as missing. |
| **Page content** (`content`) | The entry's own text, where the template puts it. A template does not add the text by itself: without this block the text is not shown. |
| **Entry blocks** | The blocks the entry has of its own (its Blocks tab), drawn where the template puts them, so each entry can still build part of its page. When the template has no *Page content*, they may place the text as on any page. |

### Field bindings

In the settings panel every field that can show a field of the entry has a small chain button beside its label (*Show a field of the entry here*). It lists the fields that fit, by label (in the language of the admin) with their key; choosing one stores `{bind: "custom_fields.author"}` where the value would be. What fits is **one table**, `EntryBindings::ACCEPTS`, keyed by the type of the block's field:

| The block field is | It can show |
|---|---|
| text | the title, the date, the author, the categories and tags (names, in a line), and fields of type text, select (the label of the option), number, decimal and date (written as the site writes dates) |
| text of several lines, Markdown | the excerpt, and fields of type textarea and markdown |
| image | the main image, and image fields |
| link, address | link and url fields |

A choice and a switch cannot be bound; a list can, to a list ([List bindings](#list-bindings)). A new kind of field becomes bindable by one more entry in the table (and a line in its row of `EntryBindings::value()` when its value is not a plain string). The paths are `title`, `excerpt`, `main_image`, `date`, `author`, `categories`, `tags` and `custom_fields.<key>`; the dotted form leaves room for relationships (`author.title`).

At render time `BlockRenderer` replaces each binding with the entry's value **before** the normal checks of the block's field, so a bound value is cleaned exactly as a typed one: text is escaped, an address with a script scheme is refused, Markdown goes through the site's Markdown. A text with a version for each language (`translatable: true`) gives the one of the page's language, else the site's own. A field the entry has no value for is empty. A binding to a field that has been removed, renamed or changed to a kind that no longer fits is empty too, never an error, and the editor shows a warning on the block ("Bound to a missing field: custom_fields.x"), so nothing is lost while it is fixed.

Each block with a binding has **Hide this block when its bound field is empty** (on by default): the block is left out when every field bound in it is empty. Switching it off is the only state that is stored (`bind_hide: false`). A block with nothing bound is always shown.

### List bindings

A block's list (a repeater: the buttons of a Hero, Call to action or Buttons block, the items of Cards, Checklist, FAQ, Accordion, Columns and so on, every block repeater) can be bound to a **list of the entry**: a repeater field the content type declares (the buy links of a book, the materials of a workshop). The chain button in the header of the list offers the lists of the type; choosing one opens a small table with **one line for each column of the block's rows**, and each line says what that column shows:

- a **column of the entry's rows** that fits it (the same table as for single values, `EntryBindings::ACCEPTS`, applied to the column: a text takes text, select, number and date columns, a link takes link and address columns, and so on). Columns of the same name that fit are mapped when the list is chosen, so the usual case needs no clicking;
- **The same value** for every row (a fixed value). A choice, such as a button's style, can have one value for the **first row** and another for **the others**, so the first shop is the main button and the rest are secondary. A text column can have one text for every row. A switch has no fixed value;
- **Nothing** (empty).

Stored in the block where the rows would be:

```yaml
- type: buttons
  actions:
    bind: custom_fields.buy_links
    map: { label: label, url: url }                    # column of the block: column of the entry's rows
    fixed: { style: { first: primary, rest: secondary } }   # or a plain value: { style: secondary }
```

At render time `EntryBindings::apply()` turns it into rows **before** the block's normal checks, so the rows are cleaned and limited exactly like typed ones: unknown keys dropped, an unsafe address refused, text escaped, a select value that is not one of the block's choices becomes the default, and the block's own `max` applies (Hero buttons: 2; Buttons: 12), whatever the entry has. A row of the entry that has nothing in the mapped columns is left out. A text with a version for each language gives the one of the page's language, else the site's own. A column mapped to a column of the entry's rows that was removed, renamed or changed to a kind that no longer fits is empty (the other columns still work); a list that is gone, or is no longer a list, leaves the block's list empty. Neither is an error, and the editor shows a warning chip on the block and on the line ("Uses a missing column: custom_fields.buy_links.url") while keeping the binding as stored. With *Hide this block when its bound field is empty* on (the default), a block whose bound list has no rows is left out; "empty" means the entry's list has no row with anything in a mapped column.

Limits: the list is bound as a whole, to a top-level list of the entry. A list **inside** a row (a nested list, such as the buttons of each slide in the Hero slider) is not mapped: it stays empty while the outer list is bound. The Fact sheet's own list names fields of the entry and is not bound. The categories and tags of an entry are not offered as lists yet (they are offered as text, in a line). A new bindable column kind is one entry in the `ACCEPTS` table, as for single values.

### Relationships in templates

A relation field ([Relationships](#relationships)) is one more source of bindings, for every type, with no code (the picker reads the type's definition). The paths follow the relation with dots:

| Path | Is | Fits |
|---|---|---|
| `custom_fields.author.title` | the title of the entry the field points at | text |
| `custom_fields.author.excerpt`, `.main_image`, `.date`, `.url` | its excerpt, picture, date and the address of its page | text of several lines, image, text, link |
| `custom_fields.author.custom_fields.country` | a field the type of that entry declares (when the field names a single type; one hop only) | what the field's kind fits |
| `custom_fields.team.title` | all the titles of a field that takes several, in one line | text |
| `custom_fields.team` | a field that takes several, as a **list** of entries | a block's list |
| `referenced_by.books.author` | the entries of type `books` that point at this one through their `author` field (`referenced_by.books.editions.earlier` for a field inside a list), newest first | a block's list |

A list of entries is bound like [a list of rows](#list-bindings), with a column for each thing a card has: `title`, `excerpt`, `main_image`, `date`, `url`, and `field_<key>` for each field the type of those entries declares (when it is one type). Same-name columns are mapped when the list is chosen, and a card's excerpt and main image take the block's `text` and `image`. A Cards block bound to `referenced_by.books.author` on an author's template is that author's books, each card linking to its book; a Hero's buttons or a Buttons block can show the entries too.

The same rules hold as everywhere: only what a visitor may see is listed (published, visible, in the page's language or the site's own), a binding is cleaned like typed text, an empty source hides the block (unless it is told to stay), and a path that leads nowhere (a field or relation that was removed or changed kind, a type that is gone) is empty and flagged in the editor, never an error. A field of the entry's own file is read only when a column or path asks for one; the title, excerpt, picture, date and address come from the index and cost no file.

**The fact sheet.** A relation shows its entries as links, in the fact sheet of the page, in `content_fields(item)` and in a Fact sheet block (a relation to several is offered there too). A relation that says `reverse_label: Books` in its field adds a line of that name to the fact sheet of the entries it points at, listing the entries that point there, with links: the page of an author names its books with no template work (a Fact sheet block with no fields chosen shows it as the default page does; one that names its fields shows only those).

### Starting templates

A type switched to *Built with blocks* (or opened in *Edit template*) with no blocks yet **starts from a starting template**, so the page works at once instead of an empty list. Nothing in the code knows a type; there are two sources, in this order:

1. **A file of data**, `single-templates/<type>.yaml`, in the site's `custom/` (kept across updates, wins) or in the theme (`themes/default/single-templates/`). It holds the blocks in the shape they are stored in, bindings included (a comment at the top of each says how it differs from the theme's own page):

   ```yaml
   blocks:
     - { type: entry-title, variant: split }
     - type: fact-sheet
       fields: [ { field: custom_fields.author }, { field: custom_fields.year } ]
     - type: buttons
       actions: { bind: custom_fields.buy_links, map: { label: label, url: url }, fixed: { style: { first: primary, rest: secondary } } }
     - { type: content }
     - { type: entry-blocks }
   ```

   Blocks the site does not have are dropped; a file that cannot be read, or has no blocks, gives way to the next source. The default theme ships one for **books, posts, projects, points, routes and businesses**.
2. **Generated from the type's definition** (`StartingTemplates::generate()`), for any type with no file, a type the site adds later included: an **Entry title**; an **Image** bound to the first image field of the type; a **Fact sheet** of the fields the page shows (those with `show` not `false`, or `card: true`; retired fields are not in a definition); **Buttons** bound to the first list whose rows have a text and a link (label and address mapped, first row primary, the others secondary); **Page content**; **Entry blocks**. Blocks that would be empty for an entry are left out when the page is drawn (*Hide when empty*).

The template is stored when the type's card is switched to *Built with blocks* while it has no blocks and was off (a template switched on and emptied is not filled again by saving the cards), and *Edit template* shows it, with a note, until it is saved. **Start again from the default** (a button in the bar of *Edit template*, with a confirmation) replaces the blocks of every language with the starting template and switches the template on; nothing else changes, and the entries are never touched.

How the hand-made ones differ from the pages of the theme: **Books** have the cover beside the title and the summary (the *Split* title area) but not the book-like shadow, the author is in the details instead of under the title, and a shop with no button text gets no button (the page of the theme used "Buy the book"). **Points, routes and businesses** have no map, no *what is near* list, and no download of the track or booking button, because a map block cannot show the place of the entry yet. Posts and projects have the same title area, facts and text.

### A new type with a built template, with no code

Define the type in a file of the site, `custom/content-types/education.yaml` (kept across updates):

```yaml
label: { en: Courses, el: Μαθήματα }
singular: { en: Course, el: Μάθημα }
fields:
  teacher: { type: text, label: Teacher }
  level:   { type: select, label: Level, options: { beginner: Beginner, advanced: Advanced } }
  hours:   { type: number, label: Hours }
  starts:  { type: date, label: Starts }
  syllabus: { type: markdown, label: Syllabus }
  cover:   { type: image, label: Cover }
  apply:   { type: link, label: Apply }
```

Add an entry (`content/education/intro.md`, or in Admin) and the type has its card in Theme > Single Layouts. Then:

1. Choose **Built with blocks**. The template starts from a **generated starting template** (see [Starting templates](#starting-templates)): the title area, an image bound to *Cover*, a fact sheet of the fields, the text and the entry's blocks. Open **Edit template** to change it.
2. Or build it yourself: an **Entry title**, a **Text** block whose *Heading* is bound to *Teacher* and whose *Text* to *Syllabus*, an **Image** block whose *Image* is bound to *Cover* and its *Link* to *Apply*, a **Fact sheet** listing *Starts* and *Level*, **Page content**, and **Entry blocks**.
3. Save. Every course is drawn from the template; a course with no cover simply has no picture block, because *Hide when empty* is on. A course that sets `template: landing` in its own front matter keeps that template.

The fields of the type appear in the chain button and in the Fact sheet by their labels; add a `room` field to the YAML later and it is offered at once. `tests/http/single_templates_test.py` does exactly this with a type that exists only as a file created during the test.

## Page templates

Pages and posts choose a template in Admin > Edit > Publish (stored as `template:`), and a content type can have one as its layout (Theme > Single Layouts). The manifest's `page_templates` lists them; a template appears only if its file exists.

- **Standard** (`default`): the normal hierarchy: title header, text, blocks.
- **Landing** (`landing`): no title header; the page is its blocks. Without an opening hero the title is still the (visually hidden) `<h1>`.
- **With sidebar** (`sidebar`): title header, then the text beside a sticky sidebar with "On this page" contents (from the text's `##` headings, which get ids), the related pages from the main-menu branch the page belongs to, and a card from Theme settings > Single Layouts > Sidebar card (text, button, phone and email). Blocks follow below at full width; a Page content block does not repeat the text.

Add a template by creating `templates/<name>.twig` and listing it under `page_templates` in the theme manifest. A site adds its own the same way without touching the theme: put `custom/templates/<name>.twig` next to a `custom/page-templates.yaml` (`name: {label: …, description: …}`); both are kept across updates.

## Ready-made sections

In the block editor, **Add block** has three tabs: single blocks, **Ready-made sections** (a few blocks that belong together, e.g. FAQ + call to action), and **Page layouts** (a whole page, optionally with a suggested template). A page layout can replace the page's blocks (with undo) or be added after them.

Presets are YAML files in `themes/default/presets/` (theme) and `custom/presets/` (site):

```yaml
kind: section            # or page
template: landing        # page layouts only, optional
label: { el: 'Ομάδα', en: 'The team' }
description: { el: '…', en: '…' }
blocks:
  el: [ { type: team, heading: 'Η ομάδα', members: [ … ] } ]
  en: [ { type: team, heading: 'The team', members: [ … ] } ]
```

Labels, descriptions, and blocks may be a single value or a map per language; the editor uses the page's language, then the default language. Theme presets hold placeholder text and no images, so they work on any site.

The theme ships these page layouts (all in Greek and English, with placeholder text and no pictures): **Home**, **About**, **Contact**, **Service page**, **Campaign landing page**, and, for the second family of blocks, **Lead generation landing** (Landing template), **Pricing page**, **Work index (portfolio)**, **Case study**, **Careers**, **Blog home (magazine)**, **FAQ and help center**, **Resources and downloads**, **Event or webinar** (Landing template), **Services overview**, **Legal page** (text with a contents list), **Coming soon** (Landing template) and **Link in bio** (Landing template).

Editors can tick blocks and **Save as section**: the blocks are checked and written to `custom/presets/<name>.yaml` in the page's language, appear under Ready-made sections with a delete button, and are included in backups (Site customizations).

## Header and footer

**Admin > Theme > Header** and **Footer** have the real site beside them (it follows what is chosen, before it is saved), the layouts to choose from, and a few small cards. Everything is a solid colour. The frame shows the page at a computer, tablet or phone width, in light or dark; because a header or footer choice changes what the page is made of, the server draws the page with the unsaved settings (`POST /admin/theme?preview=page`; nothing is stored and no form is processed) and the frame shows that, keeping its scroll and following links inside it.

Header:

- **Layout**: *classic* (logo left, menu right), *menu left* (logo and menu together on the left, tools on the right), *split* (menu left, logo in the middle, tools right), *centered* (logo in the middle, menu below), *stacked* (menu in a full-width bar underneath, with a background of its own; the page you are on is highlighted, a palette can set `--bar-highlight`), *minimal* (logo and a menu button on all screens) and *minimal centered* (the button on the left, the logo in the middle).
- **Style**: *shape* (full width bar or floating, detached from the edges with rounded corners), *content width* (contained or edge to edge), *height* (regular, compact, tall), *edge* (a line, nothing, a shadow), *background* of the row with the logo (page, muted, soft tint, a **fade** from the muted colour or the soft tint into the page background, dark, palette colour; a fixed header or one over a hero is a plain tint or clear), *menu links* (soft background on hover, underline, plain).
- **Behaviour**: *sticky* (slides in after scrolling, always at the top, **smart** (leaves while the page is read downwards and returns when it is scrolled back up), or scrolls away), *shrinks* once the page is scrolled, and **transparent over an opening hero** (on pages that start with a Hero block, or a Slider block of full width, the header overlays it, with light text over a cover image or dark hero, and turns solid when the page scrolls; a content type or an entry can decide otherwise, see Single layouts).
- **Elements**: *search* (an icon, a box, or none), the *language switcher* and the *dark mode switch* (each can be turned off), and a **button** (text, link, solid or outline) in the header and the phone menu.
- **Top bar**: a message (optionally a link), the phone and email (from the Footer), the social icons, on a muted, dark or palette background; it shows when it has any of them.
- **Phone**: how the menu opens (side drawer from the left or right, full screen, sheet from the top or bottom), and the bottom bar (menu, call, email, the button, or links of your own with icons).

Footer:

- **Layout**: *columns* (brand and summary, the footer menu, contact), *mega* (a column for each top-level link of the footer menu that has links under it; the links with none share the first column), *one row*, *bar* (the copyright on the left, the links on the right, thin lines between them) and *centered*.
- **Background**: dark (the base), the page's, muted, or the palette colour; a colour or an image of your own replaces it. **Brand**: the site name, the logo, or nothing.
- **Content**: the summary, the copyright line (`{year}`, `{site}` and `{copyright}`, the © sign, are filled in), a credits line under it (for example *Designed by [Unicorg](https://…)*; links are written `[text](address)`, and the same three words work), email, phone, address and opening hours, the **links at the bottom** (a menu given the place *Footer bottom links*), the social icons, a language switcher and a back to top link.

A site-specific block goes in `custom/blocks/<type>/` with the same files.

## Branding

**Admin > Theme > Branding** gathers everything that makes up the look of the site, for a designer: the theme's `appearance`, `brand` and `design` sections in one tab (a theme that declares `design` gets this tab; otherwise each section is its own tab). Cards on the left, the real site on the right: the frame shows the home page with the unsaved choices as they are made, at computer, tablet or phone width and in light or dark (the browser asks `POST /admin/theme?preview=branding`, which answers with the CSS and attributes and stores nothing; the same frame serves the Header and Footer tabs).

**Site / Style samples** switches between the real page and a specimen of H1–H6, paragraphs, links, buttons, a card, form fields, and accent and dark panels. Both follow the unsaved choices and keep the site's custom CSS after the branding rules. The specimen is fetched from the protected `GET /admin/theme?preview=styles` and drawn in the frame; it is not site content and stores nothing. The size controls change only the preview width, not the settings being edited.

- **Identity** (`brand`): the logo, a **logo for dark backgrounds** (shown in dark mode, in a dark or palette footer, in a dark or palette header row, and over a dark opening hero; without it the logo is used everywhere), the site name beside the logo, heights of the logo (computer, phone, footer) and the size of the name, the **favicon**, the **app icon** (`apple-touch-icon`), the **browser colour** (`theme-color`) and the default share image.
- **Colour**: one row of chips (the seven ready-made palettes, the site palettes, and **+ Custom**) and the **mode** (`appearance.mode`, which is not part of a palette). The palette is the single place where colours are set, see "Site palettes" below; the heading colour sits under it. The hover and soft shades of the accent, the second shade of the surface and the border, and a readable text colour on the accent (white or the theme's near-black) are worked out from the colours of the palette.
- **Typography**: the font pairing (`appearance`), a family for headings and one for text (thirteen system stacks, so nothing is downloaded, or **your own font families**, see [Your own font families](#your-own-font-families)), text size, line height, a **heading scale** (a ratio from 1.125 to 1.618, gentler on a phone), heading weight, letter spacing, line height and capitals.
- **Layout and spacing**: content width, reading width, side margin, space between sections, **header height** (compact and tall follow it), and a spacing scale.
- **Shape and depth**: corners (`appearance`), a **radius in pixels**, shadows, decoration.
- **Buttons**: height, side padding, text size, weight, capitals, button corners.
- **Links** (`design.link_*`): the colour and hover colour of links, for light and for dark (an empty dark box follows the light one), the underline (*Always*, *On hover*, *Never*; the theme's own when empty) and its thickness (thin, medium, thick) and distance from the text (near, far). The underline choice is for the links of text (`.prose`), never for buttons or the text links of cards; *Never* still shows it on keyboard focus. The colour is a rule of the lowest weight, so links that the theme colours itself (menus, footer, cards) and links in dark or accent bands keep their own colour.
- **Button styles** (`design.btn_<style>_*`, with `<style>` one of `primary`, `secondary`, `text`): for each style the background, text and border colour in the normal state and on hover, a border width (none, 1, 2, 3 px) and its own corners (*Inherit* follows Button corners). **Primary** is `.btn-primary` and also the submit buttons of forms and the search button; **Secondary** is the outline button (`.btn-secondary`, and a `.btn` with no other style, as in the header's outline button and the pricing plans); **Text** (`.btn-text`) looks like a link with the size and focus area of a button. Height, padding, text size, weight and capitals above are the base of all three. A hover that is empty is worked out from the normal state: the accent's own hover for a Primary on the accent, otherwise the background moved toward the text colour (darker in light mode, lighter in dark mode). A background chosen without a text colour gets the white or near-black that reads best on it in each mode.
- **Colours are tones**: a palette role (*Accent*, *On accent*, *Secondary* when the palette has it, *Text*, *Muted*, *Background*, *Surface*, *Border*, *Ink*, *White*, *None* for transparent), which follows the palette, the mode and the section a button sits in, or **Custom** (a #rrggbb). A custom colour is used only while it reaches **4.5:1 (AA)** against its partner in both light and dark (a link against the page; a text colour against its background, normal and hover); otherwise it is left out and the theme's colour stays. The screen shows a badge with the ratio for light (L) and dark (D) for each pair. Roles are never refused, so the badge is how to see a role pair that does not read. Custom colours are not re-tuned over pictures or in dark and accent bands.
- **Focus and disabled are not settings**: the keyboard focus ring is the theme's (2 px in the accent, 3 px from the button, 3:1 or more on the page in every palette and both modes) and a disabled button is dimmed (60 %) and not clickable, still readable.
- **Buttons and links specimen**: *Buttons and links* beside Site and Style samples in the preview shows Primary, Secondary and Text in normal, hover, focus and disabled states, and a paragraph with links, in a light and a dark panel, with the unsaved choices (`GET /admin/theme?preview=specimen`; admin only, stores nothing).
- **Blocks and forms**: a button style chosen in a block (Hero, Hero slider, CTA, Text, Text and image) is *Primary*, *Secondary* or now also *Text*; the defaults and the look of existing pages do not change. Pricing and Compare use Primary for the highlighted plan and Secondary for the others.

### Site palettes, the generator and Duo

**A palette is the only place where colours are set.** *Branding > Colour* is one row of chips, a radio group with exactly one chosen: the seven ready-made palettes, then the **site palettes** (made on this site; several are allowed), then **+ Custom**.

- **A ready-made palette** is shown read-only: its colours as labelled swatches with hex values, light and dark. *Customise* copies it into a new site palette called "<Palette> (custom)", chooses it and opens its editor. A ready-made palette is never changed.
- **A site palette** opens its editor right there: *Accent, Background, Surface, Text, Muted text, Border and Secondary*, plus *Dark panels*, for light and for dark, each with its contrast badge, and rename and delete. Edits are written into that palette. A role the palette leaves empty shows, greyed, the colour it inherits from its **source** (the ready-made palette it was copied from or made on). Deleting the palette in use returns the site to its source, or to the default palette when it has none.
- **+ Custom** has three starts: **Two colours** (the generator), **Duo pairs** and **Copy of a ready-made one**. The result is a new site palette, chosen and opened in the editor.
- Stored as JSON in `design.site_palettes` (each palette: `id`, `name`, `light`, `dark`, `ink`, `secondary`, `source`, and `from`, the two colours it was made from); the one in use is `design.site_palette` (its id). `appearance.palette` is the ready-made palette underneath: it still supplies whatever the site palette leaves out, and it follows the `source` of the palette in use. `design.light_accent`, `design.dark_background` and the other per-role fields are **retired**: they are no longer on the screen and are always empty.
- **From two colours**: give an **accent** and a **base**, say whether the base is the **background** or the **ink** (the text colour), and swap them with the arrows if needed. `PaletteGenerator` works in OKLCH, so the tints and shades of the neutrals step evenly, and derives both modes: surface, border, text, muted text, the accent for each mode, the dark panel colour and, with a third colour, the secondary. A base that is clearly light (or clearly dark) is used as the light (or dark) background as it is; a mid-tone only lends its hue. The result is deterministic, and **always AA**: text and muted text reach 4.5:1 on the background and on the surface, the accent reaches 4.5:1 as text on both, and the text colour Branding puts on the accent (white or the near-black) reaches 4.5:1 on it. The accent (and secondary) is moved along lightness, keeping its hue and chroma, only as far as that takes. The live preview follows every change; *Create palette* names it and keeps it in the form (stored with Save changes). *Make again from two colours* reopens the generator with the colours a palette was made from.
- **Colour pairings from Duo** (Alex Pate, duo.alexpate.com): a collection of 103 colour pairs. It has **no licence, so none of it is in this code**. When the *Duo pairs* start is opened the server fetches `https://duo.alexpate.com/colors.json` (`DuoPairings`: a few seconds' timeout, https only, a small answer only), keeps the answer in `storage/cache/duo-pairings.json` for 7 days and shows the pairs as swatches with an *Only AA pairs* switch (Duo's own flag) and our own contrast badge. Picking a pair fills the two colours (first colour accent, second base). If the request fails the picker is replaced by a quiet note and nothing else changes. The *Settings* link in that start holds the address (`design.duo_url`, empty means Duo's) and a switch (`design.duo_picker`) to turn the picker off. The credit *Colour pairings from Duo by Alex Pate* links to duo.alexpate.com. The public site never contacts Duo.
- **Update safety (the migration)**: sites from before 0.1.143 had per-role colours under the palette. They are folded into a site palette, automatically and once, when the settings are loaded (`SitePalettes::fold()`): overrides on top of a ready-made palette become a site palette "<Palette> (custom)" with the ready-made palette as its source, which is then in use; overrides on top of a site palette are folded into that palette; a site with no overrides is not touched. The CSS of every page is **byte for byte the same** before and after, and running it again changes nothing. A Branding export from before (with overrides) is converted the same way when it is imported; the new shape imports as it is. Nothing is added to a page until a site palette is in use, or a secondary colour is set; palettes that are stored but not in use add nothing.

### Contrast feedback

Under the palette, the **Contrast** panel reads the colours the preview actually has, in light and dark: text on background, text on surface, muted text, the text on the accent, links (the accent on the background) and, when set, the secondary. Each shows the two colours (so a colour inherited from the palette appears as the colour it gets), the ratio and a badge: **AAA** (7:1 or more), **AA** (4.5:1), **Large text only** (3:1) or **Fails AA**. The editor, the generator and the Duo swatches carry the same badges (`Contrast`, the same rules as WCAG 2).

**Fix.** In a site palette, every failing check has a **Fix** button. It asks the server (`Contrast::fix()`) for the nearest shade of that colour, along OKLCH lightness with the hue and chroma kept, that reaches AA on every colour it is read on (4.5:1 for text and links; 3:1 would be used for large text or interface parts), shows the before and after, and applies on **Apply**, to that role and that mode only. *Fix all* does the same for every failing colour of the palette. A ready-made palette cannot be fixed: Customise it first.

### The secondary colour

*Branding > Colour > Secondary (optional)*, `design.light_secondary` and `design.dark_secondary`, gives a second brand colour. Nothing is output until one is set; a mode left empty uses the other mode's colour. The variables, for theme developers and blocks (the default blocks do not use them yet): `--color-secondary`, `--color-secondary-hover`, `--color-secondary-soft` and `--color-secondary-contrast` follow the colour mode like `--accent`; `--secondary-l`, `--secondary-l-hover`, `--secondary-l-soft`, `--secondary-contrast-l` and the `-d` set are the fixed ones for each mode. Read them with a fallback: `var(--color-secondary, var(--accent))`.

**Headings in colour** (Branding > Colour): *Heading colour* is the colour of the text (the usual), the palette colour (the accent, following the mode), or colours of your own for light and dark (the *Headings* row). It colours the headings of the text, section titles and the title of a page or a list; dark panels, the footer and headings over pictures keep their own colour. The token is `--heading-color`.

Fields show **Theme value** when they match the schema default and **Your value** when they override it. Empty colour fields show the inherited swatch and hex code without storing that colour. A field's **Reset** restores its schema default; **Reset … overrides** resets the group's design fields while keeping the selected palette, font pairing and corners (the palette itself has no reset: delete it, or choose another). Resets update the preview immediately and take effect on the site only after Save.

For the bundled default theme without Custom CSS, button weight shows **Semi** and inherited button corners show **Pill** with the Soft and Rounded shape presets. These are still stored as `auto` until you explicitly choose a value, including clicking the selected choice; Reset restores `auto`. With Sharp, **From shape** preserves the theme's 4 px radius because none of the fixed choices matches it. Changing the shape never replaces an explicit button radius. Inherited spacing is labelled **Normal**, shadows **Soft**, varied heading defaults **By element**, and responsive heading sizes **Theme scale**. Other themes, sites with Custom CSS, and browsers without JavaScript retain an explicit inheritance choice. No stored values or public styles are migrated.

Every `design` field is optional: empty, or "Theme", leaves the theme's own value, so a site that sets nothing looks as it did. `Branding::css()` (`src/Branding.php`; a site palette in use first fills the colours the site has not set, `SitePalettes::apply()`) turns the choices into one rule on `:root[data-theme][data-mode]` (which beats the palette, font, shape and mode rules) plus a few element rules; the layout prints it in `<style id="faros-branding">` after the theme's style sheet and before `custom.css`, with `{{ branding_css()|raw }}` and `{{ branding_head(current_language) }}` (the icons, the browser colour and a hint to fetch the fonts that are used). It is made only of numbers, hex colours and words from fixed lists, so nothing a person types can reach the style sheet as code. The tokens it sets: `--accent-l*`, `--accent-d*`, `--accent-contrast-l/d`, `--color-secondary*` and `--secondary-*` (only when a secondary colour is set), `--n-*`, `--d-*`, `--ink*`, `--font-heading`, `--font-body`, `--type-scale`, `--step-1` to `--step-5`, `--heading-weight`, `--heading-tracking`, `--heading-color`, `--container`, `--container-narrow`, `--gutter`, `--section-scale`, `--space-scale`, `--header-min`, `--radius-*`, `--radius-button`, `--shadow-*`, `--btn-height`, `--btn-pad-x`, `--btn-size`, `--btn-weight`, `--btn-case`, `--btn-tracking`, `--logo-height`, `--logo-height-mobile`, `--footer-logo-height`, `--brand-name-size`; a theme's own CSS should read them with a fallback (`var(--btn-height, 2.875rem)`).

Optional component tokens carry explicit typography choices into prose, hero titles and other component headings: `--body-leading`, `--heading-leading`, `--heading-font-custom`, `--heading-weight-custom`, `--heading-tracking-custom`, and `--hero-title-size`. Components keep their existing fallbacks when those choices are unset. `--accent-band-bg` pairs a custom light accent with its calculated text colour on Slate's accent panels; without an override, Slate keeps its original dark background.

### Type, forms, corners, shadows and phones

All optional, all empty or "Theme" by default (a site that sets none gets exactly the CSS it had). Made by `BrandingTypography`, `BrandingForms` and `BrandingShape`, appended by `Branding::css()` after the token rule; every selector sits under `:root[data-theme][data-mode]`, so it wins over block and prose classes.

- **Typography** (Branding > Typography > *Sizes per element*): H1, H2, H3, the lead paragraph and small text each take a **step of the type scale** (`h1_step` … `small_step`: 5, 4, 3, 2, 1, 0, -1, -2, written as `font-size: var(--step-N)`, never pixels, so the fluid sizing and the Text size and Heading scale choices still apply), a weight, a line height (tight, normal, relaxed, loose) and a letter spacing. H4 to H6 have no override: they follow the scale. H1 also reaches the Hero title and the page title, H2 the section titles. `body_weight` sets the weight of text and `paragraph_space` the space between the paragraphs of prose.
- **Forms** (Branding > Forms): `input_bg` (page background, surface, deeper surface), `input_border` (strong border, muted text, text, accent), `input_border_width` (1 or 2px), `label_size`, `label_weight`, `input_ring` and `error_style`. They style the public forms (`PublicForms`, the form block) and the search field. Colours are the palette's own roles, so contrast follows the palette. Focus presets always draw a visible ring: the theme's ring, a 2px accent outline, or a 3px outline in the text colour ("high contrast"). Errors never rely on colour alone: *Strong border* thickens the field's border and bolds the message; *Tinted field* adds a tint, a 3px bar and a heavier message. The text colour of messages stays `--color-danger`, which is AA on the page and on the tint in light and dark.
- **Corners and borders** (Branding > Shape and depth): `radius_card`, `radius_image` and `radius_input` (empty follows *Corner radius*; a number in px, 0 is square), and `border_width` for cards (none, 1, 2, 3px; it changes a border the card already has). The lists of what counts as a card or an image are in `BrandingShape`.
- **Shadows**: `shadows` is *Theme*, *None*, *Soft* (the theme's own, written out), *Medium* or *Strong*. Sites that stored `none` or `strong` before keep exactly the same CSS.
- **Phones** (Branding > Phones, optional): `mobile_gutter` and `mobile_section_space` apply up to 640px wide (`@media(max-width:640px)`); the second is pixels on a typical phone (the theme's is about 58) and works through `--section-scale`, so compact and spacious sections follow. Empty keeps the automatic responsive sizes. The S/M/L spacing scale is not editable on purpose.

### Export, import and reset

*Branding > Export, import and reset* (`BrandingTransfer`, `src/BrandingTransfer.php`; needs the right to change the theme, like the rest of the screen):

- **Export** downloads `branding-YYYY-MM-DD.zip`: `branding.json` (format `faroscms-branding`; the whole `appearance` and `design` trees as resolved, with no list of keys, so every field a theme or a later version adds travels too) and, under `assets/`, the font files (`woff2`, `woff`, `ttf`, `otf`) that those settings name and that exist in `custom/assets`.
- **Import** takes that zip (or its JSON alone), shows a **preview** of every setting that would change (now → import) and the font files that would be added or replaced, and applies it only on *Apply import*. Only keys the theme declares are taken, each checked by the settings schema; the rest is listed as skipped. Zip entries are read by name, never extracted by path, and only font files with safe names are written. The settings from before are kept as a backup (`branding_backup` in the system database), and **Undo** puts them back in one click (imported font files stay).
- **Reset a section** (Colour, Typography, Layout and spacing, Shape and depth, Forms, Buttons): puts that section's `design` settings back to the theme's defaults, saves, and keeps the same backup, so it can be undone. This is separate from the *Reset … overrides* links of each card, which only change the unsaved form. A section owns the keys named in `BrandingTransfer::SECTIONS`.
- Routes: `GET /admin/theme?branding=export`, `POST /admin/theme?branding=preview|import|undo|reset` (JSON, with the form token).

## Design tokens

`site.css` defines semantic tokens (`--color-bg`, `--color-surface`, `--color-text`, `--color-muted`, `--color-border`, `--accent`, `--accent-soft`, `--accent-contrast`, spacing `--space-*`, type scale `--step-*`, radii `--radius-*`). Theme settings switch them through attributes on `<html>`:

- `data-glow`: `solid` removes the decorative glows (Branding > Shape and depth > Decoration);
- `data-theme`: palette (slate, indigo, emerald, teal, rose, amber, garnet), with light and dark variants of each accent;
- `data-mode` and the `.dark` class: colour mode, following the system until the visitor chooses;
- `data-font`: sans (Inter), display (serif headings with Inter text), serif, or system (no font download);
- `data-shape`: soft, rounded, or sharp corners.

Block tones re-scope the same tokens, so every component works on every background. Text and accent pairs meet WCAG AA contrast in all palettes, in light and dark mode.

## Fonts

Inter ships with the theme (`assets/fonts/inter/`, SIL Open Font License) as a variable font (weights 300–800) split into Latin, Latin Extended, Greek, and Greek Extended files. `unicode-range` makes a page download only the files its text needs, and the layout preloads Latin (and Greek on Greek pages). Nothing is loaded from Google or another third party. A site that wants no web font chooses "System fonts" in Theme settings.

### Your own font families

Theme > Branding > Typography > *Your own fonts* lets a site use its own typeface for headings and for text, each with its own family.

- **A family** is a name and a set of font files. Upload them there (WOFF2 is best; WOFF, TTF and OTF are accepted with a hint, since the CMS cannot convert them; up to 6 MB each). Each file is stored in `custom/assets/fonts/<family>/<name>-<hash>.<ext>`, so it is served by `ThemeAssets` with its real type (`font/woff2`, `font/woff`, `font/ttf`, `font/otf`), a year of caching under its `?v=` version, and is included in backups with the rest of `custom/`. A file renamed to another type is refused, and the stored extension follows what the file really is.
- **Tagged files.** The weight (100–900, or a range such as 200 900 for a variable font) and style come from the file: the weight axis of a variable font, a weight in the file name (`-SemiBold`, `-700-`), then the OS/2 table; italic from the name, the font's style bits and its name table. Both are editable on the screen.
- **Coverage.** `FontFile` (`src/FontFile.php`, a small parser with no dependency) reads the character map and shows chips for Latin, Greek, Greek Extended, Cyrillic and Symbols. On a site that has Greek content (Greek as a language), a chosen family with no Greek letters gets a warning, and a family whose coverage is not known a softer notice. A WOFF2 keeps its tables in one Brotli stream, which PHP can open only with the `brotli` extension; without it the coverage is checked in the browser (a character the font lacks is drawn by two different fallbacks differently) and the chips can be corrected by hand.
- **Output** (`BrandingFonts`, called by `Branding::css()` and `Branding::head()`): a `@font-face` for each file of a family that headings or text use, with `font-display:swap`, the file's weight and style, and the `unicode-range` of its character map (so a Latin-only font never claims Greek); a system font sized to stand in while the font loads (`"<Family> Fallback"`: `size-adjust` and the ascent, descent and line-gap overrides, from the width of a–z and the vertical metrics); and `--font-heading` / `--font-body` as `"Family", "Family Fallback", var(--font-system)`.
- **Preload.** Only the upright WOFF2 file of the weight the theme uses is preloaded: 400 for text, the heading weight (700 unless Branding sets one) for headings, the same file when it is a variable font, and in the script of the page's language (`branding_head(current_language)`; a Greek page skips a file that has no Greek). Other weights and italics load when a page asks for them.
- **Settings** (all in `design`, so Branding export can carry them): `fonts` (a list; one entry for each file: `family`, `file` under `custom/assets`, `weight`, `style`, `range`, `cover`, `adjust`, `v`), `heading_family` and `body_family`. A role uses its family when its font is *Your own font* (`custom`); with exactly one family and nothing chosen, that family. The single `font_file` of earlier versions still works for a role that chose *Your own font* and has no family, and gives exactly the CSS it always gave; the screen can turn it into a family (all weights, as it was drawn) with one button. A site that does not touch these settings renders byte for byte as before.
- **Safety.** Names, paths, weights, ranges and measures are checked when read, and the style sheet is built only from the checked parts, never from text as typed. Uploading needs the Theme permission (administrators) and a valid form token; the upload only stores the file, and the settings change when Branding is saved.

## Images

`image(src, options)` renders responsive markup for files in `/uploads`:

- intrinsic `width`/`height` (no layout shift);
- a WebP `srcset` (360–2400 px) with the original as fallback;
- `loading="lazy"` by default, `priority: true` for above-the-fold images (eager, `fetchpriority=high`);
- `alt`: the given text, `''` for decorative images, or the media library's alt text when omitted.

Variants are created on first request at `/uploads/_v/<file>/<width>-<version>.webp` and then served as static files. The version comes from the source file's modification time, so replacing a file changes its URLs. Variants are excluded from backups and removed when the media item is deleted. Alt text is edited in Admin > Media.

## SEO

The layout outputs the title (`Page | Site`, or the SEO title), meta description, canonical URL, `hreflang` alternates, Open Graph (`og:type`, `og:site_name`, `og:locale` and alternates, image), and Twitter card tags. The share image falls back from the SEO image to the main image, the first block image, and finally Theme > Branding > Identity.

Structured data is one JSON-LD graph per page:

- `Organization` everywhere: name, logo, social profiles (`sameAs`) and, when the Footer has a phone or an email, a `ContactPoint`.
- `WebSite` on the home page of each language, with a `SearchAction` that points at that language's search page.
- `BlogPosting` for a post and `Article` for a project: headline (cut at 110 characters), address, language, publisher, the author (the person named in the editor, otherwise the organization), `datePublished` and `dateModified`, the summary, and a picture (the entry's main image, then its SEO share image, then the first picture in its blocks, then the site's default share image, as a full address).
- `WebPage` for every other page, pointing at the site.
- `BreadcrumbList` on inner pages, and `FAQPage` from FAQ blocks.

Text is escaped so nothing typed in the admin can end the script tag. Templates can add nodes through the `structured_data` variable or override `{% block structured_data %}`.

## Accessibility

- Skip link to `<main id="main">`, visible focus styles, and `prefers-reduced-motion` support.
- One `<h1>` per page and no skipped heading levels (blocks and cards pick their level from the page).
- Mobile menu: a dialog with `aria-expanded`, focus moved in, kept in, and returned on close; Escape closes it.
- Cards and feature items have one link each (the title), stretched over the item.
- Forms: a field can take a half, a third or two thirds of the row (`field.width`: `form-w-half`, `form-w-third`, `form-w-two-thirds` on a screen from 40rem), and a form can hold a heading (`.form-heading`) and a text (`.form-text`) between its fields; `components/form.twig` draws them. Labels for every control, `aria-describedby` for help and errors, `aria-invalid`, announced status messages, and `autocomplete` hints.
- Social links render only when set in Theme settings (no `#` placeholders).
- Submenus open on hover and focus and close with Escape (focus returns to the parent link); overlays (viewers, the mobile menu) return focus to what opened them.
- Markdown tables are supported and sit in a keyboard-focusable box that scrolls sideways on narrow screens, so no page needs horizontal scrolling at 320 px.
- Blocks without their own heading give their items `<h2>`, so levels never skip.
- `scripts/theme-audit.js` checks pages with axe-core (WCAG 2.2 AA), reflow at 320 px, and the contrast of the theme's colour pairs in all palettes; see the developer guide. It can scan at a phone width and with the phone menu open (`themeAudit.pages(['/'], {width: 375, menu: true})`).
- The phone menu (Theme settings > Header > Phone menu: side drawer from the left or right, full screen, sheet from the top or bottom) is one panel outside the header, so a sticky, blurred, or transparent header never changes how it looks. While it is open the rest of the page is inert, focus stays inside, Escape closes it, and focus returns to the button that opened it. The showcase page `/blocks`, every content page, and the interactive states (mobile menu, viewers, open FAQ) were checked in light and dark.

## Server configuration

Theme assets and image variants go through `public/index.php` when no file exists. Apache needs nothing beyond the shipped `.htaccess`. On nginx every location that serves static files must end in `try_files $uri /index.php?$query_string;` (see `security.md`). For local development pass the router: `php -S 127.0.0.1:8087 -t public public/index.php`.

## Assets

Theme and custom assets live outside `public/` and are served by `ThemeAssets` from `public/index.php` before the application boots. Only static types are served: css, js, map, json, images, and fonts. URLs carry a `?v=` version from the file's mtime and size, and versioned responses are cached for a year. Block stylesheets of a page are served as one bundle (`/_themes/default/_blocks.css?b=hero,faq`).

In templates:

```twig
<link rel="stylesheet" href="{{ theme_asset('css/site.css') }}">
{% set extra = custom_asset('css/custom.css') %}{# '' when the file does not exist #}
```

## Translations

Strings resolve in this order, later wins:

1. the theme's default-language file
2. the site's overrides for the default language
3. the theme's file for the requested language
4. the site's overrides for the requested language

Admin > Translations saves only the strings that differ from what the theme provides. Resetting a string removes the override, so the theme text (and its future fixes) applies again.

The screen is one language at a time (each language shows how far it is translated; the default language is the source). The strings are grouped by the word before the first dot of their key (`form.error.required` is in *Form*; a key with no dot is in *General*), each with its source text beside the box to type in. A search finds a key or a text, the filters show the strings that are missing, still say what the source language says, or are customized, and a string you add (*Add a string of your own*) is marked as yours and can be deleted. Name keys with an area first (`shop.buy_now`) and the screen keeps them together.

## Backups

Full backups include `custom/`. The restore screen offers it as **Site customizations**.

## Icons

Icons are SVG files in `themes/<theme>/icons/` (and `custom/icons/`, which wins). A field of `type: icon` in `block.yaml` or in the `settings` of `theme.yaml` offers exactly that set, and the admin shows it as a popup of small pictures (`public/assets/js/admin-icons.js`). Use `{{ icon('name') }}` in templates; a name that does not exist prints nothing.
