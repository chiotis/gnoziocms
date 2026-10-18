<?php
/*
 * The skill for other chats (skills/faroscms): that what it says about the blocks, the theme settings and the ready-made layouts is
 * what this version of the theme has, that its files hold together, and that its scripts agree with the CMS on the demo site.
 * When a block, a preset or a theme setting changes, run  python3 skills/faroscms/scripts/refresh_snapshot.py --root .
 *   php tests/unit/skill.php
 */
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$skill = $root . '/skills/faroscms';
$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

// ---- the skill's own description
$text = (string)file_get_contents($skill . '/SKILL.md');
preg_match('/\A---\nname: (.+)\ndescription: (.+)\n---\n/', $text, $m);
check('SKILL.md has a name equal to its folder and a description under the limit', [$m[1] ?? '', strlen($m[2] ?? '') > 200 && strlen($m[2] ?? '') <= 1024], ['faroscms', true]);
check('SKILL.md stays short enough to be read whole', substr_count($text, "\n") <= 250, true);
preg_match_all('#`((?:references|scripts)/[A-Za-z0-9_.-]+)`#', $text, $paths);
$missing = array_values(array_filter(array_unique($paths[1]), fn(string $p): bool => !is_file($skill . '/' . $p) && !is_file($root . '/' . $p)));
check('every file SKILL.md points to exists', $missing, []);
foreach (glob($skill . '/references/*.md') ?: [] as $file) {
    preg_match_all('#`((?:references|scripts)/[A-Za-z0-9_.-]+)`#', (string)file_get_contents($file), $inner);
    $gone = array_values(array_filter(array_unique($inner[1]), fn(string $p): bool => !is_file($skill . '/' . $p) && !is_file($root . '/' . $p)));
    check(basename($file) . ' points only to files that exist', $gone, []);
}

// ---- what it carries of the theme is what the theme has
$theme = $root . '/themes/default';
$snapshot = $skill . '/snapshot/themes/default';
$same = function (string $pattern) use ($theme, $snapshot): array {
    $differ = [];
    $names = array_unique(array_merge(
        array_map(fn($f) => substr($f, strlen($theme) + 1), glob($theme . '/' . $pattern) ?: []),
        array_map(fn($f) => substr($f, strlen($snapshot) + 1), glob($snapshot . '/' . $pattern) ?: [])
    ));
    foreach ($names as $name) {
        $a = @file_get_contents($theme . '/' . $name);
        $b = @file_get_contents($snapshot . '/' . $name);
        // Icons are placeholders: only their names count.
        if (str_starts_with($name, 'icons/') ? ($a === false) !== ($b === false) : $a !== $b) {
            $differ[] = $name;
        }
    }
    sort($differ);
    return $differ;
};
$refresh = 'run python3 skills/faroscms/scripts/refresh_snapshot.py --root .';
check("block definitions are the theme's ($refresh)", $same('blocks/*/block.yaml'), []);
check('theme settings are the theme\'s', $same('theme.yaml'), []);
check('ready-made layouts are the theme\'s', $same('presets/*.yaml'), []);
check('icon names are the theme\'s', $same('icons/*.svg'), []);

$blocks = (string)file_get_contents($skill . '/references/blocks.md');
$missingBlocks = [];
foreach (glob($theme . '/blocks/*/block.yaml') ?: [] as $file) {
    $type = basename(dirname($file));
    if (!str_contains($blocks, '### `' . $type . '` ')) {
        $missingBlocks[] = $type;
        continue;
    }
    $data = Symfony\Component\Yaml\Yaml::parseFile($file);
    foreach (array_keys($data['variants'] ?? []) as $variant) {
        if (!str_contains($blocks, '`' . $variant . '`')) {
            $missingBlocks[] = $type . '/' . $variant;
        }
    }
}
check('references/blocks.md describes every block and layout', $missingBlocks, []);
$settings = (string)file_get_contents($skill . '/references/theme-settings.md');
$manifest = Symfony\Component\Yaml\Yaml::parseFile($theme . '/theme.yaml');
$missingSections = array_values(array_filter(array_keys($manifest['settings'] ?? []), fn($k): bool => !str_contains($settings, '## `' . $k . '`')));
check('references/theme-settings.md has every section of the theme settings', $missingSections, []);

