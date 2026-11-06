# Theme > Typography: plan

Status: built on 2026-11-06. Phase 1 (the tab, with the settings that existed) shipped in 0.1.225, phase 2 (Navigation) in 0.1.226, phase 3 (H4 to H6, a Case column, eyebrow, meta, quote, caption, card title and the extended Style samples) in 0.1.227, and phase 4 (button and label text, the interface and code fonts, numbers, ligatures, phone text size, justified articles, hyphenation and heading line breaks) in 0.1.228. Phase 5 (docs and skill) went with each phase. Navigation sizes are in pixels, as section 3 proposes. Left out on purpose: a field text size below 16px, because phones zoom into such a field.

## Why

Type settings are one card among six on **Theme > Branding** ("Typography: fonts, sizes, headings"), and they reach only part of the site. They cover the fonts, the text size, the heading scale, heading weight, letter spacing, line height and case, and a matrix for H1, H2, H3, lead and small text. Everything else has a size fixed in the theme's CSS:

| Text | Today | Where |
| --- | --- | --- |
| Header menu | `0.95rem`, weight 550 | `.nav-link`, site.css |
| Mobile menu | weight 550, theme size | `.mobile-link` |
| Footer menu, footer headings, copyright | `--step--1` and theme sizes | `.footer-link`, `.footer-title`, `.footer-bottom` |
| Eyebrow | `--step--1`, uppercase rules of the theme | `.eyebrow` |
| Dates, bylines, meta | `--step--1` | `.content-meta`, `.single-hero-meta` |
| Quotes, captions, code, tables in text | theme sizes, `0.9em` | `.prose-block …` |
| Site name in the header | `name_size` in **Brand** | `--brand-name-size` |
| Button text | `button_size`, `button_weight`, `button_case` in **Buttons** | Branding card |
| Form labels | `label_size`, `label_weight` in **Forms** | Branding card |
| H4 to H6 | the scale only, no override | — |

So questions like "where do I change the menu size?" have no answer except Custom CSS. Type also competes for room with colour, layout, shape and buttons on one screen.

**Proposal:** a tab of its own, **Theme > Typography**, after Branding. It gathers every type setting that exists today and adds the elements the table lists, so that any text a visitor reads can be set there.

## 1. Where it lives and how it is stored

- **New tab** `typography` in `theme_tabs` (src/App.php), labelled "Typography", placed right after **Branding**. The same form, Save and Discard as the other tabs. It has the same live preview beside the cards (`theme-preview.twig`, `admin-preview.js`), with Computer, Tablet and Phone sizes and Light and Dark.
- **Storage does not move.** The values stay in the `design` and `appearance` sections of the theme settings, under the same keys. Saved sites keep their choices with no migration. Branding > Export, Theme > Export and import, undo and `BrandingTransfer` keep working unchanged, because they already carry `design` whole.
- **The new keys** go into `design` too, with the same pattern of `<element>_<property>` (`nav_size`, `nav_weight`, `eyebrow_case`, …). An empty or "Theme" value adds no CSS, as today, so a site that sets nothing sees no change.
- **Branding loses the Typography card.** In its place, a one-line link: "Fonts and text sizes are on the Typography tab." `?tab=branding#typography` and old bookmarks lead to the new tab.
- **Heading colour stays in Branding > Colour**, because it belongs with the palette and light and dark mode. The Typography tab shows a link to it.

## 2. The tab, card by card

The admin rule applies: modern and condensed, with cards, chips, compact rows and segmented choices. Each card has its own **Reset**, and each value shows where it comes from (theme or site), as Branding does today (`admin-branding.js`).

### 2.1 Fonts

Moved as they are:

- **Font pairing** (`appearance.font`), **Heading font** and **Text font** (`heading_font`, `body_font`), the font families with uploaded files (`fonts`, `admin-branding-fonts.js`), and the earlier `font_file` when it is set.

New:

- **Interface font**: for the menus, buttons, labels, eyebrows and meta. The choices are Text font, Heading font, or one of the families. Today these follow the text font.
- **Code font**: System monospace, or one of the uploaded families.
- **Font features**: tabular numbers in tables and meta (on or off), and ligatures (theme, on, off). These are written only when the font has them, which is harmless when it does not.

### 2.2 Text

Moved: **Text size** (`base_size`), **Line height** (`line_height`), **Text weight** (`body_weight`), **Space between paragraphs** (`paragraph_space`).

New:

- **Text size on phones** in px, when it should differ from computers. Empty follows the computer size.
- **Hyphenation**: off or automatic. It is written as `hyphens: auto` and relies on `<html lang>`, which the theme already sets, so Greek and English each hyphenate by their own rules.
- **Text alignment of articles**: left or justified. Justified always turns hyphenation on.

### 2.3 Headings

Moved: **Heading scale** (`scale`), **Heading weight**, **Letter spacing**, **Line height**, **Case** (`heading_*`), and the per-element matrix (`h1_*`, `h2_*`, `h3_*`).

New:

