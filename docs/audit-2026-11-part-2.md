# Code audit, November 2026, part 2

The rest of the review that [audit-2026-11.md](audit-2026-11.md) left out: the admin and its sign-in, MCP, uploads and imports
of settings, backups, updates and the installer, the content importers, AI publishing, the public endpoints and the admin's
JavaScript. The tasks are added to [audit-2026-11-tasks.md](audit-2026-11-tasks.md) under the same ids (S17 on, P9 on).

Nothing was changed and no test was run. This part was done by reading the code and by searching it for the patterns that
usually go wrong; no attack was tried against a running site. Where a finding rests on reading alone, it says so.

## How each area was read

| Area | Read | Result |
|---|---|---|
| Admin routing and permissions (`App::handleAdmin`) | in full | sound |
| Admin sign-in (`LoginController`, `GoogleSignIn`, `FirstAdmin`) | in full | sound, two small points (S25, S26) |
| MCP OAuth (`McpOAuth`), the endpoint (`McpServer`) | in full | sound |
| MCP storage (`McpStore`) | tokens, refresh, cleanup | two small points (S22, S23) |
| MCP tools (`McpContent`, `McpShop`, `McpShopOrders`) | how they are authorised and how they save; not each tool | nothing found |
| Uploads (`MediaLibrary`), the uploads folder's rules | the checks | one finding (S17) |
| Theme and branding transfer | how archives are opened | sound: settings and fonts only, never code |
| Backups (`BackupService`, `S3BackupStorage`) | make, verify, restore by search | restore is careful; one finding (S19) |
| Updates (`UpdateInstaller`, `UpdateService`), `installer.php` | verification and locks by search | one finding (S20) |
| Importers (`WordPressHttp` and what uses it) | the HTTP layer | one finding (S18) |
| AI publishing (`AiWriter`, `AiHttp`, `FeedReader`) | how a post is saved, how feeds are parsed | nothing found |
| Public endpoints (analytics, forms, Omni command) | the handlers | one small point (S24) |
| Logs and what is kept | every repository's deletes | one finding (S21) |
| Admin and theme JavaScript | every place HTML is inserted, by search | nothing found |

Not read at all: `ContentCsv`, `ContentTransfer`, `SeoAdmin`, the geo and map code, `Mailer`'s SMTP client, the Twig templates of
the admin one by one, and each of the ~40 MCP tools' own argument checks.

## What is sound

Worth saying, because these are the places that are usually wrong:

- **The admin's front door.** The CSRF token is checked before anything else, then sign-in, then one capability per action;
  an action nobody mapped is for administrators only. I found no admin action that changes something on a GET.
- **OAuth for MCP.** PKCE with S256 only, redirect addresses matched exactly, codes used once, only hashes of codes and tokens
  kept, the account checked on every call, the consent screen shows where the person is sent back.
- **Raw HTML stays guarded on every way in.** What MCP and AI publishing write is saved through the editor's own save without
  the raw HTML permission, so neither can put script on the site.
- **Archives.** Theme and branding files carry settings and fonts, each entry checked by name and size. A restore extracts
  entry by entry, refuses `..`, and compares hashes. An update package is refused whole if one path is outside the code.
- **XML** is parsed without network access everywhere (`LIBXML_NONET`), so a feed or an SVG cannot make the server fetch.
- **The installer** stays locked until it is given a key and refuses a folder that already has a site.
- **JavaScript.** The places that insert HTML insert fixed icons and the page's own templates, not data from the server.

## Security

### Medium

**S17. The check on uploaded SVG files is a list of forbidden patterns, and patterns can be written another way.**
`MediaLibrary::isSafeSvg()` ([src/MediaLibrary.php:527](../src/MediaLibrary.php)) refuses `<script`, `on…=`, `javascript:` and
a few more by regular expression. It does not decode entities, so `&#106;avascript:` passes; it does not know SMIL, so
`<set attributeName="onclick" …>` passes; `<style>@import url(…)</style>` passes. What actually stops a script is the
Content-Security-Policy header on `.svg` files in `public/uploads/.htaccess`, which exists only on Apache; on nginx it is a
line the operator has to add by hand (as on the CloudPanel sites). From reading; no file was uploaded to prove it. An editor
can upload; the file is on the admin's own origin.