// ---- the scripts, against the demo site (needs Python 3 with PyYAML; skipped where there is none)
$python = trim((string)@shell_exec('command -v python3'));
if ($python === '' || trim((string)@shell_exec('python3 -c "import yaml" 2>&1')) !== '') {
    echo "note python3 with PyYAML is not here: the scripts of the skill were not run\n";
} else {
    $scripts = $skill . '/scripts';
    foreach (glob($scripts . '/*.py') ?: [] as $file) {
        $out = [];
        exec('python3 -m py_compile ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        check(basename($file) . ' compiles', $code, 0);
    }
    exec('find ' . escapeshellarg($scripts) . ' -name __pycache__ -prune -exec rm -rf {} + 2>/dev/null');

    // A site made of the demo content, the way use-starter.php makes one.
    $site = sys_get_temp_dir() . '/skill' . getmypid();
    mkdir($site . '/public', 0775, true);
    file_put_contents($site . '/VERSION', trim((string)file_get_contents($root . '/VERSION')) . "\n");
    symlink($theme . '/..', $site . '/themes');
    symlink($root . '/starter/content', $site . '/content');
    symlink($root . '/starter/uploads', $site . '/public/uploads');
    $run = function (string $command) use ($scripts, $site): array {
        $out = [];
        [$script, $arguments] = array_pad(explode(' ', $command, 2), 2, '');
        exec('cd ' . escapeshellarg($site) . ' && python3 ' . escapeshellarg($scripts . '/' . $script) . ' ' . $arguments . ' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    };
    [$code, $out] = $run('validate_content.py --root ' . escapeshellarg($site));
    check('the validator finds no error in the demo site', $code, 0);
    preg_match('/(\d+) files, (\d+) errors, (\d+) warnings/', $out, $n);
    check('and it read every demo file', (int)($n[1] ?? 0) > 40, true);
    [$code, $out] = $run('block_reference.py --root ' . escapeshellarg($site) . ' --list');
    check('block_reference lists every block', [$code, substr_count($out, "\n") + 1], [0, count(glob($theme . '/blocks/*/block.yaml'))]);
    [$code, $out] = $run('site_info.py --root ' . escapeshellarg($site));
    check('site_info reads the demo site', [$code, str_contains($out, 'pages'), str_contains($out, 'GnozioCMS ' . trim((string)file_get_contents($root . '/VERSION')))], [0, true, true]);

    // A page written the way the skill says reads back as the CMS reads it.
    $blocksFile = $site . '/blocks.yaml';
    file_put_contents($blocksFile, "- type: hero\n  variant: centered\n  heading: \"Γειά;\"\n  actions:\n    - { label: Go, url: contact }\n- type: faq\n  items:\n    - { question: \"Q?\", answer: \"A.\" }\n");
    $writable = $site . '/work';
    mkdir($writable);
    [$code, $out] = $run('new_entry.py --out ' . escapeshellarg($writable) . ' --type pages --title "Δοκιμή Σελίδας" --blocks ' . escapeshellarg($blocksFile) . ' --status draft');
    check('new_entry writes a page with a Latin address from a Greek title', [$code, is_file($writable . '/content/pages/dokimi-selidas.md')], [0, true]);
    $written = (string)@file_get_contents($writable . '/content/pages/dokimi-selidas.md');
    [$yaml] = GnozioCMS\FrontMatter::split($written);
    $meta = Symfony\Component\Yaml\Yaml::parse($yaml);
    check('and the CMS reads its blocks', array_column($meta['blocks'] ?? [], 'type'), ['hero', 'faq']);
    file_put_contents($blocksFile, "- type: hero\n  variant: huge\n- type: pricng\n");
    [$code, $out] = $run('new_entry.py --out ' . escapeshellarg($writable) . ' --type pages --title "Λάθος" --blocks ' . escapeshellarg($blocksFile));
    check('an unknown block type is refused and nothing is written', [$code, is_file($writable . '/content/pages/lathos.md')], [1, false]);

    // A container (rows with columns) is understood: a page with one is written and read, a good one passes and a wrong one is named.
    file_put_contents($blocksFile, "- type: hero\n  heading: Hi\n- type: container\n  settings: { gap: l, justify: center }\n  columns:\n    - settings: { width: 1/3 }\n      blocks:\n        - { type: text, heading: A }\n    - blocks:\n        - { type: cta, heading: B }\n");
    [$code, $out] = $run('new_entry.py --out ' . escapeshellarg($writable) . ' --type pages --title "Στήλες" --blocks ' . escapeshellarg($blocksFile) . ' --status draft');
    check('new_entry writes a page with a container', [$code, is_file($writable . '/content/pages/stiles.md')], [0, true]);
    [$yaml] = GnozioCMS\FrontMatter::split((string)@file_get_contents($writable . '/content/pages/stiles.md'));
    $meta = Symfony\Component\Yaml\Yaml::parse($yaml);
    check('and the CMS reads its columns and the blocks in them', [$meta['blocks'][1]['type'] ?? '', count($meta['blocks'][1]['columns'] ?? []), $meta['blocks'][1]['columns'][0]['blocks'][0]['type'] ?? ''], ['container', 2, 'text']);
    [$code, $out] = $run('validate_content.py --root ' . escapeshellarg($site) . ' ' . escapeshellarg($writable . '/content/pages/stiles.md'));
    check('the validator has nothing to say about a good container', str_contains($out, 'container'), false);
    $bad = "- type: container\n  settings: { gap: 3px, wide: yes }\n  columns:\n    - settings: { width: 9 apples, order: 40 }\n      blocks:\n        - { type: container, columns: [] }\n";
    file_put_contents($writable . '/content/pages/bad-container.md', "---\ntitle: Bad\nstatus: draft\nblocks:\n" . preg_replace('/^/m', '  ', $bad) . "---\n");
    [$code, $out] = $run('validate_content.py --root ' . escapeshellarg($site) . ' ' . escapeshellarg($writable . '/content/pages/bad-container.md'));
    check('and it names what is wrong in a bad one', [str_contains($out, 'container.gap'), str_contains($out, 'unknown setting `wide`'), str_contains($out, 'column.width'), str_contains($out, 'column.order'), str_contains($out, 'cannot be inside a column')], [true, true, true, true, true]);

    exec('rm -rf ' . escapeshellarg($site));
}

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
