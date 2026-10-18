# GnozioCMS Omni Protocol (v1)

Omni is a separate web app where one person, its owner, sees every GnozioCMS site connected to it: storage, backups, analytics, updates and the health checks, with a link to each site's admin. The public instance is `https://omni.faroscms.com`; anyone can run their own Omni and connect their own sites to it, so the Omni address is a setting of each site, never fixed in the code.

This page is the contract between the two apps. GnozioCMS (the site) and Omni are built against it separately.

## Overview

- **The site calls Omni; Omni never calls the site.** A site opens no new public endpoint. It sends a signed report to Omni from the work it already runs on a schedule (the cron script, after a visit, or when the admin is opened).
- **A site joins with a code.** The Omni owner adds a site in Omni and gets a one-time connection code. A superadmin of the site enters the Omni address and the code in the site's settings. The site and Omni exchange a secret, and every report after that is signed with it.
- **Silence is information.** Omni shows when it last heard from each site. A site that stops reporting is flagged, whatever its last report said.

## Endpoints on Omni

All paths are relative to the Omni address the site was given (for example `https://omni.faroscms.com`). Requests and responses are JSON (`Content-Type: application/json`, UTF-8). The address must use HTTPS. The only exceptions are `localhost`, `127.0.0.1` and hosts ending in `.localhost` or `.test`, which are allowed for development.

| Method and path | Signed | Purpose |
|---|:-:|---|
| `GET /api/v1/ping` | no | Confirms that an address is an Omni, before connecting |
| `POST /api/v1/connect` | no (the code is the proof) | Trades a connection code for a site id and a secret |
| `POST /api/v1/report` | yes | Sends a report |
| `POST /api/v1/disconnect` | yes | The site leaves Omni |

### Ping

`GET /api/v1/ping` returns:

```json
{ "omni": true, "name": "Omni", "protocols": [1] }
```

The site's settings screen calls it when an address is entered, so a typo or a wrong address is reported before a code is spent. `protocols` lists the protocol versions this Omni accepts.

### Connect

The Omni owner creates the site in Omni, which shows a **connection code**:

- it has the form `XXXX-XXXX-XXXX-XXXX`, uses 16 characters from an alphabet without look-alikes (`23456789ABCDEFGHJKMNPQRSTUVWXYZ`), and carries about 78 bits of randomness,
- it can be used once and expires after 24 hours,
- Omni keeps only its hash.

The site sends:

```json
POST /api/v1/connect
{
  "protocol": 1,
  "code": "K7QM-4TZP-9WXC-2HRD",
  "site": {
    "name": "Doxiadis Associates",
    "url": "https://doxiadis.com",
    "admin_url": "https://doxiadis.com/admin",
    "version": "0.1.99"
  }
}
```

Omni answers `200`:

```json
{ "site_id": "s_8f3a1c2e9b7d", "secret": "<43 characters, base64url of 32 random bytes>", "report_every": 21600 }
```

