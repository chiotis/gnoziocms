# GnozioCMS System Database

GnozioCMS uses a hybrid storage model:

- Markdown files remain the source of truth for content and content metadata.
- Site/theme/admin settings are stored in SQLite system metadata.
- Uploaded files remain on the filesystem.
- SQLite stores system/admin data that benefits from querying, filtering, history, and indexes.

The system database lives at `storage/db/app.sqlite`. Runtime database files are ignored by git; only `storage/db/.gitkeep` is tracked.

## Current Schema

The initial foundation migration creates:

- `schema_migrations`
- `system_meta`
- `users`
- `activity_logs`
- `email_logs`
- `notifications`
- `content_index`
- `backup_runs`

All tables are wired into admin flows. `login_attempts` (sign-in throttling) and the `content_index.mtime` / `search_text` columns were added by later migrations.

## Content Index

- `redirects` holds old addresses that lead elsewhere (source path without slashes, lower case; target `/path` or a full address; 301 or 302; automatic or by hand; visit count). `not_found_log` counts addresses visitors asked for that do not exist (path, hits, last referrer host and path, and whether it was chosen to be ignored: an ignored address is still counted but not listed; capped at 1000 rows).
- `analytics_hits` holds the page views and events of the last two days for the platform's analytics (a hash of the visitor that changes every day, never an address), and `analytics_daily` the counts of each finished day (see `docs/analytics.md`). Both are part of every database backup.
- `content_revisions` keeps the whole text of a content file each time it is saved, imported, restored, or deleted (deflated; type, slug, language, action (create, save, import, restore, links, delete, external, baseline), who, title, status, checksum). The latest 50 per item are kept, and items whose newest entry is a delete for 180 days. It is part of every database backup.
- `content_index` mirrors every Markdown file (type, slug, language, title, status, visibility, path, checksum, mtime, and lowercased search text).
- It is updated on save, delete, bulk actions, CSV imports, and restores, and rebuilt automatically when files change outside the admin (for example after `git pull`).
- Admin search (`/admin/search`) reads it; `/admin/system` shows its status and has a manual rebuild button.
- It can always be rebuilt from the files, so deleting it loses nothing.

## Boundaries

Do not move page/post/project content and content frontmatter into SQLite as the primary source of truth.

SQLite should be used for:

- site/theme/admin settings
- users and roles
- permission metadata
- activity logs
- email logs
- notifications
- content/search indexes rebuilt from flat files
- backup/update history
- rate-limit and operational state

If `storage/db/app.sqlite` is deleted, content remains available from Markdown files, but admin/system settings and historical system data such as logs are lost unless restored from backup.

## Settings Storage

- `system_meta.site_settings` and `system_meta.theme_settings` hold YAML documents managed from Admin `Settings`.
- The repository no longer ships `content/settings/site.yaml` or `content/settings/theme.yaml`. A fresh install starts from the code defaults.
- Installs upgraded from the YAML settings era keep their values: if a legacy `content/settings/*.yaml` file exists and the matching SQLite key does not, the file is imported once on first load.
- Settings saves fail loudly when SQLite is unavailable instead of silently discarding the change.
- Theme settings saved from the form keep any extra keys a custom theme stores in `theme_settings`.
