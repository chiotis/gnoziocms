<?php
/*
 * Checks the address helpers: Greek to Latin conversion (src/Slug.php) and the redirect store
 * (src/RedirectRepository.php), using a throwaway database. Run it after changing either:
 *
 *   php scripts/check-slugs.php
 */
require __DIR__ . '/../vendor/autoload.php';
use GnozioCMS\{Slug, SystemDatabase, RedirectRepository as R};

$bad = 0;
function t(string $label, bool $ok, $detail = ''): void { global $bad; if (!$ok) { $bad++; } echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n"; }

$cases = ['Καλημέρα κόσμε' => 'kalimera-kosme', 'Ουρανός' => 'ouranos', 'Μπάμπης και Ντίνα' => 'bampis-kai-dina', 'Αγγελος' => 'angelos', 'Υπηρεσίες Διαχείρισης Έργων' => 'ypiresies-diacheirisis-ergon',
 'Ευχαριστούμε' => 'efcharistoume', 'Ευρώπη' => 'evropi', 'Γκάζι' => 'gazi', 'Λάμπα' => 'lampa', 'Παϊδάκια' => 'paidakia', 'Café & Crème!' => 'cafe-creme', '   ' => '', '日本語' => '', 'Τι είναι το "workplace";' => 'ti-einai-to-workplace', 'Ψάρι Χταπόδι' => 'psari-chtapodi', 'Ελλάδα 2026' => 'ellada-2026'];
foreach ($cases as $in => $want) { $got = Slug::fromText((string)$in); t("slug: $in => $want", $got === $want, $got); }
t('slug: long titles are cut at a word', strlen(Slug::fromText(str_repeat('Πολύ μεγάλος τίτλος ', 12))) <= Slug::MAX_LENGTH);
t('slug: reserved words', Slug::isReserved('admin') && Slug::isReserved('posts', ['posts']) && !Slug::isReserved('about'));

$dir = sys_get_temp_dir() . '/rr' . getmypid(); mkdir($dir . '/db', 0775, true);
$db = new SystemDatabase($dir); $db->initialize(); $r = new R($db);
t('available', $r->isAvailable());
t('normalize', [R::normalizePath('/EN/About/?x=1#y'), R::normalizePath('/%CE%B1%CE%B2/'), R::normalizePath('//a//b/')] === ['en/about', 'αβ', 'a/b']);
t('canonical', [R::canonicalTarget('en/about/'), R::canonicalTarget('/en/x#p'), R::canonicalTarget('https://a.test/x')] === ['/en/about', '/en/x#p', 'https://a.test/x']);
t('validate empty', $r->validate('', '/x', 301) === 'source_empty');
t('validate admin', $r->validate('/admin/x', '/x', 301) === 'source_admin');
t('validate js', $r->validate('/a', 'javascript:alert(1)', 301) === 'target_scheme');
t('validate relative', $r->validate('/a', 'b', 301) === 'target_relative');
t('validate same', $r->validate('/a', '/A/', 301) === 'same');
t('validate ok', $r->validate('/a', 'https://x.test/y', 302) === null);
t('validate code', $r->validate('/a', '/b', 307) === 'code');
$r->create('/old', '/new', 301, 'manual', '', 'me');
t('resolve simple', $r->resolve('/OLD/')['target'] === '/new');
t('loop rejected', $r->validate('/new', '/old', 301) === 'loop');
$r->create('/new', '/newer', 301, 'manual', '', 'me');
t('chain followed', $r->resolve('old')['target'] === '/newer');
$r->moved('newer', 'newest', 'me', 'pages');
t('resolves through to newest', $r->resolve('old')['target'] === '/newest', $r->resolve('old'));
$r->moved('newest', 'old', 'me');
t('moving back removes loop source', $r->findBySource('old') === null || $r->findBySource('old')['target'] !== '/old');
t('resolve none', $r->resolve('nothing') === null);
$r->recordNotFound('/Missing', 'https://ex.test/p'); $r->recordNotFound('/missing', '');
$nf = $r->notFound(); t('404 log counts', count($nf) === 1 && (int)$nf[0]['hits'] === 2 && $nf[0]['last_referrer'] === 'https://ex.test/p', $nf);
$r->create('/missing', '/x', 301, 'manual', '', 'me'); t('creating clears 404 row', $r->notFoundCount() === 0);
$id = $r->findBySource('missing')['id']; $r->update((int)$id, 'missing', '/y', 302, false, 'n');
t('disabled not resolved', $r->resolve('missing') === null && $r->findBySource('missing', false)['status_code'] == 302);
t('filters', $r->count(['q' => 'MISS']) === 1 && $r->count(['state' => 'off']) === 1 && $r->count(['origin' => 'auto']) >= 0);
t('percent in query escaped', $r->count(['q' => '%']) === 0);
$r->recordHit((int)$id); t('hit', (int)$r->find((int)$id)['hits'] === 1);
echo $bad ? "\n$bad FAILED\n" : "\nALL PASSED\n";
exit($bad ? 1 : 0);
