# Blocks of this installation (GnozioCMS 0.1.260)

## Fields every block has

| field | meaning |
|---|---|
| `type` | the block type (required) |
| `variant` | the layout; each block lists its own, the first is the default |
| `tone` | background: `default`, `muted`, `contrast`, `accent` (a block can have another default, see its entry) |
| `spacing` | space around it: `default`, `compact`, `spacious`, `none` |
| `width` | how much of the row it takes: `25`, `33`, `50`, `66`, `75` (a number; `100`, the whole width, is the default and is not written). Blocks under 100% that follow each other sit side by side and wrap when the row is full (`33 + 33 + 33`, `25 + 25 + 50`, `66 + 33`, `75 + 25`); a 100% block starts a new row. On a tablet 25 and 33 become 50, 66 and 75 become 100, on a phone every block is full width. The block that opens a page (a hero) is always full width |
| `anchor` | an id for in-page links (`services` makes `#services`) |
| `hidden` | `true` keeps the block in the file but not on the site |

Kinds of value: **link** is a page (`contact`, relative to the page's language), a path (`/en/contact`), a full URL, `#anchor`, `mailto:` or `tel:`. **image**, **video** and **file** are `/uploads/media/<id>.<ext>` (a library file) or a full https URL; no spaces, quotes or brackets. **icon** is a name from the icon set (`python3 scripts/site_info.py --icons`). **Markdown** fields take Markdown. A **choice** must be exactly one of the listed values. A **list of rows** (repeater) is a YAML list of maps; a row may hold a list of its own (`slides[].buttons[]`), never deeper. Values equal to the default can be left out. A value the CMS does not accept is replaced by the default without any message, so check with `validate_content.py`.

## Containers: rows with columns

A **container** is an entry of `blocks:` that is not a block: a section with one to six columns and blocks inside each column. Use it for what a block's `width` cannot do: a text beside a picture beside a form, a 25 / 50 / 25 row, a band with a picture behind its columns. **One level only**: a container inside a column is dropped. A page without containers is not affected.

```yaml
blocks:
  - type: container
    settings: {gap: l, justify: center}        # only what differs from the default
    columns:
      - settings: {width: 1/3, padding: m}     # 1/3 of the row
        blocks:
          - {type: text, heading: One}
      - settings: {width: 2/3}
        blocks:
          - {type: image, image: /uploads/media/<id>.jpg}
          - {type: cta, heading: Go on, actions: [{label: Contact, url: contact}]}
```

Container `settings` (all optional): `direction` (`row`, `row-reverse`), `justify` (`start center end between around evenly`), `align` (`stretch start center end baseline`), `align_content`, `wrap` (`wrap nowrap wrap-reverse`), `gap` and `row_gap` (`none xs s m l xl`, default `m`), `stack_desktop`, `stack_tablet`, `stack_phone` (how columns stack on that screen: `row`, `two` = wrap to 2, `one` = stack to 1; tablet default `auto`, phone default `one`), `reverse_phone` (`true` reverses the order when stacked), `tone` (`default muted contrast accent`), `image` + `overlay` (`none light dark`), `inner` (`container narrow full`), `spacing`, `min_height` (`none s m l screen`), `anchor`, `class`, `hidden`.

Column `settings` (all optional): `width` (`fill` = an equal share, the default; `auto`; a fraction like `1/3` or `2/3`; a percentage like `37%`; a fixed size like `20rem` or `320px`), `min_width`, `max_width`, `grow`, `shrink`, `basis` (set any of these and the column is `flex: grow shrink basis`, replacing `width`), `align_self` (`auto start center end stretch baseline`), `valign` (`top center bottom`), `order` (-6 to 6), `padding` (`none xs s m l xl`), `tone`, `radius` (`none s m l`). Gaps and paddings are the theme's spacing steps, never free sizes.

A block inside a column takes the room of the column: its own `width` is ignored there (and not shown in the editor). A `content` block inside a column places the page text. Presets in the editor: 50/50 (`1/2 1/2`), 33/67 (`1/3 2/3`), 67/33, 3×33 (`1/3 1/3 1/3`), 25/50/25 (`1/4 1/2 1/4`), 4×25. `validate_content.py` checks containers (settings, one to six columns, the blocks inside).

### `accordion` — Accordion
Compact titles that open and close, made to sit in a narrow column. Smaller than FAQ and without FAQ structured data; use FAQ for a page of questions.

**Layouts** (`variant`): `default` (Default). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `items` | list of rows; at most 30 rows |  |
| &nbsp;&nbsp;`items[].title` | text |  |
| &nbsp;&nbsp;`items[].content` | Markdown |  |
| `initial` | choice; one of `closed`, `first`; default `closed` |  |
| `multiple` | true / false; default `true` |  |
| `icon` | choice; one of `chevron`, `plus`; default `chevron` |  |
| `style` | choice; one of `lines`, `cards`; default `lines` |  |

### `archive-title` — Archive title
The title area of a list of entries: its title and subtitle (for a category or tag, its name and description), a picture, and the trail. Only in an archive template built with blocks.

**Layouts** (`variant`): `auto` (As set for the list), `default` (Default), `centered` (Centered), `split` (Split), `cover` (Cover), `minimal` (Minimal), `chapter` (Chapter (a collection's number, title and quote)). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `image` | image | Optional. Leave empty to use the picture chosen for the list in Archive Layouts (in the Chapter layout, the term's own picture first). |
| `breadcrumbs` | true / false; default `false` |  |

### `banner` — Banner
An announcement or highlight, as a slim strip or a boxed callout with an optional button. Visitors can dismiss it if you allow that.

**Layouts** (`variant`): `strip` (Slim strip), `callout` (Boxed callout). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `icon` | icon name; default `megaphone` |  |
| `title` | text |  |
| `text` | text (several lines) |  |
| `link_label` | text |  |
| `url` | link |  |
| `dismissible` | true / false; default `false` | The choice is remembered in the visitor's browser for 30 days, and shown again if you change the text. |

### `before-after` — Before and after
Two pictures of the same subject. Visitors drag a handle to move between them, or see them side by side. Works with the keyboard.

**Layouts** (`variant`): `slider` (Slider), `side` (Side by side). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `before` | image |  |
| `before_alt` | text | What the image shows. Leave empty to use the description from the media library. |
| `before_label` | text |  |
| `after` | image | Use a picture with the same framing as the first one. |
| `after_alt` | text |  |
| `after_label` | text |  |
| `caption` | text |  |
| `image_ratio` | choice; one of `landscape`, `wide`, `portrait`, `square`; default `landscape` |  |

### `buttons` — Buttons
A row of buttons and nothing else. Type them, or, in a template built with blocks, bind the list to a list of the entry (the buy links of a book).

**Layouts** (`variant`): `left` (Left), `centered` (Centered). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `actions` | list of rows; at most 12 rows |  |
| &nbsp;&nbsp;`actions[].label` | text |  |
| &nbsp;&nbsp;`actions[].url` | link | A page ("contact"), a path ("/en/contact"), a full URL, #anchor, mailto: or tel: |
| &nbsp;&nbsp;`actions[].style` | choice; one of `primary`, `secondary`, `text`; default `primary` |  |

### `cards` — Cards
Latest posts, projects, or any content type, or hand-picked cards with an image, text, and link.

**Layouts** (`variant`): `grid` (Grid), `list` (Compact list). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `source` | choice; a content type of the site; default `manual` |  |
| `limit` | whole number; 1 to 12; default `3` |  |
| `order` | choice; one of `date_desc`, `date_asc`, `title_asc`, `title_desc`, `random`; default `date_desc`; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` |  |
| `offset` | whole number; 0 to 50; default `0`; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` | Leaves out this many of the first entries in the order above (with Newest first, the newest), for example the ones another block of the page already shows. |
| `skip_shown` | true / false; default `false`; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` | Entries that a block higher on the page already shows (or the page itself) are left out, and the next ones take their place. |
| `columns` | choice; one of `2`, `3`, `4`; default `3` |  |
| `items` | list of rows; at most 12 rows |  |
| &nbsp;&nbsp;`items[].title` | text |  |
| &nbsp;&nbsp;`items[].text` | text (several lines) |  |
| &nbsp;&nbsp;`items[].meta` | text |  |
| &nbsp;&nbsp;`items[].image` | image |  |
| &nbsp;&nbsp;`items[].url` | link |  |
| `link_label` | text |  |
| `link_url` | link |  |

### `checklist` — Checklist
A list of points marked with a tick or an icon. What is included, what you get, why choose us.

**Layouts** (`variant`): `plain` (Plain list), `cards` (Cards), `split` (Heading beside the list). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `columns` | choice; one of `1`, `2`, `3`; default `2` |  |
| `icon` | icon name; default `check` | The mark in front of each point. A point can choose its own. |
| `items` | list of rows; at most 40 rows |  |
| &nbsp;&nbsp;`items[].text` | text |  |
| &nbsp;&nbsp;`items[].note` | text (several lines) | A line or two under the point. |
| &nbsp;&nbsp;`items[].icon` | icon name | Leave empty for the block's mark. |
| &nbsp;&nbsp;`items[].excluded` | true / false; default `false` | Shown muted with a cross. |

### `columns` — Columns
Two or three columns side by side, each with an optional picture, a heading, text and a link. Stacks on a phone.

**Layouts** (`variant`): `image-side` (Picture beside the text), `image-top` (Picture above the text). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `items` | list of rows; at most 3 rows |  |
| &nbsp;&nbsp;`items[].eyebrow` | text |  |
| &nbsp;&nbsp;`items[].title` | text |  |
| &nbsp;&nbsp;`items[].url` | link | The heading becomes a link, and the link text below a button. |
| &nbsp;&nbsp;`items[].image` | image |  |
| &nbsp;&nbsp;`items[].image_alt` | text |  |
| &nbsp;&nbsp;`items[].text` | Markdown |  |
| &nbsp;&nbsp;`items[].link_label` | text |  |

### `compare` — Comparison table
Compare plans, options, or approaches feature by feature. Type yes or no in a cell to show a check or a cross, or write any text.

**Layouts** (`variant`): `lines` (Lines), `striped` (Striped rows). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `columns` | list of rows; at most 4 rows |  |
| &nbsp;&nbsp;`columns[].name` | text | The column shows only if it has a name. |
| &nbsp;&nbsp;`columns[].badge` | text |  |
| &nbsp;&nbsp;`columns[].highlight` | true / false; default `false` |  |
| &nbsp;&nbsp;`columns[].button_label` | text |  |
| &nbsp;&nbsp;`columns[].button_url` | link |  |
| `rows` | list of rows; at most 30 rows |  |
| &nbsp;&nbsp;`rows[].label` | text |  |
| &nbsp;&nbsp;`rows[].group` | true / false; default `false` |  |
| &nbsp;&nbsp;`rows[].v1` | text | Yes or no shows a check or a cross. |
| &nbsp;&nbsp;`rows[].v2` | text |  |
| &nbsp;&nbsp;`rows[].v3` | text |  |
| &nbsp;&nbsp;`rows[].v4` | text |  |
| `note` | text |  |

### `contact` — Contact
Contact details (phone, email, address, hours) with an optional form beside them.

**Layouts** (`variant`): `split` (Details beside the form), `cards` (Details as cards). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `items` | list of rows; at most 8 rows |  |
| &nbsp;&nbsp;`items[].icon` | icon name |  |
| &nbsp;&nbsp;`items[].label` | text |  |
| &nbsp;&nbsp;`items[].value` | text (several lines) |  |
| &nbsp;&nbsp;`items[].url` | link | e.g. tel:+302100000000 or mailto:hello@example.com |
| `form` | choice; the slug of a form of the site (content/forms) |  |

### `content` — Page content
The page's own Markdown text. Pages with blocks show it after an opening hero unless this block places it elsewhere.

**Layouts** (`variant`): `narrow` (Reading width), `wide` (Full width). First is the default.

| field | kind and choices | notes |
|---|---|---|

### `cta` — Call to action
A short, focused invitation with one or two buttons.

**Layouts** (`variant`): `band` (Full-width band), `card` (Contained card), `split` (Text beside the buttons). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text; required |  |
| `text` | text (several lines) |  |
| `actions` | list of rows; at most 2 rows |  |
| &nbsp;&nbsp;`actions[].label` | text |  |
| &nbsp;&nbsp;`actions[].url` | link |  |
| &nbsp;&nbsp;`actions[].style` | choice; one of `primary`, `secondary`, `text`; default `primary` |  |

### `divider` — Divider
Space between two sections, a line (optionally with a short label in the middle), or a thin band of colours.

**Layouts** (`variant`): `space` (Empty space), `line` (Line), `label` (Line with a label), `thread` (Band of colours). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `label` | text; only when `variant` is `l` or `a` or `b` or `e` or `l` |  |
| `height` | choice; one of `s`, `m`, `l`, `xl`; default `m`; only when `variant` is `s` or `p` or `a` or `c` or `e` |  |
| `style` | choice; one of `solid`, `dashed`, `dotted`; default `solid`; only when `variant` is `line` or `label` |  |
| `line_width` | choice; one of `page`, `narrow`, `short`; default `narrow`; only when `variant` is `line` or `label` |  |
| `thread_from` | choice; one of `colours`, `terms`; default `colours`; only when `variant` is `t` or `h` or `r` or `e` or `a` or `d` |  |
| `thread_taxonomy` | text; default `collections`; only when `variant` is `t` or `h` or `r` or `e` or `a` or `d` and `thread_from` is `t` or `e` or `r` or `m` or `s` | The address of the taxonomy whose terms give the colours (their deep tone, in their order), for example collections. |
| `thread_tone` | choice; one of `deep`, `surface`; default `deep`; only when `variant` is `t` or `h` or `r` or `e` or `a` or `d` and `thread_from` is `t` or `e` or `r` or `m` or `s` | Which of each term's tones colours its stripe. |
| `colour_1` | colour; only when `variant` is `t` or `h` or `r` or `e` or `a` or `d` and `thread_from` is `c` or `o` or `l` or `o` or `u` or `r` or `s` |  |
| `colour_2` | colour; only when `variant` is `t` or `h` or `r` or `e` or `a` or `d` and `thread_from` is `c` or `o` or `l` or `o` or `u` or `r` or `s` |  |
| `colour_3` | colour; only when `variant` is `t` or `h` or `r` or `e` or `a` or `d` and `thread_from` is `c` or `o` or `l` or `o` or `u` or `r` or `s` |  |
| `colour_4` | colour; only when `variant` is `t` or `h` or `r` or `e` or `a` or `d` and `thread_from` is `c` or `o` or `l` or `o` or `u` or `r` or `s` |  |
| `colour_5` | colour; only when `variant` is `t` or `h` or `r` or `e` or `a` or `d` and `thread_from` is `c` or `o` or `l` or `o` or `u` or `r` or `s` |  |
| `colour_6` | colour; only when `variant` is `t` or `h` or `r` or `e` or `a` or `d` and `thread_from` is `c` or `o` or `l` or `o` or `u` or `r` or `s` |  |
| `thread_height` | choice; one of `xs`, `s`, `m`; default `s`; only when `variant` is `t` or `h` or `r` or `e` or `a` or `d` |  |

### `downloads` — Downloads
Documents to download, each with its kind of file and size. Pick the files from the media library; the size is read from the file.

**Layouts** (`variant`): `list` (List), `cards` (Cards). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `columns` | choice; one of `1`, `2`, `3`; default `2`; only when `variant` is `c` or `a` or `r` or `d` or `s` |  |
| `button_label` | text |  |
| `items` | list of rows; at most 30 rows |  |
| &nbsp;&nbsp;`items[].title` | text | Leave empty to use the file name. |
| &nbsp;&nbsp;`items[].description` | text (several lines) |  |
| &nbsp;&nbsp;`items[].file` | file |  |
| &nbsp;&nbsp;`items[].size` | text | Leave empty to read it from the file. |

### `entry-blocks` — Entry blocks
The blocks the entry has of its own (its Blocks tab), drawn here. Use it to leave room in the template for what each entry builds itself. Only in a template built with blocks.

**Layouts** (`variant`): `default` (Default). First is the default.

| field | kind and choices | notes |
|---|---|---|

### `entry-list` — Entry list
The entries of the list, in pages. Each entry is drawn with the card of the template (blocks that show the entry's fields), or with the theme's card when the template has none. Only in an archive template built with blocks.

**Layouts** (`variant`): `grid` (Grid), `list` (List), `compact` (Compact). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `columns` | choice; one of `2`, `3`, `4`; default `3` |  |
| `per_page` | whole number; 0 to 60; default `12` | 0 shows everything on one page. |
| `order` | text; default `date_desc` |  |
| `visitor_sort` | true / false; default `false` | Shows a "Sort by" choice above the list. A page in another order is not indexed by search engines. |
| `sort_choices` | list of rows | Used when visitors can choose the order. Leave empty to offer every order of the list but the random one. |
| &nbsp;&nbsp;`sort_choices[].order` | text |  |
| `editorial_after` | whole number; default `0` | Takes the width of two entries, with the term's picture unless one is chosen below. 0 for none. |
| `editorial_image` | image |  |
| `pagination` | true / false; default `true` |  |
| `empty_text` | text | Optional. Leave empty for the theme's text. |
| `show_image` | true / false; default `true` |  |
| `show_excerpt` | true / false; default `true` |  |
| `show_meta` | true / false; default `true` |  |

### `entry-map` — Map of the list
The entries of the list that have a place, on one map, with the same filters as the list (a route is drawn as a line). The bar of the map filters them further. Shows nothing when no entry of the list has a place. Only in an archive template built with blocks.

**Layouts** (`variant`): `contained` (Inside the page width), `full` (Full width). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `height` | choice; one of `small`, `medium`, `large`, `tall`; default `large` |  |
| `list` | choice; one of `right`, `left`, `below`, `none`; default `right` |  |
| `filters` | true / false; default `true` |  |
| `cluster` | true / false; default `true` |  |
| `load` | choice; one of `site`, `click`, `auto`; default `site` |  |

### `entry-title` — Entry title
The title area of the entry, as its page draws it today: the title, the excerpt, the main image and a line with its terms. Only in a template built with blocks.

**Layouts** (`variant`): `auto` (As set for the content type), `default` (Default), `centered` (Centered), `split` (Split), `cover` (Cover), `minimal` (Minimal). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `image` | true / false; default `true` |  |
| `excerpt` | true / false; default `true` |  |
| `byline` | true / false; default `true` |  |
| `breadcrumbs` | true / false; default `false` |  |

### `fact-sheet` — Fact sheet
The fields of the entry as a list of labels and values (for a book: author, publisher, year). Choose which fields and in what order; with none chosen it shows the fields the page of the type shows. Only in a template built with blocks.

**Layouts** (`variant`): `default` (Default). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `heading` | text |  |
| `fields` | list of rows | Leave the list empty to show every field the type's page shows. A field the type gets later can be added here. |
| &nbsp;&nbsp;`fields[].field` | text |  |

### `faq` — FAQ
Questions and answers that open one at a time. Adds FAQ structured data for search engines.

**Layouts** (`variant`): `stacked` (Single column), `split` (Heading beside the questions). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `items` | list of rows; at most 30 rows |  |
| &nbsp;&nbsp;`items[].question` | text |  |
| &nbsp;&nbsp;`items[].answer` | Markdown |  |
| `initial` | choice; one of `closed`, `first`; default `closed` |  |
| `schema` | true / false; default `true` |  |

### `features` — Features
A grid of features or services, each with an icon, a title, a short text, and an optional link.

**Layouts** (`variant`): `cards` (Cards), `plain` (Plain, no cards), `numbered` (Numbered steps), `strip` (A strip of short points). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `align` | choice; one of `left`, `center`; default `left` |  |
| `columns` | choice; one of `2`, `3`, `4`; default `3` |  |
| `items` | list of rows; at most 12 rows |  |
| &nbsp;&nbsp;`items[].icon` | icon name |  |
| &nbsp;&nbsp;`items[].title` | text |  |
| &nbsp;&nbsp;`items[].text` | text (several lines) |  |
| &nbsp;&nbsp;`items[].link` | link |  |
| &nbsp;&nbsp;`items[].link_label` | text |  |

### `filter-bar` — Filters
The filters of a list of entries (the taxonomies and the select fields chosen for it), as a form with no script. Only values that some entry has are offered. Only in an archive template built with blocks.

**Layouts** (`variant`): `default` (Default), `chips` (Chips (one tap per choice)). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `show_count` | true / false; default `true` |  |

### `form` — Form
One of the site's forms (Admin > Forms) with a heading and introduction.

**Layouts** (`variant`): `split` (Text beside the form), `stacked` (Form below the text). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `form` | choice; the slug of a form of the site (content/forms) |  |

### `gallery` — Gallery
A set of images in a grid, a masonry layout, or a scrolling strip. Images open larger in a viewer.

**Layouts** (`variant`): `grid` (Even grid), `masonry` (Masonry), `strip` (Scrolling strip). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `source` | choice; one of `manual`, `entry`; default `manual` | The entry's gallery: the pictures of the page or entry the block is on (in a template, the entry it is drawn for). Shows nothing when it has none. |
| `field` | text; only when `source` is `entry` | The key of the field, for a type with more than one gallery. Empty means its gallery. |
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `columns` | choice; one of `2`, `3`, `4`; default `3` |  |
| `ratio` | choice; one of `landscape`, `square`, `portrait`, `original`; default `landscape` |  |
| `lightbox` | true / false; default `true` |  |
| `images` | list of rows; at most 60 rows; only when `source` is `manual` |  |
| &nbsp;&nbsp;`images[].image` | image |  |
| &nbsp;&nbsp;`images[].alt` | text | What the image shows. Leave empty to use the media library text. |
| &nbsp;&nbsp;`images[].caption` | text |  |

### `hero` — Hero
Opening section with a strong heading, supporting text, buttons, and an image or a video behind it. The steps layout adds up to four numbered steps over the image. It can show the newest entry of a content type (a post, a project) instead of typed text. As the first block of a page it becomes the page title (h1).

**Layouts** (`variant`): `split` (Text and image side by side), `centered` (Centered text, wide image below), `cover` (Text over a full-width image), `cover-centered` (Centered text over a full-width image), `steps` (Text over a full-width image, with numbered steps), `steps-centered` (Centered text over a full-width image, with numbered steps), `minimal` (Large heading, no image), `mosaic` (Text beside three pictures). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `source` | choice; a content type of the site; default `manual` | Or the newest entry of a content type: its title, excerpt and main image, with a button to it. |
| `term` | text; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` | Optional. The slug of a category or tag, as in Taxonomies. |
| `order` | choice; one of `date_desc`, `date_asc`, `title_asc`, `title_desc`, `random`; default `date_desc`; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` |  |
| `offset` | whole number; 0 to 50; default `0`; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` | Leaves out this many of the first entries in the order above (with Newest first, the newest), for example the ones another block of the page already shows. |
| `skip_shown` | true / false; default `false`; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` | Entries that a block higher on the page already shows (or the page itself) are left out, and the next ones take their place. |
| `entry_button` | text; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` |  |
| `eyebrow` | text | With an entry, its category when this is empty. |
| `tagline` | text |  |
| `heading` | text; required; only when `source` is `m` or `a` or `n` or `u` or `a` or `l` |  |
| `text` | text (several lines); only when `source` is `m` or `a` or `n` or `u` or `a` or `l` |  |
| `note` | text |  |
| `actions` | list of rows; at most 2 rows; only when `source` is `m` or `a` or `n` or `u` or `a` or `l` |  |
| &nbsp;&nbsp;`actions[].label` | text |  |
| &nbsp;&nbsp;`actions[].url` | link | A page ("contact"), a path ("/en/contact"), a full URL, #anchor, mailto: or tel: |
| &nbsp;&nbsp;`actions[].style` | choice; one of `primary`, `secondary`, `text`; default `primary` |  |
| `highlights` | list of rows; at most 3 rows |  |
| &nbsp;&nbsp;`highlights[].text` | text |  |
| `items` | list of rows; at most 4 rows |  |
| &nbsp;&nbsp;`items[].title` | text |  |
| &nbsp;&nbsp;`items[].text` | text (several lines) |  |
| &nbsp;&nbsp;`items[].link` | link |  |
| &nbsp;&nbsp;`items[].link_label` | text |  |
| `background` | choice; one of `image`, `video`; default `image`; only when `source` is `m` or `a` or `n` or `u` or `a` or `l` |  |
| `image` | image; only when `background` is `i` or `m` or `a` or `g` or `e` and `source` is `m` or `a` or `n` or `u` or `a` or `l` |  |
| `image_alt` | text; only when `background` is `i` or `m` or `a` or `g` or `e` and `source` is `m` or `a` or `n` or `u` or `a` or `l` | What the image shows, for people who cannot see it. Leave empty if it is purely decorative. |
| `image_2` | image; only when `variant` is `m` or `o` or `s` or `a` or `i` or `c` and `source` is `m` or `a` or `n` or `u` or `a` or `l` | The smaller pictures of the mosaic, beside and under the first, for example a detail and a texture. |
| `image_3` | image; only when `variant` is `m` or `o` or `s` or `a` or `i` or `c` and `source` is `m` or `a` or `n` or `u` or `a` or `l` |  |
| `video` | video; only when `background` is `v` or `i` or `d` or `e` or `o` and `source` is `m` or `a` or `n` or `u` or `a` or `l` | Choose a video from the media library (.mp4 or .webm), or paste a YouTube or Vimeo link, or the address of a video file elsewhere. It plays silently, in a loop, with a pause button. A YouTube or Vimeo video is loaded from them when the page opens. |
| `video_poster` | image; only when `background` is `v` or `i` or `d` or `e` or `o` and `source` is `m` or `a` or `n` or `u` or `a` or `l` | Shown while the video loads, when the visitor has asked their device for less motion, and under a video that is paused. Recommended, and it is the share image of the page when it has none. |

### `hero-slider` — Hero slider
Slides that each are a Hero. Every slide picks its own Hero layout (including the ones with numbered steps) and has everything a Hero has - heading, text, buttons, highlights, and an image or a video behind the text - styled exactly like it, on a phone too. Previous and next buttons, dots, swiping and the keyboard work; it never moves by itself unless you turn that on. The slides can also be the newest entries of a content type (posts, projects), each with its title, excerpt, main image and a button to it.

**Layouts** (`variant`): `default` (Default). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `source` | choice; a content type of the site; default `manual` | Or the newest entries of a content type: a slide for each, with its title, excerpt and main image, and a button to it. |
| `term` | text; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` | Optional. The slug of a category or tag, as in Taxonomies. |
| `limit` | whole number; 1 to 8; default `3`; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` |  |
| `order` | choice; one of `date_desc`, `date_asc`, `title_asc`, `title_desc`, `random`; default `date_desc`; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` |  |
| `offset` | whole number; 0 to 50; default `0`; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` | Leaves out this many of the first entries in the order above (with Newest first, the newest), for example the ones another block of the page already shows. |
| `skip_shown` | true / false; default `false`; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` | Entries that a block higher on the page already shows (or the page itself) are left out, and the next ones take their place. |
| `entry_layout` | choice; one of `split`, `centered`, `cover`, `cover-centered`, `minimal`; default `cover`; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` |  |
| `entry_button` | text; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` |  |
| `navigation` | choice; one of `below`, `inside`; default `below` | Over the slides suits slides that are all full-width pictures. |
| `autoplay` | choice; one of `off`, `5`, `8`, `12`; default `off` | A pause button is shown, and visitors who prefer reduced motion never get automatic movement. |
| `items` | list of rows; at most 8 rows; only when `source` is `m` or `a` or `n` or `u` or `a` or `l` |  |
| &nbsp;&nbsp;`items[].layout` | choice; one of `split`, `centered`, `cover`, `cover-centered`, `steps`, `steps-centered`, `minimal`; default `split` |  |
| &nbsp;&nbsp;`items[].eyebrow` | text |  |
| &nbsp;&nbsp;`items[].heading` | text |  |
| &nbsp;&nbsp;`items[].text` | text (several lines) |  |
| &nbsp;&nbsp;`items[].buttons` | list of rows; at most 3 rows |  |
| &nbsp;&nbsp;&nbsp;&nbsp;`items[].buttons[].label` | text |  |
| &nbsp;&nbsp;&nbsp;&nbsp;`items[].buttons[].url` | link | A page ("contact"), a path ("/en/contact"), a full URL, #anchor, mailto: or tel: |
| &nbsp;&nbsp;&nbsp;&nbsp;`items[].buttons[].style` | choice; one of `primary`, `secondary`, `text`; default `primary` |  |
| &nbsp;&nbsp;`items[].highlight_1` | text |  |
| &nbsp;&nbsp;`items[].highlight_2` | text |  |
| &nbsp;&nbsp;`items[].highlight_3` | text |  |
| &nbsp;&nbsp;`items[].step_1_title` | text; only when `layout` is `steps` or `steps-centered` |  |
| &nbsp;&nbsp;`items[].step_1_link` | link; only when `layout` is `steps` or `steps-centered` |  |
| &nbsp;&nbsp;`items[].step_1_link_label` | text; only when `layout` is `steps` or `steps-centered` |  |
| &nbsp;&nbsp;`items[].step_1_text` | text (several lines); only when `layout` is `steps` or `steps-centered` |  |
| &nbsp;&nbsp;`items[].step_2_title` | text; only when `layout` is `steps` or `steps-centered` |  |
| &nbsp;&nbsp;`items[].step_2_link` | link; only when `layout` is `steps` or `steps-centered` |  |
| &nbsp;&nbsp;`items[].step_2_link_label` | text; only when `layout` is `steps` or `steps-centered` |  |
| &nbsp;&nbsp;`items[].step_2_text` | text (several lines); only when `layout` is `steps` or `steps-centered` |  |
| &nbsp;&nbsp;`items[].step_3_title` | text; only when `layout` is `steps` or `steps-centered` |  |
| &nbsp;&nbsp;`items[].step_3_link` | link; only when `layout` is `steps` or `steps-centered` |  |
| &nbsp;&nbsp;`items[].step_3_link_label` | text; only when `layout` is `steps` or `steps-centered` |  |
| &nbsp;&nbsp;`items[].step_3_text` | text (several lines); only when `layout` is `steps` or `steps-centered` |  |
| &nbsp;&nbsp;`items[].step_4_title` | text; only when `layout` is `steps` or `steps-centered` |  |
| &nbsp;&nbsp;`items[].step_4_link` | link; only when `layout` is `steps` or `steps-centered` |  |
| &nbsp;&nbsp;`items[].step_4_link_label` | text; only when `layout` is `steps` or `steps-centered` |  |
| &nbsp;&nbsp;`items[].step_4_text` | text (several lines); only when `layout` is `steps` or `steps-centered` |  |
| &nbsp;&nbsp;`items[].background` | choice; one of `image`, `video`; default `image`; only when `layout` is `split` or `centered` or `cover` or `cover-centered` or `steps` or `steps-centered` |  |
| &nbsp;&nbsp;`items[].image` | image; only when `background` is `i` or `m` or `a` or `g` or `e` and `layout` is `split` or `centered` or `cover` or `cover-centered` or `steps` or `steps-centered` |  |
| &nbsp;&nbsp;`items[].image_alt` | text; only when `background` is `i` or `m` or `a` or `g` or `e` and `layout` is `split` or `centered` or `cover` or `cover-centered` or `steps` or `steps-centered` | What the image shows, for people who cannot see it. Leave empty if it is purely decorative. |
| &nbsp;&nbsp;`items[].video` | video; only when `background` is `v` or `i` or `d` or `e` or `o` and `layout` is `split` or `centered` or `cover` or `cover-centered` or `steps` or `steps-centered` | Choose a video from the media library (.mp4 or .webm), or paste a YouTube or Vimeo link, or the address of a video file elsewhere. It plays silently, in a loop, with a pause button. |
| &nbsp;&nbsp;`items[].video_poster` | image; only when `background` is `v` or `i` or `d` or `e` or `o` and `layout` is `split` or `centered` or `cover` or `cover-centered` or `steps` or `steps-centered` | Shown while the video loads, when the visitor has asked their device for less motion, and under a video that is paused. |

### `image` — Image
One image with a caption, an optional link, and the shape you choose. It can be narrower than its column.

**Layouts** (`variant`): `wide` (Page width), `narrow` (Text width), `full` (Edge to edge). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `image` | image |  |
| `alt` | text | What the image shows. Leave empty to use the media library text. |
| `caption` | text |  |
| `link` | link | Makes the image a link. |
| `new_tab` | true / false; default `false` |  |
| `ratio` | choice; one of `original`, `landscape`, `classic`, `square`, `portrait`, `panorama`; default `original` |  |
| `rounded` | true / false; default `true` |  |
| `caption_align` | choice; one of `left`, `center`; default `left` |  |
| `image_width` | whole number; 10 to 2000 | Empty is the whole column. In % (10 to 100) of the column, or in px (40 to 2000), never wider than the column. The height follows the picture. |
| `image_width_unit` | choice; one of `%`, `px`; default `%` | % of the column, or px. |
| `image_align` | choice; one of `left`, `center`, `right`; default `center` | Where a picture narrower than its column sits. The caption follows. |
| `image_full_mobile` | true / false; default `true` | A narrower picture fills the column on a phone, so small columns stay readable. |

### `image-hotspots` — Image hotspots
A picture with numbered points on it; each opens a short note with a link (the pieces of a look, the rooms of a house, the parts of a product). Beside the picture, the same notes as a list.

**Layouts** (`variant`): `beside` (List beside the picture), `below` (List below the picture). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `image` | image |  |
| `image_alt` | text |  |
| `ratio` | choice; one of `portrait`, `square`, `landscape`, `natural`; default `portrait` |  |
| `points` | list of rows; at most 12 rows |  |
| &nbsp;&nbsp;`points[].x` | whole number; 0 to 100; default `50` |  |
| &nbsp;&nbsp;`points[].y` | whole number; 0 to 100; default `50` |  |
| &nbsp;&nbsp;`points[].title` | text |  |
| &nbsp;&nbsp;`points[].text` | text |  |
| &nbsp;&nbsp;`points[].link` | link |  |
| &nbsp;&nbsp;`points[].link_label` | text |  |

### `latest` — Latest content
The newest posts, projects, or any content type, shown from a quiet text list to a magazine layout. Updates by itself when you publish.

**Layouts** (`variant`): `cards` (Cards), `list` (Minimal list), `compact` (Compact with thumbnails), `overlay` (Image tiles with text on top), `strip` (Scrolling strip), `featured` (Featured story and a list), `magazine` (Magazine (large lead story, cards below)), `editorial` (Editorial (large alternating rows)). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `source` | choice; a content type of the site; default `posts` |  |
| `term` | text | Optional. The slug of a category or tag, as in Taxonomies. |
| `limit` | whole number; 1 to 12; default `3` |  |
| `order` | choice; one of `date_desc`, `date_asc`, `title_asc`, `title_desc`, `random`; default `date_desc` |  |
| `offset` | whole number; 0 to 50; default `0` | Leaves out this many of the first entries in the order above (with Newest first, the newest), for example the ones another block of the page already shows. |
| `skip_shown` | true / false; default `false` | Entries that a block higher on the page already shows (or the page itself) are left out, and the next ones take their place. |
| `columns` | choice; one of `2`, `3`, `4`; default `3` |  |
| `show_image` | true / false; default `true` |  |
| `show_excerpt` | true / false; default `true` |  |
| `show_meta` | true / false; default `true` |  |
| `link_label` | text |  |
| `link_url` | link |  |

### `logos` — Logos
Client or partner logos, spread over the whole width of the content however many there are. Without an image the name is shown as text.

**Layouts** (`variant`): `row` (Single row), `grid` (Framed grid). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `heading` | text |  |
| `size` | choice; one of `small`, `medium`, `large`, `xlarge`; default `medium` |  |
| `colors` | choice; one of `hover`, `mono`, `color`; default `hover` |  |
| `items` | list of rows; at most 18 rows |  |
| &nbsp;&nbsp;`items[].name` | text | Used as the logo's alternative text. |
| &nbsp;&nbsp;`items[].image` | image |  |
| &nbsp;&nbsp;`items[].url` | link |  |

### `looks` — Looks
The looks as cards: the picture, the name and how many pieces it has with what they cost together. Each leads to the look's page.

**Layouts** (`variant`): `grid` (Grid). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `limit` | whole number; 1 to 24; default `3` |  |
| `columns` | choice; one of `2`, `3`, `4`; default `3` |  |

### `map` — Map
An OpenStreetMap map with a marker, or a map of the points, routes and businesses of the site with filters and a list. By default a map loads only after the visitor asks for it, so no third-party request is made on page load.

**Layouts** (`variant`): `contained` (Inside the page width), `full` (Full width), `split` (Details beside the map), `overlay` (Full width, list over the map). First is the default.
Some layouts need a field: `split` only when {'source': 'manual'}; `overlay` only when {'source': '!manual'}

| field | kind and choices | notes |
|---|---|---|
| `source` | choice; a content type that has places; default `manual` | Points, routes, businesses, or everything with a place. They are put on the map, with a list beside it. |
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `lat` | decimal number; only when `source` is `m` or `a` or `n` or `u` or `a` or `l` | Right-click the place on openstreetmap.org and choose "Show address" to copy the coordinates. |
| `lng` | decimal number; only when `source` is `m` or `a` or `n` or `u` or `a` or `l` |  |
| `zoom` | whole number; 3 to 19; default `15`; only when `source` is `m` or `a` or `n` or `u` or `a` or `l` |  |
| `address` | text (several lines); only when `source` is `m` or `a` or `n` or `u` or `a` or `l` |  |
| `place_name` | text; only when `source` is `m` or `a` or `n` or `u` or `a` or `l` | Used in the map's title for screen readers, e.g. our office. |
| `height` | choice; one of `small`, `medium`, `large`, `tall`; default `medium` |  |
| `load` | choice; one of `site`, `click`, `auto`; default `site` |  |
| `term` | text; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` | The address of a category, to show only its entries. |
| `limit` | whole number; 1 to 800; default `300`; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` |  |
| `filters` | true / false; default `true`; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` |  |
| `list` | choice; one of `right`, `left`, `below`, `none`; default `right`; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` |  |
| `cluster` | true / false; default `true`; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` |  |

### `marquee` — Marquee
A band that scrolls by itself, with big words or logos. It stops for people who ask for less motion, and has a pause button.

**Layouts** (`variant`): `text` (Big words), `logos` (Logos). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `heading` | text |  |
| `speed` | choice; one of `slow`, `normal`, `fast`; default `normal` |  |
| `direction` | choice; one of `left`, `right`; default `left` |  |
| `items` | list of rows; at most 30 rows |  |
| &nbsp;&nbsp;`items[].text` | text | The word, or the name of the logo (used when there is no image). |
| &nbsp;&nbsp;`items[].image` | image |  |
| &nbsp;&nbsp;`items[].url` | link |  |

### `mini-cart` — Mini cart
A link to the cart with how many pieces are in it, for a footer or a page. The header has one already while the shop is on.

**Layouts** (`variant`): `default` (Default). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `label` | text |  |
| `show_account` | true / false; default `false` |  |

### `newsletter` — Newsletter
A quiet invitation to sign up, like a letter: a heading, a few words and one of the site's forms (an email field and a button, made in Admin > Forms).

**Layouts** (`variant`): `centered` (Centred), `inline` (Text beside the form). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `form` | choice; the slug of a form of the site (content/forms) | The form people sign up with. Keep it short (an email field and a button); its messages and storage are the form's own. |
| `dots` | true / false; default `false` |  |

### `next-term` — Next term
On the page of a term (a collection, a category), a link to the next term of its taxonomy, in their order, as "Next chapter: Kampos →". Only in an archive template built with blocks.

**Layouts** (`variant`): `next` (The next one), `both` (The previous and the next). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `label` | text |  |
| `previous_label` | text; only when `variant` is `b` or `o` or `t` or `h` |  |
| `loop` | true / false; default `true` |  |

### `playlist` — YouTube playlist
The videos of a YouTube playlist, drawn by the site as a grid, a list, a strip, or a player with the list beside it. Nothing is loaded from YouTube until a visitor plays a video. The playlist must be public or unlisted.

**Layouts** (`variant`): `grid` (Grid of videos), `list` (List with descriptions), `featured` (Player with the list beside it), `strip` (Scrolling strip). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `use_title` | true / false; default `false` |  |
| `intro` | text (several lines) |  |
| `playlist` | text; required | The address of the playlist (or its id). Without a YouTube key in Settings > APIs the 15 newest videos are shown; with one, up to 50, with their lengths. |
| `limit` | whole number; 1 to 50; default `6` |  |
| `order` | choice; one of `playlist`, `newest`, `oldest`, `views`; default `playlist` |  |
| `columns` | choice; one of `2`, `3`, `4`; default `3`; only when `variant` is `g` or `r` or `i` or `d` |  |
| `ratio` | choice; one of `wide`, `cinema`, `classic`; default `wide`; only when `variant` is `grid` or `strip` or `featured` |  |
| `play` | choice; one of `lightbox`, `inline`, `youtube`; default `lightbox`; only when `variant` is `grid` or `list` or `strip` |  |
| `thumbs` | choice; one of `site`, `youtube`; default `site` | Showing the pictures from YouTube sends every visitor's address to Google when the page opens. |
| `show_description` | true / false; default `true` |  |
| `show_duration` | true / false; default `true` |  |
| `show_views` | true / false; default `false` |  |
| `show_date` | true / false; default `false` |  |
| `show_numbers` | true / false; default `false`; only when `variant` is `list` or `featured` |  |
| `link_label` | text | Leave empty for no link. |

### `portfolio` — Portfolio
A grid of work with filter buttons for the categories. Type the pieces in, or show the entries of a content type (filtered by their categories).

**Layouts** (`variant`): `grid` (Grid), `overlay` (Title over the picture). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `source` | choice; a content type of the site; default `manual` |  |
| `limit` | whole number; 1 to 24; default `12` |  |
| `order` | choice; one of `date_desc`, `date_asc`, `title_asc`, `title_desc`, `random`; default `date_desc`; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` |  |
| `offset` | whole number; 0 to 50; default `0`; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` | Leaves out this many of the first entries in the order above (with Newest first, the newest), for example the ones another block of the page already shows. |
| `skip_shown` | true / false; default `false`; only when `source` is `!` or `m` or `a` or `n` or `u` or `a` or `l` | Entries that a block higher on the page already shows (or the page itself) are left out, and the next ones take their place. |
| `columns` | choice; one of `2`, `3`, `4`; default `3` |  |
| `ratio` | choice; one of `landscape`, `square`, `portrait`; default `landscape` |  |
| `filters` | true / false; default `true` |  |
| `all_label` | text |  |
| `items` | list of rows; at most 48 rows |  |
| &nbsp;&nbsp;`items[].title` | text |  |
| &nbsp;&nbsp;`items[].category` | text | One or more, separated by commas. |
| &nbsp;&nbsp;`items[].image` | image |  |
| &nbsp;&nbsp;`items[].text` | text (several lines) |  |
| &nbsp;&nbsp;`items[].url` | link |  |

### `pricing` — Pricing
Plans or price lists, as cards side by side or as clean rows. One plan can be highlighted.

**Layouts** (`variant`): `cards` (Plan cards), `list` (Price list). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `note` | text |  |
| `billing_switch` | true / false; default `false` | Visitors choose whether prices are shown per month or per year. Give each plan a yearly price below. |
| `monthly_label` | text; only when `billing_switch` is `1` or `true` |  |
| `yearly_label` | text; only when `billing_switch` is `1` or `true` |  |
| `yearly_note` | text; only when `billing_switch` is `1` or `true` |  |
| `items` | list of rows; at most 8 rows |  |
| &nbsp;&nbsp;`items[].name` | text |  |
| &nbsp;&nbsp;`items[].badge` | text |  |
| &nbsp;&nbsp;`items[].price` | text |  |
| &nbsp;&nbsp;`items[].period` | text |  |
| &nbsp;&nbsp;`items[].yearly_price` | text | Shown when the visitor chooses yearly. Leave empty to keep the same price. |
| &nbsp;&nbsp;`items[].yearly_period` | text | Leave empty for "per year". |
| &nbsp;&nbsp;`items[].description` | text (several lines) |  |
| &nbsp;&nbsp;`items[].features` | text (several lines) | One item per line. |
| &nbsp;&nbsp;`items[].button_label` | text |  |
| &nbsp;&nbsp;`items[].button_url` | link |  |
| &nbsp;&nbsp;`items[].highlight` | true / false; default `false` |  |

### `product-card` — Product card
The whole card of a product: its picture, a second picture on hover, the name and the price. For the card of a list of products.

**Layouts** (`variant`): `default` (Default). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `hover_image` | true / false; default `true` |  |

### `product-details` — Product details
The product's Details sections (Details, Care, Shipping & returns, Warranty) as sections that open one at a time. Only in a template built with blocks for products.

**Layouts** (`variant`): `default` (Default). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `heading` | text |  |
| `initial` | choice; one of `none`, `first`; default `none` |  |

### `product-gallery` — Product gallery
The pictures of the product: one under the other beside its details, or a large one with small ones to choose from. Only in a template built with blocks for products.

**Layouts** (`variant`): `stack` (Pictures one under the other), `thumbs` (Large picture and thumbnails). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `ratio` | choice; one of `portrait`, `square`, `natural`; default `portrait` |  |

### `product-grid` — Product grid
Products as cards with their price: all of them, one collection or kind, a list you choose, or (on a product's page) more from its collection. A picture with a few words can sit between the cards.

**Layouts** (`variant`): `grid` (Grid), `strip` (Scrolling strip). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `source` | choice; one of `all`, `collection`, `category`, `picked`, `related`, `complete`; default `all` |  |
| `term` | text; only when `source` is `collection` or `category` | The address (slug) of the collection or the product category, as in Taxonomies. |
| `picked` | text (several lines); only when `source` is `p` or `i` or `c` or `k` or `e` or `d` | The address (slug) of each product, one per line, in the order to show them. |
| `order` | choice; one of `date_desc`, `date_asc`, `title_asc`, `price_asc`, `price_desc`; default `date_desc` |  |
| `limit` | whole number; 1 to 48; default `8` |  |
| `columns` | choice; one of `2`, `3`, `4`; default `4` |  |
| `hover_image` | true / false; default `true` | The product's "Picture on hover", for example the piece worn. |
| `show_collection` | true / false; default `false` |  |
| `editorial_every` | whole number; 0 to 12; default `0` | 0 for none. The picture takes the place of one card, as a break in the grid. |
| `editorial_image` | image; only when `editorial_every` is `1` or `2` or `3` or `4` or `5` or `6` or `7` or `8` or `9` or `10` or `11` or `12` |  |
| `editorial_text` | text; only when `editorial_every` is `1` or `2` or `3` or `4` or `5` or `6` or `7` or `8` or `9` or `10` or `11` or `12` |  |
| `editorial_url` | link; only when `editorial_every` is `1` or `2` or `3` or `4` or `5` or `6` or `7` or `8` or `9` or `10` or `11` or `12` |  |
| `link_label` | text |  |
| `link_url` | link |  |

### `product-price` — Product price
The product's price, with the old price struck through when it is on sale, and "Sold out" when nothing is left. For the card of a list of products and a product's template; elsewhere it shows nothing.

**Layouts** (`variant`): `default` (Default). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `show_stock` | true / false; default `true` |  |

### `product-summary` — Product summary
The product's collection, name and price, its options as choices (finish, size), whether it is in stock, and a short note. Only in a template built with blocks for products.

**Layouts** (`variant`): `default` (Default). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `show_title` | true / false; default `true` | Turn it off when the template already starts with the title. |
| `show_collection` | true / false; default `true` |  |
| `show_sku` | true / false; default `false` |  |
| `note` | text |  |

### `quote` — Quote
One large quotation, with who said it and an optional photo.

**Layouts** (`variant`): `centered` (Centered), `bar` (Left, with a bar), `photo` (Photo beside the quote), `statement` (Statement (large, with a second line)). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `quote` | text (several lines) |  |
| `translation` | text (several lines); only when `variant` is `s` or `t` or `a` or `t` or `e` or `m` or `e` or `n` or `t` | Under the quote, smaller, for example the same words in another language. |
| `name` | text |  |
| `role` | text |  |
| `image` | image; only when `variant` is `p` or `h` or `o` or `t` or `o` |  |
| `mark` | true / false; default `true` |  |
| `dots` | true / false; default `false` | A small row of dots in the accent colour, as a quiet mark. |

### `result-count` — Result count
How many entries the list has. By default it appears only when filters are applied. Only in an archive template built with blocks.

**Layouts** (`variant`): `default` (Default). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `always` | true / false; default `false` |  |

### `shop-the-look` — Shop the look
A look: its picture with a point on each piece, the pieces with their prices, the total and Add the look, which puts them all in the cart. On a product's page, Complete the look lists the other pieces of the product's look.

**Layouts** (`variant`): `split` (Picture and pieces side by side), `complete` (Complete the look (a short list)). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `tour` | true / false; default `false` | Each piece lights up with its note on the picture, then the next, in a loop. It waits while a visitor points at the look, stops once they open a note, and stays still for visitors who ask for less motion. |
| `header_beside` | true / false; default `false` |  |
| `total_in_button` | true / false; default `false` |  |
| `picture` | true / false; default `false`; only when `variant` is `c` or `o` or `m` or `p` or `l` or `e` or `t` or `e` | Complete the look only; the other layout always has it. |
| `add_each` | true / false; default `false`; only when `variant` is `c` or `o` or `m` or `p` or `l` or `e` or `t` or `e` |  |
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `look` | choice; a choice the site offers | Empty on a look's own page, or on a product's page for its first look. |

### `stats` — Stats
Key numbers with a short label, e.g. years of experience or completed projects.

**Layouts** (`variant`): `row` (In a row), `cards` (Cards). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `items` | list of rows; at most 6 rows |  |
| &nbsp;&nbsp;`items[].value` | text |  |
| &nbsp;&nbsp;`items[].label` | text |  |
| &nbsp;&nbsp;`items[].text` | text |  |

### `table` — Table
A data table with a header row. Type one row to a line, or paste a range copied from a spreadsheet.

**Layouts** (`variant`): `lines` (Lines), `striped` (Striped rows), `boxed` (Boxed). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `head` | text | Divided by /. Leave empty for a table without a header row. |
| `rows` | text (several lines) | One row to a line, cells divided by / (or paste from a spreadsheet). **bold** and [links](/contact) work in a cell. |
| `caption` | text |  |
| `first_column` | true / false; default `true` |  |
| `align_numbers` | true / false; default `true` |  |

### `tabs` — Tabs
Content in switchable panels, such as services, plans, or steps. Without JavaScript every panel is shown one after another.

**Layouts** (`variant`): `horizontal` (Tabs above the content), `vertical` (Tabs beside the content). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `items` | list of rows; at most 8 rows |  |
| &nbsp;&nbsp;`items[].label` | text |  |
| &nbsp;&nbsp;`items[].title` | text |  |
| &nbsp;&nbsp;`items[].text` | Markdown |  |
| &nbsp;&nbsp;`items[].image` | image |  |
| &nbsp;&nbsp;`items[].link_label` | text |  |
| &nbsp;&nbsp;`items[].url` | link |  |

### `team` — Team
People with photo, role, a short bio, and contact links. Initials are shown when there is no photo.

**Layouts** (`variant`): `grid` (Photo cards), `compact` (Compact list). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `columns` | choice; one of `2`, `3`, `4`; default `3` |  |
| `members` | list of rows; at most 40 rows |  |
| &nbsp;&nbsp;`members[].name` | text |  |
| &nbsp;&nbsp;`members[].role` | text |  |
| &nbsp;&nbsp;`members[].image` | image |  |
| &nbsp;&nbsp;`members[].bio` | text (several lines) |  |
| &nbsp;&nbsp;`members[].email` | email |  |
| &nbsp;&nbsp;`members[].linkedin` | web address |  |

### `term-tiles` — Term tiles
The terms of a taxonomy (collections, categories, any taxonomy of the site) as numbered tiles with their pictures, or as a list whose picture changes as you move over it. Each leads to the term's page.

**Layouts** (`variant`): `grid` (Tiles), `list` (List with a picture that changes). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `taxonomy` | text; default `categories` | The address of the taxonomy, as in Taxonomies (categories, tags, collections…). Its terms are shown in their order. |
| `parent` | text | Optional. The address of a term, to show the terms under it instead of the top ones. |
| `limit` | whole number; 1 to 24; default `6` |  |
| `numbers` | true / false; default `true` | A collection's own chapter number, or the term's place in the list. |
| `ratio` | choice; one of `portrait`, `square`, `landscape`; default `portrait`; only when `variant` is `g` or `r` or `i` or `d` |  |
| `link_label` | text |  |
| `link_url` | link |  |

### `testimonials` — Testimonials
Quotes from clients or partners, with name, role, and an optional photo.

**Layouts** (`variant`): `grid` (Grid of quotes), `featured` (One large quote). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `items` | list of rows; at most 9 rows |  |
| &nbsp;&nbsp;`items[].quote` | text (several lines) |  |
| &nbsp;&nbsp;`items[].name` | text |  |
| &nbsp;&nbsp;`items[].role` | text |  |
| &nbsp;&nbsp;`items[].image` | image | Optional. Initials are shown without a photo. |

### `text` — Text
A heading with rich text. Use it for introductions, statements, and longer copy.

**Layouts** (`variant`): `default` (Single column), `split` (Heading beside the text), `lead` (Large introduction), `contents` (Text with a contents list). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `body` | Markdown |  |
| `align` | choice; one of `left`, `center`; default `left` |  |
| `actions` | list of rows; at most 2 rows |  |
| &nbsp;&nbsp;`actions[].label` | text |  |
| &nbsp;&nbsp;`actions[].url` | link |  |
| &nbsp;&nbsp;`actions[].style` | choice; one of `primary`, `secondary`, `text`; default `primary` |  |

### `text-image` — Text and image
Text with an image beside it, above it or below it. Alternate left and right to give long pages a rhythm.

**Layouts** (`variant`): `image-right` (Image on the right), `image-left` (Image on the left), `image-top` (Image above the text), `image-bottom` (Image below the text), `story` (Story (a large quote, the text, a portrait on the right)). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `pull_quote` | text (several lines); only when `variant` is `s` or `t` or `o` or `r` or `y` | Large, above the text. |
| `body` | Markdown | Bullet lists are shown with check marks. |
| `actions` | list of rows; at most 2 rows |  |
| &nbsp;&nbsp;`actions[].label` | text |  |
| &nbsp;&nbsp;`actions[].url` | link |  |
| &nbsp;&nbsp;`actions[].style` | choice; one of `primary`, `secondary`, `text`; default `primary` |  |
| `image` | image |  |
| `image_alt` | text | What the image shows. Leave empty if it is purely decorative. |
| `image_ratio` | choice; one of `landscape`, `wide`, `portrait`, `square`; default `landscape` |  |
| `dots` | true / false; default `false` |  |

### `timeline` — Timeline
Milestones or process steps in order, each with a date or label, a title, and text.

**Layouts** (`variant`): `vertical` (Vertical line), `alternating` (Alternating sides), `steps` (Horizontal steps). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `items` | list of rows; at most 30 rows |  |
| &nbsp;&nbsp;`items[].label` | text |  |
| &nbsp;&nbsp;`items[].title` | text |  |
| &nbsp;&nbsp;`items[].text` | text (several lines) |  |

### `video` — Video
One or several videos from YouTube, Vimeo, or an uploaded file. By default a video opens in a large viewer over the page.

**Layouts** (`variant`): `featured` (One large video), `grid` (Grid of videos), `split` (Text beside the video). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `play` | choice; one of `lightbox`, `inline`; default `lightbox` |  |
| `ratio` | choice; one of `wide`, `cinema`, `classic`; default `wide` |  |
| `columns` | choice; one of `2`, `3`, `4`; default `3` |  |
| `videos` | list of rows; at most 12 rows |  |
| &nbsp;&nbsp;`videos[].url` | web address | A YouTube or Vimeo link, or the address of an uploaded .mp4 or .webm file. |
| &nbsp;&nbsp;`videos[].title` | text |  |
| &nbsp;&nbsp;`videos[].caption` | text |  |
| &nbsp;&nbsp;`videos[].poster` | image | Recommended. Without one no thumbnail is fetched from YouTube or Vimeo, so nothing is loaded from them until the visitor plays the video. |
| &nbsp;&nbsp;`videos[].duration` | text |  |

