<?php
/*
 * Official repository rename: effective settings migrate without writes; custom update sources remain intact.
 *   php tests/unit/update-source.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{SiteSettings, SystemDatabase, SystemMetaRepository, UpdateService};
use Symfony\Component\Yaml\Yaml;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual) . ' expected ' . json_encode($expected)) . "\n"; }

$dir = sys_get_temp_dir() . '/gnozio-update-source-' . bin2hex(random_bytes(4));
mkdir($dir . '/storage', 0775, true);
$db = new SystemDatabase($dir . '/storage');
$db->initialize();
$meta = new SystemMetaRepository($db);
$site = new SiteSettings($meta, $dir . '/content');
$service = static fn(array $settings): UpdateService => new UpdateService($dir, $settings, $meta);
$current = [
    'repository' => 'chiotis/gnoziocms', 'branch' => 'main',
    'version_url' => 'https://raw.githubusercontent.com/chiotis/gnoziocms/main/VERSION',
    'changelog_url' => 'https://raw.githubusercontent.com/chiotis/gnoziocms/main/CHANGELOG.md',
    'package_url' => 'https://github.com/chiotis/gnoziocms/archive/refs/heads/main.zip',
];
$release = 'https://github.com/chiotis/gnoziocms/releases/latest/download/release.json';
$old = [
    'repository' => 'chiotis/faroscms', 'branch' => 'main',
    'version_url' => 'https://raw.githubusercontent.com/chiotis/faroscms/main/VERSION',
    'changelog_url' => 'https://raw.githubusercontent.com/chiotis/faroscms/main/CHANGELOG.md',
    'package_url' => 'https://github.com/chiotis/faroscms/archive/refs/heads/main.zip',
    'release_url' => 'https://github.com/chiotis/faroscms/releases/latest/download/release.json',
    'github_token' => 'saved-test-token', 'channel' => 'preview', 'latest_version' => '1.2.3',
];

check('a standalone update service defaults to the new official repository', $service([])->sourceConfig(), $current);
check('its release manifest uses the same repository', $service([])->releaseManifestUrl(), $release);
check('blank repository, branch and endpoints still use the defaults', $service(array_fill_keys(array_merge(array_keys($current), ['release_url']), ' '))->sourceConfig(), $current);
check('normalizing stored settings keeps automatic endpoints blank', array_values(array_intersect_key(UpdateService::normalizeSettings(['repository' => 'chiotis/faroscms']), array_flip(['version_url', 'changelog_url', 'package_url', 'release_url']))), ['', '', '', '']);
check('the legacy official source migrates as a whole', $service($old)->sourceConfig(), $current);
check('an explicit legacy release manifest migrates too', $service($old)->releaseManifestUrl(), $release);
check('new repository settings with leftover legacy URLs agree', $service(['repository' => 'chiotis/gnoziocms'] + $old)->sourceConfig(), $current);
$normalized = UpdateService::normalizeSettings($old + ['custom_key' => ['unchanged']]);
check('normalization leaves tokens, channel, configured version and other keys alone', [$normalized['github_token'], $normalized['channel'], $normalized['latest_version'], $normalized['custom_key']], ['saved-test-token', 'preview', '1.2.3', ['unchanged']]);
check('normalization is idempotent', UpdateService::normalizeSettings($normalized), $normalized);

$branch = [
    'repository' => 'chiotis/faroscms', 'branch' => 'release/v1',
    'version_url' => 'https://raw.githubusercontent.com/chiotis/faroscms/release%2Fv1/VERSION',
    'changelog_url' => 'https://raw.githubusercontent.com/chiotis/faroscms/release%2Fv1/CHANGELOG.md',
    'package_url' => 'https://github.com/chiotis/faroscms/archive/refs/heads/release%2Fv1.zip',
];
$currentBranch = [
    'repository' => 'chiotis/gnoziocms', 'branch' => 'release/v1',
    'version_url' => 'https://raw.githubusercontent.com/chiotis/gnoziocms/release%2Fv1/VERSION',
    'changelog_url' => 'https://raw.githubusercontent.com/chiotis/gnoziocms/release%2Fv1/CHANGELOG.md',
    'package_url' => 'https://github.com/chiotis/gnoziocms/archive/refs/heads/release%2Fv1.zip',
];
check('an explicit official branch and its encoded URLs survive the rename', $service($branch)->sourceConfig(), $currentBranch);
check('missing official URLs derive from the selected branch', $service(['repository' => 'chiotis/faroscms', 'branch' => 'release/v1'])->sourceConfig(), $currentBranch);
check('an explicitly saved main endpoint stays on main even with another branch selected', $service(['branch' => 'develop'] + $old)->sourceConfig(), array_replace($current, ['branch' => 'develop']));

$fork = [
    'repository' => 'acme/faroscms', 'branch' => 'release/v1',
    'version_url' => 'https://raw.githubusercontent.com/acme/faroscms/release%2Fv1/VERSION',
    'changelog_url' => 'https://raw.githubusercontent.com/acme/faroscms/release%2Fv1/CHANGELOG.md',
    'package_url' => 'https://github.com/acme/faroscms/archive/refs/heads/release%2Fv1.zip',
];
check('a custom fork derives URLs from its own repository and branch', $service(['repository' => 'acme/faroscms', 'branch' => 'release/v1'])->sourceConfig(), $fork);
check('a custom fork keeps an explicitly configured official URL', $service(['repository' => 'acme/faroscms'] + $old)->sourceConfig(), ['repository' => 'acme/faroscms'] + array_diff_key($old, array_flip(['release_url', 'github_token', 'channel', 'latest_version'])));
check('a custom fork keeps an explicitly configured official release URL', $service(['repository' => 'acme/faroscms'] + $old)->releaseManifestUrl(), $old['release_url']);
check('a similarly named repository is not the official repository', $service(['repository' => 'chiotis/faroscms-extra'])->sourceConfig()['repository'], 'chiotis/faroscms-extra');

$custom = [
    'repository' => 'chiotis/faroscms', 'branch' => 'main',
    'version_url' => $old['version_url'] . '?custom=1',
    'changelog_url' => 'https://updates.example.test/chiotis/faroscms/CHANGELOG.md',
    'package_url' => 'https://github.com/chiotis/faroscms/releases/download/v0.1.150/faroscms-0.1.150.zip',
    'release_url' => 'https://github.com/chiotis/faroscms/releases/download/v0.1.150/release.json',
];
check('custom URL queries, hosts and pinned packages are not rewritten', $service($custom)->sourceConfig(), ['repository' => 'chiotis/gnoziocms'] + array_diff_key($custom, ['release_url' => true]));
check('a pinned release manifest is not rewritten', $service($custom)->releaseManifestUrl(), $custom['release_url']);
check('an unrelated explicit branch URL is not rewritten', $service(['version_url' => 'https://raw.githubusercontent.com/chiotis/faroscms/pinned/VERSION'] + $old)->sourceConfig()['version_url'], 'https://raw.githubusercontent.com/chiotis/faroscms/pinned/VERSION');

// Read the old persisted YAML exactly as an upgraded site would, including the settings form's raw parsed overlay.
$raw = "# Existing installation\n" . Yaml::dump(['title' => 'A client site', 'updates' => $old], 4, 2);
$meta->set('site_settings', $raw);
$loaded = $site->load();
$form = $site->formValues($site->parse($raw));
check('site settings and direct callers resolve the same migrated source', $service($loaded['updates'])->sourceConfig(), $current);
check('the settings form shows canonical effective URLs even over parsed legacy YAML', array_map(static fn(string $key): string => $form['update_' . $key], array_keys($current)), array_values($current));
check('the settings form shows the canonical release URL and masks the token', [$form['update_release_url'], $form['update_github_token'], $form['update_github_token_set']], [$release, '', true]);
check('loading and displaying the settings never rewrite saved YAML', $meta->get('site_settings'), $raw);
check('explicit settings save succeeds', $site->save($raw, SiteSettings::formFromPost($form)), true);
$saved = $site->parse((string)$meta->get('site_settings'));
check('the explicit save persists the canonical repository and URLs', [$saved['updates']['repository'], $saved['updates']['version_url'], $saved['updates']['release_url']], ['chiotis/gnoziocms', $current['version_url'], $release]);
check('the explicit save preserves the stored token and update preferences', [$saved['updates']['github_token'], $saved['updates']['channel'], $saved['updates']['latest_version']], ['saved-test-token', 'preview', '1.2.3']);

$meta->set('site_settings', $raw);
$site->setSeo(['separator' => '/']);
check('saving another settings section does not persist an incidental update migration', $site->parse((string)$meta->get('site_settings'))['updates'], $old);
$forkRaw = Yaml::dump(['title' => 'A forked site', 'updates' => ['repository' => 'acme/faroscms', 'branch' => 'release/v1', 'github_token' => 'fork-test-token']]);
$meta->set('site_settings', $forkRaw);
$forkForm = $site->formValues($site->parse($forkRaw));
check('missing fork URLs are resolved before official site defaults can fill them', $service($site->load()['updates'])->sourceConfig(), $fork);
check('the settings form keeps automatic fork endpoints blank', [$forkForm['update_repository'], $forkForm['update_branch'], $forkForm['update_version_url'], $forkForm['update_changelog_url'], $forkForm['update_package_url'], $forkForm['update_release_url']], ['acme/faroscms', 'release/v1', '', '', '', '']);
check('reading the fork settings also leaves stored YAML intact', $meta->get('site_settings'), $forkRaw);
$site->save($forkRaw, SiteSettings::formFromPost($forkForm));
$forkSaved = $site->parse((string)$meta->get('site_settings'));
check('saving the fork preserves its branch, endpoints and token', [$service($forkSaved['updates'])->sourceConfig(), $forkSaved['updates']['github_token']], [$fork, 'fork-test-token']);
check('saving keeps automatic endpoints blank in the persisted settings', [$forkSaved['updates']['version_url'], $forkSaved['updates']['release_url']], ['', '']);
$nextForm = $site->formValues($forkSaved);
$nextForm['update_repository'] = 'other/custom-cms';
$nextForm['update_branch'] = 'next';
$site->save((string)$meta->get('site_settings'), SiteSettings::formFromPost($nextForm));
$next = $service($site->load()['updates']);
check('changing repository and branch after a save still updates automatic URLs', [$next->sourceConfig()['version_url'], $next->releaseManifestUrl()], ['https://raw.githubusercontent.com/other/custom-cms/next/VERSION', 'https://github.com/other/custom-cms/releases/latest/download/release.json']);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
