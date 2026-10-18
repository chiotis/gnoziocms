<?php
/*
 * Site settings: defaults, the legacy YAML read once, the values the form shows, and how a submitted form is
 * checked and saved (languages, menu locations, backups, secrets that are kept, replaced or cleared).
 *   php tests/unit/site-settings.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\SiteSettings;
use GnozioCMS\SystemDatabase;
use GnozioCMS\SystemMetaRepository;
use Symfony\Component\Yaml\Yaml;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/sitesettings' . getmypid();
mkdir($dir . '/storage', 0775, true);
mkdir($dir . '/content/settings', 0775, true);
$db = new SystemDatabase($dir . '/storage');
$db->initialize();
$meta = new SystemMetaRepository($db);
$s = new SiteSettings($meta, $dir . '/content');

// ---- defaults, and an old YAML file read once
file_put_contents($dir . '/content/settings/site.yaml', "title: Old site\ntagline: Old tag\nlanguages:\n  default: en\n  available: [en, el]\n");
$loaded = $s->load();
check('the old YAML file is read on first load', $loaded['title'], 'Old site');
check('defaults fill what the file lacks', $loaded['date_format'], 'd/m/Y');
check('and the value is now kept in the database', str_contains((string)$meta->get('site_settings'), 'Old site'), true);
unlink($dir . '/content/settings/site.yaml');
check('later loads come from the database', $s->load()['title'], 'Old site');
check('parse of blank text is empty', $s->parse('  '), []);
check('parse of broken text is empty', $s->parse("a: [\n"), []);

// ---- a list that shrinks stays shrunk
check('a list is the stored list: taking a language out keeps it out', SiteSettings::withDefaults(['languages' => ['default' => 'el', 'available' => ['el', 'en']]], ['languages' => ['available' => ['el']]])['languages'], ['default' => 'el', 'available' => ['el']]);
check('groups still merge by name, and an empty stored list leaves the default', SiteSettings::withDefaults(['a' => ['x' => 1, 'y' => 2], 'l' => ['p', 'q']], ['a' => ['y' => 3], 'l' => []]), ['a' => ['x' => 1, 'y' => 3], 'l' => ['p', 'q']]);
check('a stored value is not changed by a default of another kind', SiteSettings::withDefaults(['k' => ['a', 'b']], ['k' => 'text']), ['k' => 'text']);
$meta->set('site_settings', Yaml::dump(['title' => 'One language', 'languages' => ['default' => 'el', 'available' => ['el']]]));
check('so a site saved with only its own language loads with only it', $s->load()['languages'], ['default' => 'el', 'available' => ['el']]);
$meta->set('site_settings', Yaml::dump(['title' => 'Old site', 'tagline' => 'Old tag', 'languages' => ['default' => 'en', 'available' => ['en', 'el']]]));

// ---- the form values
$form = $s->formValues([]);
check('form shows the title', $form['title'], 'Old site');
check('languages are shown as a list', $form['languages_available'], 'en, el');
check('no secret is sent to the browser', $form['smtp_pass'], '');
check('and the form learns none is stored', $form['smtp_pass_set'], false);
check('the form no longer carries the places of menus (the menu editor sets them)', array_values(preg_grep('/^menu_location/', array_keys($form))), []);
check('the storage limit shows', $form['storage_limit_mb'], 1024);

// ---- saving
$base = [
    'title' => ' New title ', 'tagline' => '', 'base_url' => 'https://x.test', 'theme' => '', 'home_page' => '', 'date_format' => '',
    'languages_default' => 'EL', 'languages_available' => 'en, De, en', 'mail_driver' => 'bogus', 'mail_from' => 'a@x.test', 'mail_from_name' => '',
    'smtp_host' => 'smtp.x.test', 'smtp_port' => '587', 'smtp_user' => 'u', 'smtp_pass' => 'secret1', 'smtp_encryption' => 'tls',
    'ses_key' => '', 'ses_secret' => '', 'ses_region' => '',
    'backup_schedule' => 'hourly', 'backup_keep_local' => '0', 'backup_remote_provider' => 'nowhere', 'backup_remote_keep' => '-3', 'backup_remote_prefix' => '/site/',
    'google_allowed_domain' => ' EXAMPLE.com ', 'update_repository' => '', 'update_branch' => '',
];
$s->setMenuLocation('footer', 'extra-menu');
$before = $s->load();
$raw = $s->raw('site_settings', $s->defaults());
check('saving works', $s->save($raw, $base), true);
$d = $s->load();
check('title is trimmed', $d['title'], 'New title');
check('a blank tagline keeps the old one', $d['tagline'], 'Old tag');
check('blank theme keeps the old one', $d['theme'], 'default');
check('languages: default in the list, names made into slugs (lower case), no repeats', $d['languages'], ['default' => 'el', 'available' => ['el', 'en', 'de']]);
check('an unknown mail driver falls back to smtp', $d['forms']['notifications']['driver'], 'smtp');
check('the port becomes a number', $d['forms']['notifications']['smtp']['port'], 587);
check('saving the form leaves the places of menus as they were', $d['menu_locations'] ?? [], $before['menu_locations'] ?? []);
check('an unknown schedule falls back to daily', $d['backup']['auto']['schedule'], 'daily');
check('keep is at least one', [$d['backup']['local']['keep'], $d['backup']['remote']['keep']], [1, 1]);
check('an unknown provider falls back to custom', $d['backup']['remote']['provider'], 'custom');
check('the prefix loses its slashes', $d['backup']['remote']['prefix'], 'site');
check('the allowed domain is lower case', $d['auth']['google']['allowed_domain'], 'example.com');
check('update repository falls back', [$d['updates']['repository'], $d['updates']['branch']], ['chiotis/faroscms', 'main']);
check('the smtp password was stored', $d['forms']['notifications']['smtp']['password'], 'secret1');

// ---- secrets: blank keeps, new replaces, clear removes
$again = $base; $again['smtp_pass'] = '';
$s->save($s->raw('site_settings', []), $again);
check('a blank secret keeps the stored one', $s->load()['forms']['notifications']['smtp']['password'], 'secret1');
check('the form knows one is stored', $s->formValues([])['smtp_pass_set'], true);
check('but never shows it', $s->formValues([])['smtp_pass'], '');
$again['smtp_pass'] = 'secret2';
$s->save($s->raw('site_settings', []), $again);
check('a new secret replaces it', $s->load()['forms']['notifications']['smtp']['password'], 'secret2');
$again['smtp_pass'] = ''; $again['clear_secrets'] = ['smtp_pass'];
$s->save($s->raw('site_settings', []), $again);
check('clearing removes it', $s->load()['forms']['notifications']['smtp']['password'], '');
check('the update token is never kept in the updates block', $s->load()['updates']['github_token'], '');

// ---- robots box: left out is not the same as emptied
$withRobots = $base; $withRobots['robots_disallow'] = "/private\n/tmp";
$s->save($s->raw('site_settings', []), $withRobots);
check('robots rules are stored', $s->load()['seo']['robots_disallow'], ['/private', '/tmp']);
$s->save($s->raw('site_settings', []), $base);
check('a form without the box leaves them', $s->load()['seo']['robots_disallow'], ['/private', '/tmp']);
$withRobots['robots_disallow'] = '';
$s->save($s->raw('site_settings', []), $withRobots);
check('an emptied box removes them', isset($s->load()['seo']), false);

// ---- limits
$lim = $base; $lim['storage_limit_mb'] = '50'; $lim['upload_limit_mb'] = '5'; $lim['upload_types'] = 'images,documents';
$s->save($s->raw('site_settings', []), $lim);
$d = $s->load();
check('limits are stored', [$d['limits']['storage_mb'], $d['limits']['upload_mb'], $d['limits']['upload_types']], [50, 5, ['images', 'documents']]);

// ---- no database: nothing is saved
$dead = new SiteSettings(new SystemMetaRepository(new SystemDatabase('/nonexistent/' . getmypid())), $dir . '/content');
check('without a database, save says no', $dead->save('', $base), false);
check('without a database, load gives the defaults', $dead->load()['title'], 'GnozioCMS');

// ---- the form as it arrives
$f = SiteSettings::formFromPost(['title' => 'T', 'backup_auto_enabled' => 'on', 'google_enabled' => '', 'clear_secret' => ['smtp_pass', 7], 'robots_disallow' => "/a\nDisallow: /b\nhttps://x.test/c"]);
check('a ticked box is 1, one that was not sent is 0', [$f['backup_auto_enabled'], $f['backup_remote_enabled'], $f['backup_remote_path_style'], $f['backup_remote_keep_local'], $f['google_enabled']], ['1', '0', '0', '0', '1']);
check('text that was not sent is empty', [$f['title'], $f['tagline'], $f['smtp_host']], ['T', '', '']);
check('the robots rules are cleaned on the way in', $f['robots_disallow'], "/a\n/b");
check('a form without the robots box says so (null), so it does not clear them', SiteSettings::formFromPost([])['robots_disallow'], null);
check('the boxes to clear secrets are a list of text', $f['clear_secrets'], ['smtp_pass', '7']);
check('what needs permission is left to the caller', array_intersect(['storage_limit_mb', 'upload_limit_mb', 'upload_types'], array_keys($f)), []);
$saved = $s->save($s->raw('site_settings', []), SiteSettings::formFromPost(['title' => 'From post', 'languages_available' => 'el, en', 'languages_default' => 'el', 'backup_auto_enabled' => '1', 'backup_schedule' => 'weekly']));
check('and it saves as it is', [$saved, $s->load()['title'], $s->load()['backup']['auto']['enabled'], $s->load()['backup']['auto']['schedule'], $s->load()['backup']['remote']['enabled']], [true, 'From post', true, 'weekly', false]);

// ---- a menu put in a place of the theme, from the menu editor
check('a menu is put in a place', [$s->setMenuLocation('footer', 'extra'), $s->load()['menu_locations']['footer']], [true, 'extra']);
check('and the places already set stay', $s->load()['menu_locations']['header'], 'main');
check('a place made only of the settings is kept too', [$s->setMenuLocation('Side Bar', 'Other Menu'), $s->load()['menu_locations']['side-bar']], [true, 'other-menu']);
check('a place or a menu with no name is refused', [$s->setMenuLocation('', 'x'), $s->setMenuLocation('x', ' ')], [false, false]);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
