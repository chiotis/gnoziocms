<?php
/*
 * SecretBox (audit S8): secrets encrypted in the database under a key in storage/key; values from before read as they are and
 * sealed on the next save; a restore on another server (no key, or another one) reads them as empty and marks them lost; a backup
 * never carries the key. Through ShopSecrets, SiteSettings and AiSettings.
 *   php tests/unit/secret-box.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{AiSettings, BackupService, SecretBox, SiteSettings, SystemDatabase, SystemMetaRepository};
use GnozioCMS\Shop\ShopSecrets;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual) . ' expected ' . json_encode($expected)) . "\n"; }

$dir = sys_get_temp_dir() . '/secretbox' . getmypid();
mkdir("$dir/storage", 0775, true);
mkdir("$dir/content", 0775, true);
register_shutdown_function(function () use ($dir) { @exec('rm -rf ' . escapeshellarg($dir)); });

// ---- the box
$box = new SecretBox("$dir/storage/key");
check('no key until one is needed', is_file("$dir/storage/key"), false);
$sealed = $box->seal('Shared-Secret-42');
check('sealed: marked, and the secret is not in it', [str_starts_with($sealed, 'gzenc:v1:'), str_contains($sealed, 'Shared'), $box->open($sealed)], [true, false, 'Shared-Secret-42']);
check('the key is made once, readable by the owner only', [is_file("$dir/storage/key"), decoct(fileperms("$dir/storage/key") & 0777)], [true, '600']);
check('each seal is different (a fresh nonce)', $box->seal('same') !== $box->seal('same'), true);
check('empty stays empty; a sealed value is not sealed twice', [$box->seal(''), $box->seal($sealed)], ['', $sealed]);
check('a value from before (plain text) is read as it is, and is not lost', [$box->open('old-plain'), $box->lost('old-plain')], ['old-plain', false]);
check('another process with the same file opens it', (new SecretBox("$dir/storage/key"))->open($sealed), 'Shared-Secret-42');
$elsewhere = new SecretBox("$dir/other/key");
check('a server without the key: empty, lost, and nothing fails', [$elsewhere->open($sealed), $elsewhere->lost($sealed)], ['', true]);
mkdir("$dir/third", 0775, true);
(new SecretBox("$dir/third/key"))->seal('x');
check('a server with another key: the same', [(new SecretBox("$dir/third/key"))->open($sealed), (new SecretBox("$dir/third/key"))->lost($sealed)], ['', true]);
$tampered = substr($sealed, 0, -4) . (substr($sealed, -4) === 'AAAA' ? 'BBBB' : 'AAAA');
check('a changed value does not open', $box->open($tampered), '');

// ---- the stores
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$meta = new SystemMetaRepository($db);
$meta->setJson(ShopSecrets::META, ['viva' => ['api_key' => 'OLD-PLAIN-KEY', 'base' => 'http://127.0.0.1:1']]);
$shop = new ShopSecrets($meta, $box);
check('a shop secret from before is still read', $shop->of('viva')['api_key'], 'OLD-PLAIN-KEY');
$shop->save('viva', ['client_secret' => 'CLIENT-SECRET-xyz']);
$raw = json_encode($meta->getJson(ShopSecrets::META));
check('saving seals the new one and the one from before; other values stay as they are', [str_contains($raw, 'OLD-PLAIN'), str_contains($raw, 'CLIENT-SECRET'), $shop->of('viva')['api_key'], $shop->of('viva')['client_secret'], $shop->of('viva')['base']], [false, false, 'OLD-PLAIN-KEY', 'CLIENT-SECRET-xyz', 'http://127.0.0.1:1']);
check('the screen sees the end only', $shop->view('viva')['client_secret'], ['set' => true, 'last4' => '-xyz', 'lost' => false]);
$restored = new ShopSecrets($meta, $elsewhere);
check('restored on another server: not set, to be entered again', [$restored->of('viva')['api_key'], $restored->view('viva')['api_key']], ['', ['set' => false, 'last4' => '', 'lost' => true]]);
$restored->save('viva', ['api_key' => 'NEW-KEY-1234']);
check('and entering it again works there', $restored->of('viva')['api_key'], 'NEW-KEY-1234');

$site = new SiteSettings($meta, "$dir/content", $box);
$site->raw('site_settings', $site->defaults());
$raw = $site->raw('site_settings', $site->defaults());
$site->save($raw, SiteSettings::formFromPost(['smtp_pass' => 'Mail-Pass-77', 'backup_remote_secret_key' => 'S3-SECRET-88', 'title' => 'T']) + ['storage_limit_mb' => 1024]);
$stored = (string)$meta->get('site_settings');
check('Settings: the SMTP password and the S3 secret are stored sealed', [str_contains($stored, 'Mail-Pass'), str_contains($stored, 'S3-SECRET'), substr_count($stored, 'gzenc:v1:')], [false, false, 2]);
$loaded = $site->load();
check('and read in plain text by the site', [$loaded['forms']['notifications']['smtp']['password'], $loaded['backup']['remote']['secret_key']], ['Mail-Pass-77', 'S3-SECRET-88']);
$site->setTitle('Other', null);
check('another change of the settings keeps them sealed and readable', [str_contains((string)$meta->get('site_settings'), 'Mail-Pass'), $site->load()['forms']['notifications']['smtp']['password']], [false, 'Mail-Pass-77']);
$form = $site->formValues($site->parse((string)$meta->get('site_settings')));
check('the form knows it is set, never its value', [$form['smtp_pass'], $form['smtp_pass_set'], $form['smtp_pass_lost']], ['', true, false]);
$there = new SiteSettings($meta, "$dir/content", $elsewhere);
$form = $there->formValues($there->parse((string)$meta->get('site_settings')));
check('restored elsewhere: empty for the site, and the form asks for it again', [$there->load()['forms']['notifications']['smtp']['password'], $form['smtp_pass_set'], $form['smtp_pass_lost']], ['', false, true]);

$ai = new AiSettings($meta, $box);
$ai->saveSettings(['openai_key' => 'sk-test-ABCD1234']);
check('the AI provider\'s key is stored sealed and read in plain text', [str_contains(json_encode($meta->getJson(AiSettings::META)), 'sk-test'), $ai->key(), $ai->view()['key_hint']], [false, 'sk-test-ABCD1234', '…1234']);
check('elsewhere it is no key', (new AiSettings($meta, $elsewhere))->hasKey(), false);

// ---- backups
$exclude = new ReflectionMethod(BackupService::class, 'shouldExclude');
$backups = new BackupService($dir, $db);
check('a backup never carries the key', [$exclude->invoke($backups, 'storage/key'), $exclude->invoke($backups, 'storage/form-uploads/a.pdf')], [true, false]);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
