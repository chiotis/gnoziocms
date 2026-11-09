# Theme settings of this installation (GnozioCMS 0.1.258)

Set with `python3 scripts/site_settings.py set theme section.field=value`. A text marked *translatable* takes a plain text or a map `{default: …, en: …}`.

## `appearance` — Appearance

| field | kind and choices | default | notes |
|---|---|---|---|
| `mode` | select; one of `system`, `light`, `dark` | system | Mode |
| `palette` | select; one of `slate`, `indigo`, `emerald`, `teal`, `rose`, `amber`, `garnet` | slate | Palette |
| `font` | select; one of `sans`, `display`, `serif`, `system` | sans | Font |
| `glow` | select; one of `soft`, `solid` | soft | Decoration. The soft coloured glows behind headings and in cards. Solid keeps every surface a flat colour. |
| `shape` | select; one of `soft`, `rounded`, `sharp` | soft | Corners |

## `header` — Header

| field | kind and choices | default | notes |
|---|---|---|---|
| `layout` | select; one of `classic`, `menu_left`, `split`, `centered`, `stacked`, `minimal`, `minimal_center` | classic | Layout |
| `shape` | select; one of `full`, `floating` | full | Shape |
| `width` | select; one of `contained`, `wide` | contained | Content width |
| `size` | select; one of `regular`, `compact`, `tall` | regular | Height |
| `border` | select; one of `line`, `none`, `shadow` | line | Edge |
| `nav_style` | select; one of `soft`, `underline`, `plain` | soft | Menu links |
| `sticky` | select; one of `on_scroll`, `always`, `smart`, `off` | on_scroll | Sticky |
| `shrink` | toggle | false | Shrinks once the page is scrolled. Works with the header that stays at the top. |
| `row_tone` | select; one of `default`, `muted`, `soft`, `fade-muted`, `fade-soft`, `contrast`, `accent` | default | Header background. The row with the logo. A fade goes from the colour down into the page background, so the header melts into the page. |
| `search` | select; one of `icon`, `field`, `none` | icon | Search |
| `show_language` | toggle | true | Language switcher |
| `show_mode` | toggle | true | Dark mode switch |
| `bar_tone` | select; one of `default`, `muted`, `contrast`, `accent` | default | Menu bar background (stacked) |
| `transparent` | toggle | false | Transparent over an opening hero. On pages that start with a Hero block, or a full width Slider, the header sits over it and turns solid on scroll. |
| `mobile_menu` | select; one of `drawer`, `drawer_right`, `fullscreen`, `top_sheet`, `bottom_sheet` | drawer | Phone menu. How the menu opens on small screens. The bottom sheet suits the bottom bar; the full screen suits few, large links. |
| `mobile_bar` | toggle | false | Bottom bar on phones. A bar of icons fixed at the bottom of small screens, with the Menu button and the links below. The menu button leaves the header while it is on. |
| `bar_items` | repeater | (empty) | Bottom bar links. The links next to the Menu button in the bottom bar, each with an icon. Leave the list empty for the automatic ones (call, email, and the header button). |
| `topbar_contacts` | toggle | false | Phone and email in a top bar. Uses the phone and email from the Footer section. |
| `cta_label` | text; translatable | (empty) | Button text |
| `cta_url` | link | (empty) | Button link |
| `cta_style` | select; one of `solid`, `outline` | solid | Button style |
| `topbar_text` | text; translatable | (empty) | Top bar message |
| `topbar_url` | link | (empty) | Top bar message link |
| `topbar_tone` | select; one of `muted`, `contrast`, `accent` | muted | Top bar background |
| `thread` | select; one of `none`, `terms`, `terms_surface` | none | Band of colours at the very top. A thin band, one stripe for each collection of the shop in its deep or lighter tone, above the top bar and the header. |
| `tools_text` | toggle | false | Search, account and cart as words. Words instead of icons in the header. The cart shows its count in brackets, also when empty. |
| `topbar_social` | toggle | false | Social icons in the top bar. Uses the profiles in Social profiles. |

## `sidebar` — Sidebar card

