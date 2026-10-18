# Theme developer guide

A hands-on guide to changing how a GnozioCMS site looks and behaves without breaking updates. [theming.md](theming.md) is the reference (the contract); this page is the how-to. Every recipe below was tried on a clean install.

## The picture in one minute

```text
themes/default/   the theme: replaced by every update. Do not edit it for a single site.
custom/           your site: never touched by updates, included in backups.
content/          your pages, posts, projects (Markdown with YAML front matter).
```

Twig looks for a file in `custom/` first, then in the theme, by the same relative path. So a file in `custom/` either **adds** something (a new block, a new template) or **replaces** a theme file of the same path. Prefer adding, or extending the theme's file, so theme fixes keep arriving.

A page is a list of **blocks** in its front matter (`blocks:`). The block editor in the admin writes that list, and the renderer checks every value against the block's `block.yaml` before a template sees it. A block's CSS and JavaScript load only on pages that use the block.

## Set up

```bash
composer install            # once
php -S 127.0.0.1:8087 -t public public/index.php
```

Use this command (not a bare `php -S -t public`) so generated files such as resized images work like they do on Apache or nginx. The admin is at `/admin`. The hidden page `/blocks` shows every block and variant; keep it open while you work.

## Rules that keep updates safe

1. Site work goes in `custom/`. Never edit `themes/default/` for one site.
2. Never rename or delete something content may refer to: a block type, a field key, a select option value, a page template name, a content type field. Retire with `hidden: true` instead.
3. Always give a new field a default. A page saved before the field existed must still render.
4. Extend the theme's templates instead of copying them:

   ```twig
   {% extends '@theme/templates/single-post.twig' %}
   {% block content %}{{ parent() }} … {% endblock %}
   ```
5. Colours, spacing, and type come from tokens (`var(--accent)`, `var(--space-m)`), never from fixed values. That is what makes every palette and dark mode work.

## Recipes

### Change colours, fonts, and corners

Most looks need no code: **Admin > Theme > Branding** has seven palettes, light/dark/system mode, **your own colours** for light and dark (the shades are worked out from the accent), thirteen font stacks or your own WOFF2 file, text size and heading scale, widths, spacing, header height, corner radius, button size and shape, logo sizes and a logo for dark backgrounds, and the favicon. Inter is the default and is bundled, so there is no request to a font service. The page beside the cards is the real site and follows each choice.

For something the screen does not offer, override the tokens in `custom/assets/css/custom.css` (loaded automatically after the theme CSS and the Branding choices), for example a palette:

```css
:root[data-theme="indigo"] {
  --accent-l: #3b3bb8;         /* accent in light mode */
  --accent-l-hover: #2e2e94;
  --accent-l-soft: #eeeefb;    /* tinted backgrounds */
  --accent-d: #a5b4fc;         /* accent in dark mode (lighter) */
  --accent-d-soft: #1c2146;
}
```

