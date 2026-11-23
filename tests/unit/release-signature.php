<?php
/*
 * Signed releases (audit S20): a manifest signed with the release key is offered, one that is unsigned, changed after
 * signing or signed by another key is not once the code lists a key, and with no key listed nothing changes. The
 * one-file installer checks the same way, and the signing script makes a key, signs and verifies.
 *   php tests/unit/release-signature.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
define('GNOZIO_INSTALLER_LIB', true);
require dirname(__DIR__, 2) . '/installer.php';
use GnozioCMS\{ReleaseSignature, SystemDatabase, SystemMetaRepository, UpdateService};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$root = dirname(__DIR__, 2);
$manifest = ['version' => '1.2.3', 'min_php' => '8.5', 'package' => 'gnoziocms-1.2.3.zip', 'package_url' => 'https://github.com/chiotis/gnoziocms/releases/download/v1.2.3/gnoziocms-1.2.3.zip', 'sha256' => str_repeat('ab', 32), 'size' => 1000, 'files' => 3, 'requires_backup' => true];
$key = ReleaseSignature::generate();
$other = ReleaseSignature::generate();
$signed = ReleaseSignature::sign($manifest, $key['secret']);

// ---- the signature itself
check('the public key of the secret key is the one made with it', ReleaseSignature::publicKeyOf($key['secret']), $key['public']);
check('a key id is 16 hex digits', (bool)preg_match('/^[a-f0-9]{16}$/', ReleaseSignature::keyId($key['public'])), true);
check('a signed manifest keeps everything it had and names the key', [array_diff_key($signed, ['signatures' => 1]), $signed['signatures'][0]['key']], [$manifest, ReleaseSignature::keyId($key['public'])]);
check('it is verified with that key', ReleaseSignature::check($signed, [$key['public']]), ReleaseSignature::VERIFIED);
check('and with that key among others', ReleaseSignature::check($signed, [$other['public'], $key['public']]), ReleaseSignature::VERIFIED);
check('an unsigned manifest is unsigned', ReleaseSignature::check($manifest, [$key['public']]), ReleaseSignature::UNSIGNED);
check('a signature by a key the code does not know counts as none', ReleaseSignature::check(ReleaseSignature::sign($manifest, $other['secret']), [$key['public']]), ReleaseSignature::UNSIGNED);
foreach (['version' => '1.2.4', 'min_php' => '8.4', 'package_url' => 'https://evil.example/p.zip', 'sha256' => str_repeat('cd', 32), 'size' => 1001, 'requires_backup' => false] as $field => $value) {
    check("changing $field after signing breaks the signature", ReleaseSignature::check([$field => $value] + $signed, [$key['public']]), ReleaseSignature::INVALID);
}
check('fields that are not signed (the file name, the count of files) may differ', ReleaseSignature::check(['package' => 'x.zip', 'files' => 9] + $signed, [$key['public']]), ReleaseSignature::VERIFIED);
check('the checksum is signed in lower case, as it is compared', ReleaseSignature::check(['sha256' => strtoupper($manifest['sha256'])] + $signed, [$key['public']]), ReleaseSignature::VERIFIED);
$forged = $signed;
$forged['signatures'][0]['sig'] = base64_encode(str_repeat("\0", 64));
check('a forged signature is invalid', ReleaseSignature::check($forged, [$key['public']]), ReleaseSignature::INVALID);
$forged['signatures'][0]['sig'] = 'not base64!';
check('so is one that is not a signature at all', ReleaseSignature::check($forged, [$key['public']]), ReleaseSignature::INVALID);
check('a good signature beside a bad one is enough', ReleaseSignature::check(['signatures' => array_merge($forged['signatures'], ReleaseSignature::sign($manifest, $other['secret'])['signatures'])] + $manifest, [$key['public'], $other['public']]), ReleaseSignature::VERIFIED);
check('signatures that are not a list are none', ReleaseSignature::check(['signatures' => 'x'] + $manifest, [$key['public']]), ReleaseSignature::UNSIGNED);
check('signing again with the same key replaces its signature', count(ReleaseSignature::sign($signed, $key['secret'])['signatures']), 1);
check('a second key adds one', count(ReleaseSignature::sign($signed, $other['secret'])['signatures']), 2);
check('the signed text starts with its purpose, so nothing else signed with the key can pass for a release', str_starts_with(ReleaseSignature::message($manifest), ReleaseSignature::CONTEXT . "\n"), true);
$threw = false;
try { ReleaseSignature::sign($manifest, 'short'); } catch (InvalidArgumentException) { $threw = true; }
check('a secret key that is not one is refused', $threw, true);

// ---- the installer checks the same way, with the same keys
check('the installer signs the same text', GnozioInstaller::signedMessage($signed), ReleaseSignature::message($signed));
check('and for a manifest without a backup flag', GnozioInstaller::signedMessage(array_diff_key($manifest, ['requires_backup' => 1])), ReleaseSignature::message(array_diff_key($manifest, ['requires_backup' => 1])));
$error = '';
check('it accepts a signed manifest', GnozioInstaller::signatureValid($signed, [$key['public']], $error), true);
check('and refuses an unsigned one, saying so', [GnozioInstaller::signatureValid($manifest, [$key['public']], $error), $error], [false, 'the release is not signed yet; try again later']);
check('and one changed after signing', [GnozioInstaller::signatureValid(['size' => 2000] + $signed, [$key['public']], $error), $error], [false, 'the signature of the release does not match it']);
check('the installer lists the same keys as the code', GnozioInstaller::RELEASE_KEYS, ReleaseSignature::PUBLIC_KEYS);
foreach (ReleaseSignature::PUBLIC_KEYS as $listed) {
    check("listed key $listed is an Ed25519 public key", strlen((string)base64_decode($listed, true)), 32);
}

// ---- the update check: what it offers
$dir = sys_get_temp_dir() . '/gnozio-release-signature-' . bin2hex(random_bytes(4));
mkdir($dir . '/storage', 0775, true);
mkdir($dir . '/www', 0775, true);
$db = new SystemDatabase($dir . '/storage');
$db->initialize();
$meta = new SystemMetaRepository($db);
$port = 0;
for ($try = 0; $try < 20 && $port === 0; $try++) {
    $candidate = random_int(20000, 60000);
    $socket = @stream_socket_server("tcp://127.0.0.1:$candidate");
    if ($socket !== false) { fclose($socket); $port = $candidate; }
}
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $dir . '/www'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
$url = "http://127.0.0.1:$port";
$deadline = microtime(true) + 10;
while (microtime(true) < $deadline && @file_get_contents("$url/") === false && !str_contains(implode(' ', $http_response_header ?? []), 'HTTP/')) { usleep(100000); }
$publish = static fn(array $data) => file_put_contents($dir . '/www/release.json', json_encode($data));
$validated = UpdateService::validateManifest($manifest);
$settings = ['repository' => 'acme/site', 'release_url' => "$url/release.json"];

$publish($manifest);
$none = new UpdateService($dir, $settings, $meta, []);
check('with no key, an unsigned release is offered as before', $none->releaseManifest(true), $validated);
check('and nothing is refused', $none->releaseRefusal(), null);
check('and it is not shown as verified', $none->releaseSignature(), ReleaseSignature::UNSIGNED);

$keyed = new UpdateService($dir, $settings, $meta, [$key['public']]);
check('with a key, an unsigned release is not offered', $keyed->releaseManifest(true), null);
$refusal = $keyed->releaseRefusal();
check('the screen learns why, and which version', [$refusal['version'], $refusal['reason'], str_contains($refusal['message'], 'not signed yet')], ['1.2.3', ReleaseSignature::UNSIGNED, true]);
check('and keeps that answer for a while like any other', $keyed->releaseManifest(), null);

$publish($signed);
check('a release signed with the key is offered', $keyed->releaseManifest(true), $validated);
check('as verified, with nothing refused', [$keyed->releaseSignature(), $keyed->releaseRefusal()], [ReleaseSignature::VERIFIED, null]);

$publish(['sha256' => str_repeat('ef', 32)] + $signed);
check('a release whose package was swapped after signing is not offered', $keyed->releaseManifest(true), null);
check('and the screen says not to install it', [$keyed->releaseRefusal()['reason'], str_contains($keyed->releaseRefusal()['message'], 'does not match')], [ReleaseSignature::INVALID, true]);

$publish(ReleaseSignature::sign($manifest, $other['secret']));
check('a release signed with some other key is not offered', $keyed->releaseManifest(true), null);
check('with a key, a signed release with no key listed is still read without a check (the old site, moving over)', (new UpdateService($dir, $settings, $meta, []))->releaseManifest(true), $validated);
proc_terminate($proc);
proc_close($proc);

// ---- which sources the keys in the code bind
check('the keys in the code bind the official repository', (new UpdateService($dir, [], $meta))->signingKeys(), ReleaseSignature::PUBLIC_KEYS);
check('and only it: a fork signs with its own keys or not at all', (new UpdateService($dir, ['repository' => 'acme/site'], $meta))->signingKeys(), []);
check('a test or custom manifest address is not bound either', (new UpdateService($dir, ['release_url' => "$url/release.json"], $meta))->signingKeys(), []);

// ---- the signing script
$home = $dir . '/home';
mkdir($home);
$script = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/sign-release.php');
$env = 'HOME=' . escapeshellarg($home) . ' GNOZIO_RELEASE_KEY= ';
exec("$env$script keygen 2>&1", $out, $code);
$keyFile = $home . '/.gnoziocms/release-signing.key';
check('keygen makes the key in the home folder, readable only by its owner', [$code, is_file($keyFile), substr(sprintf('%o', fileperms($keyFile)), -3)], [0, true, '600']);
$public = ReleaseSignature::publicKeyOf(trim((string)file_get_contents($keyFile)));
check('and prints the public key to put in the code', str_contains(implode("\n", $out), "PUBLIC_KEYS = ['$public']"), true);
exec("$env$script keygen 2>&1", $out, $code);
check('it never replaces a key that is there', $code, 1);
file_put_contents($dir . '/release.json', json_encode($manifest));
exec("$env$script sign " . escapeshellarg($dir . '/release.json') . ' 2>&1', $out, $code);
check('sign signs a release.json in place', [$code, ReleaseSignature::check(json_decode((string)file_get_contents($dir . '/release.json'), true), [$public])], [0, ReleaseSignature::VERIFIED]);
$out = [];
exec("$env$script verify " . escapeshellarg($dir . '/release.json') . ' --pub=' . escapeshellarg($public) . ' 2>&1', $out, $code);
check('verify says so', [$code, $out], [0, ['1.2.3: verified']]);
exec("$env$script verify " . escapeshellarg($dir . '/release.json') . ' --pub=' . escapeshellarg($other['public']) . ' 2>&1', $out, $code);
check('and fails against another key', $code, 1);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
