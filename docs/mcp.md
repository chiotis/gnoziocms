# MCP: AI assistants and scheduled jobs

GnozioCMS has an MCP server, so an AI assistant (Claude, ChatGPT) or a job of theirs that runs on a schedule can read and write the site's content as one of its accounts. The plan and what comes next are in `docs/mcp-plan.md`; this file describes what is built.

## Turning it on

**Admin > MCP** (super admin only, `mcp.manage`). It is off until turned on; off, its addresses answer as ordinary pages and no connection works, and connections are kept for when it is on again. The screen shows the address to give the assistant, checks from the browser that the discovery documents answer (a web server that does not pass `/.well-known/` to the site shows a red cross), lists the connections, and makes tokens by hand.

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
| `site_info` | any | the site's name, address, languages, time zone and current time, its content types with their fields, its taxonomies, and the connection itself (name, account, scopes, paused) |

The tools to read and write entries and add pictures come next (`docs/mcp-plan.md`, step 4).

## The protocol

- `POST /mcp`: Streamable HTTP without a session, one JSON-RPC message per request (no batches), `Content-Type: application/json`, at most 1 MB. Protocol versions `2025-06-18` and `2025-03-26`. A notification gets `202`. `GET` gets `405`.
- No token or a bad one: `401` with `WWW-Authenticate: Bearer resource_metadata="<site>/.well-known/oauth-protected-resource/mcp"`.
- Discovery: `/.well-known/oauth-protected-resource` (also with `/mcp`) and `/.well-known/oauth-authorization-server`.
- OAuth 2.1: registration `POST /_mcp/register` (public clients, HTTPS redirect addresses, or http on localhost), authorization `/admin/mcp-authorize` (S256 PKCE only, `resource` must be the endpoint), tokens `POST /_mcp/token` (`authorization_code`, `refresh_token`). The OAuth endpoints are under `/_mcp/` so they never take the address of a page.
- Lifetimes: codes 5 minutes and once; access tokens 1 hour; refresh tokens 90 days, rotated on each use, the replaced one still good for 60 seconds so two refreshes at once do not end the connection. Admin > MCP marks a connection that ends within a week.
- Limits: 300 requests a minute per address and 120 per token at `/mcp`; 60 token requests per 10 minutes and 20 registrations an hour per address. Over a limit: `429` with `Retry-After`.
- A request with an `Origin` other than the site's is refused (`403`).
- Errors of a tool are a result with `isError: true` and a sentence the model can act on.

## What is kept

Tables of the system database (`SystemDatabase::createMcpTables`): `mcp_clients`, `mcp_codes`, `mcp_connections` (the account it acts as, who approved it, scopes, paused, removed), `mcp_tokens` (hashes only), `mcp_rate_limits`. Old codes, tokens and counters are cleaned as new tokens are made. Every call, refusal and change is in the activity log as `mcp.*`.

## Code and tests

`src/McpStore.php` (tables), `src/McpOAuth.php` (OAuth), `src/McpServer.php` (the endpoint and its tools), `src/McpAdmin.php` (the screen), `App::handleMcp`, `App::handleMcpAuthorize`, `admin/templates/mcp.twig`, `admin/templates/mcp-authorize.twig`. Tests: `tests/unit/mcp.php`, `tests/http/mcp_oauth_test.py`. Security notes: `docs/security.md` (*MCP*).