Then run the token audit (see [Testing](#testing)). Text on the accent must reach 4.5:1, and the accent itself against the page background too. The palette is chosen in Admin > Theme > Branding, so the override applies wherever that palette is used.

### The colour roles (a stable contract)

Every palette, ready-made or made on a site, is made of the same six roles, separately for light and for dark: **accent**, **background**, **surface**, **text**, **muted** and **border**; a palette may also carry `ink` (the near-black of dark panels and the footer) and an optional **secondary**. Their names are stable: other parts of Branding (link colours, button styles) and exports use them as `design.light_<role>` and `design.dark_<role>` (`accent`, `background`, `surface`, `text`, `muted`, `border`, `secondary`), and a site palette is `{id, name, light: {...}, dark: {...}, ink, secondary: {light, dark}}` in `design.site_palettes` (`SitePalettes`). Branding works the derivatives out from them: the hover and soft shades of the accent and of the secondary, the second shade of the surface and of the border, and the text colour on the accent.

The **secondary** is optional and unused by the default blocks. When a site sets it, `:root` has `--color-secondary`, `--color-secondary-hover`, `--color-secondary-soft` and `--color-secondary-contrast` (they follow light and dark like `--accent`), and `--secondary-l*`/`--secondary-d*` for each mode. When it is not set, none of them exists, so always give a fallback:

```css
.block-quote cite { color: var(--color-secondary, var(--accent)); }
.my-tag { background: var(--color-secondary-soft, var(--accent-soft)); color: var(--color-secondary, var(--accent)); }
.my-button-2 { background: var(--color-secondary, var(--accent)); color: var(--color-secondary-contrast, var(--accent-contrast)); }
```

Keep the same accessibility rules for it as for the accent: 4.5:1 as text on the page and on the surface, and for the text on it (`--color-secondary-contrast`, white or the near-black, is chosen for you). Palettes made by the generator meet them.

### Add a block

A block is a folder with up to four files. This example adds a "Quote" block to one site.

`custom/blocks/quote/block.yaml`, the definition:

```yaml
label: Quote
description: One large pull quote with the person who said it.
variants:
  plain: Plain            # the first variant is the default
  boxed: In a box
fields:
  quote:  { type: textarea, label: Quote }
  author: { type: text, label: Author }
  role:   { type: text, label: Role or company }
  image:  { type: image, label: Photo, help: Optional. }
```

`custom/blocks/quote/block.twig`, the markup:

```twig
{% if block.quote %}
  <div class="container-narrow">
    <figure class="quote">
      <blockquote class="quote-text"><p>{{ block.quote|nl2br }}</p></blockquote>
      {% if block.author %}
        <figcaption class="quote-author">
          {% if block.image %}{{ image(block.image, {alt: '', sizes: '56px', max_width: 360, class: 'quote-photo'}) }}{% endif %}
          <span><strong>{{ block.author }}</strong>{% if block.role %}<br>{{ block.role }}{% endif %}</span>
        </figcaption>
      {% endif %}
    </figure>
  </div>
{% endif %}
```

`custom/blocks/quote/block.css`, the styles (optional, loaded only on pages that use the block):

```css
.quote-text { margin: 0; font-size: var(--step-3); letter-spacing: -0.02em; line-height: 1.3; }
.block-quote.block--boxed .quote {
  padding: var(--space-l);
  border: 1px solid var(--color-border);
  border-left: 4px solid var(--accent);
  border-radius: var(--radius-l);
  background: var(--card-bg);
}
```

That is all. The block appears in the editor's **Add block** picker (marked "(custom)") and works in front matter:

```yaml
blocks:
  - type: quote
    variant: boxed
    quote: "A block written in custom/ behaves like any block of the theme."
    author: Someone Helpful
```

**Inside `block.twig` you have:**

| Variable | Meaning |
|----------|---------|
| `block` | the checked values: your fields, plus `variant`, `tone`, `spacing`, `width`, `anchor` |
| `heading_tag`, `item_heading_tag` | `h1` (an opening hero only) or `h2`, and the level for items under the heading (`h3`, or `h2` when the block has no heading). Use them so the page's heading outline stays correct. |
| `block_uid` | a unique id for the block; the heading id is `{{ block_uid }}-title` |
| `block_first` | true for the first block on the page |
| `item`, `lang`, `lang_prefix` | the page being rendered, its language, and the URL prefix (`''` or `en/`) |

The wrapper (`<section class="block block-quote block--boxed tone-default space-default">`) is added for you, together with the background tone (default, muted, contrast, accent) and spacing. A block with a Width under 100% also gets `block--w-25|50|75` and sits in a `.block-row` with its neighbours: write the block so it still works in a quarter of the row (one column, no fixed widths), and see "Block widths and rows" in `docs/theming.md`. Style your block with `.block-<type>` and `.block--<variant>` and it will follow tones automatically, because the tone classes re-scope the colour tokens.

**Kind of block:** give a block `category: Openers` (or `Content`, `Media`, `Showcase`, `Convert`, or a name of your own) so the block picker can filter by it; a block without one is listed under *Other*.

**Field types:** `text`, `textarea`, `markdown` (also available as `<key>_html`, already rendered), `email`, `url`, `link` (a bare `contact` means the current language's `/contact`; use `link_url(value, lang_prefix)`), `image`, `color`, `number`, `decimal`, `date`, `select` (`options`), `toggle`, `repeater` (a list of items with their own `fields`; a row may hold a repeater of its own, two levels at most, see *Repeaters* in `docs/theming.md`), and `icon` (a select of the icon set). A select can take its options from the site with `options_from: content_types` or `forms`. Give a field `help:` to explain it in the editor.

**Useful Twig helpers:** `image(src, {alt, sizes, max_width, priority})` for responsive WebP with width, height, and lazy loading (an empty `alt` marks a decorative image; leave `alt` out to use the media library's text), `icon('name', 'class')`, `t('key', 'Fallback')` for translated text, `link_url()`, `url()`, `format_date()`, `taxonomy_label()`, `content_fields(item)`, and the shared macros in `components/ui.twig` (`section_header`, `actions`).

**JavaScript:** add `block.js` only when the block needs it (a menu, a slider). It is bundled with the other blocks on the page and loaded with `defer`. Write it as progressive enhancement: the block must be usable without it, and it must work with the keyboard.

To restyle a **theme** block for one site, add `custom/blocks/<type>/block.css`: it loads after the theme's CSS for that block. A theme block's fields and variants cannot be changed from `custom/`; for that, create a new block with another name.

### Containers (rows with columns) in your theme

A container is an entry of a page's `blocks:` that holds blocks in columns (format and every setting: `docs/theming.md`, *Containers*). The default theme draws it with two files; a theme made from it has them already, and `custom/` can override either by path:

- `components/block-container.twig`: the section, the optional `.bkc-media` picture, `.bkc-inner` and the row. It receives `container` (checked settings: `tone`, `spacing`, `inner`, `min_height`, `overlay`, `image`, `has_picture`, `anchor`, `class`), `container_row` (the classes of the row of columns), `container_columns` (a list of `{class, style, html}`: the classes and the custom properties of a column, and the markup of the blocks inside it, already drawn: print it with `|raw`) and, in the editor's live preview only, `container_source`. A theme without this file shows the blocks of the columns one under another.
- `assets/css/block-container.css`: the row, the columns and the stacking. It is added to the page's block stylesheet only when the page has a container (the stylesheet name `_container`, like `_row`), with the stylesheets of the blocks inside. Keep to classes and custom properties (`--bkc-f`, `--bkc-w`, `--bkc-min`, `--bkc-max`, `--bkc-grow`, `--bkc-shrink`, `--bkc-basis`, `--bkc-order`) and to the theme's spacing steps (`--space-*`); there is no JavaScript.

The blocks inside a column are ordinary sections; `BlockRenderer` gives each the narrow class that fits its column (`block--w-25|33|50`), so the rules for narrow blocks in `block-row.css` (written for `.block-row > .block--w-*`, now also `.bkc-col > .block--w-*`) apply: if your own block has a grid with fixed columns, make sure it has a rule for a narrow column. Check a page with containers at desktop, tablet and phone widths with `themeAudit.containers(['/a-page'])` (`scripts/theme-audit.js`) and `themeAudit.pages(...)` for accessibility.

### Change part of a template

Extend the theme's template and override one Twig block (see rule 4 above). To restyle without touching markup, put CSS in `custom/assets/css/custom.css`.

### Add a page template

A page template is chosen per page in the editor's Publish panel.

1. Create `custom/templates/wide.twig`:

   ```twig
   {% extends '@theme/templates/single-page.twig' %}
   {% block content %}
     <div class="template-wide">{{ parent() }}</div>
   {% endblock %}
   ```
2. List it in `custom/page-templates.yaml`:

   ```yaml
   wide:
     label: Wide
     description: Text across the full width of the page.
   ```
3. Style it in `custom.css`: `.template-wide .single-body > .prose-block { max-width: none; }`

A template is offered only when its file exists. Keep the key stable: pages store it as `template: wide`.

### Add a content type

**Admin > Content types** creates a type (a folder in `content/`) and adds fields; its archive layout, ordering, and filters, and the layout of its single page, are set on its cards in **Theme > Archive Layouts** and **Single Layouts**. The editor form then gets a "<Type> details" tab, pages show the fields as a fact sheet, and the archive page lists items with the layout you chose. To do the same in a file, write `custom/content-types/events.yaml` (fields, archive) as described in [theming.md](theming.md#content-types). For a different look, add `custom/templates/archive-events.twig` or `single-events.twig`.

### Save a ready-made section

In the block editor, tick blocks and use **Save as section**: it is written to `custom/presets/` and appears under Ready-made sections on every page. You can also write a preset by hand; see [theming.md](theming.md#ready-made-sections).

### Add an icon or a text

- An icon is one SVG file, `custom/icons/paw.svg`, using `stroke="currentColor"` and a 24×24 viewBox. It shows up in every icon field and in `icon('paw')`.
- Texts the theme prints (button labels, "Read more") are edited in **Admin > Translations** and saved to `custom/lang/<lang>.yaml`, a flat file of `key: text`. Strings for your own blocks use `t('mine.key', 'Fallback')`; the fallback is shown until you add the key to that file:

  ```yaml
  # custom/lang/el.yaml
  mine.key: "Το κείμενό μου"
  ```

## Changing the theme itself

For work that belongs in `themes/default/` (a block every site should have, a bug fix), follow the same rules and also:

- add the block's files under `blocks/<type>/`, its texts to `lang/el.php` and `lang/en.php`, and a preset if a ready-made section makes sense;
- add the block, in each variant, to `content/pages/blocks.md` so the showcase page keeps covering everything;
- check with the audit (below), then update `docs/theming.md`, add a line to `CHANGELOG.md`, and raise `version` in `themes/default/theme.yaml`;
- add new settings and options with defaults, and never rename existing ones.

## Quality checklist

Before you call a block or page done:

- **Structure:** one `<h1>` per page (an opening hero owns it), headings in order, landmarks named, lists as lists, a native `<button>` for actions and `<a>` for navigation.
- **Keyboard:** everything reachable and operable, focus always visible (the theme provides `:focus-visible` styles; do not remove them), no keyboard traps, Escape closes anything that opens over the page and focus returns to what opened it.
- **Colour:** text 4.5:1 (3:1 for large text and interface parts) in every palette, light and dark. Tokens make this true; fixed colours break it.
- **Images:** always go through `image()`; meaningful images have a description, decorative ones `alt=""`; the first image on a page (the hero) uses `priority`.
- **Motion:** animations only under `@media (prefers-reduced-motion: no-preference)`. Nothing moves by itself for more than five seconds without a pause control.
- **Third parties:** load nothing from another site until the visitor asks (maps, videos). Do not add trackers.
- **Reflow:** the page works at 320 px wide and at 200% zoom with no sideways scrolling; only tables, code, and scrolling strips may scroll inside their own box.
- **Language:** every string a visitor sees goes through `t()` or comes from content; the page works in both Greek and English.
- **No JavaScript:** content is still readable and forms still submit.

## Testing

`scripts/theme-audit.js` runs the checks that can be automated. Start the site, open any page, and paste the file into the browser console (see its header). Then:

```js
await themeAudit.pages(['/', '/en/', '/services', '/blocks'])   // axe-core, WCAG 2.2 AA + best practices, light and dark
await themeAudit.reflow(['/', '/services'])                       // no sideways scrolling at 320 px
await themeAudit.tokens()                                         // contrast of the colour pairs, all six palettes, both modes
```

An empty `failing` / `problems` / `below45` is a pass. "review" items are text over photographs, which a tool cannot judge; look at them by eye and make sure the overlay keeps the text readable on a bright photo. `pages` fetches axe-core from a CDN, so use it only on a development copy.

Automated checks find roughly half of the accessibility problems. Also do these by hand for anything new:

1. Tab through the page. Is the order logical, is the focus always visible, does Escape close overlays?
2. Zoom the browser to 200% and to a 320 px wide window.
3. Switch to dark mode and to each palette you offer.
4. Turn off JavaScript and reload.
5. Turn on "reduce motion" in the operating system.

To check that your blocks survive the editor's validation, save a page and compare the stored front matter with what you wrote: values that are not valid for their field are dropped, and that is the first thing to look at when something "does not save".

## Troubleshooting

| Symptom | Likely cause |
|---------|--------------|
| A new block is missing from the picker | The folder name must be lower case (`quote`, `text-image`) and contain a `block.yaml` that parses. Check for YAML mistakes such as an unquoted `off`/`yes` or a comma inside a one-line `{ … }` value: quote it. |
| Its CSS does not apply | Block CSS loads only on pages that use the block. Look for `_blocks.css?b=…` in the page source and make sure your type is in the list. Selectors should start with `.block-<type>`. |
| A field saves but does not show | The template must print the field under `block.<key>`; markdown fields also give `block.<key>_html` (print it with `|raw`). |
| A value keeps resetting | It failed validation: a select value that is not in `options`, a `link` with a script scheme, a `number` outside `min`/`max`. |
| A page template is missing from the editor | Its `templates/<name>.twig` file must exist, and the name must be lower case letters, digits, and dashes. |
| An image is broken | Images go through `image()`, which only handles files under `/uploads/`. Run the site with `php -S 127.0.0.1:8087 -t public public/index.php`, not a bare document root. |
| Changes in `themes/default/` vanish | An update replaced them. Move the change to `custom/`. |
