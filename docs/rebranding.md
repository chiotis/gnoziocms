# FarosCMS to GnozioCMS

GnozioCMS is the new product name. This change updates labels, messages, the PHP namespace, package metadata and release filenames. It does not change the admin or public theme's layout, CSS, colours, typography, icons or controls. The existing text initials on the login and setup screens change from `FC` to `GC`.

## Code and packages

- New PHP code uses `GnozioCMS\`. The bundled autoloader and Composer's `autoload.files` load `src/autoload.php`, which also publishes `FarosCMS\` class aliases. Existing custom code can construct classes and pass instances through old type hints without being rewritten first.
- The Composer project name is `chiotis/gnoziocms`. No dependency versions change, and production installations still need neither Composer nor Node.
- Release packages are named `gnoziocms-<version>.zip`. Their layout and `release.json` format stay the same. Existing installers read the package URL from the manifest, so they can install the renamed package.
- JavaScript exposes `GnozioEditor`, `GnozioMediaPicker`, `gnozioSlug` and `gnozio.event(name)`. The corresponding `FarosEditor`, `FarosMediaPicker`, `farosSlug` and `faros.event(name)` names refer to the same functions or objects.

## Compatibility identifiers

Some old names deliberately remain because they identify a stored format, a deployed service or an integration rather than a visible product label:

| Identifier | Reason to retain it |
| --- | --- |
| `faroscms-backup.json` | Existing and new backups stay readable by the same backup/restore tooling. |
| `faroscms-branding` and the `faroscms` export metadata field | Existing branding exports and imports continue to work. |
| `X-Faros-Update`, `X-Faros-Update-Check`, `_faros_check` | An updater already running the old code can verify the newly installed code. |
| Omni's `faros` report field and `https://omni.faroscms.com` | The existing Omni service and protocol remain usable. |
| `chiotis/faroscms` and its GitHub URLs | The repository has not been renamed. Update sources, clone instructions and badges must point to the actual repository. |
| `/assets/js/faros-analytics.js` | Existing custom templates and cached pages can still load the analytics script. Both analytics API names work. |
| Browser storage keys, preview element IDs and demo image filenames | Saved preferences, custom integrations and existing media URLs retain their meaning. |
| `skills/faroscms`, the `faroscms` skill ID and `faros_lib.py` | Existing skill installations, links and Python imports remain valid. The skill describes GnozioCMS and recognises both names. |
| `FAROS_TEST_*` environment variables | Existing CI and local test commands continue to work. |

No migration rewrites a site's saved title, content, settings, custom code or uploaded files. New installations default to GnozioCMS, and the distributed starter content uses the new name. Historical changelog entries and old-content test fixtures retain their original names.

Repository/domain changes, removal of legacy aliases and new export protocols would be separate migrations. This code change does not publish a release or change the hosted services.
