# GnozioCMS Security Notes

This page lists the protections built into the admin and what the server operator still has to configure.

## Built In

### Sessions

- Session cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` when the request arrives over HTTPS (directly or via `X-Forwarded-Proto: https`).
- `session.use_strict_mode` is enabled, so unknown session ids are never adopted.
- The session id is regenerated on every sign-in and sign-out.
- Every admin request re-reads the signed-in user from SQLite. Deactivating a user or changing their role takes effect on their next click, not when their session expires.

### Roles and permissions

Roles are defined in `PermissionService` (the super admin can adjust them, see below). Each admin action needs a capability, and an action nobody mapped is for administrators only, so a new route is never open to a lower role by accident.

| | Super admin | Admin | Editor | Basic user |
|---|:-:|:-:|:-:|:-:|
| Dashboard (content figures) | yes | yes | yes | no |
| Pages, posts, projects: write, publish, delete | yes | yes | yes | no |
| Media, categories and tags | yes | yes | yes | no |
| Raw HTML in content | yes | yes | no | no |
| Forms and their submissions, menus | yes | yes | no | no |
| Settings, content types, translations, system | yes | yes | no | no |
| Activity and email logs, notifications | yes | yes | no | no |
| Backups, updates, import, export | yes | yes | no | no |
| Users and roles | yes | no | no | no |
| Omni (what the site reports to it) | yes | no | no | no |
| Own profile and password | yes | yes | yes | yes |

An editor's dashboard shows only content: the user count, backups, system checks, storage, logs, and notifications are not sent to them. Forms are out of their reach entirely (they hold visitors' personal data and decide where notifications go), including by address, bulk actions, and search. Every refused request is written to the activity log.

**Changing what a role can do.** The table shows the built-in permissions. The super admin can change them for Admin, Editor, and Basic user at **Admin > Roles**; nobody else can open that screen, and it cannot be delegated. The super admin's own permissions are fixed, so nobody can be locked out, and three are never switchable: managing users, managing roles, and restoring backups (each would let its holder gain everything else). Signing in and editing one's own profile stay on for every role.

- **Roles of the site's own.** The super admin can also make roles (a photographer, a translator) at Admin > Roles: a name, a description, and exactly the permissions chosen, starting from nothing or from a copy of Admin, Editor, or Basic user. They are stored whole in `system_meta` (key `custom_roles`), at most 20, and follow the same rules: sign-in and the own profile are always on, and users, roles, and restoring backups can never be given. A key cannot be the name of a built-in role, is made from the name (Greek converted to Latin), and does not change. A person can only be given a role that exists, so a tampered form falls back to Basic user, and a role cannot be deleted while anyone has it (a person whose role is somehow missing can sign in but reach nothing).
- Only differences from the built-in set are stored (`system_meta`, key `role_permissions`); a role left alone follows future defaults. Unknown or unreadable stored values are ignored, never turned into access.
- Each permission is labelled Normal, Sensitive (personal data, bulk data, logs, backups, import) or Critical (raw HTML, settings including sign-in and email, updates). Turning on a Sensitive or Critical one asks for confirmation.
- Every change is written to the activity log as a warning with the capabilities added and removed.
- Settings is Critical because it includes Google sign-in and email, which decide who can get into the site. Raw HTML is Critical because it can script the public site.
- Importing content and saving content follow the same raw HTML rule, so granting import does not bypass it.

After adding or changing an admin action, run `php scripts/check-permissions.php`: it lists every action with the roles that may use it and fails when the rules drift.

**Raw HTML.** Raw HTML in Markdown can carry script, and an editor must not be able to script the public site. When someone without the `content.raw_html` capability saves, any HTML they type in the text or in a block's Markdown fields is shown as plain text and the editor is told so. HTML that is already in that content (put there by an administrator, for example an embed) is left alone, so an editor's edit never breaks it. `[text](javascript:…)` style links are neutralised for everybody. Uploads are checked separately (SVG files with scripts are refused).

### Storage limit

The super admin sets how much space the site may use (Settings > Limits, `limits.manage`, held only by the super admin and never grantable, stored as `limits.storage_mb` in the site settings, default 1024, 0 for none). A value sent by anyone else is ignored. Use is content, the system database and its backups, and uploads. Only new uploads are refused at the limit, so a full site can still be edited, saved, and backed up; the message everyone sees from 90% names the super admin's address as a `mailto:` link and nothing else leaves the server.

### Links and deleting

Updating the links that still use an old address needs `content.manage` (it edits content) and only offers permanent redirects that are on. It changes nothing but the address in the text, files of types the person cannot edit (forms without `forms.manage`) are skipped, and every changed file gets a history entry ("Links updated") holding the earlier text. When a public entry is deleted, choosing where visitors go needs `redirects.manage`; without it the choice is ignored, so a permission that lets someone send visitors to another website cannot be used through the delete form. The target is checked like any redirect (no scripts, no loops), and an address on this site must exist. Deleting keeps the text in the history for 180 days, for bulk deletes too.

### Redirects

Redirects are managed with `redirects.manage` (Sensitive: a redirect can send visitors to another website). A target must be a path on this site or an `http(s)` address; `javascript:`, `data:` and other schemes are refused, as are loops and redirects from `/admin`. The list of addresses that were not found keeps only the path and the referring host and path (no query strings, no IP addresses), ignores files and probes, and is capped at 1000 rows. The Not found tab can download the visible or ignored paths as UTF-8 plain text, one path per line, with the current search applied; filtered file probes are not present to export. Address changes made by an editor still create their redirect, because that is a side effect of saving, not a redirect management action.

### History

Admin > History and the editor's History tab need `content.manage`. Restoring or bringing back an item is a `POST` with the CSRF token, is written to the activity log, and goes through the raw HTML rule: someone without `content.raw_html` gets HTML that is not already in the current file shown as text, so an old version cannot be used to put script back. Forms are excluded from lists and refused by address unless the person may manage forms, because a form's history includes where its notifications go. The history holds the full text of content, so it is protected like the content itself and travels in database backups; it keeps the latest 50 versions of an item and deleted items for 180 days.

### CSRF

- Every admin `POST` must carry the per-session token (`_csrf` field or `X-CSRF-Token` header). This includes the sign-in form.
- Templates add the field with `{{ csrf_field() }}`. `public/assets/js/admin.js` also appends it to any `POST` form that lacks one at submit time, as a safety net for script-built forms.
- A rejected submission returns HTTP 419 with a "form expired" message and is written to the activity log as `security.csrf_rejected`. Uploads larger than PHP's `post_max_size` arrive without any fields and are rejected the same way.
- Sign-out is `POST` only. `GET /admin/logout` just redirects.

### Sign-in Throttling

- Failed password sign-ins are stored in the `login_attempts` SQLite table.
- 8 failures from one address, or 12 failures for one username, within 15 minutes block further attempts (HTTP 429 with `Retry-After`) until the oldest counted failure ages out.
- The address is `REMOTE_ADDR`. `X-Forwarded-For` is ignored for throttling because clients can forge it. Behind a reverse proxy all visitors share the proxy address, so configure the proxy to pass the real client address as `REMOTE_ADDR` (for example `mod_remoteip` or nginx `real_ip`).

### First administrator and default credentials

- A new site ships no account. While the users table is empty, the sign-in page asks for the first administrator (username, email, a password of at least 8 characters that is not the shipped one) and makes a super admin. The page is gone as soon as one account exists, and a second account cannot be made through it. **Open `/admin` right after putting a site on a server**: until the first account exists, whoever visits first can make it.
- Older sites may still have `content/users/users.yaml` with a plain-text development account. It is imported into SQLite only when the users table is empty. Signing in with that shipped password shows a red banner on every admin page and creates a `security.default_password` notification until the password is changed.
- New passwords must be at least 8 characters and cannot equal the shipped password.

### Installing updates

- Only a person with `updates.manage` (a Critical permission) can install, and only with a verified backup made in the last 24 hours on the version being replaced.
- The package is downloaded over https only and compared with the size and SHA-256 in the release manifest before it is opened. A package that is not the published one changes nothing.
- A package is accepted only if every file in it is on a list of code paths (`UpdateInstaller::isAllowedPath`). One file for `content/`, `custom/`, `storage/` or `public/uploads/`, a path with `..`, an absolute or backslash path, or a link refuses the whole package, so a package cannot overwrite a site's data or write outside the site folder.
- While the code is swapped the public site answers 503; the maintenance flag has a token that only the installer's own check of the new version knows, and a flag more than 15 minutes old is ignored.
- If the new version fails the check, the old code (and the database, if its structure changed) is put back. The checksum protects against a corrupted or swapped file, not against a compromised release: anyone who can publish a release on the repository can publish code.

### Settings Secrets

- SMTP password, SES secret, Google client secret, S3 secret key, and the GitHub update token are never rendered back into the Settings page.
- Leaving a secret field blank keeps the stored value. Tick "Remove the saved value" to clear it.
- Secrets are stored in `storage/db/app.sqlite`. Full and database backups contain that file, so treat backup archives (local and remote) as sensitive.

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

## Operator Checklist

1. Point the web root at `public/` only. `content/`, `storage/`, `src/`, and `vendor/` must never be reachable over HTTP.
2. Serve the site over HTTPS.
3. Change the shipped admin password immediately (the admin banner reminds you).
4. Keep `storage/` shut off, and with it the files visitors attach to forms (`storage/form-uploads/`): they are private and only the admin gives them out. With the web root on `public/` (point 1) nothing else is needed; as defence in depth Apache gets `storage/form-uploads/.htaccess` (`Require all denied`, written by the CMS) and nginx should have:

   ```nginx
   location ^~ /storage/ { deny all; return 404; }
   ```
5. Make sure uploads can never execute:
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

     Any other static-file location (for example a `\.(css|js|webp)$` caching block) must also end in `try_files $uri /index.php?$query_string;`, because theme assets (`/_themes/…`, `/_custom/…`) are always served by PHP, and image variants are served by PHP until they exist as files.
5. If a credential was ever committed to git (for example SMTP credentials in the old `content/settings/site.yaml`), rotate it with the provider. Deleting the file does not remove it from history.