- `site_id` is public and names the site in every signed request.
- `secret` is shown only in this response. The site keeps it in the system database (`system_meta`, never in the settings YAML, which can end up in git or in exports). Omni keeps it encrypted at rest, because it needs the secret itself to check signatures.
- `report_every` is how often Omni wants a report, in seconds (see [When a site reports](#when-a-site-reports)).

Errors use the shape described in [Responses and errors](#responses-and-errors): `403 invalid_code` (the code is unknown, already used or expired, or its site was removed), `400 invalid_request` (no code, or no `site.url` that is http or https), `426 unsupported_protocol`, or `429 rate_limited` with `Retry-After`. Omni allows 10 attempts per IP address per hour, whatever their outcome. Omni takes the code in any case, with or without its dashes.

Connecting again with a new code replaces the old secret. This is also how a site recovers if it loses its secret, for example after a restore to another server.

### Report

```
POST /api/v1/report
X-Omni-Site: s_8f3a1c2e9b7d
X-Omni-Timestamp: 1791882000
X-Omni-Nonce: 3c9e1f0a6b2d4e8f
X-Omni-Signature: v1=<hex>
```

The body is the report, described below. The response is `200`:

```json
{ "ok": true, "report_every": 21600 }
```

### Disconnect

`POST /api/v1/disconnect` takes the same signed headers and an empty JSON body (`{}`). Omni marks the site as disconnected and keeps its history. The site forgets its secret. Omni does the same for its side when its owner removes a site, and the site then learns it from a `410` on its next report.

## Signing

A signed request carries four headers:

| Header | Value |
|---|---|
| `X-Omni-Site` | the `site_id` from connect |
| `X-Omni-Timestamp` | Unix time in seconds when the request was signed |
| `X-Omni-Nonce` | 16 random bytes, hex encoded (32 characters) |
| `X-Omni-Signature` | `v1=` followed by the hex HMAC-SHA256 of the string to sign, keyed with the secret exactly as connect returned it (the 43 characters, not the bytes they encode) |

The string to sign is these five lines joined by `\n`:

```
POST
/api/v1/report
1791882000
3c9e1f0a6b2d4e8f
<hex SHA-256 of the raw body>
```

That is the method, the path, the timestamp, the nonce and the hash of the body. Omni rejects the request with `401` when:

- the site is unknown,
- the signature does not match (compared in constant time),
- the timestamp is more than 300 seconds away from Omni's clock,
- the nonce was already seen from this site in the last 10 minutes.

## The report

A report holds the state of the site at the time it was sent. Omni keeps the latest one per site, plus a short history of the summary fields for trends.

```json
{
  "protocol": 1,
  "sent_at": "2026-10-13T09:00:00Z",
  "site": {
    "name": "Doxiadis Associates",
    "url": "https://doxiadis.com",
    "admin_url": "https://doxiadis.com/admin"
  },
  "faros": {
    "version": "0.1.99",
    "commit": "1735828"
  },
  "storage": {
    "used": 734003200,
    "limit": 2147483648,
    "disk_free": 18253611008,
    "percent_of_limit": 34,
    "level": "ok",
    "full": false,
    "parts": { "uploads": 612368384, "content": 4194304, "system": 117440512 },
    "measured_at": "2026-10-13T03:00:12Z"
  },
  "backups": {
    "scheduled": true,
    "schedule": "daily",
    "remote": true,
    "keep_local": false,
    "last": { "at": "2026-10-13T03:00:40Z", "status": "success", "size": 402653184, "message": "" },
    "last_success_at": "2026-10-13T03:00:40Z",
    "failed_last_7_days": 0,
    "cron": { "state": "fresh", "last_seen": "2026-10-13T03:00:05Z", "after_visit": true }
  },
  "analytics": {
    "enabled": true,
    "mode": "platform"
  },
  "updates": {
    "current": "0.1.99",
    "latest": "0.2.0",
    "has_update": true,
    "checked_at": "2026-10-13T08:12:00Z",
    "source_status": "ok"
  },
  "system": {
    "summary": { "status": "warning", "label": "Warnings", "detail": "1 check to review" },
    "checks": [
      { "label": "PHP", "value": "8.3.12", "status": "ok" },
      { "label": "Email provider", "value": "not configured", "status": "warning" }
    ],
    "extensions": [
      { "name": "zip", "loaded": true, "status": "ok" }
    ],
    "environment": {
      "PHP": "8.3.12 (fpm-fcgi)",
      "SQLite": "3.46.1",
      "HTTPS": "yes"
    }
  }
}
```

### Fields

All sizes are bytes (integers) and all times are ISO 8601 in UTC. A value that the site cannot know is `null`, never left out, so Omni can tell "unknown" from "an older site that does not send this".

| Field | Source in GnozioCMS | Notes |
|---|---|---|
| `site.*` | site settings, the request's base URL | `admin_url` is the link Omni shows |
| `faros.version`, `faros.commit` | `UpdateService::currentVersion()`, `currentGitCommit()` | `commit` is `""` outside git |
| `storage.*` | `SiteLimits::summary()` and `measurement()` | `limit` is `0` when the site has no limit; `percent_of_limit` is then `null`; `level` is `ok`, `warn` or `danger`; `disk_free` is `null` when the host does not say |
| `backups.scheduled`, `schedule` | `backup.auto` settings | `schedule` is `daily`, `weekly`, `monthly`, or `null` when scheduled backups are off |
| `backups.remote`, `keep_local` | `backup.remote` settings | without remote storage `keep_local` is `true`: every archive stays on the server |
| `backups.last` | `BackupRunRepository::recent()`, the newest | `status` is `success`, `warning` (made, but the remote upload failed) or `failed`; `null` when no backup was ever run |
| `backups.last_success_at` | the latest run with `success`, among the last 100 | `null` when none |
| `backups.failed_last_7_days` | runs with `failed` in the last 7 days | |
| `backups.cron` | `CronStatus::info()` | `state` is `fresh`, `stale` or `never`; never the command or a path |
| `analytics.enabled` | `AnalyticsSettings::from()` | `false` when the mode is `off` |
| `analytics.mode` | `AnalyticsSettings::from()` | `off` (no tracking), `custom` (the site's own tracking code) or `platform` (GnozioCMS analytics) |
| `updates.*` | `UpdateService::status()`, from its cache | sending a report never triggers a fresh version check; `source_status` is `ok`, `unreachable` or `not_configured`; before the first check only `current` is known and the rest is `null` |
| `system.summary`, `checks` | `SystemStatus::summarize()`, `checks()` | `status` is `ok`, `warning` or `error` |
| `system.extensions` | `SystemStatus::extensions()` | without the `purpose` column |
| `system.environment` | `SystemStatus::environment()` | label to value, as on Admin > System |

### What is never sent

A report holds no content, no user names or email addresses, no form submissions, no visitor figures, and no secrets of any kind: no keys for remote storage, mail or Google sign-in, no tracking code or tag IDs, and no file paths outside what the health checks already show. Omni gets enough to say whether a site is healthy, not to read it.

## When a site reports

- Right after connecting.
- When scheduled work runs: the cron script (`src/cron-backup.php`), after a visit (`fastcgi_finish_request`), or when the admin is opened. A report is sent if the last one is older than `report_every`. The value is clamped to between 15 minutes and 24 hours, and is 6 hours by default.
- Right after a backup run and after an update, whether it succeeded or not, so Omni sees the outcome without waiting. The site keeps a fingerprint of the newest backup run and its version with each report sent; when either has changed, a report is due at the next scheduled tick (in the same request for a scheduled backup, the cron script, and the admin page that follows a manual backup or an update).
- When a superadmin presses **Send report now** in the site's Omni settings.

A report is sent with a 10-second timeout and never delays a visitor: after a visit only where the response can be finished first (PHP-FPM, LiteSpeed); after an admin page the same way where it can, otherwise before that page ends. After a failed send the next attempt waits 15 minutes (or as long as a `429`'s `Retry-After` says), so an Omni that is down slows an admin page at most once in that time. The site keeps the time and the error of the last attempt, and Admin > Omni shows them. Requests go through PHP's streams, with cURL only where streams cannot open addresses, and never follow a redirect.

Omni marks a site as **silent** when it has not reported for twice its `report_every`, or for 48 hours, whichever is longer.

## Responses and errors

Errors use the HTTP status and a JSON body:

```json
{ "ok": false, "error": "invalid_signature", "message": "The signature does not match." }
```

| Status | `error` | What the site does |
|---|---|---|
| `400` | `invalid_request` | logs it and tries again at the next tick |
| `401` | `invalid_signature`, `stale_timestamp`, `replayed_nonce`, `unknown_site` | logs it; its settings screen shows it and suggests connecting again |
| `410` | `site_removed` | forgets the secret and stops reporting; settings show "Disconnected by Omni" |
| `413` | `too_large` | logs it (a report is well under 64 KB, which is Omni's limit) |
| `426` | `unsupported_protocol` | logs it; the body adds `"protocols": [...]` |
| `429` | `rate_limited` | waits for `Retry-After` |
| `5xx` | — | tries again at the next tick |

## Versioning

- `protocol` is an integer. Adding a field does not change it: Omni ignores fields it does not know, and treats a missing field as unknown.
- Renaming or removing a field, or changing what a value means, makes a new protocol version. An Omni lists the versions it accepts in `ping`, and a site sends the highest one both sides know.

## On the site

- **Admin > Omni** is for superadmins only (the `omni.manage` permission, which cannot be given to another role). It shows:
  - while not connected: the Omni address, filled in with `https://omni.faroscms.com` and editable, and the connection code (**Connect** first checks the address with `ping`, then sends the code, then the first report),
  - while connected: the Omni, the site id, when it connected, the last report, how often it reports, and the last problem with when it will try again,
  - after Omni removed the site: a note saying so, and the form to connect again,
  - what Omni would see (health, storage, the last backup, analytics, the version) and the exact JSON that would be sent,
  - **Send report now** and **Disconnect**.
- `OmniReport` builds the report from the services listed above (`tests/unit/omni-report.php`, `tests/http/omni_test.py`).
- `OmniClient` handles ping, connect, report and disconnect, the signing, and when a report is due (`tests/unit/omni-client.php`; `tests/http/omni_test.py` runs an Omni stand-in that checks every signature). What it keeps is in `system_meta` under `omni`. Connecting and disconnecting are written to the activity log.

## On Omni

Omni is a separate repository (`faros-omni`, 0.1.0) on a plain stack (PHP 8.1+, SQLite, libsodium, no framework and no Composer, compiled Tailwind) with the same admin look. It has:

- a single owner account, made on the first visit (the page closes once it exists),
- the list of sites with a state (healthy, warnings, needs attention, silent, waiting to connect, disconnected), chips for health, storage, the last backup, the version and analytics, and a "last heard" time,
- a page per site with what to look at, the full latest report and the link to its admin,
- connection codes, shown once,
- the four endpoints above, and its own tests (`tests/run.sh`).

Later, and outside this version of the protocol:

- a "refresh now" that asks a site for a report,
- signing in to a site's admin in one click,
- starting a backup or an update from Omni,
- email alerts.
