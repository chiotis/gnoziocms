<?php
/*
 * Files attached to a form: what is accepted (size, number, kind, and that the content is what the name says, never a program or
 * a page), the names a pattern makes, the private folder they are kept in, their removal with the submission, the emails (with the
 * files attached, or only the links), and a backup that takes them along and brings them back.
 *   php tests/unit/form-uploads.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{BackupService, ContentItem, FormFields, FormProcessor, FormSubmissionRepository, FormUploads, Mailer, SystemDatabase};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/formuploads' . getmypid();
@mkdir($dir . '/content/pages', 0775, true);
@mkdir($dir . '/tmp', 0775, true);
$uploads = new FormUploads($dir . '/storage/form-uploads', true);
$say = fn(string $key, string $fallback): string => $fallback;

// A tiny PNG, a PDF, a text file; and the same bytes under other names.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$tmp = function (string $content, string $name = 'x') use ($dir): string { $path = $dir . '/tmp/' . $name . bin2hex(random_bytes(3)); file_put_contents($path, $content); return $path; };
$file = fn(string $name, string $content): array => ['name' => $name, 'tmp_name' => $tmp($content), 'size' => strlen($content), 'error' => UPLOAD_ERR_OK];
$field = fn(array $upload = [], array $extra = []): array => FormFields::normalize([['name' => 'cv', 'type' => 'file', 'label' => 'CV', 'upload' => $upload] + $extra])[0];

// ---- what a file field is
$f = $field();
check('a file field has settings filled in: one file, 10 MB, documents, pictures and archives', [$f['upload']['multiple'], $f['upload']['max'], $f['upload']['max_size_mb'], in_array('pdf', $f['upload']['extensions'], true), in_array('php', $f['upload']['extensions'], true)], [false, 1, 10.0, true, false]);
$stored = FormFields::parseInput([['type' => 'file', 'name' => 'cv', 'label' => 'CV', 'upload' => ['multiple' => '1', 'min' => '1', 'max' => '99', 'max_size_mb' => '2,5', 'total_mb' => '8', 'extensions' => 'PDF, .docx, php, svg, htaccess, ..', 'rename' => "cv-{field:name}\n"], 'button' => 'Pick', 'error_size' => 'Too big!']]);
check('it is stored cleaned: numbers kept in range, extensions lower case, programs and pages dropped', [$stored[0]['upload']['max'], $stored[0]['upload']['max_size_mb'], $stored[0]['upload']['total_mb'], $stored[0]['upload']['extensions'], $stored[0]['upload']['rename'], $stored[0]['button'], $stored[0]['error_size']], [20, 2.5, 8.0, ['pdf', 'docx'], 'cv-{field:name}', 'Pick', 'Too big!']);
check('a file field is not a text field: no placeholder, no options', isset($stored[0]['placeholder']) || isset($stored[0]['options']), false);
check('its texts are translatable', FormFields::normalize(FormFields::parseInput([['type' => 'file', 'name' => 'cv', 'label' => 'CV', 'translations' => ['en' => ['label' => 'Resume', 'button' => 'Choose', 'error_type' => 'Wrong']]], ], ['en']))[0]['translations']['en'], ['label' => 'Resume', 'button' => 'Choose', 'error_type' => 'Wrong']);
check('an extension list that names only refused kinds falls back to the defaults', FormUploads::settings(['upload' => ['extensions' => 'php, exe']])['extensions'] === FormUploads::defaultExtensions(), true);

// ---- checking what was sent
$checked = $uploads->check($f, [$file('CV.pdf', $pdf)], $say);
check('a PDF named .pdf is accepted, with what it is', [$checked['error'], $checked['accepted'][0]['ext'], $checked['accepted'][0]['mime'], $checked['accepted'][0]['original']], ['', 'pdf', 'application/pdf', 'CV.pdf']);
check('a picture is accepted', $uploads->check($f, [$file('me.PNG', $png)], $say)['error'], '');
check('nothing sent for a required field is an error', $uploads->check($field([], ['required' => true]), [], $say)['error'], 'Please choose a file.');
check('nothing sent for an optional field is fine', $uploads->check($f, [], $say), ['accepted' => [], 'error' => '']);
$mismatch = $uploads->check($f, [$file('photo.jpg', $pdf)], $say);
check('a PDF named .jpg is refused: the content is not what the name says', [$mismatch['accepted'], str_contains($mismatch['error'], 'photo.jpg')], [[], true]);
check('text named .pdf is refused', $uploads->check($f, [$file('notes.pdf', "just words\n")], $say)['accepted'], []);
foreach (['shell.php' => '<?php echo 1;', 'page.html' => '<html></html>', 'logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>', 'run.sh' => "#!/bin/sh\n", 'x.phtml' => 'x', 'x.phar' => 'x', 'app.js' => 'alert(1)', 'a.cgi' => 'x', 'noext' => 'x'] as $name => $content) {
    // Even a field that lists the kind by hand cannot allow it.
    $open = $field(['extensions' => ['php', 'html', 'svg', 'sh', 'phtml', 'phar', 'js', 'cgi', 'pdf']]);
    check("$name is refused whatever the field allows", $uploads->check($open, [$file($name, $content)], $say)['accepted'], []);
}
check('a refused kind hidden behind another extension is refused (shell.php.pdf)', $uploads->check($f, [$file('shell.php.pdf', $pdf)], $say)['accepted'], []);
check('the kind is checked against the list of the field', $uploads->check($field(['extensions' => ['png']]), [$file('CV.pdf', $pdf)], $say)['accepted'], []);
check('an empty .htaccess is refused', $uploads->check($f, [$file('.htaccess', 'Require all granted')], $say)['accepted'], []);
check('a name with a path keeps only its last part', $uploads->check($f, [$file('..\\..\\evil/CV.pdf', $pdf)], $say)['accepted'][0]['original'], 'CV.pdf');

$big = str_repeat('a', 2 * 1048576);
$small = $field(['max_size_mb' => 1, 'extensions' => ['txt']]);
$r = $uploads->check($small, [$file('big.txt', $big)], $say);
check('a file over the size is refused, naming it and the limit', $r['error'], '"big.txt" is larger than 1.0 MB.');
check('the field can say it in its own words', $uploads->check($field(['max_size_mb' => 1, 'extensions' => ['txt']], ['error_size' => 'Huge: {name} > {max}']), [$file('big.txt', $big)], $say)['error'], 'Huge: big.txt > 1.0 MB');
check('a file the server already refused (too large for PHP) is told as too big', $uploads->check($small, [['name' => 'x.txt', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE]], $say)['error'], '"x.txt" is larger than 1.0 MB.');
check('a failed upload is told', str_contains($uploads->check($small, [['name' => 'x.txt', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_PARTIAL]], $say)['error'], 'could not be uploaded'), true);
check('the limit is never more than the site allows', (function () use ($dir, $png, $file, $say) {
    $capped = new FormUploads($dir . '/storage/form-uploads', true);
    $capped->configure(fn(): int => 1000, fn(int $n): bool => true, fn(int $n) => null);
    $f = FormFields::normalize([['name' => 'p', 'type' => 'file', 'label' => 'P', 'upload' => ['max_size_mb' => 50, 'extensions' => ['txt']]]])[0];
    return [$capped->limits(FormUploads::settings($f))['per_file'], $capped->check($f, [$file('a.txt', str_repeat('a', 2000))], $say)['error'] !== ''];
})(), [1000, true]);

$many = $field(['multiple' => true, 'min' => 2, 'max' => 3, 'extensions' => ['txt'], 'total_mb' => 1]);
check('too many files', $uploads->check($many, array_map(fn($n) => $file("$n.txt", 'x'), [1, 2, 3, 4]), $say)['error'], 'You can add at most 3 files.');
check('too few files', $uploads->check($many, [$file('1.txt', 'x')], $say)['error'], 'Please add at least 2 files.');
$ok2 = $uploads->check($many, [$file('1.txt', 'xx'), $file('2.txt', 'yy')], $say);
check('the right number is accepted', [$ok2['error'], count($ok2['accepted'])], ['', 2]);
$half = str_repeat('a', 700000);
check('files together over the total are refused', str_contains($uploads->check($many, [$file('1.txt', $half), $file('2.txt', $half)], $say)['error'], 'together'), true);
check('a single file field refuses two files', $uploads->check($f, [$file('1.pdf', $pdf), $file('2.pdf', $pdf)], $say)['error'], 'You can add at most 1 files.');
check('what PHP gives for name[] is a plain list, empty slots left out', array_column(FormUploads::normalizeFiles(['name' => ['a.txt', ''], 'tmp_name' => ['/t/a', ''], 'size' => [1, 0], 'error' => [0, UPLOAD_ERR_NO_FILE]]), 'name'), ['a.txt']);
check('and for one file', count(FormUploads::normalizeFiles(['name' => 'a.txt', 'tmp_name' => '/t/a', 'size' => 1, 'error' => 0])), 1);

// ---- the whole form: the processor
$p = new FormProcessor($say);
$fields = FormFields::normalize([
    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
    ['name' => 'cv', 'label' => 'CV', 'type' => 'file', 'required' => true, 'upload' => ['extensions' => ['pdf']]],
]);
$tmpPdf = $tmp($pdf);
$r = $p->collect($fields, ['name' => ''], ['cv' => ['name' => 'a.pdf', 'tmp_name' => $tmpPdf, 'size' => strlen($pdf), 'error' => 0]], $uploads);
check('a mistake elsewhere refuses the submission and the temporary file is removed', [array_keys($r['errors']), $r['uploads'], is_file($tmpPdf)], [['name'], [], false]);
$tmpPdf = $tmp($pdf);
$r = $p->collect($fields, ['name' => 'Ada'], ['cv' => ['name' => 'a.pdf', 'tmp_name' => $tmpPdf, 'size' => strlen($pdf), 'error' => 0]], $uploads);
check('a good one keeps the file for later, and nothing is written yet', [$r['errors'], count($r['uploads']['cv']), is_dir($dir . '/storage/form-uploads')], [[], 1, false]);
check('a required file field with no file is an error', $p->collect($fields, ['name' => 'Ada'], [], $uploads)['errors'], ['cv' => 'Please choose a file.']);
check('a form with a file field starts empty', $p->defaults($fields), ['name' => '', 'cv' => []]);

// ---- the names
$taken = [];
$ctx = fn(array $extra = []): array => $extra + ['fields' => ['name' => 'Γιώργος', 'surname' => 'Παπαδόπουλος'], 'form' => 'careers', 'lang' => 'el', 'submission' => '20261015-101112-ab12cd34', 'original' => 'My CV', 'index' => 1, 'at' => mktime(10, 11, 12, 10, 15, 2026)];
check('the example pattern', FormUploads::fileName('cv-{field:surname}-{field:name}-{date}', $ctx(), 'pdf', $taken), 'cv-papadopoulos-giorgos-2026-10-15.pdf');
$taken = [];
check('a date format', FormUploads::fileName('{date:d-m-Y}_{time}', $ctx(), 'pdf', $taken), '15-10-2026_101112.pdf');
$taken = [];
check('form, submission, index, original and language', FormUploads::fileName('{form}-{submission}-{index}-{original}-{lang}', $ctx(['index' => 3]), 'png', $taken), 'careers-20261015-101112-ab12cd34-3-my-cv-el.png');
$taken = [];
check('a random part is different each time and short', [strlen(explode('-', FormUploads::fileName('{random}', $ctx(), 'pdf', $taken))[0]) > 5, FormUploads::fileName('{random}', $ctx(), 'pdf', $taken) !== FormUploads::fileName('{random}', $ctx(), 'pdf', $taken)], [true, true]);
$taken = [];
$a = FormUploads::fileName('cv', $ctx(), 'pdf', $taken);
$b = FormUploads::fileName('cv', $ctx(), 'pdf', $taken);
$c = FormUploads::fileName('CV', $ctx(), 'pdf', $taken);
check('a name already used gets -2, -3', [$a, $b, $c], ['cv.pdf', 'cv-2.pdf', 'cv-3.pdf']);
$taken = [];
check('an empty result falls back to the original name, then to "file"', [FormUploads::fileName('{field:missing}', $ctx(), 'pdf', $taken), FormUploads::fileName('{field:missing}', $ctx(['original' => '']), 'pdf', $taken), FormUploads::fileName('', $ctx(), 'pdf', $taken)], ['my-cv.pdf', 'file.pdf', 'my-cv-2.pdf']);
$taken = [];
$long = FormUploads::fileName(str_repeat('abcd-', 60), $ctx(), 'pdf', $taken);
check('a long name is cut', strlen($long) <= 84 && str_ends_with($long, '.pdf'), true);
$taken = [];
check('unsafe characters in answers and text become dashes, the extension cannot be forged', FormUploads::fileName('../x/{field:name}.php', $ctx(['fields' => ['name' => '<b>A/B</b>']]), 'pdf', $taken), 'x-b-a-b-b-php.pdf');
$taken = [];
check('an unknown word in braces is just text', FormUploads::fileName('a-{nothing}', $ctx(), 'pdf', $taken), 'a-nothing.pdf');
check('a plan of no files is empty', FormUploads::plan($fields, ['name' => 'Ada'], [], 'careers', 'en', 'sub1', 0), []);

// ---- keeping them: private, and only under the submission
$repo = new FormSubmissionRepository($dir . '/content', $dir . '/storage/form-uploads');
$repo->uploads()->trustTemp();
$changes = [];
$repo->uploads()->configure(fn(): int => 0, fn(int $n): bool => true, function (int $n) use (&$changes) { $changes[] = $n; });
$id = $repo->newId();
$multi = FormFields::normalize([['name' => 'docs', 'type' => 'file', 'label' => 'Docs', 'upload' => ['multiple' => true, 'max' => 5, 'extensions' => ['pdf', 'png'], 'rename' => 'doc-{field:name}-{index}']], ['name' => 'name', 'type' => 'text', 'label' => 'Name']]);
$accepted = [
    'docs' => [
        ['tmp' => $tmp($pdf), 'original' => 'one.pdf', 'ext' => 'pdf', 'mime' => 'application/pdf', 'size' => strlen($pdf)],
        ['tmp' => $tmp($png), 'original' => 'two.png', 'ext' => 'png', 'mime' => 'image/png', 'size' => strlen($png)],
    ],
];
$plan = FormUploads::plan($multi, ['name' => 'Άννα', 'docs' => []], $accepted, 'careers', 'el', $id, time());
check('the plan names every file', array_column($plan['docs'], 'name'), ['doc-anna-1.pdf', 'doc-anna-2.png']);
$kept = $repo->uploads()->save('careers', $id, $plan);
check('the files are kept under storage/form-uploads/<form>/<submission>/', [array_keys($kept['docs'] ?? []) === [0, 1], is_file($dir . '/storage/form-uploads/careers/' . $id . '/doc-anna-1.pdf'), is_file($dir . '/storage/form-uploads/careers/' . $id . '/doc-anna-2.png')], [true, true, true]);
check('the folder is shut off for the web server', [is_file($dir . '/storage/form-uploads/.htaccess'), str_contains((string)file_get_contents($dir . '/storage/form-uploads/.htaccess'), 'Require all denied'), is_file($dir . '/storage/form-uploads/index.html')], [true, true, true]);
check('the storage count was told', $changes, [strlen($pdf) + strlen($png)]);
check('a stored file is found by the folder and name', basename((string)$repo->uploads()->path('careers', $id, 'doc-anna-1.pdf')), 'doc-anna-1.pdf');
foreach ([['careers', $id, '../../../content/pages/a.md'], ['careers', $id, '..'], ['careers', '../x', 'a.pdf'], ['../careers', $id, 'a.pdf'], ['careers', $id, 'sub/a.pdf'], ['careers', $id, '.htaccess'], ['careers', $id, 'missing.pdf']] as $i => [$form, $sub, $name]) {
    check("no way out of the folder (#$i)", $repo->uploads()->path($form, $sub, $name), null);
}
$repo->store('careers', ['form' => 'careers', 'lang' => 'el', 'submitted_at' => date('c'), 'fields' => ['name' => 'Άννα', 'docs' => ['doc-anna-1.pdf', 'doc-anna-2.png']], 'files' => $kept], $id);
$entry = $repo->all('careers')[0];
check('the submission keeps the names, and says how many files it has', [$entry['fields']['docs'], FormSubmissionRepository::fileCount($entry), $entry['files']['docs'][0]['original']], [['doc-anna-1.pdf', 'doc-anna-2.png'], 2, 'one.pdf']);
$repo->store('careers', ['form' => 'careers', 'lang' => 'el', 'submitted_at' => date('c', time() - 100), 'fields' => ['name' => 'No files']]);
check('submissions can be filtered to those with files', array_map(fn($e) => $e['fields']['name'], $repo->filter($repo->all('careers'), ['has_files' => '1'])), ['Άννα']);
check('the zip has every file', (function () use ($repo, $id) {
    $zip = $repo->uploads()->zip('careers', $id);
    if ($zip === null) { return 'none'; }
    $archive = new ZipArchive();
    $archive->open($zip);
    $names = [];
    for ($i = 0; $i < $archive->numFiles; $i++) { $names[] = $archive->getNameIndex($i); }
    $archive->close();
    unlink($zip);
    sort($names);
    return $names;
})(), ['doc-anna-1.pdf', 'doc-anna-2.png']);
$moved = $repo->uploads()->save('careers', 'bad', ['x' => [['tmp' => $dir . '/tmp/nothing', 'original' => 'a.pdf', 'ext' => 'pdf', 'mime' => 'application/pdf', 'size' => 1, 'name' => 'a.pdf']]]);
check('a file that cannot be moved leaves nothing behind and says so', [$moved, is_dir($dir . '/storage/form-uploads/careers/bad')], [null, false]);

// ---- deleting
check('the files are counted before a submission is deleted', $repo->uploads()->count('careers', $id), 2);
check('deleting the submission deletes its files', [$repo->delete('careers', $id), is_dir($dir . '/storage/form-uploads/careers/' . $id), count($repo->all('careers'))], [true, false, 1]);
check('and the figure of what the site uses went down', end($changes), -(strlen($pdf) + strlen($png)));

// ---- the emails
$form = new ContentItem('forms', 'careers', 'en', ['title' => 'Careers', 'notifications' => ['enabled' => true, 'to' => 'jobs@example.com', 'auto_reply' => true, 'auto_reply_include' => true, 'attach_files' => true, 'attach_max_mb' => 1]], '', '', '', 0);
$formFields = FormFields::normalize([['name' => 'name', 'label' => 'Name', 'type' => 'text'], ['name' => 'email', 'label' => 'Email', 'type' => 'email'], ['name' => 'cv', 'label' => 'CV', 'type' => 'file']]);
$values = ['name' => 'Ada', 'email' => 'ada@example.com', 'cv' => ['cv.pdf']];
$pdfPath = $tmp($pdf);
$upload = ['files' => ['cv' => [['name' => 'cv.pdf', 'original' => 'My CV.pdf', 'size' => strlen($pdf), 'mime' => 'application/pdf']]], 'urls' => ['cv.pdf' => 'https://x.test/admin/form-file?slug=careers&id=1&f=cv.pdf'], 'paths' => ['cv.pdf' => $pdfPath], 'zip' => 'https://x.test/admin/form-files-zip?slug=careers&id=1'];
$mail = $p->emails($form, $values, $formFields, ['from' => 'site@example.com'], 'https://x.test/forms/careers', $upload);
check('the notification and the reply', array_column($mail, 'to'), ['jobs@example.com', 'ada@example.com']);
check('the notification has the link and the file attached', [str_contains($mail[0]['body'], 'https://x.test/admin/form-file?slug=careers&id=1&f=cv.pdf'), str_contains($mail[0]['body'], 'CV: cv.pdf'), str_contains($mail[0]['body'], 'attached'), $mail[0]['attachments']], [true, true, true, [['path' => $pdfPath, 'name' => 'cv.pdf', 'mime' => 'application/pdf']]]);
check('the visitor gets no attachment and no link into the admin, only the names in the copy of the answers', [$mail[1]['attachments'], str_contains($mail[1]['body'], '/admin/'), str_contains($mail[1]['body'], 'CV: cv.pdf')], [[], false, true]);
$form->meta['notifications']['attach_max_mb'] = 0.00001;
$mail = $p->emails($form, $values, $formFields, ['from' => 'site@example.com'], 'https://x.test/forms/careers', $upload);
check('over the size only the links are sent, and the email says so', [$mail[0]['attachments'], str_contains($mail[0]['body'], 'not attached'), str_contains($mail[0]['body'], '/admin/form-file?')], [[], true, true]);
unset($form->meta['notifications']['attach_files']);
$mail = $p->emails($form, $values, $formFields, ['from' => 'site@example.com'], 'https://x.test/forms/careers', $upload);
check('with attaching off there are links and no attachment and no note about it', [$mail[0]['attachments'], str_contains($mail[0]['body'], '/admin/form-file?'), str_contains($mail[0]['body'], 'attached')], [[], true, false]);
$two = $upload;
$two['files']['cv'][] = ['name' => 'b.pdf', 'size' => 10];
$two['urls']['b.pdf'] = 'https://x.test/b';
check('several files also have a link to all of them', str_contains($p->emails($form, $values, $formFields, ['from' => 's@example.com'], 'https://x.test/f', $two)[0]['body'], 'All files as a ZIP: https://x.test/admin/form-files-zip'), true);

// ---- the message that goes out: a real SMTP conversation with a stand-in server
$serverScript = $dir . '/smtp.php';
file_put_contents($serverScript, <<<'PHP'
<?php
[$script, $port, $out] = $argv;
$server = stream_socket_server("tcp://127.0.0.1:$port");
$conn = stream_socket_accept($server, 20);
fwrite($conn, "220 test\r\n");
$data = '';
$inData = false;
while (($line = fgets($conn)) !== false) {
    if ($inData) {
        if (rtrim($line) === '.') { $inData = false; fwrite($conn, "250 ok\r\n"); continue; }
        $data .= $line;
        continue;
    }
    $cmd = strtoupper(substr($line, 0, 4));
    if ($cmd === 'EHLO') fwrite($conn, "250 hi\r\n");
    elseif ($cmd === 'DATA') { $inData = true; fwrite($conn, "354 go\r\n"); }
    elseif ($cmd === 'QUIT') { fwrite($conn, "221 bye\r\n"); break; }
    else fwrite($conn, "250 ok\r\n");
}
file_put_contents($out, $data);
PHP);
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = (int)substr(strrchr((string)stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$sent = $dir . '/sent.eml';
$proc = proc_open([PHP_BINARY, $serverScript, (string)$port, $sent], [], $pipes);
usleep(300000);
$result = (new Mailer(['driver' => 'smtp', 'smtp' => ['host' => '127.0.0.1', 'port' => $port]]))->send('jobs@example.com', 'New CV', "Hello\n.dot line", ['From' => 'Site <site@example.com>'], [['path' => $pdfPath, 'name' => 'cv.pdf', 'mime' => 'application/pdf']]);
proc_close($proc);
$eml = (string)@file_get_contents($sent);
check('the mailer delivered it', $result['ok'], true);
check('as a message with a text part and the file as an attachment', [str_contains($eml, 'Content-Type: multipart/mixed; boundary='), str_contains($eml, 'Content-Disposition: attachment; filename="cv.pdf"'), str_contains($eml, 'Content-Type: application/pdf; name="cv.pdf"')], [true, true, true]);
preg_match('/filename="cv.pdf"\r?\n\r?\n(.*?)\r?\n--gnozio-/s', $eml, $m);
check('the attached bytes are the file', base64_decode(str_replace(["\r", "\n"], '', $m[1] ?? '')), $pdf);
check('a message without files is still plain text', true, !str_contains(Mailer::extractEmailAddress('a@b.test'), 'multipart'));

// ---- a backup takes the files along and a restore puts them back
mkdir($dir . '/site/storage', 0775, true);
mkdir($dir . '/site/content/pages', 0775, true);
file_put_contents($dir . '/site/content/pages/a.md', "---\ntitle: A\n---\nHi\n");
$db = new SystemDatabase($dir . '/site/storage');
$db->initialize();
$site = new FormSubmissionRepository($dir . '/site/content', $dir . '/site/storage/form-uploads');
$site->uploads()->trustTemp();
$sid = $site->newId();
$plan2 = FormUploads::plan($multi, ['name' => 'Bob'], ['docs' => [['tmp' => $tmp($pdf), 'original' => 'cv.pdf', 'ext' => 'pdf', 'mime' => 'application/pdf', 'size' => strlen($pdf)]]], 'careers', 'en', $sid, time());
$kept2 = $site->uploads()->save('careers', $sid, $plan2);
$site->store('careers', ['form' => 'careers', 'lang' => 'en', 'submitted_at' => date('c'), 'fields' => ['name' => 'Bob'], 'files' => $kept2], $sid);
$backups = new BackupService($dir . '/site', $db);
$snap = $backups->createFullSnapshot('site');
check('a backup is made', $snap['ok'], true);
$verify = $backups->verify($snap['filename']);
$entryName = 'storage/form-uploads/careers/' . $sid . '/doc-bob-1.pdf';
$zip = new ZipArchive();
$zip->open($snap['path']);
check('the archive holds the file and is listed in the manifest with its checksum', [$zip->locateName($entryName) !== false, $verify['ok'], isset($verify['manifest']['files'][$entryName]['sha256']), $verify['areas']['form-uploads'] >= 1], [true, true, true, true]);
$zip->close();
check('Form files is an area of a restore', isset($backups->restoreScopes()['form-uploads']), true);
unlink($dir . '/site/storage/form-uploads/careers/' . $sid . '/doc-bob-1.pdf');
file_put_contents($dir . '/site/storage/form-uploads/stray.txt', 'newer');
$restore = $backups->restore($snap['filename'], ['form-uploads']);
check('a restore puts the files back', [$restore['ok'], is_file($dir . '/site/storage/form-uploads/careers/' . $sid . '/doc-bob-1.pdf'), sha1_file($dir . '/site/storage/form-uploads/careers/' . $sid . '/doc-bob-1.pdf') === sha1($pdf)], [true, true, true]);
check('and the folder is still shut off', str_contains((string)@file_get_contents($dir . '/site/storage/form-uploads/.htaccess'), 'Require all denied'), true);

// ---- clean up
exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
