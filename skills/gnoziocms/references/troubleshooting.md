# When it does not look right

The CMS is forgiving by design, so most problems are silent. Find the symptom, check the cause, fix it in the file (or the screen), and run `python3 scripts/validate_content.py` again.

| Symptom | Likely cause | Check / fix |
|---|---|---|
| The page is a 404 | `status: draft`, or `visible: false` (that is a 404 for visitors too), or the file name is wrong (capitals, spaces, `.eng.md`, not `.md`), or it is in the wrong folder, or its language is not the one in the address (`/en/x` needs `x.en.md`; a default-language file never answers under `/en/`) | the validator names each; a signed-in person can open drafts at their address |
| The home page is not mine | the home page is the file named in `home_page` (default `pages/index.md`), not "the first page" | `site_info.py` prints it |
| Everything on the page is gone / it is "a draft" after my edit | the YAML front matter cannot be parsed: the CMS then treats the file as an unpublished draft and the dashboard flags it | validator ERROR "front matter cannot be read". Usual causes below |
| A block is missing | unknown `type`; `hidden: true`; its list is empty (a features block with no items draws nothing); `latest` with no entries; wrong indentation put it inside the previous block | validator; count blocks in the file |
| A block looks like its default (wrong layout, wrong columns) | a value that is not one of the choices was replaced by the default: `variant: wide`, `columns: 5`, `tone: dark` | validator WARN names the allowed values |
| A button or link does nothing / disappeared | an unsafe link was replaced by empty: spaces, quotes, `javascript:` | links are a path, a full http(s) URL, `#anchor`, `mailto:`, `tel:` |
| A picture does not show | the file is not in `public/uploads/media/`; the address in the block is the old site's; `image_alt` filled but `image` empty; the file kind is not allowed | validator WARN "does not exist"; use `add_media.py` |
| The English page does not link to the Greek one (language switcher) | the two files do not share the same `translation_id`, or one is missing | give both the same 16-hex value |
| The page exists but is in no menu | pages are never added to menus automatically | `content/menus/main.yaml` or Admin > Menus |
| A theme setting had no effect | invalid value (replaced by default); the field has a `when` condition (only matters while another field has a value, such as `align` only for cover/steps hero layouts); an admin tab was open and saved old values afterwards; a `custom/` template overrides that part | `site_settings.py show theme section.field` prints the stored value and the allowed ones |
| Text with `: ` or `#` or `?` breaks the YAML | an unquoted value containing `: `, ` #`, or starting with `*`, `&`, `!`, `@`, `%`, backtick, `{`, `[`, `'`, `"`; or a flow map `{ a: b, c: d }` with commas or `?` in a text | put the text in double quotes (`"Πόσο διαρκεί;"`), use `\|` for several lines, or write maps one field per line. Tabs are not allowed in YAML |
| `yes`, `no`, `on`, `off` | the CMS's YAML reads them as text, not as booleans (`visible: no` stays visible) | write `true` / `false` |
| A number or code lost its zeros, or `3` is not accepted | unquoted `00123` becomes 123 (or is read as octal); a select expecting `'3'` is fine with 3, but a phone number is not | quote codes and phone numbers: `"00123"` |
| The date is wrong | a date that is not `YYYY-MM-DD`; posts sort by `date`, else by file time | write `2026-10-06` |
| A heading appears twice | the title is already the `<h1>`; the body or a text block repeated it | start the body with `##` |
| The admin cannot save a page I wrote | the file belongs to another system user than PHP runs as (written by SSH as root, edited by the web server as www-data), so PHP cannot write it | match the owner and mode of the neighbouring files (`ls -l content/pages`), `chown`/`chmod g+w` |
| The admin search does not find new files | the search index rebuilds itself when files change outside the admin; if not, Admin > System has a rebuild button | the public site never uses the index; it reads the files |
| A change from `site_settings.py`, an import or a file you wrote does not show to visitors | the page cache in front of the site (Varnish/CloudPanel) is emptied only by a save in the admin; or a cached page in the browser; or you wrote to a different installation | *Admin > System > Page cache > Empty the page cache*; reload; `site_info.py` prints the root it works on |
| After a migration the Not found list fills with old addresses | real old pages without a redirect, plus bots asking for WordPress paths (`wp-login.php`, `xmlrpc.php`, `/wp-content/…`) | redirect the real ones (Redirects > Import); *Ignore all* hides the noise for good (Show ignored lists it again). `/category/x/page/2/` and `/feed/` need no redirect |
| Two pages fight for one address | a page and a post (they share `/<slug>`), or a slug that equals a content type or a language code | the validator reports it |
| After an update something is different | updates replace code and theme, never `content/`, `custom/`, `storage/` or uploads; a field you used may have been retired (kept working) | `CHANGELOG.md` of the installation |

## Before you hand over

- Every file you wrote passes `validate_content.py` with no ERROR, and you have read each WARN.
- You opened (or asked the person to open) one page of each kind in each language.
- Drafts are listed as drafts; anything invented is marked `TODO` and mentioned.
- You say what you changed, where (file paths or screens), and how to undo it (History, the database copy `site_settings.py` made, the backup).
