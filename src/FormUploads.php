<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The files a visitor attaches to a form: which kinds are allowed (and that the content really is what the name says), the
 * limits that apply, the file name a pattern makes, and the private folder they are kept in (storage/form-uploads/<form>/<submission>/,
 * outside the web root and shut off for the web server as well). Files are only written once the rest of the submission is valid,
 * and are removed with their submission.
 */
final class FormUploads
{
    /** Kinds that are never accepted, whatever a form allows: code a server could run, pages a browser would run, and server settings. */
    public const BLOCKED = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh', 'bat', 'cmd', 'com', 'exe', 'dll', 'msi', 'scr', 'vbs', 'ps1',
        'js', 'mjs', 'jsp', 'jspx', 'asp', 'aspx', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'htaccess', 'htpasswd', 'user', 'ini', 'jar', 'war', 'apk', 'app', 'dmg', 'swf', 'hta', 'lnk',
    ];

    /** @var array<string, array{label: string, extensions: string[]}> */
    public const PRESETS = [
        'images' => ['label' => 'Images', 'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif']],
        'pdf' => ['label' => 'PDF', 'extensions' => ['pdf']],
        'documents' => ['label' => 'Documents', 'extensions' => ['doc', 'docx', 'odt', 'rtf', 'txt', 'xls', 'xlsx', 'ods', 'csv', 'ppt', 'pptx', 'odp']],
        'archives' => ['label' => 'Archives', 'extensions' => ['zip', '7z', 'rar', 'gz', 'tar']],
    ];

    private const OLE = ['application/vnd.ms-office', 'application/x-ole-storage', 'application/cdfv2', 'application/cdfv2-corrupt', 'application/cdfv2-unknown'];
    private const ZIPPED = ['application/zip', 'application/x-zip', 'application/x-zip-compressed', 'application/octet-stream'];

    /** What the content of a kind of file may be called by finfo. A kind not listed here is accepted if its content is not something dangerous. */
    private const MIME = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'], 'avif' => ['image/avif'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'], 'csv' => ['text/csv', 'text/plain', 'application/csv'], 'rtf' => ['text/rtf', 'application/rtf'],
        'doc' => ['application/msword', ...self::OLE], 'xls' => ['application/vnd.ms-excel', ...self::OLE], 'ppt' => ['application/vnd.ms-powerpoint', ...self::OLE],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', ...self::ZIPPED],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...self::ZIPPED],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', ...self::ZIPPED],
        'odt' => ['application/vnd.oasis.opendocument.text', ...self::ZIPPED],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', ...self::ZIPPED],
        'odp' => ['application/vnd.oasis.opendocument.presentation', ...self::ZIPPED],
        'zip' => ['application/zip', 'application/x-zip', 'application/x-zip-compressed'],
        '7z' => ['application/x-7z-compressed'], 'rar' => ['application/x-rar', 'application/vnd.rar', 'application/x-rar-compressed'],
        'gz' => ['application/gzip', 'application/x-gzip'], 'tar' => ['application/x-tar'],
    ];

    /** Content that is never accepted, whatever the name says. */
    private const DANGEROUS_MIME = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/x-php', 'application/x-httpd-php', 'application/x-php', 'text/javascript', 'application/javascript', 'application/x-javascript',
        'text/x-shellscript', 'application/x-sh', 'application/x-executable', 'application/x-sharedlib', 'application/x-mach-binary', 'application/x-dosexec', 'application/x-msdownload', 'application/x-msdos-program',
        'application/java-archive', 'application/x-java-applet', 'application/vnd.microsoft.portable-executable', 'text/x-script.python', 'text/x-perl', 'text/x-ruby',
    ];

    public const DEFAULT_MAX_MB = 10;
    public const MAX_FILES = 20;

    /** @var (\Closure(): int)|null the site's own upload limit in bytes (already capped by the server), 0 for none */
    private ?\Closure $siteMax = null;
    /** @var (\Closure(int): bool)|null whether that many more bytes still fit in the site's storage */
    private ?\Closure $room = null;
    /** @var (\Closure(int): void)|null told how many bytes were added (or taken off) */
    private ?\Closure $changed = null;

    /** @param bool $trustTemp accept temporary files that PHP did not receive as an upload (for tests only) */
    public function __construct(private string $directory, private bool $trustTemp = false)
    {
    }

    /**
     * @param \Closure(): int $siteMax
     * @param \Closure(int): bool $room
     * @param \Closure(int): void $changed
     */
    public function configure(\Closure $siteMax, \Closure $room, \Closure $changed): void
    {
        $this->siteMax = $siteMax;
        $this->room = $room;
        $this->changed = $changed;
    }

    public function baseDirectory(): string
    {
        return $this->directory;
    }

    public function trustTemp(bool $trust = true): void
    {
        $this->trustTemp = $trust;
    }

    // ---- what a field allows

    /** Every extension a field accepts when it names none: the usual documents, pictures and archives. @return string[] */
    public static function defaultExtensions(): array
    {
        return array_merge(...array_map(static fn(array $preset): array => $preset['extensions'], array_values(self::PRESETS)));
    }

    /** Extensions as the builder sends them (text or a list): lower case, letters and digits, never a refused kind. @return string[] */
    public static function cleanExtensions(mixed $raw): array
    {
        $list = is_array($raw) ? $raw : (preg_split('/[\s,;]+/', (string)$raw) ?: []);
        $clean = [];
        foreach ($list as $extension) {
            $extension = strtolower(ltrim(trim((string)$extension), '.*'));
            if ($extension !== '' && preg_match('/^[a-z0-9]{1,8}$/', $extension) && !in_array($extension, self::BLOCKED, true) && !in_array($extension, $clean, true)) {
                $clean[] = $extension;
            }
        }
        return $clean;
    }

    /**
     * What a file field allows, filled in: single or several, how many, how big, and which kinds.
     *
     * @param array<string, mixed> $field
     * @return array{multiple: bool, min: int, max: int, max_size_mb: float, total_mb: float, extensions: string[], rename: string}
     */
    public static function settings(array $field): array
    {
        $raw = is_array($field['upload'] ?? null) ? $field['upload'] : [];
        $multiple = Format::isTruthy($raw['multiple'] ?? false);
        $max = $multiple ? max(1, min(self::MAX_FILES, (int)($raw['max'] ?? 5))) : 1;
        $min = $multiple ? max(0, min($max, (int)($raw['min'] ?? 0))) : 0;
        $size = (float)str_replace(',', '.', (string)($raw['max_size_mb'] ?? self::DEFAULT_MAX_MB));
        $total = (float)str_replace(',', '.', (string)($raw['total_mb'] ?? 0));
        $extensions = self::cleanExtensions($raw['extensions'] ?? []);
        return [
            'multiple' => $multiple,
            'min' => $min,
            'max' => $max,
            'max_size_mb' => $size > 0 ? round(min($size, 1024.0), 2) : (float)self::DEFAULT_MAX_MB,
            'total_mb' => $total > 0 ? round(min($total, 4096.0), 2) : 0.0,
            'extensions' => $extensions !== [] ? $extensions : self::defaultExtensions(),
            'rename' => trim(str_replace(["\r", "\n"], ' ', (string)($raw['rename'] ?? ''))),
        ];
    }

    /** The limits that really apply: what the field says, never more than the server and the site allow. @return array{per_file: int, total: int, count: int} bytes (0 for no total) and a number of files */
    public function limits(array $settings): array
    {
        $perFile = (int)round($settings['max_size_mb'] * 1048576);
        $ceiling = $this->ceiling();
        if ($ceiling > 0) {
            $perFile = min($perFile, $ceiling);
        }
        $total = (int)round($settings['total_mb'] * 1048576);
        $serverTotal = SiteLimits::serverPostCap();
        if ($serverTotal > 0) {
            $total = $total > 0 ? min($total, $serverTotal) : 0;
        }
        $count = $settings['max'];
        $iniCount = (int)ini_get('max_file_uploads');
        if ($iniCount > 0) {
            $count = min($count, $iniCount);
        }
        return ['per_file' => $perFile, 'total' => $total, 'count' => $count];
    }

    /** The most that one file can be on this site: the site's upload limit, never more than the server takes. 0 when nothing limits it. */
    public function ceiling(): int
    {
        return $this->siteMax !== null ? max(0, ($this->siteMax)()) : SiteLimits::serverUploadCap();
    }

    // ---- checking what was sent

    /**
     * What was sent for a field as a plain list: PHP gives one file or a list (name[]) in its own layout. Entries where nothing was chosen are left out.
     *
     * @param mixed $entry $_FILES[name]
     * @return array<int, array{name: string, tmp_name: string, size: int, error: int}>
     */
    public static function normalizeFiles(mixed $entry): array
    {
        if (!is_array($entry) || !isset($entry['name'])) {
            return [];
        }
        $names = is_array($entry['name']) ? $entry['name'] : [$entry['name']];
        $files = [];
        foreach (array_keys($names) as $i) {
            $pick = static fn(string $key) => is_array($entry[$key] ?? null) ? ($entry[$key][$i] ?? null) : ($entry[$key] ?? null);
            $error = (int)($pick('error') ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name' => is_string($pick('name')) ? (string)$pick('name') : '',
                'tmp_name' => is_string($pick('tmp_name')) ? (string)$pick('tmp_name') : '',
                'size' => (int)($pick('size') ?? 0),
                'error' => $error,
            ];
        }
        return $files;
    }

    /**
     * Checks the files sent for one field. Nothing is written: the result is the files that are fine (and what they really are) and the first thing
     * that is wrong.
     *
     * @param array<string, mixed> $field the field, as FormFields::normalize gives it
     * @param array<int, array{name: string, tmp_name: string, size: int, error: int}> $files
     * @param callable(string, string): string $translate a theme string: key, fallback
     * @return array{accepted: array<int, array{tmp: string, original: string, ext: string, mime: string, size: int}>, error: string}
     */
    public function check(array $field, array $files, callable $translate): array
    {
        $settings = self::settings($field);
        $limits = $this->limits($settings);
        $required = (bool)($field['required'] ?? false);
        $say = function (string $key, string $fallbackKey, string $fallback, array $tokens) use ($field, $translate): string {
            $own = trim((string)($field[$key] ?? ''));
            $text = $own !== '' ? $own : $translate($fallbackKey, $fallback);
            $map = [];
            foreach ($tokens as $token => $value) {
                $map['{' . $token . '}'] = $value;
            }
            return strtr($text, $map);
        };
        $kinds = implode(', ', array_map(static fn(string $e): string => '.' . $e, $settings['extensions']));
        $size = Format::bytes($limits['per_file']);

        if ($files === []) {
            if ($required || $settings['min'] > 0) {
                $needed = max(1, $settings['min']);
                return ['accepted' => [], 'error' => $needed > 1
                    ? $say('error_min', 'form.error.file_min', 'Please add at least {count} files.', ['count' => (string)$needed])
                    : $translate('form.file.required', 'Please choose a file.')];
            }
            return ['accepted' => [], 'error' => ''];
        }
        if (count($files) > $limits['count']) {
            return ['accepted' => [], 'error' => $say('error_max', 'form.error.file_max', 'You can add at most {count} files.', ['count' => (string)$limits['count']])];
        }
        $min = $required ? max(1, $settings['min']) : $settings['min'];
        if (count($files) < $min) {
            return ['accepted' => [], 'error' => $say('error_min', 'form.error.file_min', 'Please add at least {count} files.', ['count' => (string)$min])];
        }

        $accepted = [];
        $total = 0;
        foreach ($files as $file) {
            $original = self::cleanOriginal($file['name']);
            $tokens = ['name' => $original, 'max' => $size, 'types' => $kinds, 'count' => (string)$limits['count']];
            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                return ['accepted' => [], 'error' => $say('error_size', 'form.error.file_size', '"{name}" is larger than {max}.', $tokens)];
            }
            if ($file['error'] !== UPLOAD_ERR_OK || $file['tmp_name'] === '' || !$this->isTemp($file['tmp_name'])) {
                return ['accepted' => [], 'error' => $translate('form.error.file_upload', 'The file "' . $original . '" could not be uploaded. Please try again.')];
            }
            $actual = (int)@filesize($file['tmp_name']);
            if ($limits['per_file'] > 0 && $actual > $limits['per_file']) {
                return ['accepted' => [], 'error' => $say('error_size', 'form.error.file_size', '"{name}" is larger than {max}.', $tokens)];
            }
            $kind = self::kind($original, $file['tmp_name'], $settings['extensions']);
            if ($kind['error'] !== '') {
                return ['accepted' => [], 'error' => $say('error_type', 'form.error.file_type', '"{name}" is not an accepted kind of file. Allowed: {types}.', $tokens)];
            }
            $total += $actual;
            $accepted[] = ['tmp' => $file['tmp_name'], 'original' => $original, 'ext' => $kind['ext'], 'mime' => $kind['mime'], 'size' => $actual];
        }
        if ($limits['total'] > 0 && $total > $limits['total']) {
            return ['accepted' => [], 'error' => $say('error_size', 'form.error.file_total', 'The files together are larger than {max}.', ['max' => Format::bytes($limits['total'])])];
        }
        return ['accepted' => $accepted, 'error' => ''];
    }

    /**
     * What a file is: its kind from the name, checked against what finfo finds in it. The error is set for a refused or unlisted kind, and for content that is not what the name says.
     *
     * @param string[] $allowed
     * @return array{ext: string, mime: string, error: string}
     */
    public static function kind(string $name, string $path, array $allowed): array
    {
        $bad = static fn(string $mime = ''): array => ['ext' => '', 'mime' => $mime, 'error' => 'type'];
        $parts = explode('.', strtolower(ltrim($name, '.')));
        if (count($parts) < 2) {
            return $bad();
        }
        $ext = (string)array_pop($parts);
        // A refused kind anywhere in the name counts ("shell.php.jpg").
        foreach (array_merge($parts, [$ext]) as $part) {
            if (in_array($part, self::BLOCKED, true)) {
                return $bad();
            }
        }
        if (!in_array($ext, $allowed, true)) {
            return $bad();
        }
        $mime = self::mime($path);
        if ($mime === 'application/octet-stream' && in_array($ext, ['txt', 'csv'], true)) {
            // finfo cannot tell a line of a few characters from anything else.
            $head = (string)@file_get_contents($path, false, null, 0, 65536);
            if ($head !== '' && !str_contains($head, "\0") && mb_check_encoding($head, 'UTF-8')) {
                $mime = 'text/plain';
            }
        }
        if ($mime === '' || in_array($mime, self::DANGEROUS_MIME, true)) {
            return $bad($mime);
        }
        if (isset(self::MIME[$ext])) {
            if (!in_array($mime, self::MIME[$ext], true)) {
                return $bad($mime);
            }
        } elseif (str_starts_with($mime, 'text/') && !in_array($mime, ['text/plain', 'text/csv', 'text/markdown', 'text/rtf'], true)) {
            return $bad($mime);
        }
        return ['ext' => $ext, 'mime' => $mime, 'error' => ''];
    }

    /** What finfo finds in the file ('' when it cannot tell). */
    public static function mime(string $path): string
    {
        if (!class_exists(\finfo::class)) {
            return '';
        }
        $found = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        return is_string($found) ? strtolower($found) : '';
    }

    /** A name as the visitor's browser gave it, without a path or control characters, and not too long. */
    public static function cleanOriginal(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name));
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        return mb_substr($name, 0, 150);
    }

    private function isTemp(string $path): bool
    {
        return is_file($path) && ($this->trustTemp || is_uploaded_file($path));
    }

    /** @param array<string, array<int, array{tmp: string}>> $uploads removes the temporary files of files that were not kept */
    public function discard(array $uploads): void
    {
        foreach ($uploads as $files) {
            foreach ($files as $file) {
                $path = (string)($file['tmp'] ?? '');
                if ($path !== '' && is_file($path) && ($this->trustTemp || is_uploaded_file($path))) {
                    @unlink($path);
                }
            }
        }
    }

    /** Whether the site's storage still has room for that many bytes. */
    public function hasRoom(int $bytes): bool
    {
        return $this->room === null || ($this->room)($bytes);
    }

    // ---- names

    /**
     * The name of one file from a pattern: text mixed with {field:name} (what the visitor answered), {form}, {date} / {date:Y-m-d},
     * {time}, {submission}, {index}, {original}, {random} and {lang}. Everything goes through the address slug (Greek to Latin, safe
     * characters). The extension is the real one. A name already taken gets -2, -3, and so on.
     *
     * @param array<string, mixed> $context fields (name => answer), form, lang, submission, original, index, at (timestamp)
     * @param array<string, true> $taken lower-case names already used in the submission; the new one is added
     */
    public static function fileName(string $pattern, array $context, string $ext, array &$taken): string
    {
        $at = (int)($context['at'] ?? time());
        $pattern = trim($pattern) !== '' ? $pattern : '{original}';
        $text = preg_replace_callback('/\{(field:[A-Za-z0-9_-]+|form|date(?::[^{}]*)?|time|submission|index|original|random|lang)\}/', static function (array $m) use ($context, $at): string {
            $token = $m[1];
            if (str_starts_with($token, 'field:')) {
                return FormProcessor::text($context['fields'][substr($token, 6)] ?? '');
            }
            if ($token === 'date' || str_starts_with($token, 'date:')) {
                $format = $token === 'date' ? 'Y-m-d' : substr($token, 5);
                return date($format !== '' ? $format : 'Y-m-d', $at);
            }
            return match ($token) {
                'time' => date('His', $at),
                'form' => (string)($context['form'] ?? ''),
                'submission' => (string)($context['submission'] ?? ''),
                'index' => (string)($context['index'] ?? 1),
                'original' => (string)($context['original'] ?? ''),
                'random' => bin2hex(random_bytes(3)),
                'lang' => (string)($context['lang'] ?? ''),
                default => '',
            };
        }, $pattern) ?? '';
        // The same Greek-to-Latin and safe-character rules as the addresses of pages; a long name is cut at a dash.
        $base = Slug::fromText($text);
        if ($base === '') {
            $base = Slug::fromText((string)($context['original'] ?? '')) ?: 'file';
        }
        $name = $base . '.' . $ext;
        for ($n = 2; isset($taken[strtolower($name)]); $n++) {
            $name = $base . '-' . $n . '.' . $ext;
        }
        $taken[strtolower($name)] = true;
        return $name;
    }

    /**
     * The names of every accepted file of a form, decided together so a name is never used twice.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $values the answers (a file field's own is left out of the tokens)
     * @param array<string, array<int, array{tmp: string, original: string, ext: string, mime: string, size: int}>> $uploads
     * @return array<string, array<int, array{tmp: string, original: string, ext: string, mime: string, size: int, name: string}>>
     */
    public static function plan(array $fields, array $values, array $uploads, string $formSlug, string $lang, string $submission, int $at): array
    {
        $answers = $values;
        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'file') {
                unset($answers[(string)$field['name']]);
            }
        }
        $byName = [];
        foreach ($fields as $field) {
            $byName[(string)($field['name'] ?? '')] = $field;
        }
        $taken = [];
        $plan = [];
        foreach ($uploads as $name => $files) {
            $pattern = self::settings($byName[$name] ?? [])['rename'];
            foreach (array_values($files) as $i => $file) {
                $base = pathinfo($file['original'], PATHINFO_FILENAME);
                $file['name'] = self::fileName($pattern, [
                    'fields' => $answers, 'form' => $formSlug, 'lang' => $lang, 'submission' => $submission,
                    'original' => $base, 'index' => $i + 1, 'at' => $at,
                ], $file['ext'], $taken);
                $plan[$name][] = $file;
            }
        }
        return $plan;
    }

    // ---- the private folder

    /** The folder one submission's files are in. */
    public function folder(string $form, string $submission): string
    {
        return $this->directory . '/' . $form . '/' . $submission;
    }

    /**
     * Moves the checked files into the submission's folder. Nothing is left behind when one cannot be moved.
     *
     * @param array<string, array<int, array{tmp: string, original: string, ext: string, mime: string, size: int, name: string}>> $plan
     * @return array<string, array<int, array{name: string, original: string, size: int, mime: string}>>|null null when it failed
     */
    public function save(string $form, string $submission, array $plan): ?array
    {
        if ($plan === [] || !FormSubmissionRepository::isValidId($submission) || $form === '' || $form !== Slug::plain($form)) {
            return $plan === [] ? [] : null;
        }
        $folder = $this->folder($form, $submission);
        $this->protect();
        if (!is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) {
            return null;
        }
        $kept = [];
        $bytes = 0;
        foreach ($plan as $field => $files) {
            foreach ($files as $file) {
                $target = $folder . '/' . $file['name'];
                $moved = $this->trustTemp ? @rename($file['tmp'], $target) : @move_uploaded_file($file['tmp'], $target);
                if (!$moved) {
                    self::removeTree($folder);
                    return null;
                }
                @chmod($target, 0644);
                $bytes += $file['size'];
                $kept[$field][] = ['name' => $file['name'], 'original' => $file['original'], 'size' => $file['size'], 'mime' => $file['mime']];
            }
        }
        if ($this->changed !== null) {
            ($this->changed)($bytes);
        }
        return $kept;
    }

    /** The path of a stored file, or null when the name is not one that could be kept (nothing outside the folder is ever reached). */
    public function path(string $form, string $submission, string $name): ?string
    {
        if ($form === '' || $form !== Slug::plain($form) || !FormSubmissionRepository::isValidId($submission) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) || str_contains($name, '..')) {
            return null;
        }
        $path = $this->folder($form, $submission) . '/' . $name;
        return is_file($path) ? $path : null;
    }

    /** The files of one submission as a ZIP in a temporary file (the caller sends it and deletes it), or null when it has none or ZIP is not available. */
    public function zip(string $form, string $submission): ?string
    {
        if ($form === '' || $form !== Slug::plain($form) || !FormSubmissionRepository::isValidId($submission) || !class_exists(\ZipArchive::class)) {
            return null;
        }
        $files = array_values(array_filter(glob($this->folder($form, $submission) . '/*') ?: [], 'is_file'));
        if ($files === []) {
            return null;
        }
        $temporary = tempnam(sys_get_temp_dir(), 'faros-zip-');
        $zip = new \ZipArchive();
        if ($temporary === false || $zip->open($temporary, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }
        return $zip->close() ? $temporary : null;
    }

    /** Deletes the files of one submission. @return int how many files there were */
    public function remove(string $form, string $submission): int
    {
        if ($form === '' || $form !== Slug::plain($form) || !FormSubmissionRepository::isValidId($submission)) {
            return 0;
        }
        $folder = $this->folder($form, $submission);
        $count = 0;
        $bytes = 0;
        foreach (glob($folder . '/*') ?: [] as $path) {
            if (is_file($path)) {
                $count++;
                $bytes += (int)@filesize($path);
            }
        }
        self::removeTree($folder);
        if ($bytes > 0 && $this->changed !== null) {
            ($this->changed)(-$bytes);
        }
        return $count;
    }

    /** How many files one submission has. */
    public function count(string $form, string $submission): int
    {
        return count(array_filter(glob($this->folder($form, $submission) . '/*') ?: [], 'is_file'));
    }

    /** Shuts the folder off for the web server (it is outside the web root already; this is for a site whose root is the project folder). */
    public function protect(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return;
        }
        $htaccess = $this->directory . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "# Files sent through forms are private: they are only given out by the admin.\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        }
        $index = $this->directory . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) && !is_link($child) ? self::removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
