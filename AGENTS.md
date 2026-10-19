# Notes for AI agents

This file is for coding agents (Codex, Claude Code and others) working in this repository. People should start with `README.md`.

## Environment

- **PHP 8.5 or newer** is required (`composer.json`), with the extensions listed in `SystemStatus::EXTENSIONS` (pdo_sqlite, zip, curl, mbstring, fileinfo, openssl, simplexml; intl is optional). Python 3 runs the HTTP tests. Node 20 or newer runs the JS tests (`tests/run.sh js`).
- Run `php -v` before running tests. On an older PHP the suite fails in ways that look like code failures: the installer and update checks refuse the version, and later steps cascade from that.

## Tests

- `tests/run.sh` runs the whole suite. `tests/run.sh <name>` runs one unit or HTTP test, and `tests/README.md` lists the names. Run the tests of the area you changed, and the full suite when the change touches shared code.
- **If the environment cannot run a test** (wrong PHP, missing extension, no network, a sandbox that blocks the built-in server), do not report its failures as failures of the code. Say what was missing, which tests could not run, and rely on CI (`.github/workflows/tests.yml`, PHP 8.5 on every push) for them.
- A test that hangs is a bug in the test. Every wait needs a deadline.

## CI and releases

- A push to `main` that changes `VERSION` publishes a GitHub Release automatically (`.github/workflows/release.yml`): the package, `release.json` and `installer.php`. Live sites see it on their Updates screen.
- Each merge to `main` bumps the third digit of `VERSION` and adds a `CHANGELOG.md` entry. Feature branches leave both alone.
- Before starting new work, read the latest **completed** run of the Tests workflow (`gh run list -w Tests -L 1`). If it is red, say so and name the failing test. A cancelled or unfinished run is not evidence that anything passed.

## The code and the site

The repository holds code only. `content/`, `custom/`, `storage/` and `public/uploads/` belong to a site and are never committed. Changes stay generic: no code for one client site.
