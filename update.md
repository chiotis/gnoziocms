# Installing and updating GnozioCMS

A GnozioCMS site is two things kept apart on purpose:

| | What it is | Who changes it |
|---|---|---|
| **The code** | `src/`, `admin/`, `vendor/`, `themes/`, `public/assets/` and a few files (`VERSION`, `README.md`, …) | Updates replace it. Never edit it for one site. |
| **The site** | `content/` (pages, posts, menus, media notes, form submissions), `public/uploads/` (images and files), `custom/` (your CSS, templates and translations) and `storage/` (the database with settings, users and history, and backups) | You. Updates never touch it. |

Everything that follows comes from that split. A release is a ZIP of the code only, so nothing in it can replace your pages, uploads, settings or accounts.

## Put a new site on a server

1. Download `gnoziocms-<version>.zip` from the [latest release](https://github.com/chiotis/gnoziocms/releases/latest) and unzip it into the folder for the site. Point the web server's document root at its `public/` folder (see `README.md`).
2. PHP must be able to write to the site folder, because that is how updates are installed from the admin (see *Installing an update* below). `content/`, `custom/`, `storage/` and `public/uploads/` are created on the first visit.
3. Open `/admin` **straight away**. A site with no accounts asks for its first administrator (username, email and a password of at least 8 characters). Nobody else can make an account until that exists, so do it before sharing the address.
4. To start from the demo site instead of an empty one, run `php scripts/use-starter.php` before step 3. It copies the demo pages and images from `starter/` and refuses to run on a site that already has content.

## Move a site you built on your own computer

Upload the code as above, then copy the site over the empty folders: `content/`, `public/uploads/`, `custom/` and `storage/db/app.sqlite` (the database with the settings and accounts). The site then runs exactly as it did, and updates keep it that way. Make a backup first (Admin → Backups) if the copy is the only one.

## Installing an update from the admin

Admin → **Updates** shows the installed version, the latest release and its notes. When a newer release has a package, the **Install** button appears. It is available once these are true:

- a **verified backup** made today, on the version being replaced, exists (Updates → *Create verified backup*);
- PHP can write to the site folder, there is disk space (three times the package), the PHP version is high enough, and no other update is running.

Pressing it does this, in order. If any step fails, nothing has changed or everything is put back:

1. Downloads the package and checks its **size and SHA-256** against the release's `release.json`. A package that is not the one that was published is refused.
2. Unpacks it beside the live code and checks every file: only code paths are accepted (a package with a file for `content/`, `custom/`, `storage/` or `public/uploads/`, a path that climbs out of the folder, or a link is refused as a whole).
3. Makes a copy of the database.
4. **Closes the public site** with a "back in a moment" page (503). The admin stays open. A site closed this way opens again by itself after 15 minutes at most.
5. Moves each live folder aside and the new one in, one by one. If one move fails, the ones already done are undone.
6. Asks the site, in a request of its own, whether the new version starts: the sign-in page must answer and neither page may show a PHP error.
7. If it does not start, the old code is put back (and the database, if the new version had changed its structure) and the site opens again. If it does, the site opens and the old code is kept in `storage/updates/<version>/previous` so it can be put back later (the last two updates are kept).

The result is shown on the Updates screen, in the activity log and as a notification.

**"Installed, but not checked"** means the site could not call itself (some hosts block that). Open the site and the admin at once; if something is wrong, press **Put version … back** on the Updates screen.

### Putting the old version back later

The Updates screen shows **Put version X back** after an install. It restores the old code from what the install kept. The database is left as it is, because it has been used since; if the old version cannot work with it, restore the backup taken before the update (Admin → Backups). Pages, uploads and custom files are not involved.

### When the Install button is not there

- *"The package of this version is not published yet"*: a release is published a few minutes after its version appears; press **Check status** again.
- *"Code folders writable"* is blocked: the web server's user cannot write to the site folder. Update by hand (below) or change the permissions.

## Updating by hand

Use this when the admin cannot write to the code, or you prefer it.

- **ZIP**: unzip the new package over the site folder. It contains no site data, so nothing is overwritten except code. Then open `/admin`; pending database changes run on the first request.
- **git** (a clone of the repository): `git pull --ff-only`, then open `/admin`. Since 0.1.20 the repository holds no site data (`content/` and `public/uploads/` are ignored), so a pull never touches your pages. **A clone made before 0.1.20 still has the demo site tracked: pull only after copying out your `content/` and `public/uploads/`, because git removes the files the new version no longer tracks.**

After either, check the home page, a page of each kind, `/admin`, `/sitemap.xml` and `/robots.txt`.

## If something goes wrong

1. Press **Put version … back** on the Updates screen (code only), or restore the code from git/ZIP.
2. If data changed, restore the backup taken before the update: Admin → Backups, restore, superadmin only (see `docs/backups.md`).
3. Re-check the public site and the admin.

## Where updates come from

Settings → Updates names the repository and, optionally, the address of the release manifest (`release.json`). By default both are the project's own GitHub releases. A private repository needs a GitHub token (Settings → Updates) to read its version; installing from a private release needs a manifest address the server can reach.

The official repository is `chiotis/gnoziocms`. Recognised source settings for the former `chiotis/faroscms` repository resolve to the new address; custom sources are preserved. Stored settings change only when you explicitly save them. The external repository rename must be completed before this transition is deployed; see [the repository transition notes](docs/rebranding.md).

## Good habits

- Keep everything specific to one site in `custom/` (see `docs/theming.md`), never in `themes/`, `src/` or `admin/`. That is what makes updates safe.
- Treat updates as: **backup → install → look at the site → keep the backup for a few days**.