| field | kind and choices | default | notes |
|---|---|---|---|
| `card_heading` | text | (empty) | Card heading |
| `card_text` | textarea | (empty) | Card text |
| `card_label` | text | (empty) | Card button text |
| `card_url` | link | (empty) | Card button link |
| `card_contacts` | toggle | true | Show phone and email in the card. Uses the phone and email from the Footer section. |

## `brand` — Brand

| field | kind and choices | default | notes |
|---|---|---|---|
| `logo` | image | (empty) | Logo. Replaces the site name in the header. SVG or a wide PNG works best. |
| `logo_dark` | image | (empty) | Logo for dark backgrounds. Used in dark mode, in the dark footer and over a dark opening hero. Without it the logo is used everywhere. |
| `show_name` | toggle | false | Show the site name next to the logo |
| `logo_height` | number; 16 to 160 | (empty) | Logo height |
| `logo_height_mobile` | number; 14 to 120 | (empty) | Logo on a phone. Empty is as on a computer. |
| `footer_logo_height` | number; 16 to 200 | (empty) | Logo in the footer |
| `name_size` | number; 12 to 56 | (empty) | Site name size. When there is no logo. |
| `favicon` | image | (empty) | Favicon. The small icon in the browser tab. SVG, PNG or ICO. |
| `touch_icon` | image | (empty) | App icon. 180×180 pixels PNG, for the home screen of a phone. |
| `theme_color` | color | (empty) | Browser colour. Colours the address bar of the browser on a phone. |
| `share_image` | image | (empty) | Default share image. 1200×630 pixels is the common size for social previews. |

## `design` — Design

