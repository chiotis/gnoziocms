# Signed releases

A site installs a new version from the package of the latest GitHub Release, after checking the package's SHA-256
against `release.json`. That proves the download is the published package, but not who published it: anyone who gets
into the GitHub account, or changes the release workflow, could publish a package and every site would install it.

Signed releases close that. `release.json` carries an Ed25519 signature made with a key that lives only on the
maintainer's computer, never on GitHub. Sites check it against the public key written in the code
(`src/ReleaseSignature.php`) and refuse a release that is unsigned, signed by another key, or changed after it was
signed. The SHA-256 is part of what is signed, so the signature covers the package too.

## How it works

- The release workflow builds and publishes the package and an unsigned `release.json`, as before.
- On the maintainer's computer, `php scripts/sign-release.php release` downloads that `release.json`, builds the same
  version again from its tag, and signs only if the package on GitHub is byte for byte the one built locally. It then
  uploads the signed `release.json` in place of the unsigned one. If the packages differ it stops (see below).
- A site reads `release.json`. Once the code lists a key, a release of the official repository that is not signed with it
  is not offered: the Updates screen says "Version X is published but not signed yet" and looks again within the hour.
  A signature that does not match says the release is refused. Omni's remote install follows the same rule.
- The one-file installer (`installer.php`) checks the same signature with the same keys (`RELEASE_KEYS`).
- What is signed: `gnoziocms-release-v1`, then the version, lowest PHP, package address, SHA-256, size and
  `backup`/`no-backup`, one per line. Signatures sit in `release.json` as
  `"signatures": [{"key": "<key id>", "sig": "<base64>"}]`; older sites ignore them.
- Forks and custom manifest addresses are not bound by the keys in the code.
- The server needs PHP's sodium extension (part of standard PHP builds) to check signatures. System status lists it.

## Turning it on (once)

1. On your own computer, in the repository: `php scripts/sign-release.php keygen`
   It writes the secret key to `~/.gnoziocms/release-signing.key` (mode 600) and prints the public key.
   Put a copy of the secret key in your password manager. Without it, no new release can be installed by sites that
   require it, until a release signed with the old key lists a new one.
2. Put the printed public key in both lists, in one commit:
   - `src/ReleaseSignature.php`: `public const PUBLIC_KEYS = ['<public key>'];`
   - `installer.php`: `const RELEASE_KEYS = ['<public key>'];`
   Release that commit as usual. Sites on older versions install it without a check (they know no key yet), which is how
   they move over.
3. Sign that release right away: `php scripts/sign-release.php release`

From then on, after every release: `php scripts/sign-release.php release` (the latest release; give a version to sign
another). It needs `git` and the GitHub CLI (`gh`, signed in). Until you run it, sites show the new version as waiting
for its signature and keep running the one they have.

## When the packages differ

`release` refuses to sign when the package GitHub holds is not the one built from the tag here. Either the build is not
reproducible between the two machines (a different zlib, for example), or the package was not built from that tag. If
you did not expect it, look at the release workflow's latest runs and the account's security log first. If you trust
the tag, `php scripts/sign-release.php release <version> --replace` uploads the package built on your computer, with its
signed `release.json`, in place of GitHub's.

## Changing the key

List the new public key beside the old one in both lists, release, and sign that release with the old key
(`--key=<old key file>`). Then sign the following releases with the new key and remove the old one from the lists in a
later release. If the secret key is lost, sites that require it cannot be moved by an update; a new version would have
to be installed by hand on each site.

## Other commands

- `php scripts/sign-release.php public` prints the public key of your signing key.
- `php scripts/sign-release.php sign build/release.json` signs a local manifest in place.
- `php scripts/sign-release.php verify release.json [--pub=<key>]` checks one against the keys in the code (or that key).
- `--key=<file>` or the `GNOZIO_RELEASE_KEY` environment variable names another key file.
