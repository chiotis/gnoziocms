# GnozioCMS Backups and Restore

## Archives

Archives live in `storage/backups/` and are listed in Admin `Backups`.

| Type | Contents | Filename |
|------|----------|----------|
| Full | Every project file (form uploads in `storage/form-uploads/` included, with their checksums in the manifest) except `.git/`, `node_modules/`, `storage/backups/`, `storage/cache/`, `storage/restore/`, `storage/updates/`, and generated image variants (`public/uploads/_v/`), plus a consistent copy of the system database | `<site>-backup-YYYYMMDD-HHMMSS-<id>.zip` |
| Database | A consistent copy of `storage/db/app.sqlite` | `<site>-database-backup-YYYYMMDD-HHMMSS-<id>.zip` |

Every archive contains `faroscms-backup.json`:

- `type`, `reason` (`manual`, `scheduled`, `pre-update`, `pre-restore`), `created_at`, GnozioCMS `version` and git `commit`
- `files`: the size and SHA-256 of every file in the archive

The database is copied with SQLite `VACUUM INTO`, so the archive holds one clean file even while WAL mode has unflushed pages. Archives are written under a temporary name and renamed when complete, so a half-written archive never appears in the list.

Archives made before manifests existed show a **No checksums** badge. They can still be restored after an explicit acknowledgement.

## When a scheduled backup runs

Settings > Backup chooses a schedule (daily, weekly or monthly). A PHP site has no clock of its own, so a scheduled backup is taken when something asks for it:

- **After a visit to the public site**, once the page has been sent (so the visitor never waits for it), when a backup is due. This needs PHP-FPM or LiteSpeed (`fastcgi_finish_request`); on other setups a visit does not start it.
- **When someone opens the admin**, on any server.
- **From a cron job**, which does not depend on visits at all: `php /path/to/site/src/cron-backup.php`, once a day (for example `0 3 * * * …`). It prints `off`, `not due`, `busy`, `ok` or `failed`, and does nothing when no backup is due, so running it more often does no harm. Backups > Policy and Admin > System (the *Cron job* card) show the line with the real path; System also says whether this server can start due work after a visit, and when the cron job was last seen (the script records it each time it runs). The health checks warn when scheduled backups are on and nothing starts them: no cron job on a server that cannot start work after a visit, or a cron job not seen for more than 36 hours.

A site that nobody visits, on a server without FPM and with no cron job, takes no scheduled backups: that is the case for a quiet site, and why the cron job is advised. A backup is due when the last one is older than the interval, less two hours, so a job that runs at the same time every day is not found a few seconds early. Only one run goes at a time (a lock in the backups folder).

## Retention and remote copies

- `Settings > Backups > Backups to keep on this server` limits how many archives stay in `storage/backups/` (all types together). It is a number of backups, not days. Pre-restore safety snapshots never trigger pruning, so the archive being restored cannot be deleted by it.
- With remote storage enabled, each new archive is uploaded (streamed) to the S3-compatible bucket. Remote pruning only deletes GnozioCMS archive names directly under the configured prefix; other objects in the bucket are never touched.
- `Backups to keep in remote storage` (`backup.remote.keep`, 20 by default) is a separate number, also a count of backups. Right after each successful upload the newest that many stay in the bucket and the older GnozioCMS archives are deleted, so a lower number takes effect with the next backup, not when it is saved.
- A failed remote upload marks the run as a **warning**. The local archive is kept and the schedule advances.
- **No copy stays on the server by default.** Once a scheduled or manual backup (full or database-only) is confirmed uploaded, its local archive is deleted, so the server holds nothing but what it needs. Tick `Also keep a copy on this server` (`backup.remote.keep_local`) to keep both. A failed upload never deletes anything, and `pre-update` and `pre-restore` snapshots always stay on the server (an update is undone, and a restore started, from them).
- Because the Backups screen lists the archives on this server, a backup kept only in remote storage is not under *Local backups*. A superadmin can bring it back from the same screen: **Remote storage > Show backups in remote storage** reads the bucket (only when asked, never on every visit) and lists the GnozioCMS archives in it, and **Fetch to this server** downloads one into `storage/backups/`, where it appears under *Local backups* and is restored like any other (*Restore*). Fetching never restores anything by itself.
  - The file is downloaded under a temporary name, compared with the size the bucket listed, and checked against its own checksums; a file that is cut short, is not a valid backup or has a checksum that does not match is deleted, and the message says why. It keeps the date it was made.
  - Only archives the bucket lists under the configured prefix with a GnozioCMS name can be fetched. There has to be room on the disk for it, and it is limited to superadmins, like restoring.
  - A fetched archive counts with the local ones: after the next backup the oldest are removed down to *Backups to keep on this server*, so restore soon after fetching. The same file can also be put in `storage/backups/` by hand (download the zip from the provider).
  - Needs the PHP cURL extension (with `curl_exec` allowed), like the upload. The dashboard and the system check still know when the last backup was taken (from the run history).

## Verify

The shield icon in the backups list checks every file against the manifest (size and SHA-256), flags files that are missing or not listed, and rejects unsafe paths (`..`, absolute paths, backslashes).

## Restore

Restore is limited to superadmins (`backups.restore` capability).

1. Click the restore icon on an archive. The page shows the manifest, the verification result, and which areas the archive contains.
2. Choose the areas:
   - **Content**: `content/` (pages, posts, projects, forms and submissions, menus, taxonomies, media metadata)
   - **Uploads**: `public/uploads/`
   - **Form files**: `storage/form-uploads/` (what visitors attached to form submissions; private, restored with its web-server deny rule)
   - **Site customizations**: `custom/` (translation overrides, custom CSS/JS, template overrides)
   - **System database**: `storage/db/app.sqlite` (settings, users, logs, notifications, backup history)
3. Type the archive name to confirm.

What happens next:

1. The archive is verified again. Any mismatch stops the restore.
2. A full safety snapshot of the current site is created (reason `pre-restore`).
3. The selected areas are extracted to `storage/restore/<run>/staged/`. A restored database is opened, checked with `PRAGMA integrity_check`, and must contain `system_meta`.
4. Each live area is renamed into `storage/restore/<run>/previous/` and the staged area is renamed into place. If any rename fails, every area already swapped is moved back and the half-restored copy is kept in `storage/restore/<run>/failed/`.
5. Pending migrations run on the restored database. The restore is logged there and a notification is created.

Code (`src/`, `vendor/`, `admin/`, `themes/`, `public/assets/`) is never restored from a backup. Archives made before `custom/` existed kept edited strings in `themes/default/lang/`; those files are not restored automatically, so copy any strings you need into Admin > Translations. Use git or a release package for code.

If the restored database does not contain your user, or your user is inactive there, you are signed out on the next request.

The two most recent `storage/restore/<run>/` directories are kept for manual recovery. Delete them when you no longer need them.

## Manual recovery

If the admin itself is unusable:

1. Stop the web server or put the site in maintenance.
2. Unzip the archive somewhere else and compare `faroscms-backup.json` checksums if needed (`shasum -a 256 <file>`).
3. Copy `content/`, `public/uploads/`, `storage/form-uploads/`, `custom/`, and `storage/db/app.sqlite` into place. Remove any stale `storage/db/app.sqlite-wal` and `app.sqlite-shm` first.
4. Start the server and sign in.
