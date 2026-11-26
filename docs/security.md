# GnozioCMS Security Notes

This page lists the protections built into the admin and what the server operator still has to configure.

## Built In

### Sessions

- Session cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` when the request arrives over HTTPS (directly, or via `X-Forwarded-Proto: https` from a trusted proxy, below).
- `session.use_strict_mode` is enabled, so unknown session ids are never adopted.
- The session id is regenerated on every sign-in and sign-out.
- Every admin request re-reads the signed-in user from SQLite. Deactivating a user or changing their role takes effect on their next click, not when their session expires.
- An admin session ends after a time without use (**Settings > General > Sign out after**, 2 hours by default, 10 minutes to a
  day) and 24 hours after signing in whatever happens (`src/Auth.php`). Any page of the admin or of the site seen while signed
  in counts as use, a script's polling included. The sign-in page then says why, and signing in again goes back to the admin
  page the person was on (only an address of this site's admin; `LoginController::adminReturn()`). A form sent after the session
  ended is refused as expired, so what it held is not saved. PHP's own session clean-up is set to last at least as long.
  Sessions from before 0.1.312 start counting at their first request after the update.

### Roles and permissions

Roles are defined in `PermissionService` (the super admin can adjust them, see below). Each admin action needs a capability, and an action nobody mapped is for administrators only, so a new route is never open to a lower role by accident.

| | Super admin | Admin | Editor | Basic user |
|---|:-:|:-:|:-:|:-:|
| Dashboard (content figures) | yes | yes | yes | no |
| Pages, posts, projects: write, publish, delete | yes | yes | yes | no |
| Media, categories and tags | yes | yes | yes | no |
| Raw HTML in content | yes | yes | no | no |
| Forms and their submissions, menus | yes | yes | no | no |
| Theme (`theme.manage`; Settings includes it) | yes | yes | no | no |
| Settings, content types, translations, system | yes | yes | no | no |
| The shop's products and looks, coupons, files, Shop settings (`shop.manage`, also Shop manager) | yes | yes | no | no |
| Activity and email logs, notifications | yes | yes | no | no |
| Backups, updates (each with its own settings), import, export | yes | yes | no | no |
| Users and roles | yes | no | no | no |
| Omni (what the site reports to it) | yes | no | no | no |
| Own profile and password | yes | yes | yes | yes |

An editor's dashboard shows only content: the user count, backups, system checks, storage, logs, and notifications are not sent to them. Forms are out of their reach entirely (they hold visitors' personal data and decide where notifications go), including by address, bulk actions, and search. The shop's products and looks are the same for whoever lacks `shop.manage`: an editor cannot change a price or the stock, in the admin or through MCP. The admin menu shows a group (Overview, Content, Shop, Site, System) only when its reader may open something in it. Every refused request is written to the activity log.

**Changing what a role can do.** The table shows the built-in permissions. The super admin can change them for Admin, Editor, and Basic user at **Admin > Roles**; nobody else can open that screen, and it cannot be delegated. The super admin's own permissions are fixed, so nobody can be locked out, and three are never switchable: managing users, managing roles, and restoring backups (each would let its holder gain everything else). Signing in and editing one's own profile stay on for every role.

- **Roles of the site's own.** The super admin can also make roles (a photographer, a translator) at Admin > Roles: a name, a description, and exactly the permissions chosen, starting from nothing or from a copy of Admin, Editor, or Basic user. They are stored whole in `system_meta` (key `custom_roles`), at most 20, and follow the same rules: sign-in and the own profile are always on, and users, roles, and restoring backups can never be given. A key cannot be the name of a built-in role, is made from the name (Greek converted to Latin), and does not change. A person can only be given a role that exists, so a tampered form falls back to Basic user, and a role cannot be deleted while anyone has it (a person whose role is somehow missing can sign in but reach nothing).
- Only differences from the built-in set are stored (`system_meta`, key `role_permissions`); a role left alone follows future defaults. Unknown or unreadable stored values are ignored, never turned into access.
- Each permission is labelled Normal, Sensitive (personal data, bulk data, logs, backups, import) or Critical (raw HTML, settings including sign-in and email, updates). Turning on a Sensitive or Critical one asks for confirmation.
- Every change is written to the activity log as a warning with the capabilities added and removed.
- Settings is Critical because it includes Google sign-in and email, which decide who can get into the site. Raw HTML is Critical because it can script the public site.
- Importing content and saving content follow the same raw HTML rule, so granting import does not bypass it.

After adding or changing an admin action, run `php scripts/check-permissions.php`: it lists every action with the roles that may use it and fails when the rules drift.

**Raw HTML.** Raw HTML in Markdown can carry script, and an editor must not be able to script the public site. When someone without the `content.raw_html` capability saves, any HTML they type in the text or in a block's Markdown fields is shown as plain text and the editor is told so. HTML that is already in that content (put there by an administrator, for example an embed) is left alone, so an editor's edit never breaks it. `[text](javascript:…)` style links are neutralised for everybody. Uploads are checked separately (SVG files with scripts are refused).

### Automation accounts

An account that a connection (MCP, `docs/plans/mcp-plan.md`) writes as, made by someone who manages users with **Add automation account** (`users.source = 'automation'`). It has a role like anyone and its name is in the history and the logs, but it has no password, no email and no Google link, so it never signs in: the password form, Google sign-in (which matches by email) and `Auth::loginUser` refuse it, and a session that somehow names it ends at the next look. It can never be the super admin, the fallback that makes a super admin when none is left skips it, and a person never becomes one (or the other way round). Making it inactive stops every connection that uses it. Tests: `tests/unit/accounts.php`, `tests/http/automation_accounts_test.py`.

### MCP (AI assistants)

Off until a super admin turns it on in **Settings > MCP** (`mcp.manage`, super admin only, not grantable). It is OAuth 2.1 with S256 PKCE and public clients only, dynamic registration limited to HTTPS redirect addresses (http only on localhost), a consent screen in the admin where the person picks the account the connection acts as (themself or an automation account) and the scopes, codes used once within five minutes, access tokens of an hour, refresh tokens of 90 days that rotate (the one replaced works for 60 more seconds), and tokens made by hand for at most a year. Only SHA-256 hashes of codes and tokens are stored. The endpoint refuses a foreign `Origin`, a batch, a body over 1 MB and anything but JSON, and limits requests per address (300 a minute) and per token (120 a minute); registration and the token endpoint have limits of their own. On every call the account must still be active and the connection not paused or removed; what a tool may do is its scope and the permission the account's role has then. Every call, refusal and change is in the activity log (`mcp.*`). Pictures fetched from an address (`add_image_from_url`) go through `SafeHttp`: http(s) on 80 and 443, public addresses only (checked for every address of the host, and the connection pinned to the one checked, so DNS rebinding cannot turn it inward), at most 3 redirects re-checked, 10 MB, 15 seconds, and only files whose bytes are a picture. The site tools (`terms:write`, `site:read`, `site:write`, all off by default) leave to a person what an assistant tricked by a page it read could turn against visitors or the owner: no redirect to another website, no change to where a form's emails go or where it sends visitors, no reading of what visitors sent, and no change to the codes that prove who owns the site. Details: `docs/mcp.md`.

### Storage limit

The super admin sets how much space the site may use (Settings > Limits, `limits.manage`, held only by the super admin and never grantable, stored as `limits.storage_mb` in the site settings, default 1024, 0 for none). A value sent by anyone else is ignored. Use is content, the system database and its backups, and uploads. Only new uploads are refused at the limit, so a full site can still be edited, saved, and backed up; the message everyone sees from 90% names the super admin's address as a `mailto:` link and nothing else leaves the server.

### Links and deleting

Updating the links that still use an old address needs `content.manage` (it edits content) and only offers permanent redirects that are on. It changes nothing but the address in the text, files of types the person cannot edit (forms without `forms.manage`, the shop's products and looks without `shop.manage`) are skipped, and every changed file gets a history entry ("Links updated") holding the earlier text. When a public entry is deleted, choosing where visitors go needs `redirects.manage`; without it the choice is ignored, so a permission that lets someone send visitors to another website cannot be used through the delete form. The target is checked like any redirect (no scripts, no loops), and an address on this site must exist. Deleting keeps the text in the history for 180 days, for bulk deletes too.

### Redirects

Redirects are managed with `redirects.manage` (Sensitive: a redirect can send visitors to another website). A target must be a path on this site or an `http(s)` address; `javascript:`, `data:` and other schemes are refused, as are loops and redirects from `/admin`. The list of addresses that were not found keeps only the path and the referring host and path (no query strings, no IP addresses), ignores files and probes, and is capped at 1000 rows. The Not found tab can download the visible or ignored paths as UTF-8 plain text, one path per line, with the current search applied; filtered file probes are not present to export. Address changes made by an editor still create their redirect, because that is a side effect of saving, not a redirect management action.

### History

Admin > History and the editor's History tab need `content.manage`. Restoring or bringing back an item is a `POST` with the CSRF token, is written to the activity log, and goes through the raw HTML rule: someone without `content.raw_html` gets HTML that is not already in the current file shown as text, so an old version cannot be used to put script back. Forms are excluded from lists and refused by address unless the person may manage forms, because a form's history includes where its notifications go; the shop's products and looks likewise unless the person has `shop.manage`, since their prices and stock are the shop's. The history holds the full text of content, so it is protected like the content itself and travels in database backups; it keeps the latest 50 versions of an item and deleted items for 180 days.

### CSRF

- Every admin `POST` must carry the per-session token (`_csrf` field or `X-CSRF-Token` header). This includes the sign-in form.
- Templates add the field with `{{ csrf_field() }}`. `public/assets/js/admin.js` also appends it to any `POST` form that lacks one at submit time, as a safety net for script-built forms.
- A rejected submission returns HTTP 419 with a "form expired" message and is written to the activity log as `security.csrf_rejected`. Uploads larger than PHP's `post_max_size` arrive without any fields and are rejected the same way.
- Sign-out is `POST` only. `GET /admin/logout` just redirects.

### Second factor

- A person can add a second factor from their profile (**Second factor**, `/admin/second-factor`): a code from an
  authenticator app (TOTP, RFC 6238: 6 digits, 30 seconds; `src/Totp.php`). The screen shows a QR code, drawn on the server
  (`src/QrCode.php`) so the secret never goes to a service that draws codes, and the key to type. It is turned on with a code from
  the app, and then shows ten recovery codes once; each signs in once in place of a code from the app.
- After a right password, the session holds who gave it for five minutes and the sign-in page asks for the code; nobody is signed
  in until it is given. A code is taken from the step before and after too (a phone clock a little off), and never twice. A wrong
  code counts against the limits of *Sign-in Throttling* below like a wrong password, and a right password does not clear them:
  only a right code does. Signing in with a recovery code leaves a notification saying how many are left.
- The secret is kept sealed by the site key (*Settings Secrets*), the recovery codes only as SHA-256 hashes, in
  `user_second_factors` (`src/SecondFactor.php`). On a server without the key (a backup restored elsewhere) the codes from the
  app cannot be checked; recovery codes still work, and the screen says to set it up again.
- **Users > Roles > Second factor**: the super admin chooses the roles that must have one (none by default; the roles that can
  change the shop, the settings or the users are marked as suggested). A person of such a role who signs in with a password and has
  none is taken to set it up before any other admin page, and cannot turn it off. Signing in with Google is not asked for a code
  (Google has its own) and is not sent to set one up.
- New recovery codes and turning it off each need a code. The super admin can clear another person's (a lost phone), from that
  person's profile; it is logged. Someone with access to the server can clear anyone's, the super admin's included:
  `php scripts/clear-second-factor.php <username>` (also logged).

### Sign-in Throttling

- Failed password sign-ins, and wrong codes of the second factor, are stored in the `login_attempts` SQLite table.
- 8 failures from one address, or 12 failures for one username, within 15 minutes block further attempts (HTTP 429 with `Retry-After`) until the oldest counted failure ages out.
- The address is the visitor's address as *Trusted proxies* (below) defines it: `REMOTE_ADDR`, unless the request comes from a listed proxy. MCP's limits and the activity log use the same address.

### Shop limits for anonymous visitors

What anyone can make the shop do without signing in is counted in the `action_limits` table (`src/ActionLimit.php`), by the
visitor's address and by what it is done to, kept as hashes. Past a limit the visitor gets the answer they would get anyway, and
nothing is made or sent:

- **Account emails** (sign-up, forgot password, the confirmation again): at most 6 an hour from one address and 3 an hour to one
  email (`ShopFront::MAILS_PER_ADDRESS`, `MAILS_PER_EMAIL`).
- **Sign-up with an address that has an account** answers as a new sign-up does ("we sent a link to …") and mails the owner a
  link to set a new password instead; the account is not changed. With *Confirm the email address* off a new account is signed in
  at once while a taken address is not, so that setting still tells the two apart.
- **Orders**: at most *Orders from one visitor in an hour* (Shop settings > General, 10 by default) from one address and from
  one cart. Card orders, which hold their pieces until paid, at most *Unpaid card orders one visitor may hold* (3) that are
  still waiting.
- **A guest's order not yet paid** can be placed with anyone's email: the confirmation sent to it leaves out the gift note (the
  shop's own notice keeps it).
- **The Viva webhook** takes an event once by its key before it asks Viva about it; an event Viva does not know is forgotten (so
  Viva's next try is asked again) and counts against the address it came from: past 20 in ten minutes it is answered 429 without
  asking Viva.

When a request comes through `X-Forwarded-For` from a proxy that is not listed under *Trusted proxies*, every visitor would look
like the proxy, so the limits by address are left off and only those by email and cart count.

### Trusted proxies and the site address

`X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Host` are written by whoever sends a request, so the site believes them
only when the connection comes from a proxy listed in **Settings > General > Trusted proxies** (addresses or CIDR ranges, IPv4 or
IPv6, one per line). Until the list is changed it holds this machine, `127.0.0.1` and `::1`, since only a proxy on the same
server can connect from there; emptied, no forwarded header is believed. With none listed, the visitor's address is `REMOTE_ADDR`, HTTPS is the connection's own,
and the host is `Host`. Behind a listed proxy, the visitor is the first address of `X-Forwarded-For` read from the right that is
not itself a listed proxy, so what a visitor writes in the header (on the left) is never used (`src/RequestOrigin.php`).

What to list:

- **CloudPanel** (nginx in front of PHP-FPM on the same server): PHP sees the visitor's address as `REMOTE_ADDR` (nginx's own,
  or the one `real_ip` sets), so the default is fine; empty the list if nothing else stands in front.
- **Varnish** in front of nginx (CloudPanel's Varnish, or one of your own on the same machine): `127.0.0.1` and `::1`, the default, plus the
  address of Varnish if it runs elsewhere.
- **cloudflared** (a Cloudflare Tunnel): the default, when the tunnel runs on the server. cloudflared adds the visitor's
  address to `X-Forwarded-For`.

**Links that leave the request** (password-reset and verify emails, the order's link in an email, download links, the payment
gateways' return and webhook addresses) are built from **Settings > General > Site address** only, never from `Host` or
`X-Forwarded-Host`. With no address set they are paths without a host, so an email can never point to another site; and the
shop refuses to turn on card payments (Viva Wallet, Cardlink) or customer accounts (sign-up, Google sign-in) until the address is
set, and says why. Pages themselves are still built with the request's host when the address is blank, so a fresh install works.

### First administrator and default credentials

- A new site ships no account. While the users table is empty, the sign-in page asks for the first administrator (username, email, a password of at least 8 characters that is not the shipped one) and makes a super admin. The page is gone as soon as one account exists, and a second account cannot be made through it. A site set up by `installer.php` is closed to strangers: the installer's last screen shows a one-time setup code, keeps only its hash in `storage/setup-code.json`, and the form asks for it; the file is deleted when the account is made (delete it by hand if the code is lost). A site unpacked by hand has no code: **open `/admin` right after putting it on a server**, because until the first account exists, whoever visits first can make it.
- Older sites may still have `content/users/users.yaml` with a plain-text development account. It is imported into SQLite only when the users table is empty. Signing in with that shipped password shows a red banner on every admin page and creates a `security.default_password` notification until the password is changed.
- New passwords must be at least 8 characters and cannot equal the shipped password.

### Installing updates

- Only a person with `updates.manage` (a Critical permission) can install, and only with a verified backup made in the last 24 hours on the version being replaced.
- The package is downloaded over https only and compared with the size and SHA-256 in the release manifest before it is opened. A package that is not the published one changes nothing.
- A package is accepted only if every file in it is on a list of code paths (`UpdateInstaller::isAllowedPath`). One file for `content/`, `custom/`, `storage/` or `public/uploads/`, a path with `..`, an absolute or backslash path, or a link refuses the whole package, so a package cannot overwrite a site's data or write outside the site folder.
- While the code is swapped the public site answers 503; the maintenance flag has a token that only the installer's own check of the new version knows, and a flag more than 15 minutes old is ignored.
- If the new version fails the check, the old code (and the database, if its structure changed) is put back. The checksum protects against a corrupted or swapped file, not against a compromised release: anyone who can publish a release on the repository can publish code.

### Settings Secrets

- SMTP password, SES secret, Google client secret, S3 secret key, the GitHub update token and the YouTube key are never rendered back into the Settings page; nor are the shop's payment keys (Viva, Cardlink, Meta, Google, WooCommerce) or the AI provider's key on their screens.
- Leaving a secret field blank keeps the stored value. Tick "Remove the saved value" to clear it.
- **Encrypted at rest** (`src/SecretBox.php`, 0.1.313): all of these are stored in `storage/db/app.sqlite` encrypted with AES-256-GCM
  under a key in **`storage/key`**, made on first use (mode 0600). The key is never put in a backup or an update package, so a
  backup alone, local or on S3, opens no secret. Values stored before 0.1.313 are read as they are and encrypted the next time
  their screen is saved.
- **Keep `storage/key`.** Restoring a backup on the same server works as before. On another server (or after `storage/key` was
  lost) the secrets cannot be opened: they read as empty, nothing fails, and each screen shows them as *to be entered again*.
  To move a site with its secrets, copy `storage/key` to the new server yourself, over a safe channel.
- The rest of a backup (content, customer data, orders) is plain unless **Backups > Settings > Backup passphrase** is set: then
  each archive is encrypted with AES-256 (`docs/backups.md`, *Encryption*). Without one, treat archives as sensitive.

### Uploads

- The media library only accepts inert file types: images, office documents, PDF, text/CSV/Markdown, ZIP, and common audio/video formats. Anything else, including `.php`, is rejected. A site can narrow this further in Settings > Limits (super admin only): by kind (images, SVG, documents, archives, audio, video) and by the size of one file (default 20 MB, never more than the server's `upload_max_filesize` and `post_max_size`). It can never widen it: the extension list is fixed in `MediaLibrary::UPLOAD_GROUPS`.
- Raster images must be detected as `image/*`.
- SVG files are rejected if they contain scripts, event handlers, `javascript:` URLs, `foreignObject`, embedded frames, entity declarations, or external/data references.

### Files attached to forms

- A file field accepts only the extensions the form lists, checks the real content with `finfo` against the extension, never uses the browser's type, and always refuses programs, scripts, web pages and SVG (`FormUploads::BLOCKED`, also in a double extension, and HTML/PHP/shell/executable content), whatever a form allows.
- Files are written only after the rest of the submission is valid, into `storage/form-uploads/<form>/<submission>/` under names made from the form's pattern (slug characters only, the extension the checked one). They are outside the web root and denied for the web server; a visitor never gets a file back.
- The admin gives a file out only through `/admin/form-file` and `/admin/form-files-zip`, which need a signed-in user with `forms.manage` (the editor role has none), take the form and submission from the address and the file name from a strict pattern (never a path), send `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`, and log each download.
- Files are kept until their submission is deleted. They hold visitors' personal data (CVs, IDs): treat backups that contain them as sensitive, as with the database.
- Email attachments are the form owner's choice, with a size cap; the visitor's automatic reply never has files or admin links.

### Response Headers

- Admin: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Cache-Control: no-store, private`.
- Frontend: `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`.
- Everywhere: `Permissions-Policy: camera=(), microphone=(), usb=(), payment=(self)` (`src/SecurityHeaders.php`).
- **HSTS** (`Strict-Transport-Security: max-age=31536000`) when **Settings > General > Always use HTTPS** is on, and only over
  HTTPS. Off by default: once a browser has seen it, it refuses plain HTTP for a year, so turn it on only when the site and every
  address it answers on work over HTTPS. No `includeSubDomains` or `preload`.
- **Content-Security-Policy, report-only**, on the admin and on the shop's pages (cart, checkout, account, the payment pages):
  scripts and styles from the site (inline ones too), pictures from anywhere over HTTPS, no plugins (`object-src 'none'`), no
  other base address; the admin is never framed (`frame-ancestors 'none'`) and posts only to the site and Google sign-in, and
  takes its font from Google Fonts; the shop's pages may post to Viva Wallet and the Cardlink acquirers (live and test) and run
  the Meta Pixel and the Google tag. Report-only means a browser writes in its console what the policy would block and blocks
  nothing; no address collects the reports. Checked in a browser on the dashboard, the editor, the media library, Shop settings,
  the cart, the checkout with the Pixel running, and the account page: no report. Enforcing it (and dropping `'unsafe-inline'`
  for scripts, which needs nonces in the templates) is the next step. Ordinary pages get no policy: their blocks embed what the
  editor chose (videos, maps, forms of other services). The site's own copy of a page (System > Page cache) is sent with the
  headers it was kept with.

## Operator Checklist

1. Point the web root at `public/` only. `content/`, `storage/`, `src/`, and `vendor/` must never be reachable over HTTP.
2. Serve the site over HTTPS.
3. Change the shipped admin password immediately (the admin banner reminds you). Consider requiring a second factor for the roles that can change the shop, the settings or the users (*Second factor* above).
4. Keep `storage/` shut off, and with it the files visitors attach to forms (`storage/form-uploads/`): they are private and only the admin gives them out. With the web root on `public/` (point 1) nothing else is needed; as defence in depth Apache gets `storage/form-uploads/.htaccess` (`Require all denied`, written by the CMS) and nginx should have:

   ```nginx
   location ^~ /storage/ { deny all; return 404; }
   ```
5. Make sure uploads can never execute:
   - SVG drawings are parsed on upload and written again with only the elements and attributes a drawing needs (`SvgSanitizer`): no script, event attribute, `<foreignObject>`, animation that sets an event or a link, stylesheet that imports, or link outside the drawing; a file with a DOCTYPE, or that does not parse, is refused. The header below is the second line of defence, and **System > Status › SVG uploads header** asks the site for a small drawing once a day to see that it is sent.
   - Apache: `public/uploads/.htaccess` ships with the project and disables script handlers, denies script/HTML extensions, and adds a restrictive CSP to SVG responses. It requires `AllowOverride` to be enabled for that directory.
   - nginx: add an equivalent block, for example:

     ```nginx
     location ^~ /uploads/ {
         location ~* \.(php\d?|phtml|phar|pht|cgi|pl|py|sh|html?)$ { deny all; }
         location ~* \.svg$ { add_header Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; img-src data:"; }
         add_header X-Content-Type-Options nosniff;
         # Missing responsive image variants (/uploads/_v/…) are generated by index.php.
         try_files $uri /index.php?$query_string;
     }
     ```

     **CloudPanel** (and any server block that has no PHP and passes pages on with `proxy_pass`): the `try_files … /index.php` line above would answer 404 for picture variants not yet made. Add these two locations instead, in the block on port 443 and **above** CloudPanel's `location ~* ^.+\.(css|js|jpg|…|svg|…)$`, since nginx takes the first regular expression that matches. Everything else under `/uploads/` stays as it was:

     ```nginx
     location ~* ^/uploads/.*\.(php\d?|phtml|phar|pht|cgi|pl|py|sh|html?)$ { deny all; }
     location ~* ^/uploads/.*\.svg$ {
         add_header Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; img-src data:";
         add_header X-Content-Type-Options nosniff;
         add_header Access-Control-Allow-Origin "*";
         expires max;
         try_files $uri =404;
     }
     ```

     Saving the Vhost in CloudPanel tests and reloads nginx; elsewhere `sudo nginx -t && sudo systemctl reload nginx`. **Behind a CDN** (Cloudflare and the like) purge its cache of `/uploads/` afterwards: it keeps SVG files with the headers they had, for hours. Check with `curl -sI "https://example.com/uploads/_v/header-check.svg?x=1" | grep -i content-security`. The status check asks past the CDN's cache and looks again ten minutes after a warning.

     Theme and custom files are copied into `public/` and image variants not yet written have addresses with no file extension, so a static-file location (for example a `\.(css|js|webp)$` caching block, as CloudPanel has) needs no change while PHP can write to `public/`. Where it cannot, theme assets (`/_themes/…`, `/_custom/…`) are served by PHP, and such a location must end in `try_files $uri /index.php?$query_string;`.

     For that case: CloudPanel's PHP site, with Varnish on or off, has no PHP in the block on port 443: its `location /` passes pages on (`{{varnish_proxy_pass}}`, to Varnish or straight to the block on port 8080, which runs PHP). There `/index.php` would reach the site as the address `/index.php` and answer 404. In that block send a missing file the same way as a page, under its own address, with a named location:

     ```nginx
     location ~* ^.+\.(css|js|jpg|jpeg|gif|png|ico|gz|svg|svgz|ttf|otf|woff|woff2|eot|mp4|ogg|ogv|webm|webp|zip|swf|map|mjs)$ {
         # …CloudPanel's lines…
         try_files $uri @app;
     }
     location @app {
         {{varnish_proxy_pass}}
         # and every proxy_* line of `location /` in this block
     }
     ```

     The installer checks this before it finishes: when `/_themes/default/css/site.css` does not answer with the stylesheet, it shows the line to add.
5. If a credential was ever committed to git (for example SMTP credentials in the old `content/settings/site.yaml`), rotate it with the provider. Deleting the file does not remove it from history.

## What the logs keep

The activity log (with each row's address and browser), the email log and the payment providers' events hold personal data, so
they are cleared once a day (`LogRetention`, from the cron job's work or after a visit): activity after **180 days**, emails
after **90**, provider events after **180**. Other periods (7 to 3650 days) go in `settings.yaml`:

```yaml
retention:
  activity_days: 365
  email_days: 30
  gateway_days: 180
```

MCP clients that registered and never made a connection are removed after a week, a bad MCP token writes one warning per
address a minute rather than one per request, and a replaced refresh token used again after its minute of grace ends its
connection (the sign of a stolen token) with a warning in Activity.
