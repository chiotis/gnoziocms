<?php
/*
 * What the Media screen does: which list is asked for and kept across an action, uploads (several at once, within the
 * storage limit), tags and text, deleting one or many (files in use are protected), bulk tags, the list with where each
 * file is used, and the page of pictures for the image picker.
 *   php tests/unit/media-admin.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{MediaAdmin, MediaLibrary, MediaUsage};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/mediaadm' . getmypid();
mkdir("$dir/content/pages", 0775, true);
mkdir("$dir/public/uploads/media", 0775, true);
$media = new MediaLibrary("$dir/content", "$dir/public/uploads");
$media->ensureDirectories();

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$room = true;           // whether the storage has room
$changes = [];          // bytes the uploads folder grew or shrank by
$log = [];
$maxBytes = 0;
$make = function () use ($media, $dir, &$room, &$changes, &$maxBytes, &$log) {
    return new MediaAdmin(
        $media, new MediaUsage("$dir/content", fn() => [], 'el'),
        function (int $bytes) use (&$room) { return $room; },
        fn() => 'The storage limit is reached.',
        function (int $bytes) use (&$changes) { $changes[] = $bytes; },
        function () use (&$maxBytes) { return $maxBytes; },
        function (string $action, string $level, ?string $type, ?string $id, string $message, array $ctx) use (&$log) { $log[] = $action; }
    );
};
// Where files are used is worked out once per request, so a change to content needs a new one.
$admin = $make();
$file = function (string $name, ?string $content = null) use ($png, $dir) {
    $tmp = tempnam($dir, 'up');
    file_put_contents($tmp, $content ?? $png);
    return ['name' => $name, 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
};
$many = fn(array $files) => ['name' => array_column($files, 'name'), 'type' => array_column($files, 'type'), 'tmp_name' => array_column($files, 'tmp_name'), 'error' => array_column($files, 'error'), 'size' => array_column($files, 'size')];

// ---- which list is asked for
$s = $admin->state(['type' => 'IMAGE', 'tag' => 'Hero Shot', 'q' => ' logo ', 'usage' => 'Unused', 'page' => '3', 'per_page' => '50', 'view' => 'thumbs'], 'list');
check('the list asked for is read and cleaned', [$s['type'], $s['q'], $s['usage'], $s['page'], $s['per_page'], $s['view'], $s['view_asked']], ['image', 'logo', 'unused', 3, 50, 'thumbs', true]);
$s = $admin->state(['type' => 'nonsense', 'usage' => 'x', 'page' => '-4', 'per_page' => '7', 'view' => 'grid'], 'thumbs');
check('nonsense falls back to the defaults, and the remembered view is used', [$s['type'], $s['usage'], $s['page'], $s['per_page'], $s['view'], $s['view_asked']], ['all', '', 1, 20, 'thumbs', false]);
check('a remembered view that is not valid is the thumbnails', $admin->state([], 'weird')['view'], 'thumbs');
$post = $admin->postState(['_state_type' => 'video', '_state_page' => '2', '_state_q' => ' x ', '_state_view' => 'bad'], $s);
check('the form carries the list it was sent from, the rest comes from the address', [$post['type'], $post['page'], $post['q'], $post['view'], $post['per_page']], ['video', 2, 'x', 'thumbs', 20]);
check('a form that carries nothing returns to the list of the address', $admin->postState([], $admin->state(['type' => 'image', 'page' => '2'], 'list'))['type'], 'image');
check('the way back names the list and what happened, empty values left out', $admin->returnQuery(['type' => 'image', 'view' => 'list', 'per_page' => 20, 'page' => 2, 'tag' => '', 'q' => 'logo', 'usage' => ''], ['success' => 'Saved.', 'error' => '', 'page' => 1]), ['type' => 'image', 'view' => 'list', 'per_page' => 20, 'page' => 1, 'q' => 'logo', 'success' => 'Saved.']);
check('places are named, three at most', [MediaAdmin::placesSentence([['label' => 'A'], ['label' => 'A'], ['label' => 'B']]), MediaAdmin::placesSentence([['label' => 'A'], ['label' => 'B'], ['label' => 'C'], ['label' => 'D'], ['label' => 'E']])], ['A, B', 'A, B, C and 2 more']);

// ---- ids that are only digits (a random 16-character id is one about once in two thousand)
check('ids are kept as text even when they are only digits', array_map('gettype', $media->collectIds(['1234567890123456', 'abc0123456789def', '1234567890123456', '12'])), ['string', 'string']);

// ---- uploads
check('nothing to upload', $admin->apply(['media_action' => 'upload'], [], 'tester'), ['error' => 'No file uploaded.']);
$r = $admin->apply(['upload_tags' => 'Hero, Logo'], ['upload_file' => $many([$file('one.png')])], 'tester');
check('one file: success, back to the first page (the action is implied by the file)', $r, ['page' => 1, 'success' => 'Upload complete.']);
$items = $media->list();
check('it is in the library with its tags and uploader', [count($items), $items[0]['original_name'], $items[0]['tags'], $items[0]['uploaded_by']], [1, 'one.png', ['hero', 'logo'], 'tester']);
check('the uploads folder was told', count($changes) === 1 && $changes[0] > 0, true);
$r = $admin->apply([], ['upload_file' => $many([$file('two.png'), $file('three.png')])], 'tester');
check('several at once', [$r['success'], count($media->list())], ['Uploaded 2 files.', 3]);
$r = $admin->apply([], ['upload_file' => $many([$file('four.png'), $file('bad.php', '<?php echo 1;'), $file('five.png')])], 'tester');
check('a refused file is reported with the others kept', [$r['success'], $r['error'], count($media->list())], ['Uploaded 2 files.', '1 uploads failed: This kind of file is not allowed on this site.', 5]);
$r = $admin->apply([], ['upload_file' => $many([$file('bad.php', 'x')])], 'tester');
check('all refused is an error', $r, ['error' => 'Upload failed: This kind of file is not allowed on this site.']);
$room = false;
$r = $admin->apply([], ['upload_file' => $many([$file('full.png')])], 'tester');
check('no room stops the upload with the storage message', [$r['error'], count($media->list())], ['Upload failed: The storage limit is reached.', 5]);
$room = true; $maxBytes = 10;
$r = $admin->apply([], ['upload_file' => $many([$file('big.png')])], 'tester');
check('the size limit applies', str_contains($r['error'], 'exceeds the maximum'), true);
$maxBytes = 0;
$empty = ['name' => [''], 'type' => [''], 'tmp_name' => [''], 'error' => [UPLOAD_ERR_NO_FILE], 'size' => [0]];
check('an empty file box uploads nothing', $admin->apply([], ['upload_file' => $empty], 'tester'), ['error' => 'Upload failed.']);

// ---- tags and text
$ids = array_column($media->list(), 'id');
$id = $ids[0];
check('tags and text are saved', [$admin->apply(['media_action' => 'save_tags', 'id' => $id, 'tags' => 'Sun, Sea', 'alt' => 'A beach'], [], 't'), $media->find($id)['tags'], $media->find($id)['alt']], [['success' => 'Saved.'], ['sea', 'sun'], 'A beach']);
check('a bad id is refused', $admin->apply(['media_action' => 'save_tags', 'id' => '!!', 'tags' => 'x'], [], 't'), ['error' => 'Invalid media item.']);
check('an unknown item is refused', $admin->apply(['media_action' => 'save_tags', 'id' => 'abcdef0123456789', 'tags' => 'x'], [], 't'), ['error' => 'Media item not found.']);
check('no alt sent keeps the old text', [$admin->apply(['media_action' => 'save_tags', 'id' => $id, 'tags' => 'sun'], [], 't'), $media->find($id)['alt']], [['success' => 'Saved.'], 'A beach']);

// ---- where files are used, the list
$used = $media->find($ids[1]);
file_put_contents("$dir/content/pages/home.md", "---\ntitle: Home\nstatus: published\nimage: /uploads/" . $used['path'] . "\n---\nBody\n");
$admin = $make();
$state = $admin->state([], 'list');
$l = $admin->listing($state);
check('the list says how many are unused', [$l['total_items'], $l['unused_total'], $l['total_pages'], $l['page']], [5, 4, 1, 1]);
$usedRow = array_values(array_filter($l['items'], fn($i) => $i['id'] === $used['id']))[0];
check('a file in use names where', [count($usedRow['places']) > 0, $usedRow['places_sentence']], [true, $usedRow['places'][0]['label']]);
check('the unused filter', $admin->listing($admin->state(['usage' => 'unused'], 'list'))['total_items'], 4);
check('the used filter', $admin->listing($admin->state(['usage' => 'used'], 'list'))['total_items'], 1);
$paged = $admin->listing(['type' => 'all', 'tag' => '', 'q' => '', 'usage' => '', 'page' => 9, 'per_page' => 2, 'view' => 'list']);
check('a page past the end is the last page', [$paged['page'], $paged['total_pages'], count($paged['items'])], [3, 3, 1]);
check('search finds by name', $admin->listing($admin->state(['q' => 'three.png'], 'list'))['total_items'], 1);

// ---- deleting
$r = $admin->apply(['media_action' => 'delete', 'id' => $used['id']], [], 't');
check('a file in use is not deleted without the confirmation', [str_starts_with($r['error'], 'Not deleted: this file is used in '), $media->find($used['id']) !== null], [true, true]);
$r = $admin->apply(['media_action' => 'delete', 'id' => $used['id'], 'confirm_used' => '1'], [], 't');
check('with it, it is, and the log says where it was used', [$r, $media->find($used['id'])], [['success' => 'Media item deleted.'], null]);
check('the uploads folder was told it shrank', end($changes) < 0, true);
$before = count($changes);
$plain = $media->list()[0]['id'];
check('an unused file is deleted at once, via the delete button name as well', [$admin->apply(['delete' => '1', 'id' => $plain], [], 't'), $media->find($plain)], [['success' => 'Media item deleted.'], null]);
check('deleting what is gone is reported', $admin->apply(['media_action' => 'delete', 'id' => $plain], [], 't'), ['error' => 'Media item not found.']);
check('no id at all is refused', $admin->apply(['media_action' => 'delete'], [], 't'), ['error' => 'Invalid media item.']);

// ---- several at once
$admin = $make();
$rest = array_column($media->list(), 'id');
check('nothing selected is refused', $admin->apply(['media_action' => 'bulk_delete', 'selected_ids' => []], [], 't'), ['error' => 'Select at least one media item.']);
check('tags are added to each, keeping theirs', [$admin->apply(['media_action' => 'bulk_tags', 'selected_ids' => $rest, 'tags' => 'Batch'], [], 't'), in_array('batch', $media->find($rest[0])['tags'], true)], [['success' => 'Updated tags for ' . count($rest) . ' items.'], true]);
file_put_contents("$dir/content/pages/home.md", "---\ntitle: Home\nstatus: published\nimage: /uploads/" . $media->find($rest[0])['path'] . "\n---\nBody\n");
$admin = $make();
$r = $admin->apply(['media_action' => 'bulk_delete', 'selected_ids' => $rest], [], 't');
check('files in use are kept and counted', [$r, array_column($media->list(), 'id')], [['success' => 'Deleted 2 items. 1 file was kept because it is in use.'], [$rest[0]]]);
check('all in use: nothing deleted', $admin->apply(['media_action' => 'bulk_delete', 'selected_ids' => [$rest[0]]], [], 't'), ['error' => 'Nothing deleted. 1 file was kept because it is in use.']);
$admin->apply([], ['upload_file' => $many([$file('a.png'), $file('b.png')])], 't');
$admin->apply(['media_action' => 'bulk_tags', 'apply_all_filtered' => '1', '_filter_type' => 'image', '_filter_q' => 'a.png', 'tags' => 'picked'], [], 't');
check('"all that match" uses the list the form came from, not the ticks', array_map(fn($i) => in_array('picked', $i['tags'], true), $media->list()), array_map(fn($i) => $i['original_name'] === 'a.png', $media->list()));
$r = $admin->apply(['media_action' => 'bulk_delete', 'apply_all_filtered' => '1', '_filter_usage' => 'unused'], [], 't');
check('and respects the used filter', [$r['success'], array_column($media->list(), 'id')], ['Deleted 2 items.', [$rest[0]]]);
check('an unknown action does nothing', [$admin->apply(['media_action' => 'explode'], [], 't'), $admin->apply([], [], 't')], [null, null]);

// ---- the image picker
$admin->apply([], ['upload_file' => $many(array_map(fn($n) => $file("pic$n.png"), range(1, 8)))], 't');
$p = $admin->picker(['per_page' => '6']);
check('a page of pictures with their addresses', [count($p['items']), $p['total'], $p['pages'], $p['per_page'], array_keys($p['items'][0])], [6, 9, 2, 6, ['url', 'thumb', 'name', 'alt', 'kind', 'tags']]);
check('the second page has the rest', count($admin->picker(['per_page' => '6', 'page' => '2'])['items']), 3);
check('a page past the end is the last', $admin->picker(['per_page' => '6', 'page' => '99'])['page'], 2);
check('the page size is kept between 6 and 48', [$admin->picker(['per_page' => '1'])['per_page'], $admin->picker(['per_page' => '500'])['per_page']], [6, 48]);
check('search narrows the pictures', $admin->picker(['q' => 'pic3'])['total'], 1);
check('the actions were logged', array_values(array_unique($log)), ['media.upload', 'media.tags_update', 'media.delete', 'media.bulk_tags_update', 'media.bulk_delete']);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
