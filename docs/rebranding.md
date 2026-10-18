# FarosCMS to GnozioCMS

GnozioCMS is the new product name. This change updates labels, messages, the PHP namespace, package metadata and release filenames. It does not change the admin or public theme's layout, CSS, colours, typography, icons or controls. The existing text initials on the login and setup screens change from `FC` to `GC`.

## Code and packages

- New PHP code uses `GnozioCMS\`. The bundled autoloader and Composer's `autoload.files` load `src/autoload.php`, which also publishes `FarosCMS\` class aliases. Existing custom code can construct classes and pass instances through old type hints without being rewritten first.
- The Composer project name is `chiotis/gnoziocms`. No dependency versions change, and production installations still need neither Composer nor Node.
- Release packages are named `gnoziocms-<version>.zip`. Their layout and `release.json` format stay the same. Existing installers read the package URL from the manifest, so they can install the renamed package.
- JavaScript exposes `GnozioEditor`, `GnozioMediaPicker`, `gnozioSlug` and `gnozio.event(name)`. The corresponding `FarosEditor`, `FarosMediaPicker`, `farosSlug` and `faros.event(name)` names refer to the same functions or objects.

## Repository transition

The canonical repository in the code, release builder, clone instructions and badges is now `chiotis/gnoziocms`. **The external GitHub rename and local folder rename are still pending; this patch performs neither.** Complete the GitHub rename and verify the new update addresses before deploying these source changes or publishing a release.

For the official repository, update checks and the Settings > Updates form resolve the old `chiotis/faroscms` name to `chiotis/gnoziocms`. The recognised legacy URLs for `VERSION`, `CHANGELOG.md`, branch archives and the latest release manifest resolve to the new repository too. A configured branch stays unchanged. Forks and custom source URLs, including URLs with query strings or fragments, retain their configured values; tokens and other update settings are preserved. Blank URL fields remain blank in the form and saved settings, and the updater derives their effective addresses from the selected repository and branch.

This is a change to the effective source configuration, not a database migration on page load. Reading settings or checking for an update does not rewrite the stored configuration. An explicit save in Settings writes the normalised source values shown in the form.

Rename the existing GitHub repository so old links and release addresses can continue through GitHub's redirects. Keep the old repository name unused, because creating another `chiotis/faroscms` repository would remove those redirects. After the rename, verify both old and new `VERSION`, changelog, release manifest and package addresses, then force a status check on an existing installation. The release workflow uses GitHub's current repository name; local builds default to `chiotis/gnoziocms` and still accept `--repo=owner/name`. See [GitHub's repository rename guidance](https://docs.github.com/en/repositories/creating-and-managing-repositories/renaming-a-repository).

## Compatibility identifiers

Some old names deliberately remain because they identify a stored format, a deployed service or an integration rather than a visible product label:

| Identifier | Reason to retain it |
| --- | --- |
| `faroscms-backup.json` | Existing and new backups stay readable by the same backup/restore tooling. |
| `faroscms-branding` and the `faroscms` export metadata field | Existing branding exports and imports continue to work. |
| `X-Faros-Update`, `X-Faros-Update-Check`, `_faros_check` | An updater already running the old code can verify the newly installed code. |
| Omni's `faros` report field and `https://omni.faroscms.com` | The existing Omni service and protocol remain usable. |
| Legacy `chiotis/faroscms` source values and GitHub links | Recognised official source settings resolve to the new repository; GitHub redirects must remain available for older installations and existing links after the rename. |
| `/assets/js/faros-analytics.js` | Existing custom templates and cached pages can still load the analytics script. Both analytics API names work. |
| Browser storage keys, preview element IDs and demo image filenames | Saved preferences, custom integrations and existing media URLs retain their meaning. |
| `skills/faroscms`, the `faroscms` skill ID and `faros_lib.py` | Existing skill installations, links and Python imports remain valid. The skill describes GnozioCMS and recognises both names. |
| `FAROS_TEST_*` environment variables | Existing CI and local test commands continue to work. |

No automatic migration rewrites a site's saved title, stored settings, content, custom code or uploaded files. Update source values are saved only through the explicit Settings save described above. New installations default to GnozioCMS, and the distributed starter content uses the new name. Historical changelog entries and old-content test fixtures retain their original names.

The legacy aliases, backup formats, updater headers and Omni protocol remain unchanged. Domain changes, removal of legacy aliases and new export protocols would be separate migrations. This code change does not publish a release or change the hosted services.
