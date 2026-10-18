# Blocks of this installation (GnozioCMS 0.1.148)

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

Kinds of value: **link** is a page (`contact`, relative to the page's language), a path (`/en/contact`), a full URL, `#anchor`, `mailto:` or `tel:`. **image**, **video** and **file** are `/uploads/media/<id>.<ext>` (a library file) or a full https URL; no spaces, quotes or brackets. **icon** is a name from the icon set (`python3 scripts/site_info.py --icons`). **Markdown** fields take Markdown. A **choice** must be exactly one of the listed values. Values equal to the default can be left out. A value the CMS does not accept is replaced by the default without any message, so check with `validate_content.py`.

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
Space between two sections, or a line, optionally with a short label in the middle.

**Layouts** (`variant`): `space` (Empty space), `line` (Line), `label` (Line with a label). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `label` | text; only when `variant` is `l` or `a` or `b` or `e` or `l` |  |
| `height` | choice; one of `s`, `m`, `l`, `xl`; default `m`; only when `variant` is `s` or `p` or `a` or `c` or `e` |  |
| `style` | choice; one of `solid`, `dashed`, `dotted`; default `solid`; only when `variant` is `line` or `label` |  |
| `line_width` | choice; one of `page`, `narrow`, `short`; default `narrow`; only when `variant` is `line` or `label` |  |

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

**Layouts** (`variant`): `cards` (Cards), `plain` (Plain, no cards), `numbered` (Numbered steps). First is the default.

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
| `eyebrow` | text |  |
| `heading` | text |  |
| `intro` | text (several lines) |  |
| `columns` | choice; one of `2`, `3`, `4`; default `3` |  |
| `ratio` | choice; one of `landscape`, `square`, `portrait`, `original`; default `landscape` |  |
| `lightbox` | true / false; default `true` |  |
| `images` | list of rows; at most 60 rows |  |
| &nbsp;&nbsp;`images[].image` | image |  |
| &nbsp;&nbsp;`images[].alt` | text | What the image shows. Leave empty to use the media library text. |
| &nbsp;&nbsp;`images[].caption` | text |  |

### `hero` — Hero
Opening section with a strong heading, supporting text, buttons, and an image or a video behind it. The steps layout adds up to four numbered steps over the image. As the first block of a page it becomes the page title (h1).

**Layouts** (`variant`): `split` (Text and image side by side), `centered` (Centered text, wide image below), `cover` (Text over a full-width image), `cover-centered` (Centered text over a full-width image), `steps` (Text over a full-width image, with numbered steps), `steps-centered` (Centered text over a full-width image, with numbered steps), `minimal` (Large heading, no image). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text; required |  |
| `text` | text (several lines) |  |
| `actions` | list of rows; at most 2 rows |  |
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
| `background` | choice; one of `image`, `video`; default `image` |  |
| `image` | image; only when `background` is `i` or `m` or `a` or `g` or `e` |  |
| `image_alt` | text; only when `background` is `i` or `m` or `a` or `g` or `e` | What the image shows, for people who cannot see it. Leave empty if it is purely decorative. |
| `video` | video; only when `background` is `v` or `i` or `d` or `e` or `o` | Choose a video from the media library (.mp4 or .webm), or paste a YouTube or Vimeo link, or the address of a video file elsewhere. It plays silently, in a loop, with a pause button. A YouTube or Vimeo video is loaded from them when the page opens. |
| `video_poster` | image; only when `background` is `v` or `i` or `d` or `e` or `o` | Shown while the video loads, when the visitor has asked their device for less motion, and under a video that is paused. Recommended, and it is the share image of the page when it has none. |

### `hero-slider` — Hero slider
Slides that each are a Hero. Every slide picks its own Hero layout (including the ones with numbered steps) and has everything a Hero has - heading, text, buttons, highlights, and an image or a video behind the text - styled exactly like it, on a phone too. Previous and next buttons, dots, swiping and the keyboard work; it never moves by itself unless you turn that on.

**Layouts** (`variant`): `default` (Default). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `navigation` | choice; one of `below`, `inside`; default `below` | Over the slides suits slides that are all full-width pictures. |
| `autoplay` | choice; one of `off`, `5`, `8`, `12`; default `off` | A pause button is shown, and visitors who prefer reduced motion never get automatic movement. |
| `items` | list of rows; at most 8 rows |  |
| &nbsp;&nbsp;`items[].layout` | choice; one of `split`, `centered`, `cover`, `cover-centered`, `steps`, `steps-centered`, `minimal`; default `split` |  |
| &nbsp;&nbsp;`items[].eyebrow` | text |  |
| &nbsp;&nbsp;`items[].heading` | text |  |
| &nbsp;&nbsp;`items[].text` | text (several lines) |  |
| &nbsp;&nbsp;`items[].label` | text |  |
| &nbsp;&nbsp;`items[].url` | link | A page ("contact"), a path ("/en/contact"), a full URL, #anchor, mailto: or tel: |
| &nbsp;&nbsp;`items[].style` | choice; one of `primary`, `secondary`, `text`; default `primary` |  |
| &nbsp;&nbsp;`items[].label_2` | text |  |
| &nbsp;&nbsp;`items[].url_2` | link |  |
| &nbsp;&nbsp;`items[].style_2` | choice; one of `primary`, `secondary`, `text`; default `secondary` |  |
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

### `quote` — Quote
One large quotation, with who said it and an optional photo.

**Layouts** (`variant`): `centered` (Centered), `bar` (Left, with a bar), `photo` (Photo beside the quote). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `quote` | text (several lines) |  |
| `name` | text |  |
| `role` | text |  |
| `image` | image; only when `variant` is `p` or `h` or `o` or `t` or `o` |  |
| `mark` | true / false; default `true` |  |

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

**Layouts** (`variant`): `image-right` (Image on the right), `image-left` (Image on the left), `image-top` (Image above the text), `image-bottom` (Image below the text). First is the default.

| field | kind and choices | notes |
|---|---|---|
| `eyebrow` | text |  |
| `heading` | text |  |
| `body` | Markdown | Bullet lists are shown with check marks. |
| `actions` | list of rows; at most 2 rows |  |
| &nbsp;&nbsp;`actions[].label` | text |  |
| &nbsp;&nbsp;`actions[].url` | link |  |
| &nbsp;&nbsp;`actions[].style` | choice; one of `primary`, `secondary`, `text`; default `primary` |  |
| `image` | image |  |
| `image_alt` | text | What the image shows. Leave empty if it is purely decorative. |
| `image_ratio` | choice; one of `landscape`, `wide`, `portrait`, `square`; default `landscape` |  |

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