**S18. The importers fetch whatever address they are given, and whatever the other site names.**
`WordPressHttp::fetch()` ([src/WordPressHttp.php:40](../src/WordPressHttp.php)) accepts any `http(s)` address and follows five
redirects, with no check that the host is a public one. The WordPress and WooCommerce imports use it for the site's address
(typed by someone with the import permission) and for every picture the remote site lists. A remote site, or a typed address,
can point it at `localhost`, the server's private network or a cloud metadata address. `SafeHttp` already does this properly
for MCP and AI pictures (public addresses only, the connection pinned, redirects checked again); the importers do not use it.
The S3 endpoint and the Omni address are typed by the super admin only, so they matter less.

**S19. Backups are plain archives.**
A backup holds the database (password hashes, customers and their addresses, orders, form submissions, and the payment keys of
S8) and the files visitors attached to forms. It is written as an ordinary zip, locally and to S3, with no encryption of its
own. Whoever gets the bucket, or a downloaded file, has everything.

**S20. An update is checked against a checksum that comes from the same place as the package.**
The installer compares the package with the SHA-256 in `release.json`; both are published by the same GitHub release. That
protects against a damaged download, not against someone who can publish a release (a stolen token, a compromised workflow).
`docs/security.md` says so already. It weighs more now: live shops install from the Updates screen, and Omni can ask a site to
install the latest release without anyone signing in. A signature made with a key that never leaves your machine, checked
against a public key in the code, closes it.

**S21. Nothing clears the logs, and they hold personal data.**
`activity_logs` (every row with an IP address and a browser), `email_logs` (who was written to), `shop_gateway_events` and
`mcp_clients` have no clean-up; the email log can only be emptied whole, by hand. MCP writes a row for every tool call (up to
120 a minute per connection) and for every request with a bad token. The database and every backup grow without end, and
addresses are kept for good, which is hard to defend under GDPR.

### Low

**S22.** A refresh token that was already replaced, used again after its 60 seconds, is refused, but the connection lives on
(`McpStore::refresh()`). Using an old token again is the sign of a stolen one; the usual answer is to end the connection.

**S23.** Anyone may register an MCP client while MCP is on, and a client that never completed a connection is never removed.

**S24.** The analytics endpoint limits each visitor, and a visitor is the address plus the browser's name. One address sending
a different browser name each time writes without limit for the day.

**S25.** Signing in to the admin with Google finds the user by email every time; the Google account's own id (`sub`) is stored
but not used to recognise them. If an address is given to another person in a Workspace, they sign in as the earlier user. The
`state` is also compared with `!==` rather than `hash_equals`.

**S26.** Until the first administrator exists, whoever opens `/admin` first makes it (documented). The installer could hand
over a one-time code that the first-administrator form asks for.

## Speed

**P9. A scheduled backup that is due runs inside someone's admin page.**
`handleAdmin()` calls `backupManager()->runIfDue()` on every GET, before the screen is drawn
([src/App.php:1441](../src/App.php)). The person who opens the admin when a backup is due waits for the whole backup (and for
scheduled entries and the AI tick). On the public site the same work is done after the response; the admin should do the same,
or leave it to cron when cron is set up.

**P10. Every save in the admin empties the whole page cache.**
Any admin POST outside a short list calls `frontCache()->purgeAll()` and `contentIndex->bump()`. Changing an order's status,
saving a draft nobody can see, or editing a coupon throws away every cached page, and the next visitors pay for all of them
again. On a busy shop, the admin is saving all day. The shop's order, customer and coupon screens change nothing a cached page
shows and can join the list at once; a draft that stays a draft can too.

**P11. Small ones.** Form submissions are listed by reading every JSON file of the form (`glob`, then parse), which slows with
thousands of submissions. Every MCP call writes `last_used_at`; once a minute per connection is enough.

## Bloat

Nothing new beyond S21 (tables that only grow). The areas read here have no dead code that I found.

## Summary across both parts

The code is in better shape than most of its kind: the patterns that usually fail (SQL, CSRF, permissions, archives, OAuth,
payment confirmation) are done properly throughout. What is left is of three sorts:

1. **Trust at the edges**: the `Host` header (S1), forwarded headers (S6, S12), addresses the importers fetch (S18), SVG
   files (S17), update packages (S20).
2. **No limits and no end**: what an anonymous visitor may repeat (S4, S24), what the logs keep (S21), sessions that never
   end (S5, S9).
3. **Secrets and data at rest**: payment keys (S8), backups (S19), keys in addresses that reach Meta (S2).

And one bank answer read the wrong way (S3), which is the only finding that can lose an order's money on an ordinary day.