- **H4, H5 and H6** in the matrix (size step, weight, line height, letter spacing), so that every level can be set.
- **A Case column** in the matrix (theme, normal, uppercase, small caps) for each level.
- **Balanced line breaks** (`text-wrap: balance`) for headings: on by default in the theme, and can be turned off.

### 2.4 Text elements

This is the H1–H6 matrix extended with the same columns (Size, Weight, Line, Spacing, Case):

- **Lead** and **Small**, moved from the current matrix.
- **Eyebrow**, the small label above section headings.
- **Meta**: dates, bylines, reading time, categories on cards.
- **Quote**: blockquotes in text and the Quote block.
- **Caption**: figure captions and the Gallery's captions.
- **Card title**: the titles in Latest content, Cards and archive cards.

### 2.5 Navigation

This is the card that answers the question about the menu. It is a compact matrix with the columns **Size, Weight, Case, Spacing**, and these rows:

- **Header menu**: the top-level links (`.nav-link`).
- **Dropdown**: the links in submenus.
- **Mobile menu**: the drawer and the full-screen menu.
- **Top bar**: the strip above the header.
- **Footer menu**: the footer links.
- **Footer headings**: the titles of the footer columns.
- **Breadcrumbs**.
- **Site name**: `name_size` moved from Brand, with its weight added.

### 2.6 Buttons and forms

- **Button text**: `button_size`, `button_weight`, `button_case` move here, with Letter spacing added. The Buttons card in Branding keeps height, padding, corners and colours.
- **Form labels**: `label_size`, `label_weight` move here, and **Field text size** is added. The Forms card in Branding keeps the fields' colours, borders and focus ring.

## 3. Sizes: steps for reading, pixels for the interface

- **Text a visitor reads** (headings, lead, quote, caption, card titles, eyebrow, meta) uses a **step of the scale**, as the matrix does today (`var(--step-N)`). It stays fluid and follows Text size and Heading scale.
- **Interface text** (menus, buttons, labels, breadcrumbs, top bar) uses **pixels**, as `button_size` and `label_size` already do. A menu is not fluid in the theme today (`0.95rem`), and owners think of it in px. The values are written as `rem`, so the visitor's browser zoom still applies.
- Every size select starts with **Theme**, which keeps the theme's own value.

## 4. How the CSS reaches the theme

- The default theme reads **tokens with fallbacks** instead of fixed values. For example:

  ```css
  .nav-link { font-size: var(--type-nav-size, 0.95rem); font-weight: var(--type-nav-weight, 550); }
  ```

  The settings then write only the variables that are set. This is cleaner than adding more selectors with the high specificity `BrandingTypography` uses now. It also gives theme authors one documented list of names.
- **`BrandingTypography`** becomes **`Typography`** (src/Typography.php). It keeps the current H1–H3, lead and small output as it is, so those sites draw the same, and adds the token output for the new rows. `Branding::css()` keeps calling it, so there is one stylesheet as today.
- **Generic, not tied to one theme:** `theme.yaml` declares which elements a theme supports (`typography: [nav, dropdown, mobile_nav, eyebrow, meta, …]`). The tab shows only those rows. A theme without the block shows the elements that existed before.
- `docs/theme-developer-guide.md` lists the token names and their fallbacks.

## 5. Preview

- **Style samples** (`admin/theme?preview=styles`) gains every element of the tab, each with its name beside it: H1 to H6, lead, text, eyebrow, meta, quote, caption, code, a card, the header menu with a dropdown open, breadcrumbs, a button and a field.
- The tab opens on the real site, like Branding, with Style samples one click away. Changing a value redraws the frame without saving, as it does now.

## 6. Phases

Each phase is one version on `main`, with its CHANGELOG entry and its tests.

1. **Move.** Add the tab, move the Typography card and its scripts (fonts manager, resets, value sources), and put the link in Branding. There are no new settings. Tests: `branding`, `branding_fonts`, `theme_page` and `theme_transfer` over HTTP, and `tests/unit/branding-extras.php`, adjusted to the new tab.
2. **Navigation.** Add the token fallbacks to `site.css` for the menu, dropdown, mobile menu, top bar, footer menu, footer headings, breadcrumbs and site name, and add the Navigation card. This is the most requested part, so it comes first.
3. **Text elements.** Add H4–H6, the Case column, and eyebrow, meta, quote, caption and card title. The extended Style samples page ships with this phase.
4. **Buttons and forms, and the fonts extras.** Move the button and label text settings, and add the interface font, code font, font features, phone text size, hyphenation, justified articles and balanced headings.
5. **Docs and skill.** Update `docs/theming.md`, `docs/theme-developer-guide.md`, and the gnoziocms skill (`skills/gnoziocms/references/theme-settings.md`, `settings.md` and `admin-ui.md`, plus its snapshot of `theme.yaml`).

## 7. Open questions

- **Buttons and labels** (2.6): should their text settings move to Typography, or stay in Branding with a mention on the Typography tab? The plan moves them so all text sizes are in one place. The cost is that a button's look is then set on two tabs.
- **Interface sizes in px** (3): or steps everywhere, for one rule? Steps are coarse at small sizes, because step −1 to step 0 is the whole range a menu uses.
- **Tab position**: right after Branding, or before it?
