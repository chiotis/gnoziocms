<?php
/*
 * Signs releases with a key kept on this computer, never on GitHub (docs/signed-releases.md).
 *
 *   php scripts/sign-release.php keygen [--key=PATH]       make the signing key (once); prints the public key for the code
 *   php scripts/sign-release.php public [--key=PATH]       print the public key of the signing key
 *   php scripts/sign-release.php release [VERSION] [--key=PATH] [--repo=owner/name] [--replace]
 *                                                          sign a published release (the latest one by default)
 *   php scripts/sign-release.php sign FILE [--key=PATH]    sign a release.json file in place
 *   php scripts/sign-release.php verify FILE [--pub=KEY]   check a release.json against the keys in the code (or this one)
 *
 * The key is at ~/.gnoziocms/release-signing.key unless --key or GNOZIO_RELEASE_KEY names another file.
 *
 * "release" needs git and the GitHub CLI (gh, signed in). It downloads the release.json that the release workflow
 * published, builds the same version again here from its tag, and signs only when the package GitHub holds is byte for
 * byte the one built here: a workflow or account that was tampered with cannot get its package signed by accident.
 * When they differ it stops; --replace then uploads the package built here, with its signed release.json, in place of
 * GitHub's. Nothing is uploaded but release.json (and with --replace the package).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/ReleaseSignature.php';

use GnozioCMS\ReleaseSignature;

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function run(string $command, ?string &$output = null): int
{
    $lines = [];
    exec($command . ' 2>&1', $lines, $code);
    $output = implode("\n", $lines);
    return $code;
}

if (!function_exists('sodium_crypto_sign_detached')) {
    fail('This PHP has no sodium extension, which signing needs.');
}

$args = [];
$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $arg, $m)) {
        $options[$m[1]] = $m[2] ?? true;
    } else {
        $args[] = $arg;
    }
}
$command = array_shift($args) ?? '';
$home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '');
$keyPath = (string)($options['key'] ?? (getenv('GNOZIO_RELEASE_KEY') ?: $home . '/.gnoziocms/release-signing.key'));
$repo = (string)($options['repo'] ?? 'chiotis/gnoziocms');

$readKey = static function () use ($keyPath): string {
    if (!is_file($keyPath)) {
        fail("No signing key at $keyPath. Make one with: php scripts/sign-release.php keygen");
    }
    $secret = trim((string)file_get_contents($keyPath));
    try {
        ReleaseSignature::publicKeyOf($secret);
    } catch (InvalidArgumentException) {
        fail("$keyPath does not hold an Ed25519 secret key.");
    }
    return $secret;
};

$readManifest = static function (string $file): array {
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data) || !isset($data['version'], $data['sha256'], $data['size'])) {
        fail("$file is not a release manifest.");
    }
    return $data;
};

$writeManifest = static function (string $file, array $data): void {
    if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
        fail("Cannot write $file.");
    }
};

switch ($command) {
    case 'keygen':
        if (file_exists($keyPath)) {
            fail("$keyPath already exists. It is not replaced: move it away first if you really mean to make a new key.");
        }
        $dir = dirname($keyPath);
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            fail("Cannot create $dir.");
        }
        $pair = ReleaseSignature::generate();
        $old = umask(0077);
        $written = file_put_contents($keyPath, $pair['secret'] . "\n");
        umask($old);
        if ($written === false) {
            fail("Cannot write $keyPath.");
        }
        chmod($keyPath, 0600);
        echo "Signing key written to $keyPath (only you can read it).\n";
        echo "Keep a copy somewhere safe and offline (a password manager): without it no new release can be signed.\n\n";
        echo "Public key: {$pair['public']}\n";
        echo "Key id:     " . ReleaseSignature::keyId($pair['public']) . "\n\n";
        echo "To require it, put the public key in both lists and release that change:\n";
        echo "  src/ReleaseSignature.php  public const PUBLIC_KEYS = ['{$pair['public']}'];\n";
        echo "  installer.php             const RELEASE_KEYS = ['{$pair['public']}'];\n";
        break;

    case 'public':
        $public = ReleaseSignature::publicKeyOf($readKey());
        echo $public . "\n";
        echo 'Key id: ' . ReleaseSignature::keyId($public) . "\n";
        break;

    case 'sign':
        $file = (string)($args[0] ?? '');
        if ($file === '') {
            fail('Which release.json? php scripts/sign-release.php sign build/release.json');
        }
        $writeManifest($file, ReleaseSignature::sign($readManifest($file), $readKey()));
        echo "Signed $file.\n";
        break;

    case 'verify':
        $file = (string)($args[0] ?? '');
        $data = $readManifest($file);
        $keys = isset($options['pub']) ? [(string)$options['pub']] : ReleaseSignature::PUBLIC_KEYS;
        if ($keys === []) {
            fail('No public key to check against: the code lists none yet. Pass one with --pub=KEY.');
        }
        $result = ReleaseSignature::check($data, $keys);
        echo "{$data['version']}: $result\n";
        exit($result === ReleaseSignature::VERIFIED ? 0 : 1);

    case 'release':
        $secret = $readKey();
        foreach (['git --version', 'gh --version'] as $tool) {
            if (run($tool) !== 0) {
                fail('This needs ' . strtok($tool, ' ') . ' on the PATH.');
            }
        }
        $version = (string)($args[0] ?? '');
        if ($version === '') {
            if (run('gh release view --repo ' . escapeshellarg($repo) . ' --json tagName -q .tagName', $tag) !== 0) {
                fail("Cannot read the latest release of $repo: $tag");
            }
            $version = ltrim(trim($tag), 'v');
        }
        $version = ltrim($version, 'v');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            fail("'$version' is not a version like 0.1.340.");
        }
        $tag = 'v' . $version;
        $work = sys_get_temp_dir() . '/gnozio-sign-' . bin2hex(random_bytes(4));
        mkdir($work . '/published', 0700, true);
        $cleanup = static function () use ($root, $work): void {
            run('git -C ' . escapeshellarg($root) . ' worktree remove --force ' . escapeshellarg($work . '/source'));
            run('rm -rf ' . escapeshellarg($work));
        };
        register_shutdown_function($cleanup);

        echo "Downloading release.json of $tag from $repo...\n";
        if (run('gh release download ' . escapeshellarg($tag) . ' --repo ' . escapeshellarg($repo) . ' -p release.json -D ' . escapeshellarg($work . '/published'), $out) !== 0) {
            fail("Cannot download release.json of $tag: $out");
        }
        $published = $readManifest($work . '/published/release.json');
        if ((string)$published['version'] !== $version) {
            fail("The release.json of $tag says it is {$published['version']}.");
        }

        echo "Building $version here from its tag...\n";
        if (run('git -C ' . escapeshellarg($root) . ' fetch --quiet origin ' . escapeshellarg("refs/tags/$tag:refs/tags/$tag"), $out) !== 0) {
            fail("Cannot fetch the tag $tag: $out");
        }
        if (run('git -C ' . escapeshellarg($root) . ' worktree add --quiet --detach ' . escapeshellarg($work . '/source') . ' ' . escapeshellarg($tag), $out) !== 0) {
            fail("Cannot check out $tag: $out");
        }
        if (run(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/source/scripts/build-release.php') . ' ' . escapeshellarg($work . '/build') . ' --repo=' . escapeshellarg($repo), $out) !== 0) {
            fail("The build failed: $out");
        }
        $built = $readManifest($work . '/build/release.json');
        $same = strtolower((string)$built['sha256']) === strtolower((string)$published['sha256'])
            && (int)$built['size'] === (int)$published['size']
            && ReleaseSignature::message($built) === ReleaseSignature::message($published);

        if ($same) {
            $writeManifest($work . '/published/release.json', ReleaseSignature::sign($published, $secret));
            if (run('gh release upload ' . escapeshellarg($tag) . ' --repo ' . escapeshellarg($repo) . ' --clobber ' . escapeshellarg($work . '/published/release.json'), $out) !== 0) {
                fail("Cannot upload the signed release.json: $out");
            }
            echo "Signed $version: the package on GitHub is the one built here (sha256 {$built['sha256']}).\n";
            break;
        }
        echo "The package on GitHub is NOT the one built here from $tag:\n";
        echo "  GitHub: sha256 {$published['sha256']}, {$published['size']} bytes\n";
        echo "  here:   sha256 {$built['sha256']}, {$built['size']} bytes\n";
        if (!isset($options['replace'])) {
            echo "Nothing was signed. If you trust the tag, run again with --replace to publish the package built here instead.\n";
            echo "If you did not expect this, check the release workflow and the account before doing anything else.\n";
            exit(1);
        }
        $writeManifest($work . '/build/release.json', ReleaseSignature::sign($built, $secret));
        $zip = $work . '/build/gnoziocms-' . $version . '.zip';
        if (run('gh release upload ' . escapeshellarg($tag) . ' --repo ' . escapeshellarg($repo) . ' --clobber ' . escapeshellarg($zip) . ' ' . escapeshellarg($work . '/build/release.json'), $out) !== 0) {
            fail("Cannot upload: $out");
        }
        echo "Replaced the package of $version with the one built here, and signed it.\n";
        break;

    default:
        fwrite(STDERR, "Usage: php scripts/sign-release.php keygen | public | release [VERSION] [--replace] | sign FILE | verify FILE [--pub=KEY]\n");
        exit(1);
}
