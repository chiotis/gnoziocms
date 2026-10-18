# Settings: where they are and how to change them

**Settings are not files.** They are two YAML documents inside the SQLite database `storage/db/app.sqlite` (table `system_meta`, keys `site_settings` and `theme_settings`). A fresh install has neither stored: the code defaults apply until someone saves a screen. Users, roles, redirects, history and logs are in the same database. Content, menus, taxonomies, forms, media notes, `custom/` and uploads are files.

Pick the way that fits what you have:

| You have | Change settings by |
|---|---|
| the installation's files and Python | `scripts/site_settings.py` (theme settings: all of them; site settings: a safe subset). It validates, copies the database first, and the site shows the change on the next request. |
| the installation's files and PHP/sqlite, no Python | read the YAML from `system_meta`, change it the same way, write it back; keep the values valid (see `theme-settings.md`) and make a copy of `app.sqlite` first. |
| only a browser signed in to the admin | the screens below. |
| nothing but the chat | tell the person exactly which screen and which values, from this page. |

Never do these for the person, even with access: set passwords or API keys (SMTP, Amazon SES, Google sign-in, YouTube, S3 backups, update tokens), create or change users and roles, change storage/upload limits, install updates, restore a backup. They are in the admin on purpose (secrets are stored without being shown again, and a mistake can lock people out). Say which screen, and let them type the secret.

## Theme settings (the look): `theme_settings`

Sections: `appearance` (palette, mode, font, corner shape, heading colour: `text`, `accent` or `custom`), `header`, `sidebar`, `brand` (logo, favicon, share image), `design` (your own colours, fonts, sizes, spacing, buttons), `social`, `hero_layouts`, `transparent_header`, `footer`. The header's `row_tone` also has `fade-muted` and `fade-soft` (the header melts into the page). Every field with its choices and default is in `theme-settings.md` (shipped with this skill for one version; for the installed version run `python3 scripts/site_settings.py schema theme --markdown`).

```bash
python3 scripts/site_settings.py show theme header                       # what is stored
python3 scripts/site_settings.py set theme appearance.palette=emerald header.layout=split footer.layout=bar
python3 scripts/site_settings.py set theme brand.logo=/uploads/media/<id>.png social.facebook=https://facebook.com/x
python3 scripts/site_settings.py set theme footer.copyright='{"default":"© {year} {site}","en":"© {year} {site}. All rights reserved."}'
```

Rules the CMS enforces (the script applies the same): a value not among a select's choices, a colour that is not plain colour syntax, a URL with spaces or quotes, a number out of range is **replaced by the default without an error**, so check the answer of the script. Text fields marked *translatable* take a plain text (every language) or a map `default` + language codes. Missing fields use their default, so only changed values need to be stored. Keys the theme does not declare are kept untouched.

Also inside theme settings, but **edit these in the admin**: `single_layouts.<type>` (Theme > Single Layouts: page layout, title area, sidebar, a *Breadcrumbs* chip, and for points/routes/businesses how many places *near* it to list), `search_page` (Theme > Archive Layouts > Search) and `footer_blocks` (Theme > Footer Blocks: blocks shown above the footer on every page, one set per language: a logo row, a call to action, a form).

In the admin: **Theme** has a tab per section plus *Branding* (identity, colours, typography, layout, shape, buttons, links, button styles, with a live preview), *Single Layouts*, *Archive Layouts* and *Footer Blocks*. Save with the Save bar at the bottom.

Colours live in the palette (a site palette, `design.site_palettes`; the per-role `design.light_accent` and the like are retired and stay empty). Font and layout choices go further than the palette: `design.heading_font`, `design.body_font`, `design.scale`, `design.container`, `design.radius`… (`#rrggbb`; empty means "the theme's own").