| field | kind and choices | default | notes |
|---|---|---|---|
| `light_accent` | color | (empty) | Accent |
| `light_background` | color | (empty) | Background |
| `light_surface` | color | (empty) | Surface |
| `light_text` | color | (empty) | Text |
| `light_muted` | color | (empty) | Muted text |
| `light_border` | color | (empty) | Border |
| `dark_accent` | color | (empty) | Accent |
| `dark_background` | color | (empty) | Background |
| `dark_surface` | color | (empty) | Surface |
| `dark_text` | color | (empty) | Text |
| `dark_muted` | color | (empty) | Muted text |
| `dark_border` | color | (empty) | Border |
| `light_secondary` | color | (empty) | Secondary. An optional second brand colour, available to blocks as --color-secondary. |
| `dark_secondary` | color | (empty) | Secondary |
| `site_palette` | text | (empty) | Site palette in use. The id of the palette made on this site that is in use. Empty uses the palette chosen under Appearance. |
| `site_palettes` | textarea | (empty) | Site palettes. The palettes made on this site, as JSON; edited on the Branding screen. |
| `duo_picker` | toggle | true | Colour pairings from Duo. Lets the palette studio show colour pairs fetched from Duo when it is opened. The public site never contacts Duo. |
| `duo_url` | text | (empty) | Address of the colour pairings |
| `ink` | color | (empty) | Dark panels and footer. The near-black behind pictures, dark panels and the footer. |
| `heading_font` | select; one of `pairing`, `inter`, `system`, `humanist`, `geometric`, `neo_grotesque`, `rounded`, `industrial`, `classical`, `transitional`, `old_style`, `slab`, `didone`, `mono`, `custom` | pairing | Headings |
| `body_font` | select; one of `pairing`, `inter`, `system`, `humanist`, `geometric`, `neo_grotesque`, `rounded`, `industrial`, `classical`, `transitional`, `old_style`, `slab`, `didone`, `mono`, `custom` | pairing | Text |
| `ui_font` | select; one of `auto`, `heading`, `inter`, `system`, `humanist`, `geometric`, `neo_grotesque`, `rounded`, `industrial`, `classical`, `transitional`, `old_style`, `slab`, `didone`, `mono`, `custom` | auto | Interface. Menus, buttons, labels, eyebrows, dates and breadcrumbs. |
| `ui_family` | text | (empty) | Interface family. The family of your own fonts the interface uses when Interface is Your own font. |
| `code_font` | select; one of `auto`, `custom` | auto | Code |
| `code_family` | text | (empty) | Code family. The family of your own fonts code uses when Code is Your own font. |
| `numerals` | select; one of `auto`, `tabular`, `oldstyle` | auto | Numbers |
| `ligatures` | select; one of `auto`, `none`, `extra` | auto | Ligatures |
| `font_file` | url | (empty) | Font file. A WOFF2 file (one variable font with all weights is best). Put it in custom/assets/fonts/ and type fonts/name.woff2, or give a full address. |
| `heading_family` | text | (empty) | Heading font family. The family of your own fonts that headings use when Headings is “Your own font”. |
| `body_family` | text | (empty) | Text font family. The family of your own fonts that text uses when Text is “Your own font”. |
| `fonts` | repeater | (empty) | Your own font files. The font files of your own families, each with its weight and style. Managed on the Typography tab (Fonts). |
| `base_size` | number; 13 to 24 | (empty) | Text size. Headings follow it. |
| `line_height` | decimal | (empty) | Line height of text |
| `scale` | select; one of `auto`, `1.125`, `1.2`, `1.25`, `1.333`, `1.414`, `1.5`, `1.618` | auto | Heading scale |
| `heading_weight` | select; one of `auto`, `400`, `500`, `600`, `700`, `800` | auto | Heading weight |
| `heading_tracking` | select; one of `auto`, `tighter`, `tight`, `normal`, `wide` | auto | Heading letter spacing |
| `heading_leading` | select; one of `auto`, `tight`, `normal`, `relaxed` | auto | Heading line height |
| `heading_case` | select; one of `auto`, `uppercase` | auto | Heading case |
| `heading_color` | select; one of `text`, `accent`, `custom` | text | Heading colour. Page titles, section titles and the headings of the text. Dark panels, the footer and headings over pictures keep their own colour. |
| `heading_color_light` | color | (empty) | Headings |
| `heading_color_dark` | color | (empty) | Headings |
| `container` | number; 640 to 2400 | (empty) | Content width |
| `reading_width` | number; 480 to 1200 | (empty) | Reading width. Narrow pages and text. |
| `gutter` | number; 16 to 160 | (empty) | Side margin. A phone keeps 20. |
| `section_space` | number; 16 to 320 | (empty) | Space between sections. Phones use about half. |
| `header_height` | number; 40 to 200 | (empty) | Header height. Compact and tall follow it. |
| `spacing` | select; one of `auto`, `tight`, `airy`, `roomy` | auto | Spacing |
| `radius` | number; 0 to 48 | (empty) | Corner radius. Empty follows Corners. |
| `button_radius` | select; one of `auto`, `pill`, `rounded`, `square` | auto | Button corners |
| `shadows` | select; one of `auto`, `none`, `soft`, `medium`, `strong` | auto | Shadows |
| `button_height` | number; 28 to 80 | (empty) | Button height |
| `button_pad` | number; 8 to 80 | (empty) | Button side padding |
| `button_size` | number; 11 to 24 | (empty) | Button text size |
| `button_weight` | select; one of `auto`, `500`, `600`, `700`, `800` | auto | Button text weight |
| `button_case` | select; one of `auto`, `uppercase` | auto | Button text case |
| `button_tracking` | select; one of `auto`, `tight`, `normal`, `wide`, `wider` | auto | Button letter spacing |
| `link_color` | text | (empty) | Link colour |
| `link_hover` | text | (empty) | Link hover colour |
| `link_color_dark` | text | (empty) | Link colour |
| `link_hover_dark` | text | (empty) | Link hover colour |
| `link_underline` | select; one of `auto`, `always`, `hover`, `never` | auto | Link underline |
| `link_thickness` | select; one of `auto`, `thin`, `medium`, `thick` | auto | Underline thickness |
| `link_offset` | select; one of `auto`, `near`, `far` | auto | Underline distance |
| `btn_primary_bg` | text | (empty) | Primary background |
| `btn_primary_text` | text | (empty) | Primary text |
| `btn_primary_border` | text | (empty) | Primary border |
| `btn_primary_hover_bg` | text | (empty) | Primary hover background |
| `btn_primary_hover_text` | text | (empty) | Primary hover text |
| `btn_primary_hover_border` | text | (empty) | Primary hover border |
| `btn_primary_border_width` | select; one of `auto`, `0`, `1`, `2`, `3` | auto | Primary border width |
| `btn_primary_radius` | select; one of `auto`, `pill`, `rounded`, `square` | auto | Primary corners |
| `btn_secondary_bg` | text | (empty) | Secondary background |
| `btn_secondary_text` | text | (empty) | Secondary text |
| `btn_secondary_border` | text | (empty) | Secondary border |
| `btn_secondary_hover_bg` | text | (empty) | Secondary hover background |
| `btn_secondary_hover_text` | text | (empty) | Secondary hover text |
| `btn_secondary_hover_border` | text | (empty) | Secondary hover border |
| `btn_secondary_border_width` | select; one of `auto`, `0`, `1`, `2`, `3` | auto | Secondary border width |
| `btn_secondary_radius` | select; one of `auto`, `pill`, `rounded`, `square` | auto | Secondary corners |
| `btn_text_bg` | text | (empty) | Text background |
| `btn_text_text` | text | (empty) | Text text |
| `btn_text_border` | text | (empty) | Text border |
| `btn_text_hover_bg` | text | (empty) | Text hover background |
| `btn_text_hover_text` | text | (empty) | Text hover text |
| `btn_text_hover_border` | text | (empty) | Text hover border |
| `btn_text_border_width` | select; one of `auto`, `0`, `1`, `2`, `3` | auto | Text border width |
| `btn_text_radius` | select; one of `auto`, `pill`, `rounded`, `square` | auto | Text corners |
| `h1_step` | select; one of `auto`, `5`, `4`, `3`, `2`, `1`, `0`, `-1`, `-2` | auto | H1 size |
| `h1_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | H1 weight |
| `h1_leading` | select; one of `auto`, `tight`, `normal`, `relaxed`, `loose` | auto | H1 line height |
| `h1_tracking` | select; one of `auto`, `tighter`, `tight`, `normal`, `wide` | auto | H1 letter spacing |
| `h2_step` | select; one of `auto`, `5`, `4`, `3`, `2`, `1`, `0`, `-1`, `-2` | auto | H2 size |
| `h2_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | H2 weight |
| `h2_leading` | select; one of `auto`, `tight`, `normal`, `relaxed`, `loose` | auto | H2 line height |
| `h2_tracking` | select; one of `auto`, `tighter`, `tight`, `normal`, `wide` | auto | H2 letter spacing |
| `h3_step` | select; one of `auto`, `5`, `4`, `3`, `2`, `1`, `0`, `-1`, `-2` | auto | H3 size |
| `h3_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | H3 weight |
| `h3_leading` | select; one of `auto`, `tight`, `normal`, `relaxed`, `loose` | auto | H3 line height |
| `h3_tracking` | select; one of `auto`, `tighter`, `tight`, `normal`, `wide` | auto | H3 letter spacing |
| `lead_step` | select; one of `auto`, `5`, `4`, `3`, `2`, `1`, `0`, `-1`, `-2` | auto | Lead paragraph size |
| `lead_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | Lead paragraph weight |
| `lead_leading` | select; one of `auto`, `tight`, `normal`, `relaxed`, `loose` | auto | Lead paragraph line height |
| `lead_tracking` | select; one of `auto`, `tighter`, `tight`, `normal`, `wide` | auto | Lead paragraph letter spacing |
| `small_step` | select; one of `auto`, `5`, `4`, `3`, `2`, `1`, `0`, `-1`, `-2` | auto | Small text size |
| `small_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | Small text weight |
| `small_leading` | select; one of `auto`, `tight`, `normal`, `relaxed`, `loose` | auto | Small text line height |
| `small_tracking` | select; one of `auto`, `tighter`, `tight`, `normal`, `wide` | auto | Small text letter spacing |
| `phone_text_size` | number; 13 to 22 | (empty) | Text size on phones. Screens up to 640px wide. Empty follows Text size. |
| `text_align` | select; one of `auto`, `left`, `justify` | auto | Article text |
| `hyphens` | select; one of `auto`, `True`, `off` | auto | Hyphenation |
| `heading_wrap` | select; one of `auto`, `balance`, `pretty` | auto | Heading line breaks |
| `h4_step` | select; one of `auto`, `5`, `4`, `3`, `2`, `1`, `0`, `-1`, `-2` | auto | H4 size |
| `h4_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | H4 weight |
| `h4_leading` | select; one of `auto`, `tight`, `normal`, `relaxed`, `loose` | auto | H4 line height |
| `h4_tracking` | select; one of `auto`, `tighter`, `tight`, `normal`, `wide` | auto | H4 letter spacing |
| `h5_step` | select; one of `auto`, `5`, `4`, `3`, `2`, `1`, `0`, `-1`, `-2` | auto | H5 size |
| `h5_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | H5 weight |
| `h5_leading` | select; one of `auto`, `tight`, `normal`, `relaxed`, `loose` | auto | H5 line height |
| `h5_tracking` | select; one of `auto`, `tighter`, `tight`, `normal`, `wide` | auto | H5 letter spacing |
| `h6_step` | select; one of `auto`, `5`, `4`, `3`, `2`, `1`, `0`, `-1`, `-2` | auto | H6 size |
| `h6_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | H6 weight |
| `h6_leading` | select; one of `auto`, `tight`, `normal`, `relaxed`, `loose` | auto | H6 line height |
| `h6_tracking` | select; one of `auto`, `tighter`, `tight`, `normal`, `wide` | auto | H6 letter spacing |
| `eyebrow_step` | select; one of `auto`, `5`, `4`, `3`, `2`, `1`, `0`, `-1`, `-2` | auto | Eyebrow size |
| `eyebrow_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | Eyebrow weight |
| `eyebrow_leading` | select; one of `auto`, `tight`, `normal`, `relaxed`, `loose` | auto | Eyebrow line height |
| `eyebrow_tracking` | select; one of `auto`, `tighter`, `tight`, `normal`, `wide` | auto | Eyebrow letter spacing |
| `meta_step` | select; one of `auto`, `5`, `4`, `3`, `2`, `1`, `0`, `-1`, `-2` | auto | Meta size |
| `meta_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | Meta weight |
| `meta_leading` | select; one of `auto`, `tight`, `normal`, `relaxed`, `loose` | auto | Meta line height |
| `meta_tracking` | select; one of `auto`, `tighter`, `tight`, `normal`, `wide` | auto | Meta letter spacing |
| `quote_step` | select; one of `auto`, `5`, `4`, `3`, `2`, `1`, `0`, `-1`, `-2` | auto | Quote size |
| `quote_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | Quote weight |
| `quote_leading` | select; one of `auto`, `tight`, `normal`, `relaxed`, `loose` | auto | Quote line height |
| `quote_tracking` | select; one of `auto`, `tighter`, `tight`, `normal`, `wide` | auto | Quote letter spacing |
| `caption_step` | select; one of `auto`, `5`, `4`, `3`, `2`, `1`, `0`, `-1`, `-2` | auto | Caption size |
| `caption_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | Caption weight |
| `caption_leading` | select; one of `auto`, `tight`, `normal`, `relaxed`, `loose` | auto | Caption line height |
| `caption_tracking` | select; one of `auto`, `tighter`, `tight`, `normal`, `wide` | auto | Caption letter spacing |
| `card_title_step` | select; one of `auto`, `5`, `4`, `3`, `2`, `1`, `0`, `-1`, `-2` | auto | Card title size |
| `card_title_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | Card title weight |
| `card_title_leading` | select; one of `auto`, `tight`, `normal`, `relaxed`, `loose` | auto | Card title line height |
| `card_title_tracking` | select; one of `auto`, `tighter`, `tight`, `normal`, `wide` | auto | Card title letter spacing |
| `h1_case` | select; one of `auto`, `none`, `uppercase`, `capitalize`, `small_caps` | auto | H1 case |
| `h2_case` | select; one of `auto`, `none`, `uppercase`, `capitalize`, `small_caps` | auto | H2 case |
| `h3_case` | select; one of `auto`, `none`, `uppercase`, `capitalize`, `small_caps` | auto | H3 case |
| `h4_case` | select; one of `auto`, `none`, `uppercase`, `capitalize`, `small_caps` | auto | H4 case |
| `h5_case` | select; one of `auto`, `none`, `uppercase`, `capitalize`, `small_caps` | auto | H5 case |
| `h6_case` | select; one of `auto`, `none`, `uppercase`, `capitalize`, `small_caps` | auto | H6 case |
| `lead_case` | select; one of `auto`, `none`, `uppercase`, `capitalize`, `small_caps` | auto | Lead case |
| `small_case` | select; one of `auto`, `none`, `uppercase`, `capitalize`, `small_caps` | auto | Small case |
| `eyebrow_case` | select; one of `auto`, `none`, `uppercase`, `capitalize`, `small_caps` | auto | Eyebrow case |
| `meta_case` | select; one of `auto`, `none`, `uppercase`, `capitalize`, `small_caps` | auto | Meta case |
| `quote_case` | select; one of `auto`, `none`, `uppercase`, `capitalize`, `small_caps` | auto | Quote case |
| `caption_case` | select; one of `auto`, `none`, `uppercase`, `capitalize`, `small_caps` | auto | Caption case |
| `card_title_case` | select; one of `auto`, `none`, `uppercase`, `capitalize`, `small_caps` | auto | Card title case |
| `body_weight` | select; one of `auto`, `300`, `400`, `500` | auto | Text weight |
| `paragraph_space` | select; one of `auto`, `tight`, `normal`, `relaxed`, `loose` | auto | Space between paragraphs |
| `nav_size` | number; 10 to 32 | (empty) | Header menu text size |
| `nav_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | Header menu weight |
| `nav_case` | select; one of `auto`, `none`, `uppercase`, `capitalize` | auto | Header menu case |
| `nav_tracking` | select; one of `auto`, `tight`, `normal`, `wide`, `wider` | auto | Header menu letter spacing |
| `dropdown_size` | number; 10 to 32 | (empty) | Dropdown text size |
| `dropdown_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | Dropdown weight |
| `dropdown_case` | select; one of `auto`, `none`, `uppercase`, `capitalize` | auto | Dropdown case |
| `dropdown_tracking` | select; one of `auto`, `tight`, `normal`, `wide`, `wider` | auto | Dropdown letter spacing |
| `drawer_size` | number; 10 to 32 | (empty) | Mobile menu text size |
| `drawer_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | Mobile menu weight |
| `drawer_case` | select; one of `auto`, `none`, `uppercase`, `capitalize` | auto | Mobile menu case |
| `drawer_tracking` | select; one of `auto`, `tight`, `normal`, `wide`, `wider` | auto | Mobile menu letter spacing |
| `topbar_size` | number; 10 to 32 | (empty) | Top bar text size |
| `topbar_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | Top bar weight |
| `topbar_case` | select; one of `auto`, `none`, `uppercase`, `capitalize` | auto | Top bar case |
| `topbar_tracking` | select; one of `auto`, `tight`, `normal`, `wide`, `wider` | auto | Top bar letter spacing |
| `footer_nav_size` | number; 10 to 32 | (empty) | Footer menu text size |
| `footer_nav_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | Footer menu weight |
| `footer_nav_case` | select; one of `auto`, `none`, `uppercase`, `capitalize` | auto | Footer menu case |
| `footer_nav_tracking` | select; one of `auto`, `tight`, `normal`, `wide`, `wider` | auto | Footer menu letter spacing |
| `footer_title_size` | number; 10 to 32 | (empty) | Footer headings text size |
| `footer_title_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | Footer headings weight |
| `footer_title_case` | select; one of `auto`, `none`, `uppercase`, `capitalize` | auto | Footer headings case |
| `footer_title_tracking` | select; one of `auto`, `tight`, `normal`, `wide`, `wider` | auto | Footer headings letter spacing |
| `crumbs_size` | number; 10 to 32 | (empty) | Breadcrumbs text size |
| `crumbs_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | Breadcrumbs weight |
| `crumbs_case` | select; one of `auto`, `none`, `uppercase`, `capitalize` | auto | Breadcrumbs case |
| `crumbs_tracking` | select; one of `auto`, `tight`, `normal`, `wide`, `wider` | auto | Breadcrumbs letter spacing |
| `name_weight` | select; one of `auto`, `300`, `400`, `500`, `600`, `700`, `800` | auto | Site name weight |
| `name_case` | select; one of `auto`, `none`, `uppercase`, `capitalize` | auto | Site name case |
| `name_tracking` | select; one of `auto`, `tight`, `normal`, `wide`, `wider` | auto | Site name letter spacing |
| `input_bg` | select; one of `auto`, `background`, `surface`, `surface_2` | auto | Field background |
| `input_border` | select; one of `auto`, `strong`, `muted`, `text`, `accent` | auto | Field border colour |
| `input_border_width` | select; one of `auto`, `thin`, `medium` | auto | Field border width |
| `label_size` | select; one of `auto`, `small`, `normal`, `large` | auto | Label size |
| `label_weight` | select; one of `auto`, `400`, `500`, `600`, `700` | auto | Label weight |
| `input_size` | select; one of `auto`, `normal`, `large`, `larger` | auto | Field text size. Never below 16px, so phones do not zoom into a field. |
| `input_ring` | select; one of `auto`, `outline`, `high` | auto | Field focus |
| `error_style` | select; one of `auto`, `strong`, `tinted` | auto | Errors |
| `radius_card` | number; 0 to 48 | (empty) | Card corners |
| `radius_image` | number; 0 to 48 | (empty) | Image corners |
| `radius_input` | number; 0 to 48 | (empty) | Field corners |
| `border_width` | select; one of `auto`, `none`, `thin`, `medium`, `thick` | auto | Card border |
| `mobile_gutter` | number; 8 to 60 | (empty) | Side margin on phones |
| `mobile_section_space` | number; 16 to 160 | (empty) | Space between sections on phones. Pixels on a typical phone; the sections grow a little on larger ones. |

