# MCP: AI assistants and scheduled jobs

GnozioCMS has an MCP server, so an AI assistant (Claude, ChatGPT) or a job of theirs that runs on a schedule can read and write the site's content as one of its accounts. The plan and what comes next are in `docs/mcp-plan.md`; this file describes what is built.

## Turning it on

**Admin > MCP** (super admin only, `mcp.manage`). It is off until turned on; off, its addresses answer as ordinary pages and no connection works, and connections are kept for when it is on again. The screen shows the address to give the assistant, checks from the browser that the discovery documents answer (a web server that does not pass `/.well-known/` to the site shows a red cross), lists the connections, and makes tokens by hand.

While MCP is on, the two discovery documents are also written as files, `public/.well-known/oauth-protected-resource` and `public/.well-known/oauth-authorization-server` (with a `.htaccess` that gives them their JSON type on Apache), and they are removed when it is turned off. A web server that answers `/.well-known/` itself, as nginx panels such as CloudPanel do for certificates, then serves them without the site. Each visit of the screen writes them again if the site's address changed.

That is enough for Claude and Codex, but not for ChatGPT: nginx sends a file without an extension as `application/octet-stream`, and ChatGPT refuses discovery documents that are not `application/json` ("Couldn't discover OAuth settings"). On nginx add this block before its `location ~ /.well-known` (in CloudPanel: the site's Vhost tab); it gives the files their type, and passes the addresses to the site when there are no files:

```nginx
location ^~ /.well-known/oauth- {
    default_type application/json;
    try_files $uri /index.php?$query_string;
}
```

Without access to the vhost, behind Cloudflare: Rules > Transform Rules > Modify Response Header, for `starts_with(http.request.uri.path, "/.well-known/oauth-")`, set `Content-Type` to `application/json`.

The site's address comes from Settings > *Base URL* (`base_url`), or the address of the request when it is empty. It must be the public HTTPS address: tokens are bound to `<address>/mcp`.

## Connecting

- **Claude** (web and desktop): Settings > Connectors > *Add custom connector*, with `https://<site>/mcp`. Claude registers itself, sends you to the consent screen of this admin, and keeps its tokens. For a scheduled job, set the tools it writes with to **Always allow**; otherwise the run stops at the first write, waiting for an approval nobody gives.
- **ChatGPT**: Developer mode, a new app with that address and OAuth.
- **A client that sends its own header** (Claude Code, the API, n8n): *Make a token by hand* in Admin > MCP, then `Authorization: Bearer <token>`. The token is shown once and works for the days chosen (at most 365).

On the consent screen you choose whom the connection **acts as** (yourself, or an automation account from Users > *Add automation account*, which keeps your name out of the history) and untick any permission it should not have. Approving sends you back to the assistant.

## Scopes

| Scope | Allows |
|---|---|
| `content:read` | read entries, terms and what the site offers, drafts included |
| `content:write` | write and edit drafts and scheduled entries |
| `content:publish` | publish, schedule and change published entries |
| `media:write` | add pictures to the media library |

A tool needs one of its scopes and, on every call, the permission it asks of the account's role at that moment (a role that loses `content.manage` takes it from its connections at once).

## Tools

| Tool | Scope | What it does |
|---|---|---|
| `site_info` | any | the site's name, address, languages, time zone and current time, its content types with their fields, its taxonomies, and the connection itself (name, account, scopes, paused, its rules and publications left) |
| `list_entries` | read | entries of a type, newest first, drafts and scheduled included; by language, status, changed since; paged |
| `get_entry` | read | one entry: text in Markdown, status, dates, terms, fields, translations, `updated_at` |
| `search_entries` | read | the words in title, address or text |
| `list_terms` | read | the terms of a taxonomy, with ids and labels |
| `find_by_source` | read | the entry made earlier for a `source_key` |
| `validate_entry` | write | the arguments of `create_entry`, checked, nothing saved |
| `create_entry` | write | a new entry; `status` (draft, published, scheduled with `publish_at`) is required; `source_key` returns the entry made before for the same source; `translation_of` makes it a translation |
| `update_entry` | write | changes what is given and keeps the rest; `expected_updated_at` refuses a change made meanwhile |
| `add_image_from_url` | media | fetches a picture from a public address into the media library, with its alt text, and gives its address for `main_image` or the body |

The read tools need `content.manage` on the account (they show drafts). Writing needs `content.manage`; published and scheduled entries, and changing them, need `content:publish` too. `add_image_from_url` needs `media:write` and `media.manage`; `main_image` takes a picture of the library (one added this way, or any other).

**Pictures from an address** (`src/SafeHttp.php`). The address is someone else's choice, so it never reaches the site's own network: http or https on ports 80 and 443 only, no name and password in it, every address its host resolves to must be public (private, loopback, link-local, carrier-grade NAT, documentation, multicast and reserved ranges are refused, IPv6 too), the connection goes to the very address that was checked, at most 3 redirects, each checked the same way, no cookies, 10 MB and 15 seconds. What arrives is kept only if its first bytes are a JPEG, PNG, WebP, GIF or AVIF the site takes, and only if the storage limit leaves room. The picture's id is its fingerprint, so the same picture fetched again (from any address) is the one already kept. Alt text is required; the uploader is the account and the connection.

**Strict writes.** The job cannot see the page, so nothing it sends is quietly fixed: an unknown argument, type, language, taxonomy, term or field, a value a field does not take (a select's other options are named), a date that is not `YYYY-MM-DD` and a scheduled entry without `publish_at` are errors, all of them at once, in sentences that say what to send instead. Text is Markdown; HTML is shown as text and the result says so. Fields that are lists, relations or galleries cannot be written yet. New terms are added by a person.

**What is written.** Every save goes through the admin's own editor code (address from the title, translations, index, history, redirects). A new entry gets `generated_by: <connection>` (shown in the admin, marked *MCP* in the content list) and the connection's byline as `author` unless the job gives one. The history names the account and the connection. Each publication or scheduling leaves a notification in the admin, and the caches are emptied as after a save.

## Rules of a connection

Each connection in Admin > MCP has **Rules**: the content types and languages it may write (none ticked: all), how many entries it may publish or schedule in 24 hours (10 by default, 0 for no limit) and the byline for new entries. A draft is never counted. `site_info` tells the job its rules and what is left of today's limit.

## The protocol

- `POST /mcp`: Streamable HTTP without a session, one JSON-RPC message per request (no batches), `Content-Type: application/json`, at most 1 MB. Protocol versions `2025-06-18` and `2025-03-26`. A notification gets `202`. `GET` gets `405`.
- No token or a bad one: `401` with `WWW-Authenticate: Bearer resource_metadata="<site>/.well-known/oauth-protected-resource"`.
- Discovery: `/.well-known/oauth-protected-resource` (also with `/mcp`) and `/.well-known/oauth-authorization-server`.
- OAuth 2.1: registration `POST /_mcp/register` (public clients, HTTPS redirect addresses, or http on localhost), authorization `/admin/mcp-authorize` (S256 PKCE only, `resource` must be the endpoint), tokens `POST /_mcp/token` (`authorization_code`, `refresh_token`). The OAuth endpoints are under `/_mcp/` so they never take the address of a page.
- Lifetimes: codes 5 minutes and once; access tokens 1 hour; refresh tokens 90 days, rotated on each use, the replaced one still good for 60 seconds so two refreshes at once do not end the connection. Admin > MCP marks a connection that ends within a week.
- Limits: 300 requests a minute per address and 120 per token at `/mcp`; 60 token requests per 10 minutes and 20 registrations an hour per address. Over a limit: `429` with `Retry-After`.
- A request with an `Origin` other than the site's is refused (`403`).
- Errors of a tool are a result with `isError: true` and a sentence the model can act on.

## What is kept

Tables of the system database (`SystemDatabase::createMcpTables`): `mcp_clients`, `mcp_codes`, `mcp_connections` (the account it acts as, who approved it, scopes, paused, removed), `mcp_tokens` (hashes only), `mcp_rate_limits`, `mcp_sources` (the entry made for each `source_key`) and `mcp_publications` (for the daily limit). Old codes, tokens and counters are cleaned as new tokens are made. Every call, refusal and change is in the activity log as `mcp.*`.

## Code and tests

`src/McpStore.php` (tables), `src/McpOAuth.php` (OAuth), `src/McpServer.php` (the endpoint, `site_info`), `src/McpContent.php` (the content tools), `src/SafeHttp.php` (fetching pictures), `src/McpAdmin.php` (the screen), `App::handleMcp`, `App::handleMcpAuthorize`, `admin/templates/mcp.twig`, `admin/templates/mcp-authorize.twig`. Tests: `tests/unit/mcp.php`, `tests/unit/mcp-images.php`, `tests/http/mcp_oauth_test.py`, `tests/http/mcp_tools_test.py`. Security notes: `docs/security.md` (*MCP*).