**Your own fonts** (a client's brand typeface) are a screen job for a person, because font files must be uploaded: Theme > Branding > Typography > *Your own fonts*. There they add the files of a family (WOFF2 is best; WOFF, TTF and OTF work), check the weight and style of each file (read from the file and editable), see which scripts it covers (Latin, Greek, Greek Extended, Cyrillic, symbols) and a Greek and Latin sample, then set Headings or Text to *Your own font* and choose the family. The settings are `design.fonts` (a list of `{family, file, weight, style, range, cover, adjust, v}`, the file being a path under `custom/assets/`, for example `fonts/acme/acme-400-1a2b3c4d.woff2`), `design.heading_family` and `design.body_family`; `design.heading_font: custom` / `design.body_font: custom` make a role use its family. Do not write `design.fonts` by hand: the upload fills in the rest. On a Greek site, tell the person if the chosen family has no Greek: the screen warns about it. The single `design.font_file` of earlier versions keeps working.

## Site settings: `site_settings`

Admin > **Settings** (System menu), tabs General, Email / SMTP, Auth, Backups, Updates, APIs, Limits (superadmin only).

| Key | What | How |
|---|---|---|
| `title`, `tagline` | the site's name and one-line description (page titles, the structured data, emails) | script or *Settings > General* |
| `base_url` | full address `https://example.com` (used in absolute links, sitemap, emails; empty derives it from the request) | script or General |
| `date_format` | how dates print (`d/m/Y`) | script or General |
| `home_page` | file name (no `.md`) of the home page, default `index` | General |
| `languages.default`, `languages.available` | **risky**: they decide which files are the default language (`about.md`) and which have a suffix. Changing the default means renaming files. | General, or the script with `--risky` after planning the renames |
| `menu_locations.header`, `.footer` (`.footer_legal`) | which `content/menus/<name>.yaml` shows where | script, or *Menus* (menu editor sets it) |
| `forms.*` | mail driver, sender, SMTP/SES, anti-spam | *Settings > Email*; passwords by the person |
| `backup.*` | schedule, local retention, S3-compatible remote storage | *Settings > Backups*; keys by the person |
| `apis.maps.*`, `apis.youtube.*` | tile server, when maps load, YouTube key and cache | script (not the key) or *Settings > APIs* |
| `seo.*` | title format, social cards, robots rules, sitemap, structured data, ownership codes | *Admin > SEO* (tabs Overview, Search, Social, Crawling, Identity, Verification): has live previews and checks |
| `content_types`, `content_types_off` | which content types are on | *Admin > Content types* |
| `limits.*` | storage and upload limits, allowed upload kinds | superadmin, *Settings > Limits* |
| analytics choice | none / own code / the platform's cookieless analytics | *Admin > Analytics* |
| page cache address | a Varnish in front of the site (CloudPanel's is found by itself); *Empty the page cache* button | *Admin > System > Page cache* |
| Omni connection | reports the site's health to an Omni monitoring app | superadmin only, *Admin > Omni*; never connect or disconnect it yourself |

`python3 scripts/site_settings.py set site title="Νέο όνομα"` accepts only the safe keys it lists in `--help`. For the others use the screens.

## Other things people call "settings"

- **Words on the site** (Read more, Search, footer headings, form messages): *Admin > Translations*, or the file `custom/lang/<lang>.yaml`, a flat map `key: text` (keys are in `themes/default/lang/<lang>.php`). Only differences from the theme are stored.
- **Menus**: *Admin > Menus* or `content/menus/*.yaml` (see `content-format.md`).
- **Redirects**: *Admin > Redirects* (one by one, or *Import*: lines `old-path new-path [301|302]`, up to 500 at a time). The *Not found* tab lists addresses visitors missed; *Ignore all* hides bot noise for good.
- **Scheduled backups**: run after a public visit (PHP-FPM, LiteSpeed) or from a cron job `php src/cron-backup.php` once a day (Admin > System > Cron job shows the exact line). Setting up the cron job is the person's or the host's job.
- **Content types and their fields, taxonomies**: *Admin > Content types / Taxonomies*, or `custom/content-types/<type>.yaml` and `content/taxonomies/*.yaml`.
- **Custom CSS/JS**: `custom/assets/css/custom.css` and `custom/assets/js/custom.js` load automatically after the theme's. Prefer the Branding settings; use CSS only for what they cannot do.
- **Users, roles**: *Admin > Users & Roles*. Roles: superadmin, admin, editor, user; the superadmin can adjust what each role may do.

## After a change

Reload the page it should change. A site behind Varnish (CloudPanel) shows a change made outside the admin only after *System > Page cache > Empty the page cache*. If a theme setting seems ignored: it may have been replaced by the default (invalid value), it may be hidden by a `when` condition (a field only matters while another has a value), or the Theme tab was open in a browser and saved its old values afterwards.
