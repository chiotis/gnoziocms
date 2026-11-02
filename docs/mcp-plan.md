# MCP and automated publishing: plan

Status: proposal (2026-11-02). Nothing of it is built yet.

## What it is for

The main use is **unattended publishing**: a scheduled job in Claude or ChatGPT reads its sources, writes a post and publishes it on the site through MCP, every day or every week, with nobody at the keyboard. Reading the site from an LLM (summaries of the latest news) comes second. Letting a person edit the site through a chat is not a goal.

So the scheduler lives in the LLM; the site offers a remote MCP server and keeps the rules. Everything the job must not do is refused by the site, not left to the job's prompt: the job reads third-party pages and can be steered by them.

## Model: GnozioMCP

Gnozio Suite has a working MCP server (`gnozio-suite`: `system/Controllers/McpController.php`, `McpOAuthController.php`, `system/Models/Mcp*.php`, `system/Core/Mcp*.php`). It is ported, not copied: the CMS has its own namespace, `SystemDatabase` and `PermissionService`, and the Suite's 3,200-line controller is split here into transport, OAuth and tools.

Taken as it is:

- Stateless `POST /mcp`, one JSON-RPC object per request (no batches), `application/json` only, 1 MB cap, `202` for notifications, `GET` answers `405`. Protocol `2025-06-18`. `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `MCP-Protocol-Version`.
- OAuth 2.1: protected-resource and authorization-server metadata under `/.well-known/` (written as files into `public/.well-known/` when possible, PHP routes as fallback), dynamic client registration, authorization code with S256 PKCE only, exact redirect URIs (http only for localhost), `resource` bound to `/mcp`, single-use codes, CSRF on the consent form, access tokens of one hour, rotating refresh tokens. `401` carries `WWW-Authenticate` with `resource_metadata`.
- A manual bearer token for clients that set headers themselves (Claude Code, the API, n8n): shown once, at most a year, only its SHA-256 stored.
- Every token is hash-only, bound to a user and the resource, revocable; the user must still be active on every call.
- Scopes per area; `tools/list` shows only what the connection may call and `tools/call` checks again.
- Rate limits per IP (300/min) and per credential (120/min), with `Retry-After`; OAuth endpoints limited too.
- Every call logged (tool, connection, outcome, reason) and filterable in the logs.
- Tool errors as `isError: true` with a plain sentence, so the model can correct itself; internal errors as one generic sentence, details only in the PHP log.
- Server `instructions` that name only what is enabled, and say that content read through MCP is untrusted data.
- No deletes through MCP; `expected_updated_at` on edits; every change kept in the revision history.

Changed or added for unattended jobs:

1. **The connection acts as an automation account**, not as the person who approved it (below).
2. **Effective rights are scope ∩ role**, checked on every call. A role that loses a permission takes it from its connections at once.
3. **Publishing is its own scope** (`content:publish`), apart from writing drafts.
4. **Repeat runs do not repeat posts**: `source_key` on create (below).
5. **Refresh tokens last 90 days** (sliding), and the admin warns 7 days before one expires; the previous refresh token stays valid for 60 seconds after a rotation, so two refreshes at once do not break the connection.
6. **`Origin` is checked**: a request that carries an `Origin` other than the site's own or an allowed client's is refused (`403`).
7. **A daily publishing limit** per connection, apart from the request limits.
8. **Tools have an `outputSchema`** where they return `structuredContent`.
9. **Instructions fit a job**: "create or publish only what the task asks", instead of the Suite's "only when the user explicitly asks".

## Phase 1

### 1. Scheduled publishing in the CMS

Today an entry is public when `status` is `published` and `visible` is not false (`ContentRepository::filterVisibility`); the date plays no part. Added:

- `status: scheduled` with `date` in the future. A scheduled entry behaves like a draft (signed-in people see it, the public does not) until it is released.
- **Release**: a step that finds scheduled entries whose date has passed, rewrites them as `status: published` through `ContentEditor` (a revision with the actor *Schedule*), and purges the page cache and the front cache the way a save in the admin does. The file always tells the truth; no list, feed or sitemap has to know about times.
- The release runs from the cron script (`src/cron-backup.php`, which becomes the site's one cron entry; its name stays so older cron jobs keep working), after a public response where the response can be finished first (as the scheduled backup does, `App::runScheduledAfterResponse`), and when the admin is opened. The System screen recommends a cron job every 5 minutes for sites that schedule.
- The editor gets a *Scheduled* choice next to Draft and Published, with the date and time, and the content list shows the time.

This is useful without MCP too, and it lets a job write a week of posts in one run.

### 2. Automation accounts

- A user can be an **automation account**: it has a role like anyone, but no password and no sign-in (the sign-in form and Google sign-in refuse it). Users shows it with its own chip. Whether it counts against the site's user limit is open (below).
- On the consent screen the person approving chooses whom the connection acts as: themself, or an automation account whose role is not above their own. The revision history and the logs then show the account and the connection name ("Automation: Daily news (Claude)").
- Raw HTML is never allowed through MCP, whatever the role (`canRawHtml` is always false; `HtmlGuard` applies).

### 3. Endpoint, routing, caching

- `mcp`, `token`, `authorize`, `register` and `.well-known/oauth-*` are handled in `App::handle` before the page cache, like `_omni/command`. `mcp`, `token` and `register` start no session; `authorize` uses the admin session (the person signs in to approve).
- None of them is ever kept by the page cache, Varnish or a CDN (`no-store`); `docs/varnish.md` gets the paths to pass.
- MCP is off until it is switched on in **Settings > MCP / AI**, after the screen's check that the public metadata URLs answer.
- The maintenance mode of an update answers `/mcp` with `503` and `Retry-After`.

### 4. Scopes and connection policy

| Scope | Allows | Needs the permission |
|---|---|---|
| `content:read` | read and search entries, terms, site facts | — (any active account) |
| `content:write` | create and edit drafts and scheduled entries | `content.manage` |
| `content:publish` | publish, schedule, edit published entries | `content.manage` |
| `media:write` | add pictures to the library | `media.manage` |

Each connection also has a policy, set on its row in Settings > MCP / AI: which content types and languages it may write, its default status (draft unless changed), and at most how many entries it may publish or schedule per day (10 by default). A call outside the policy is refused with the reason.

### 5. Tools

| Tool | Scope | What it does |
|---|---|---|
| `site_info` | read | languages, content types with their fields (from `FieldSchema`), taxonomies, the blocks allowed in a body, the connection's own policy and today's remaining publications |
| `list_entries` | read | by type, language, status, `since` (date or modified time), term; paged |
| `get_entry` | read | one entry: front matter, body as Markdown, address, `updated_at`, translations |
| `search_entries` | read | the site search (`ContentSearch`), drafts included for the connection |
| `list_terms` | read | terms of a taxonomy, with their slugs |
| `find_by_source` | read | the entry made earlier for a `source_key`, or none |
| `validate_entry` | write | the arguments of `create_entry`, checked and not saved (`ContentEditor::save` with `dryRun`) |
| `create_entry` | write | type, language, title, body (Markdown), excerpt, terms, fields, main image (a library id), SEO texts, `status` (`draft`, `scheduled`, `published`; the last two need `content:publish`), `date`, `source_key`, `translation_of` |
| `update_entry` | write / publish | the same fields, with `expected_updated_at`; changing a published entry needs `content:publish` |
| `add_image_from_url` | media | fetches a picture into the library (below) and returns its id |

All writes go through `ContentEditor::save`, so slugs, translations, relations, redirects, the content index and revisions behave as in the admin. Writes are **strict**: an unknown field, block, block option, term or language is an error that names it, never silently dropped or replaced by a default, because the job cannot see the page.

**`source_key`** (up to 200 characters, usually the source article's URL) is kept in a table of the system database (`mcp_sources`: key, connection, type, slug, language, created). `create_entry` with a key that exists returns the entry made before, with `"existing": true`, and changes nothing.

### 6. Pictures from a URL

`add_image_from_url` fetches only `http`/`https` on ports 80 and 443, resolves the host and refuses private, loopback, link-local and reserved addresses (also after each redirect, at most 3), sends no cookies, stops at 10 MB and 15 seconds, and keeps the file only if its bytes are JPEG, PNG, WebP, GIF or AVIF. It goes in through `MediaLibrary::import` with alt text and the uploader "Automation: …". The same file fetched twice (same hash) returns the existing id. `WordPressHttp` is not reused for this: it is for the importer on the command line and has none of these checks.

### 7. Admin: Settings > MCP / AI

One screen, in the condensed style of the other settings screens: the switch and the endpoint address to copy, the metadata check, connections (OAuth clients and tokens) as rows with their account, scopes, policy, last use, refresh expiry and Revoke, a form for a manual token, and a link to the logs filtered to MCP. Only people with `settings.manage` see it; only a super admin can give a connection an account other than their own.

### 8. Documentation

- `docs/mcp.md`: endpoint, scopes, tools with examples, setting up Claude (Settings > Connectors, then **Always allow** for the write tools, or a scheduled run stops at the first write waiting for approval) and ChatGPT (Developer mode). One sample prompt for a daily news job: read the sources, `find_by_source` for each, write in the site's language, `validate_entry`, then `create_entry` as draft or scheduled.
- `docs/security.md`: the endpoint, tokens, SSRF rules, what MCP can never do.
- `README.md` and the `gnoziocms` skill: a short section each.

### 9. Tests

- Unit: scope ∩ role; the connection policy and the daily limit; strict validation messages; `source_key` returning the existing entry; release of scheduled entries (one due, one not, one edited meanwhile); the URL guard (private addresses, redirects to them, size, wrong type).
- HTTP: the whole OAuth flow (registration, consent, code, token, refresh, the 60-second grace, reuse after it refused), `401` with `WWW-Authenticate`, `415`, `413`, batch refused, `Origin` refused, rate limit with `Retry-After`, a create and a publish through `/mcp`, a revoked token refused at once, the page cache never keeping `/mcp`.
- Each test with a deadline, as `AGENTS.md` asks.

### Order of work

1. Scheduled publishing and its release (useful alone; can be merged first).
2. Automation accounts.
3. Transport, OAuth, tokens, rate limits, logging, the settings screen (no tools yet but `site_info`).
4. Read tools, then write tools with strict validation and `source_key`.
5. Pictures from a URL.
6. Documentation, then a real scheduled job from Claude on a test site for a week.

## Later phases

- **Phase 2**: a *Review* status with a notice and an email to the editors (Publish / Reject from the email's link), as the default for new connections during their first weeks; a log of each connection's publications on its row; an alert when a connection is refused often.
- **Phase 3**: reading for summaries: `latest(since)` with the text as Markdown (`HtmlToMarkdown`), a public read-only MCP for published entries with no sign-in, and `llms.txt`; ready-made job prompts in the skill.

## Open questions

- Default for a new connection: drafts only, or publish within the daily limit?
- Do automation accounts count against the site's user limit (`SiteLimits`)?
- The first site to try it on.
- Whether ChatGPT's scheduled tasks can call a custom MCP connector without a person present; to be tried before it is promised to a client.