## `social` — Social profiles

| field | kind and choices | default | notes |
|---|---|---|---|
| `facebook` | url | (empty) | Facebook |
| `instagram` | url | (empty) | Instagram |
| `linkedin` | url | (empty) | LinkedIn |
| `youtube` | url | (empty) | YouTube |
| `x` | url | (empty) | X (Twitter) |

## `hero_layouts` — Hero Layouts

| field | kind and choices | default | notes |
|---|---|---|---|

## `transparent_header` — Transparent Header

| field | kind and choices | default | notes |
|---|---|---|---|

## `footer` — Footer

| field | kind and choices | default | notes |
|---|---|---|---|
| `layout` | select; one of `columns`, `mega`, `simple`, `bar`, `centered` | columns | Layout |
| `tone` | select; one of `dark`, `light`, `muted`, `accent` | dark | Background. A solid colour. A colour or an image below replaces it. |
| `brand` | select; one of `name`, `logo`, `none` | name | Brand |
| `show_social` | toggle | true | Social icons |
| `show_language` | toggle | false | Language switcher |
| `back_to_top` | toggle | false | Back to top link |
| `copyright` | text; translatable | (empty) | Copyright line. {year}, {site} and {copyright} (the © sign) are filled in. Empty uses the theme's own line. |
| `credits` | text; translatable | (empty) | Credits line. A line under the copyright. Write a link as [text](https://address). {year}, {site} and {copyright} work here too. |
| `summary` | textarea; translatable | (empty) | Summary |
| `email` | email | (empty) | Email |
| `phone` | text | (empty) | Phone |
| `address` | text; translatable | (empty) | Address |
| `hours` | text; translatable | (empty) | Opening hours |
| `background` | color | (empty) | Background color |
| `background_image` | image | (empty) | Background image |

## Stored with the theme settings, edited only in the admin

`single_layouts.<type>` (Theme > Single Layouts: page layout, title area, sidebar for each content type), `search_page` (Theme > Archive Layouts > Search) and `footer_blocks` (Theme > Footer Blocks: blocks above the footer, a set per language).
