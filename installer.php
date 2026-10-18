<?php
/*
 * GnozioCMS installer: one file, uploaded to an empty site folder and opened in a browser.
 *
 *   1. Set INSTALL_KEY below (at least 12 characters), save, upload this file to the site folder.
 *   2. Open https://your-site/installer.php and enter the key.
 *   3. The installer checks the server, downloads the latest release from GitHub, verifies its size and SHA-256,
 *      unpacks it beside this file and asks you to point the site's document root at the new public/ folder.
 *   4. When you confirm, it deletes itself and sends you to /admin, where you create the first administrator.
 *
 * The key is the only thing that stands between a visitor and this page, so use a long random one and let the
 * installer delete itself when it is done (it does, in the last step). It refuses to run on a folder that already
 * holds a GnozioCMS site. Written to start on old PHP versions too, so it can say what is wrong with a server.
 * The code of the package is unpacked with the same rules as an update from the admin (code paths only, nothing
 * that climbs out of the folder, no links).
 */
declare(strict_types=1);

const INSTALL_KEY = '';

if (!defined('GNOZIO_INSTALLER_LIB')) {
    GnozioInstaller::run();
}

final class GnozioInstaller
{
    const REPOSITORY = 'chiotis/gnoziocms';
    const MIN_PHP_FALLBACK = '8.5';
    const MAX_PACKAGE_BYTES = 104857600;
    const MAX_MANIFEST_BYTES = 65536;
    const MAX_ENTRIES = 20000;
    const MAX_UNPACKED_BYTES = 314572800;
    const CHUNK_BYTES = 4194304;
    const COOKIE = 'gz_installer';
    const COOKIE_SECONDS = 7200;
    const MIN_KEY_LENGTH = 12;
    const STATE_DIR = 'storage/install';
    /** Must equal UpdateInstaller::DIRECTORIES / FILES (tests/unit/installer.php checks it). */
    const DIRECTORIES = ['src', 'admin', 'vendor', 'starter', 'public/assets'];
    const FILES = ['public/index.php', 'public/.htaccess', 'public/uploads/.htaccess', 'custom/README.md', 'scripts/use-starter.php', 'CHANGELOG.md', 'README.md', 'LICENSE', 'update.md', 'composer.json', 'composer.lock', 'VERSION'];
    const EXTENSIONS = ['zip' => true, 'pdo_sqlite' => true, 'mbstring' => true, 'json' => true, 'openssl' => true, 'fileinfo' => true, 'curl' => false, 'simplexml' => false, 'intl' => false];
    const STEPS = ['check', 'download', 'verify', 'unpack', 'prepare', 'docroot', 'finish'];

    /** @var string the folder the site lives in (the parent of public/ when the public copy runs) */
    private static $base = '';
    /** @var bool whether the running file is the copy inside public/ */
    private static $isPublicCopy = false;
    /** @var string */
    private static $lang = 'en';

    // ---------------------------------------------------------------- entry

    public static function run()
    {
        self::locate();
        self::$lang = self::chooseLanguage();
        $step = isset($_GET['step']) ? (string)$_GET['step'] : '';
        if ($step !== '') {
            self::handleStep($step);
            return;
        }
        self::securityHeaders();
        $method = isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : 'GET';
        if (strlen(INSTALL_KEY) < self::MIN_KEY_LENGTH) {
            self::notice('no_key');
            return;
        }
        $state = self::readState();
        if ($state === null && self::existingInstall() !== '') {
            self::notice('existing');
            return;
        }
        if ($state !== null && !empty($state['finished'])) {
            self::notice('finished');
            return;
        }
        if (!self::authenticated()) {
            $wrong = false;
            if ($method === 'POST' && isset($_POST['key'])) {
                if (hash_equals(INSTALL_KEY, (string)$_POST['key'])) {
                    self::setCookie();
                    header('Location: ' . self::selfPath(), true, 303);
                    return;
                }
                sleep(2);
                $wrong = true;
            }
            self::keyPage($wrong);
            return;
        }
        self::appPage();
    }

    private static function locate()
    {
        $parent = dirname(__DIR__);
        self::$base = __DIR__;
        self::$isPublicCopy = false;
        if (basename(__DIR__) === 'public' && is_file($parent . '/src/App.php') && is_file($parent . '/' . self::STATE_DIR . '/state.json')) {
            self::$base = $parent;
            self::$isPublicCopy = true;
        }
    }

    // ---------------------------------------------------------------- security

    private static function securityHeaders($nonce = '')
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        $csp = "default-src 'none'; img-src data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";
        if ($nonce !== '') {
            $csp .= "; style-src 'nonce-$nonce'; script-src 'nonce-$nonce'; connect-src 'self'";
        } else {
            $csp .= "; style-src 'unsafe-inline'";
        }
        header('Content-Security-Policy: ' . $csp);
    }

    private static function isHttps()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        return isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0]) === 'https';
    }

    private static function cookieValue($expires)
    {
        return $expires . '.' . hash_hmac('sha256', $expires . '|gnozio-installer', INSTALL_KEY);
    }

    private static function setCookie()
    {
        setcookie(self::COOKIE, self::cookieValue((string)(time() + self::COOKIE_SECONDS)), [
            'expires' => time() + self::COOKIE_SECONDS, 'path' => '/', 'secure' => self::isHttps(), 'httponly' => true, 'samesite' => 'Strict',
        ]);
    }

    private static function authenticated()
    {
        if (strlen(INSTALL_KEY) < self::MIN_KEY_LENGTH || empty($_COOKIE[self::COOKIE])) {
            return false;
        }
        $parts = explode('.', (string)$_COOKIE[self::COOKIE], 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0]) || (int)$parts[0] < time()) {
            return false;
        }
        return hash_equals(self::cookieValue($parts[0]), (string)$_COOKIE[self::COOKIE]);
    }

    private static function selfPath()
    {
        $path = (string)parse_url((string)(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/installer.php'), PHP_URL_PATH);
        return $path !== '' ? $path : '/installer.php';
    }

    // ---------------------------------------------------------------- steps (JSON)

    private static function json(array $data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private static function fail($key, array $args = [])
    {
        return ['ok' => false, 'error' => ['key' => $key, 'args' => (object)$args]];
    }

    private static function handleStep($step)
    {
        if (strtoupper((string)(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '')) !== 'POST'
            || !isset($_SERVER['HTTP_X_GNOZIO_INSTALLER']) || $_SERVER['HTTP_X_GNOZIO_INSTALLER'] !== '1'
            || !self::authenticated()) {
            self::json(self::fail('e_auth'), 403);
            return;
        }
        if (!in_array($step, array_merge(['status', 'begin'], self::STEPS), true)) {
            self::json(self::fail('e_unknown'), 404);
            return;
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        clearstatcache(true);
        try {
            $state = self::readState();
            if ($state === null && !in_array($step, ['status', 'check', 'begin'], true)) {
                self::json(self::fail('e_nostate'));
                return;
            }
            if ($state === null && self::existingInstall() !== '') {
                self::json(self::fail('e_existing'), 409);
                return;
            }
            if ($state !== null && !empty($state['finished'])) {
                self::json(self::fail('e_finished'), 409);
                return;
            }
            switch ($step) {
                case 'status':
                    $result = self::stepStatus($state);
                    break;
                case 'check':
                    $result = self::stepCheck();
                    break;
                case 'begin':
                    $result = self::stepBegin($state);
                    break;
                case 'download':
                    $result = self::stepDownload($state);
                    break;
                case 'verify':
                    $result = self::stepVerify($state);
                    break;
                case 'unpack':
                    $result = self::stepUnpack($state);
                    break;
                case 'prepare':
                    $result = self::stepPrepare($state);
                    break;
                case 'docroot':
                    $result = self::stepDocroot($state);
                    break;
                default:
                    $result = self::stepFinish($state);
            }
            self::json($result);
        } catch (Throwable $e) {
            self::json(self::fail('e_internal', ['detail' => $e->getMessage()]));
        }
    }

    private static function stepStatus($state)
    {
        $out = ['ok' => true, 'phase' => 'fresh', 'done' => [], 'servedFromPublic' => self::$isPublicCopy];
        if ($state !== null) {
            $out['phase'] = !empty($state['waiting']) ? 'waiting' : 'running';
            $out['done'] = array_values($state['done']);
            $out['version'] = $state['manifest']['version'];
            $out['size'] = $state['manifest']['size'];
            $out['downloaded'] = $state['downloaded'];
            $out['demo'] = !empty($state['demo']);
            $out['docroot'] = realpath(self::$base) . '/public';
        }
        return $out;
    }

    // ---- 1. server check

    private static function stepCheck()
    {
        $rows = [];
        $blocking = false;
        $add = function ($id, $level, $key, array $args = []) use (&$rows, &$blocking) {
            $rows[] = ['id' => $id, 'level' => $level, 'key' => $key, 'args' => (object)$args];
            if ($level === 'fail') {
                $blocking = true;
            }
        };
        $manifest = null;
        $error = null;
        $manifest = self::fetchManifest($error);
        $minPhp = $manifest !== null ? $manifest['min_php'] : self::MIN_PHP_FALLBACK;
        $add('php', version_compare(PHP_VERSION, $minPhp, '>=') ? 'ok' : 'fail', version_compare(PHP_VERSION, $minPhp, '>=') ? 'c_php_ok' : 'c_php_bad', ['have' => PHP_VERSION, 'need' => $minPhp]);
        foreach (self::EXTENSIONS as $extension => $required) {
            if (extension_loaded($extension)) {
                $add('ext_' . $extension, 'ok', 'c_ext_ok', ['name' => $extension]);
            } elseif ($extension === 'curl' && extension_loaded('openssl') && filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
                $add('ext_curl', 'warn', 'c_ext_curl_warn');
            } else {
                $add('ext_' . $extension, $required ? 'fail' : 'warn', $required ? 'c_ext_bad' : 'c_ext_warn', ['name' => $extension]);
            }
        }
        $writable = self::probeWritable(__DIR__);
        $add('writable', $writable ? 'ok' : 'fail', $writable ? 'c_write_ok' : 'c_write_bad', ['path' => __DIR__]);
        $free = function_exists('disk_free_space') ? @disk_free_space(self::$base) : false;
        if ($manifest !== null && $free !== false) {
            $need = 3 * $manifest['size'];
            $add('disk', $free >= $need ? 'ok' : 'fail', $free >= $need ? 'c_disk_ok' : 'c_disk_bad', ['free' => self::mb($free), 'need' => self::mb($need)]);
        } else {
            $add('disk', 'warn', 'c_disk_unknown');
        }
        if ($manifest !== null) {
            $add('network', 'ok', 'c_net_ok');
            $add('release', 'ok', 'c_release_ok', ['version' => $manifest['version'], 'size' => self::mb($manifest['size'])]);
        } else {
            $add('network', 'fail', 'c_net_bad', ['detail' => (string)$error]);
        }
        return ['ok' => true, 'rows' => $rows, 'blocking' => $blocking, 'version' => $manifest !== null ? $manifest['version'] : null];
    }

    private static function probeWritable($dir)
    {
        $file = $dir . '/.gnozio-write-test-' . bin2hex(random_bytes(4));
        $ok = @file_put_contents($file, 'x') === 1;
        @unlink($file);
        return $ok;
    }

    private static function mb($bytes)
    {
        return number_format($bytes / 1048576, 1, '.', '');
    }

    // ---- manifest

    /** The address of the manifest: the latest release of the project; tests may name another one (see overrideUrl). */
    public static function manifestUrl()
    {
        $override = self::overrideUrl();
        return $override !== '' ? $override : 'https://github.com/' . self::REPOSITORY . '/releases/latest/download/release.json';
    }

    /**
     * A different manifest address for tests. It is read only when PHP runs as the built-in development server
     * (cli-server, which a production host never uses) and the environment names it, and it must be an address on this
     * machine. A visitor can set neither the SAPI nor the process environment, so it cannot be used to point a
     * production installer at another package.
     */
    private static function overrideUrl()
    {
        if (PHP_SAPI !== 'cli-server') {
            return '';
        }
        $url = (string)getenv('GNOZIO_INSTALLER_RELEASE_URL');
        $parts = parse_url($url);
        $host = strtolower((string)(isset($parts['host']) ? $parts['host'] : ''));
        $scheme = strtolower((string)(isset($parts['scheme']) ? $parts['scheme'] : ''));
        return $scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost'], true) ? $url : '';
    }

    public static function isAllowedUrl($url)
    {
        $parts = parse_url((string)$url);
        $scheme = strtolower((string)(isset($parts['scheme']) ? $parts['scheme'] : ''));
        $host = strtolower((string)(isset($parts['host']) ? $parts['host'] : ''));
        return $host !== '' && ($scheme === 'https' || ($scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost', '[::1]', '::1'], true)));
    }

    /** The same checks as UpdateService::validateManifest. */
    public static function validateManifest($data)
    {
        if (!is_array($data)) {
            return null;
        }
        $version = (string)(isset($data['version']) ? $data['version'] : '');
        $minPhp = (string)(isset($data['min_php']) ? $data['min_php'] : '');
        $url = (string)(isset($data['package_url']) ? $data['package_url'] : '');
        $sha = strtolower((string)(isset($data['sha256']) ? $data['sha256'] : ''));
        $size = (int)(isset($data['size']) ? $data['size'] : 0);
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version) || !preg_match('/^\d+(\.\d+)*$/', $minPhp)
            || !preg_match('/^[a-f0-9]{64}$/', $sha) || $size < 1 || $size > self::MAX_PACKAGE_BYTES || !self::isAllowedUrl($url)) {
            return null;
        }
        return ['version' => $version, 'min_php' => $minPhp, 'package_url' => $url, 'sha256' => $sha, 'size' => $size];
    }

    private static function fetchManifest(&$error)
    {
        $path = tempnam(sys_get_temp_dir(), 'gzm');
        if ($path === false) {
            $error = 'no temporary file';
            return null;
        }
        $failure = self::fetch(self::manifestUrl(), $path, 0, 0, $status, self::MAX_MANIFEST_BYTES);
        $body = $failure === null ? (string)@file_get_contents($path) : '';
        @unlink($path);
        if ($failure !== null) {
            $error = $failure;
            return null;
        }
        $manifest = self::validateManifest(json_decode($body, true));
        if ($manifest === null) {
            $error = 'the release manifest is not valid';
        }
        return $manifest;
    }

    // ---------------------------------------------------------------- network

    private static function hasCurl()
    {
        foreach (['curl_init', 'curl_setopt_array', 'curl_exec', 'curl_error', 'curl_getinfo'] as $function) {
            if (!function_exists($function)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Fetches bytes $offset .. $offset+$length-1 (all of it when $length is 0 and $offset 0) and appends them to a
     * file. A server that ignores the range and sends the whole file from the start is accepted when $offset is 0 and
     * the whole file is not above $maxBytes. Returns an error message or null; $status gets the final HTTP status.
     */
    private static function fetch($url, $destination, $offset, $length, &$status, $maxBytes = null)
    {
        if (!self::isAllowedUrl($url)) {
            return 'the address is not allowed (it must be https)';
        }
        $maxBytes = $maxBytes === null ? ($length > 0 ? $length : self::MAX_MANIFEST_BYTES) : $maxBytes;
        $out = @fopen($destination, $offset > 0 ? 'ab' : 'wb');
        if ($out === false) {
            return 'the file could not be written';
        }
        $written = 0;
        $status = 0;
        $error = null;
        $ranged = $length > 0;
        if (self::hasCurl()) {
            $ch = curl_init($url);
            $options = [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_USERAGENT => 'GnozioCMS installer',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_REDIR_PROTOCOLS => strncasecmp($url, 'https://', 8) === 0 ? CURLPROTO_HTTPS : CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$status) {
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                        $status = (int)$m[1];
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use ($out, $maxBytes, $offset, &$written, &$status, &$error) {
                    if ($status >= 400) {
                        return strlen($chunk);
                    }
                    if ($status === 200 && $offset > 0) {
                        $error = 'the server ignored the range of the download';
                        return 0;
                    }
                    $written += strlen($chunk);
                    if ($written > $maxBytes) {
                        $error = 'the file is larger than expected';
                        return 0;
                    }
                    return (int)fwrite($out, $chunk);
                },
            ];
            if ($ranged) {
                $options[CURLOPT_RANGE] = $offset . '-' . ($offset + $length - 1);
            }
            curl_setopt_array($ch, $options);
            $ok = curl_exec($ch);
            if ($ok === false && $error === null) {
                $error = curl_error($ch);
            }
            if ($status === 0) {
                $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            }
            unset($ch);
        } else {
            $header = 'User-Agent: GnozioCMS installer';
            if ($ranged) {
                $header .= "\r\nRange: bytes=" . $offset . '-' . ($offset + $length - 1);
            }
            $in = @fopen($url, 'rb', false, stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 1, 'max_redirects' => 5, 'ignore_errors' => true, 'header' => $header]]));
            if ($in === false) {
                $error = 'the address could not be reached (cURL is missing and PHP may not be allowed to open web addresses: allow_url_fopen)';
            } else {
                $meta = stream_get_meta_data($in);
                $lines = isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) ? $meta['wrapper_data'] : [];
                foreach ($lines as $line) {
                    if (is_string($line) && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                        $status = (int)$m[1];
                    }
                }
                if ($status < 400) {
                    if ($status === 200 && $offset > 0) {
                        $error = 'the server ignored the range of the download';
                    }
                    while ($error === null && !feof($in)) {
                        $chunk = fread($in, 65536);
                        if ($chunk === false) {
                            $error = 'the download was interrupted';
                            break;
                        }
                        $written += strlen($chunk);
                        if ($written > $maxBytes) {
                            $error = 'the file is larger than expected';
                            break;
                        }
                        fwrite($out, $chunk);
                    }
                }
                fclose($in);
            }
        }
        fclose($out);
        if ($error === null && ($status < 200 || $status >= 300)) {
            $error = 'the server answered ' . $status;
        }
        if ($error !== null && $offset === 0) {
            @unlink($destination);
        }
        return $error;
    }

    // ---------------------------------------------------------------- state

    private static function stateDir()
    {
        return self::$base . '/' . self::STATE_DIR;
    }

    private static function readState()
    {
        $file = self::stateDir() . '/state.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) && isset($data['manifest']['version'], $data['done']) ? $data : null;
    }

    private static function writeState(array $state)
    {
        $dir = self::stateDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('storage/install could not be made');
        }
        $tmp = $dir . '/state.json.tmp';
        if (@file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX) === false || !@rename($tmp, $dir . '/state.json')) {
            throw new RuntimeException('the installer state could not be saved');
        }
    }

    /** A reason why a site is installed here already, or an empty string. */
    public static function existingInstall($base = null)
    {
        $base = $base === null ? self::$base : $base;
        if (is_file($base . '/VERSION') && is_dir($base . '/src')) {
            return 'code';
        }
        if (is_file($base . '/storage/db/app.sqlite')) {
            return 'database';
        }
        return '';
    }

    private static function done(array &$state, $step)
    {
        if (!in_array($step, $state['done'], true)) {
            $state['done'][] = $step;
        }
        self::writeState($state);
    }

    // ---- 2. begin / download / verify

    private static function stepBegin($state)
    {
        if ($state !== null) {
            return ['ok' => true, 'resumed' => true];
        }
        $error = null;
        $manifest = self::fetchManifest($error);
        if ($manifest === null) {
            return self::fail('e_manifest', ['detail' => (string)$error]);
        }
        if (!version_compare(PHP_VERSION, $manifest['min_php'], '>=')) {
            return self::fail('c_php_bad', ['have' => PHP_VERSION, 'need' => $manifest['min_php']]);
        }
        $state = [
            'created' => gmdate('c'), 'manifest' => $manifest, 'done' => ['check'], 'downloaded' => 0,
            'demo' => !empty($_POST['demo']), 'probe' => bin2hex(random_bytes(16)), 'waiting' => false, 'finished' => false,
        ];
        self::writeState($state);
        $htaccess = self::stateDir() . '/.htaccess';
        @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        @file_put_contents(self::stateDir() . '/index.html', '');
        return ['ok' => true];
    }

    private static function stepDownload($state)
    {
        if (!empty($state['refresh'])) {
            // After a refused package the release may have been republished: read its manifest again (the old one is kept when GitHub does not answer).
            $error = null;
            $fresh = self::fetchManifest($error);
            if ($fresh !== null) {
                $state['manifest'] = $fresh;
            }
            $state['refresh'] = false;
            self::writeState($state);
        }
        $total = $state['manifest']['size'];
        $file = self::stateDir() . '/package.zip';
        $offset = (int)$state['downloaded'];
        if ($offset > 0 && (!is_file($file) || filesize($file) !== $offset)) {
            $offset = 0;
        }
        if ($offset >= $total) {
            self::done($state, 'download');
            return ['ok' => true, 'done' => true, 'downloaded' => $total, 'total' => $total];
        }
        $length = min(self::CHUNK_BYTES, $total - $offset);
        // A server that ignores the range sends the whole package from the first request: accept up to the size.
        $failure = self::fetch($state['manifest']['package_url'], $file, $offset, $length, $status, $offset === 0 ? $total + 1 : $length);
        if ($failure !== null) {
            return self::fail('e_download', ['detail' => $failure]);
        }
        $size = (int)filesize($file);
        if ($size > $total) {
            @unlink($file);
            $state['downloaded'] = 0;
            self::writeState($state);
            return self::fail('e_size');
        }
        $state['downloaded'] = $size;
        $finished = $size >= $total;
        if ($finished) {
            $state['done'][] = 'download';
        }
        self::writeState($state);
        return ['ok' => true, 'done' => $finished, 'downloaded' => $size, 'total' => $total];
    }

    private static function stepVerify($state)
    {
        $file = self::stateDir() . '/package.zip';
        if (!is_file($file) || filesize($file) !== $state['manifest']['size']) {
            return self::restartDownload($state, 'e_size');
        }
        if (!hash_equals($state['manifest']['sha256'], (string)hash_file('sha256', $file))) {
            return self::restartDownload($state, 'e_sha');
        }
        self::done($state, 'verify');
        return ['ok' => true];
    }

    /** A package that is not the published one is thrown away; "Try again" downloads it anew. */
    private static function restartDownload(array $state, $key)
    {
        @unlink(self::stateDir() . '/package.zip');
        $state['downloaded'] = 0;
        $state['done'] = array_values(array_diff($state['done'], ['download', 'verify']));
        $state['refresh'] = true;
        self::writeState($state);
        return self::fail($key, ['restart' => 'download']);
    }

    // ---- 4. unpack

    /** Whether a path inside a package is one an install may write (the same rule as UpdateInstaller::isAllowedPath). */
    public static function isAllowedPath($name)
    {
        if ($name === '' || strpos($name, "\0") !== false || strpos($name, '\\') !== false || $name[0] === '/' || preg_match('/^[a-z]:/i', $name)) {
            return false;
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '..' || $segment === '.' || $segment === '') {
                return false;
            }
        }
        if (in_array($name, self::FILES, true)) {
            return true;
        }
        foreach (self::DIRECTORIES as $directory) {
            if (strncmp($name, $directory . '/', strlen($directory) + 1) === 0) {
                return true;
            }
        }
        return (bool)preg_match('#^themes/[A-Za-z0-9_-]+/.+#', $name);
    }

    /** Whether writing $relative under $base would pass through a link (a link could lead out of the folder). */
    private static function passesLink($base, $relative)
    {
        $path = $base;
        foreach (explode('/', $relative) as $segment) {
            $path .= '/' . $segment;
            if (is_link($path)) {
                return true;
            }
        }
        return false;
    }

    private static function stepUnpack($state)
    {
        $zipPath = self::stateDir() . '/package.zip';
        if (!class_exists('ZipArchive') || !is_file($zipPath)) {
            return self::fail('e_zip_bad');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            return self::fail('e_zip_bad');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
                return self::fail('e_zip_count');
            }
            $total = 0;
            $entries = [];
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = is_array($stat) ? (string)$stat['name'] : '';
                if ($name === '' || substr($name, -1) === '/') {
                    continue;
                }
                if (!self::isAllowedPath($name)) {
                    return self::fail('e_zip_unsafe', ['name' => $name]);
                }
                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) && $opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                    return self::fail('e_zip_link', ['name' => $name]);
                }
                $total += (int)$stat['size'];
                $entries[] = [$i, $name, (int)$stat['size']];
                $names[$name] = true;
            }
            if ($total > self::MAX_UNPACKED_BYTES) {
                return self::fail('e_zip_large');
            }
            foreach (['VERSION', 'src/App.php', 'public/index.php', 'vendor/autoload.php', 'themes/default/theme.yaml'] as $required) {
                if (!isset($names[$required])) {
                    return self::fail('e_zip_incomplete', ['name' => $required]);
                }
            }
            if (trim((string)$zip->getFromName('VERSION')) !== $state['manifest']['version']) {
                return self::fail('e_zip_version', ['version' => $state['manifest']['version']]);
            }
            $base = self::$base;
            foreach ($entries as $entry) {
                if (self::passesLink($base, $entry[1])) {
                    return self::fail('e_zip_link', ['name' => $entry[1]]);
                }
            }
            foreach ($entries as $entry) {
                list($index, $name, $size) = $entry;
                $target = $base . '/' . $name;
                if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0775, true)) {
                    return self::fail('e_unpack_write', ['name' => $name]);
                }
                if (is_dir($target)) {
                    return self::fail('e_unpack_write', ['name' => $name]);
                }
                $in = $zip->getStream($zip->getNameIndex($index));
                $out = $in !== false ? @fopen($target, 'wb') : false;
                if ($in === false || $out === false) {
                    return self::fail('e_unpack_write', ['name' => $name]);
                }
                $copied = stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                if ($copied !== $size) {
                    return self::fail('e_unpack_write', ['name' => $name]);
                }
            }
        } finally {
            $zip->close();
        }
        @unlink($zipPath);
        self::done($state, 'unpack');
        return ['ok' => true, 'files' => count($entries)];
    }

    // ---- 5. prepare: folders, the demo, the public copy

    private static function stepPrepare($state)
    {
        $base = self::$base;
        foreach (['content', 'custom', 'storage/db', 'storage/backups', 'public/uploads'] as $dir) {
            if (!is_dir($base . '/' . $dir) && !@mkdir($base . '/' . $dir, 0775, true)) {
                return self::fail('e_unpack_write', ['name' => $dir . '/']);
            }
        }
        if (!empty($state['demo'])) {
            $copied = self::copyStarter($base);
            if ($copied === null) {
                return self::fail('e_demo');
            }
        }
        // The same address must keep working once public/ is the document root: this copy, with the same key, goes there.
        $probe = $base . '/public/gnozio-installer-' . $state['probe'] . '.txt';
        $public = $base . '/public/installer.php';
        $source = (string)@file_get_contents(self::$isPublicCopy ? $public : __FILE__);
        if ($source === '' || @file_put_contents($public . '.tmp', $source, LOCK_EX) === false || !@rename($public . '.tmp', $public)) {
            return self::fail('e_unpack_write', ['name' => 'public/installer.php']);
        }
        if (@file_put_contents($probe, $state['probe']) === false) {
            return self::fail('e_unpack_write', ['name' => basename($probe)]);
        }
        $state['waiting'] = true;
        self::done($state, 'prepare');
        return ['ok' => true, 'docroot' => realpath($base) . '/public'];
    }

    /** What scripts/use-starter.php does, in this file: the demo pages and pictures, never over existing content. Null when there is none. */
    private static function copyStarter($base)
    {
        if (!is_dir($base . '/starter/content')) {
            return null;
        }
        if (glob($base . '/content/pages/*.md') || glob($base . '/content/posts/*.md')) {
            return 0;
        }
        $count = 0;
        foreach ([$base . '/starter/content' => $base . '/content', $base . '/starter/uploads' => $base . '/public/uploads'] as $from => $to) {
            if (!is_dir($from)) {
                continue;
            }
            @mkdir($to, 0775, true);
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($items as $item) {
                $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
                if ($item->isDir()) {
                    @mkdir($target, 0775, true);
                } elseif (!is_file($target)) {
                    @mkdir(dirname($target), 0775, true);
                    if (copy($item->getPathname(), $target)) {
                        $count++;
                    }
                }
            }
        }
        return $count;
    }

    // ---- 6. the document root

    private static function selfOrigin()
    {
        $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$|^\[[0-9a-f:]+\](:\d{1,5})?$/i', $host)) {
            return '';
        }
        return (self::isHttps() ? 'https://' : 'http://') . $host;
    }

    /**
     * Whether the web server now serves public/ as the document root. Only the copy inside public/ can say so, and
     * only when the server names that folder as its document root, or when this site answers, at its own address, with
     * the secret of a file that exists only in public/. Anything less is "not yet".
     */
    private static function docrootChanged(array $state)
    {
        if (!self::$isPublicCopy) {
            return false;
        }
        $root = isset($_SERVER['DOCUMENT_ROOT']) ? (string)$_SERVER['DOCUMENT_ROOT'] : '';
        if ($root !== '' && realpath($root) !== false && realpath($root) === realpath(__DIR__)) {
            return true;
        }
        $origin = self::selfOrigin();
        if ($origin === '' || !function_exists('curl_init') && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }
        $path = tempnam(sys_get_temp_dir(), 'gzp');
        if ($path === false) {
            return false;
        }
        $status = 0;
        $failure = self::fetch($origin . '/gnozio-installer-' . $state['probe'] . '.txt', $path, 0, 0, $status, 256);
        $body = $failure === null ? trim((string)@file_get_contents($path)) : '';
        @unlink($path);
        return $failure === null && hash_equals($state['probe'], $body);
    }

    private static function stepDocroot($state)
    {
        if (empty($state['waiting'])) {
            return self::fail('e_order');
        }
        if (!self::docrootChanged($state)) {
            return ['ok' => true, 'changed' => false, 'servedFromPublic' => self::$isPublicCopy, 'docroot' => realpath(self::$base) . '/public'];
        }
        self::done($state, 'docroot');
        return ['ok' => true, 'changed' => true];
    }

    // ---- 7. finish

    private static function stepFinish($state)
    {
        if (!in_array('docroot', $state['done'], true) || !self::docrootChanged($state)) {
            return self::fail('e_docroot_wait');
        }
        $base = self::$base;
        $left = [];
        foreach ([$base . '/installer.php', $base . '/public/installer.php', $base . '/public/installer.php.tmp', $base . '/public/gnozio-installer-' . $state['probe'] . '.txt'] as $file) {
            if (is_file($file) && !@unlink($file)) {
                $left[] = $file;
            }
        }
        $dir = self::stateDir();
        $items = is_dir($dir) ? scandir($dir) : [];
        foreach ($items === false ? [] : $items as $item) {
            if ($item !== '.' && $item !== '..' && is_file($dir . '/' . $item)) {
                @unlink($dir . '/' . $item);
            }
        }
        @rmdir($dir);
        if (is_dir($dir)) {
            $left[] = $dir;
        }
        return ['ok' => true, 'redirect' => '/admin', 'left' => $left];
    }

    // ---------------------------------------------------------------- language

    private static function chooseLanguage()
    {
        if (isset($_COOKIE['gz_lang']) && in_array($_COOKIE['gz_lang'], ['en', 'el'], true)) {
            return (string)$_COOKIE['gz_lang'];
        }
        $accept = strtolower((string)(isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : ''));
        $el = strpos($accept, 'el');
        $en = strpos($accept, 'en');
        return $el !== false && ($en === false || $el < $en) ? 'el' : 'en';
    }

    /** @return array<string, array<string, string>> */
    public static function strings()
    {
        return [
            'en' => [
                'title' => 'GnozioCMS installer', 'subtitle' => 'Installer',
                'key_title' => 'Unlock the installer', 'key_help' => 'Enter the key you set in installer.php before uploading it.', 'key_label' => 'Installer key', 'key_button' => 'Unlock', 'key_wrong' => 'That is not the key. Check INSTALL_KEY in the file.',
                'no_key_title' => 'Set an installer key first', 'no_key_text' => 'This installer is locked until it has a key. Open installer.php in a text editor, set INSTALL_KEY near the top to a long random value of at least 12 characters (for example: const INSTALL_KEY = \'your-long-random-key\';), save it and upload it again.',
                'existing_title' => 'GnozioCMS is already installed here', 'existing_text' => 'This folder already holds a GnozioCMS site, so the installer will not touch it. Delete installer.php from the server. To update the site, use Admin → Updates.',
                'finished_title' => 'This installation has finished', 'finished_text' => 'Delete installer.php from the server if it is still there, then open /admin.',
                's_check' => 'Server check', 's_download' => 'Download', 's_verify' => 'Verify', 's_unpack' => 'Unpack', 's_prepare' => 'Prepare the site', 's_docroot' => 'Document root', 's_finish' => 'Finish',
                'pending' => 'Waiting', 'running' => 'Working', 'done' => 'Done', 'failed' => 'Failed', 'waiting' => 'Needs you',
                'checking' => 'Checking the server…', 'check_card' => 'Your server', 'check_blocked' => 'Something below must be fixed before the installation can start. Fix it, then check again.', 'check_again' => 'Check again',
                'c_php_ok' => 'PHP {have} (needs {need} or newer)', 'c_php_bad' => 'PHP {have} is too old: GnozioCMS needs {need} or newer. Choose a newer PHP version for this site in your hosting panel.',
                'c_ext_ok' => '{name} is available', 'c_ext_all' => 'PHP extensions available: {list}', 'c_ext_bad' => 'The PHP extension {name} is missing. Enable it in your hosting panel (PHP settings) or ask your host.', 'c_ext_warn' => 'The optional PHP extension {name} is missing; some features will be limited.', 'c_ext_curl_warn' => 'cURL is missing; downloads will use PHP streams instead, which works too.',
                'c_write_ok' => 'This folder is writable', 'c_write_bad' => 'PHP cannot write to this folder ({path}). Give the web user write permission to it.',
                'c_disk_ok' => '{free} MB free (needs {need} MB)', 'c_disk_bad' => 'Only {free} MB free; {need} MB are needed. Free some space and check again.', 'c_disk_unknown' => 'Free disk space could not be measured; the installation will go ahead.',
                'c_net_ok' => 'GitHub can be reached over HTTPS', 'c_net_bad' => 'The server could not read the release from GitHub ({detail}). Check that outgoing HTTPS connections are allowed, then check again.',
                'c_release_ok' => 'Latest release: GnozioCMS {version} ({size} MB)',
                'options' => 'Options', 'demo' => 'Start from the demo site', 'demo_help' => 'Adds example pages, posts and pictures that you can edit or delete. Leave it off for an empty site.', 'start' => 'Install GnozioCMS',
                'dl_progress' => '{done} of {total} MB', 'verify_ok' => 'Size and SHA-256 match the release', 'unpack_ok' => '{files} files unpacked',
                'retry' => 'Try again', 'what_next' => 'What to do', 'details' => 'Details',
                'docroot_title' => 'One thing is left: change the document root', 'docroot_text' => 'The installer cannot move files above the web root. Set this site\'s document root to the folder below, so only public/ is served to visitors. Keep this page open; when you are done, press the button.',
                'docroot_path' => 'Document root', 'copy' => 'Copy', 'copied' => 'Copied',
                'docroot_cp' => 'CloudPanel: Sites → your site → Settings → Root Directory. Enter public (the folder inside the site\'s folder) and save.', 'docroot_other' => 'Other panels: find "Document root", "Web root" or "Root directory" for this site, and set it to the path above. In Apache or nginx set root / DocumentRoot to it.',
                'docroot_same' => 'The address of this page stays the same: the installer has copied itself into public/, so this page keeps working after the change.',
                'docroot_button' => 'I\'ve changed it — continue', 'docroot_checking' => 'Checking…',
                'docroot_not_yet' => 'The site is still served from the old folder. Make sure the document root is the path above and saved, wait a moment for the web server to reload, then press the button again.', 'docroot_not_public' => 'This page is still coming from the old location. Change the document root, then press the button again.',
                'finishing' => 'Removing the installer…', 'finish_ok' => 'Installer removed', 'finish_left' => 'These could not be deleted; please delete them by hand: {left}',
                'redirecting' => 'GnozioCMS is installed. Opening the admin to create the first administrator…',
                'e_auth' => 'Your session ended. Reload the page and enter the key again.', 'e_unknown' => 'Unknown step.', 'e_nostate' => 'The installation has not started. Reload the page.', 'e_existing' => 'A GnozioCMS site already exists in this folder; the installer stops here.', 'e_finished' => 'This installation has finished.', 'e_internal' => 'Something unexpected went wrong: {detail}', 'e_manifest' => 'The latest release could not be read from GitHub ({detail}). Try again in a minute.',
                'e_download' => 'The download failed: {detail}. Try again.', 'e_size' => 'The downloaded file has the wrong size, so it was thrown away. Try again; if it repeats, GitHub or a proxy is altering the download.', 'e_sha' => 'The downloaded package is not the one that was published (its checksum differs), so it was refused and thrown away. Nothing was installed. Try again.',
                'e_zip_bad' => 'The package is not a valid ZIP file (or the ZIP extension is missing).', 'e_zip_count' => 'The package has an unexpected number of files, so it was refused.', 'e_zip_unsafe' => 'The package holds a file an install may not write ({name}), so it was refused. Nothing was installed.', 'e_zip_link' => 'The package holds a link ({name}), so it was refused. Nothing was installed.', 'e_zip_large' => 'The package is larger than expected once unpacked, so it was refused.', 'e_zip_incomplete' => 'The package is incomplete ({name} is missing).', 'e_zip_version' => 'The package is not version {version}.',
                'e_unpack_write' => 'Could not write {name}. Check the permissions of this folder and try again.', 'e_demo' => 'The demo site is not part of this package. Try again without the demo option.',
                'e_order' => 'That step is not ready yet. Reload the page.', 'e_docroot_wait' => 'The document root has not been changed yet, so the installer cannot finish.', 'e_badresponse' => 'The server answered with something unexpected (status {status}). Try again.', 'e_network' => 'The page could not reach the server. Check your connection and try again.',
            ],
            'el' => [
                'title' => 'Εγκατάσταση GnozioCMS', 'subtitle' => 'Εγκατάσταση',
                'key_title' => 'Ξεκλείδωμα του installer', 'key_help' => 'Γράψε το κλειδί που όρισες στο installer.php πριν το ανεβάσεις.', 'key_label' => 'Κλειδί εγκατάστασης', 'key_button' => 'Ξεκλείδωμα', 'key_wrong' => 'Το κλειδί δεν είναι σωστό. Έλεγξε το INSTALL_KEY μέσα στο αρχείο.',
                'no_key_title' => 'Όρισε πρώτα κλειδί εγκατάστασης', 'no_key_text' => 'Ο installer μένει κλειδωμένος μέχρι να έχει κλειδί. Άνοιξε το installer.php σε έναν επεξεργαστή κειμένου, βάλε στο INSTALL_KEY (στην αρχή του αρχείου) μια μακριά τυχαία τιμή τουλάχιστον 12 χαρακτήρων (π.χ. const INSTALL_KEY = \'το-μακρύ-τυχαίο-κλειδί-σου\';), αποθήκευσε και ανέβασέ το ξανά.',
                'existing_title' => 'Το GnozioCMS είναι ήδη εγκατεστημένο εδώ', 'existing_text' => 'Αυτός ο φάκελος έχει ήδη site GnozioCMS, οπότε ο installer δεν θα το αγγίξει. Διέγραψε το installer.php από τον server. Για αναβάθμιση, χρησιμοποίησε το Admin → Updates.',
                'finished_title' => 'Η εγκατάσταση ολοκληρώθηκε', 'finished_text' => 'Διέγραψε το installer.php από τον server αν είναι ακόμη εκεί και άνοιξε το /admin.',
                's_check' => 'Έλεγχος server', 's_download' => 'Λήψη', 's_verify' => 'Επαλήθευση', 's_unpack' => 'Αποσυμπίεση', 's_prepare' => 'Προετοιμασία του site', 's_docroot' => 'Document root', 's_finish' => 'Ολοκλήρωση',
                'pending' => 'Αναμονή', 'running' => 'Σε εξέλιξη', 'done' => 'Έτοιμο', 'failed' => 'Απέτυχε', 'waiting' => 'Θέλει εσένα',
                'checking' => 'Έλεγχος του server…', 'check_card' => 'Ο server σου', 'check_blocked' => 'Κάτι από τα παρακάτω πρέπει να διορθωθεί πριν ξεκινήσει η εγκατάσταση. Διόρθωσέ το και έλεγξε ξανά.', 'check_again' => 'Έλεγχος ξανά',
                'c_php_ok' => 'PHP {have} (χρειάζεται {need} ή νεότερη)', 'c_php_bad' => 'Η PHP {have} είναι παλιά: το GnozioCMS θέλει {need} ή νεότερη. Διάλεξε νεότερη έκδοση PHP για αυτό το site στο πάνελ του hosting.',
                'c_ext_ok' => 'Το {name} είναι διαθέσιμο', 'c_ext_all' => 'Διαθέσιμες επεκτάσεις PHP: {list}', 'c_ext_bad' => 'Λείπει η επέκταση PHP {name}. Ενεργοποίησέ την στο πάνελ (ρυθμίσεις PHP) ή ρώτα τον πάροχο.', 'c_ext_warn' => 'Λείπει η προαιρετική επέκταση PHP {name}· κάποιες λειτουργίες θα είναι περιορισμένες.', 'c_ext_curl_warn' => 'Λείπει το cURL· οι λήψεις θα γίνουν με streams της PHP, που δουλεύουν κι αυτά.',
                'c_write_ok' => 'Ο φάκελος είναι εγγράψιμος', 'c_write_bad' => 'Η PHP δεν μπορεί να γράψει σε αυτόν τον φάκελο ({path}). Δώσε δικαίωμα εγγραφής στον χρήστη του web server.',
                'c_disk_ok' => '{free} MB ελεύθερα (χρειάζονται {need} MB)', 'c_disk_bad' => 'Μόνο {free} MB ελεύθερα· χρειάζονται {need} MB. Άδειασε χώρο και έλεγξε ξανά.', 'c_disk_unknown' => 'Δεν μετρήθηκε ο ελεύθερος χώρος· η εγκατάσταση θα συνεχίσει.',
                'c_net_ok' => 'Το GitHub είναι προσβάσιμο μέσω HTTPS', 'c_net_bad' => 'Ο server δεν μπόρεσε να διαβάσει την έκδοση από το GitHub ({detail}). Έλεγξε ότι επιτρέπονται εξερχόμενες συνδέσεις HTTPS και ξαναδοκίμασε.',
                'c_release_ok' => 'Τελευταία έκδοση: GnozioCMS {version} ({size} MB)',
                'options' => 'Επιλογές', 'demo' => 'Ξεκίνα από το demo site', 'demo_help' => 'Προσθέτει δείγμα σελίδων, άρθρων και εικόνων που μπορείς να αλλάξεις ή να σβήσεις. Άφησέ το κλειστό για κενό site.', 'start' => 'Εγκατάσταση GnozioCMS',
                'dl_progress' => '{done} από {total} MB', 'verify_ok' => 'Το μέγεθος και το SHA-256 ταιριάζουν με την έκδοση', 'unpack_ok' => 'Αποσυμπιέστηκαν {files} αρχεία',
                'retry' => 'Ξαναδοκίμασε', 'what_next' => 'Τι να κάνεις', 'details' => 'Λεπτομέρειες',
                'docroot_title' => 'Μένει ένα βήμα: άλλαξε το document root', 'docroot_text' => 'Ο installer δεν μπορεί να μετακινήσει αρχεία πάνω από το web root. Όρισε ως document root του site τον φάκελο παρακάτω, ώστε στους επισκέπτες να σερβίρεται μόνο το public/. Κράτα αυτή τη σελίδα ανοιχτή· όταν τελειώσεις, πάτα το κουμπί.',
                'docroot_path' => 'Document root', 'copy' => 'Αντιγραφή', 'copied' => 'Αντιγράφηκε',
                'docroot_cp' => 'CloudPanel: Sites → το site σου → Settings → Root Directory. Γράψε public (ο φάκελος μέσα στον φάκελο του site) και αποθήκευσε.', 'docroot_other' => 'Άλλα πάνελ: βρες το «Document root», «Web root» ή «Root directory» του site και βάλε τη διαδρομή παραπάνω. Σε Apache ή nginx όρισε το root / DocumentRoot σε αυτήν.',
                'docroot_same' => 'Η διεύθυνση αυτής της σελίδας μένει ίδια: ο installer έχει αντιγράψει τον εαυτό του στο public/, οπότε η σελίδα συνεχίζει να δουλεύει μετά την αλλαγή.',
                'docroot_button' => 'Την άλλαξα — συνέχεια', 'docroot_checking' => 'Έλεγχος…',
                'docroot_not_yet' => 'Το site σερβίρεται ακόμη από τον παλιό φάκελο. Βεβαιώσου ότι το document root είναι η διαδρομή παραπάνω και αποθηκεύτηκε, περίμενε λίγο να ξαναφορτώσει ο web server και πάτα ξανά το κουμπί.', 'docroot_not_public' => 'Αυτή η σελίδα έρχεται ακόμη από την παλιά θέση. Άλλαξε το document root και πάτα ξανά το κουμπί.',
                'finishing' => 'Διαγραφή του installer…', 'finish_ok' => 'Ο installer διαγράφηκε', 'finish_left' => 'Αυτά δεν μπόρεσαν να διαγραφούν· σβήσε τα με το χέρι: {left}',
                'redirecting' => 'Το GnozioCMS εγκαταστάθηκε. Ανοίγω το admin για να φτιάξεις τον πρώτο διαχειριστή…',
                'e_auth' => 'Η συνεδρία έληξε. Φόρτωσε ξανά τη σελίδα και γράψε το κλειδί.', 'e_unknown' => 'Άγνωστο βήμα.', 'e_nostate' => 'Η εγκατάσταση δεν έχει ξεκινήσει. Φόρτωσε ξανά τη σελίδα.', 'e_existing' => 'Υπάρχει ήδη site GnozioCMS σε αυτόν τον φάκελο· ο installer σταματά εδώ.', 'e_finished' => 'Η εγκατάσταση έχει ολοκληρωθεί.', 'e_internal' => 'Κάτι δεν πήγε όπως έπρεπε: {detail}', 'e_manifest' => 'Δεν διαβάστηκε η τελευταία έκδοση από το GitHub ({detail}). Ξαναδοκίμασε σε ένα λεπτό.',
                'e_download' => 'Η λήψη απέτυχε: {detail}. Ξαναδοκίμασε.', 'e_size' => 'Το αρχείο που κατέβηκε έχει λάθος μέγεθος και πετάχτηκε. Ξαναδοκίμασε· αν επαναληφθεί, το GitHub ή κάποιο proxy αλλοιώνει τη λήψη.', 'e_sha' => 'Το πακέτο που κατέβηκε δεν είναι αυτό που δημοσιεύτηκε (διαφέρει το checksum), οπότε απορρίφθηκε και πετάχτηκε. Δεν εγκαταστάθηκε τίποτα. Ξαναδοκίμασε.',
                'e_zip_bad' => 'Το πακέτο δεν είναι έγκυρο αρχείο ZIP (ή λείπει η επέκταση ZIP).', 'e_zip_count' => 'Το πακέτο έχει μη αναμενόμενο αριθμό αρχείων και απορρίφθηκε.', 'e_zip_unsafe' => 'Το πακέτο περιέχει αρχείο που μια εγκατάσταση δεν επιτρέπεται να γράψει ({name}) και απορρίφθηκε. Δεν εγκαταστάθηκε τίποτα.', 'e_zip_link' => 'Το πακέτο περιέχει σύνδεσμο (link) ({name}) και απορρίφθηκε. Δεν εγκαταστάθηκε τίποτα.', 'e_zip_large' => 'Το πακέτο είναι μεγαλύτερο από το αναμενόμενο μετά την αποσυμπίεση και απορρίφθηκε.', 'e_zip_incomplete' => 'Το πακέτο είναι ελλιπές (λείπει το {name}).', 'e_zip_version' => 'Το πακέτο δεν είναι η έκδοση {version}.',
                'e_unpack_write' => 'Δεν γράφτηκε το {name}. Έλεγξε τα δικαιώματα του φακέλου και ξαναδοκίμασε.', 'e_demo' => 'Το demo site δεν περιλαμβάνεται σε αυτό το πακέτο. Ξαναδοκίμασε χωρίς την επιλογή demo.',
                'e_order' => 'Το βήμα δεν είναι ακόμη έτοιμο. Φόρτωσε ξανά τη σελίδα.', 'e_docroot_wait' => 'Το document root δεν έχει αλλάξει ακόμη, οπότε ο installer δεν μπορεί να ολοκληρώσει.', 'e_badresponse' => 'Ο server απάντησε κάτι μη αναμενόμενο (κατάσταση {status}). Ξαναδοκίμασε.', 'e_network' => 'Η σελίδα δεν έφτασε στον server. Έλεγξε τη σύνδεσή σου και ξαναδοκίμασε.',
            ],
        ];
    }

    private static function t($key, array $args = [])
    {
        $all = self::strings();
        $text = isset($all[self::$lang][$key]) ? $all[self::$lang][$key] : (isset($all['en'][$key]) ? $all['en'][$key] : $key);
        foreach ($args as $name => $value) {
            $text = str_replace('{' . $name . '}', (string)$value, $text);
        }
        return $text;
    }

    // ---------------------------------------------------------------- pages

    private static function h($text)
    {
        return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function css()
    {
        return <<<'CSS'
:root{color-scheme:light dark;--bg:#f1f5f9;--card:#fff;--line:#e2e8f0;--text:#1e293b;--strong:#0f172a;--muted:#64748b;--faint:#94a3b8;--btn:#0f172a;--btn-text:#fff;--btn-hover:#334155;--ok:#047857;--ok-bg:#ecfdf5;--ok-line:#a7f3d0;--warn:#b45309;--warn-bg:#fffbeb;--warn-line:#fde68a;--bad:#be123c;--bad-bg:#fff1f2;--bad-line:#fecdd3;--info-bg:#f8fafc;--ring:#cbd5e1;--mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
@media (prefers-color-scheme:dark){:root{--bg:#0f172a;--card:#1e293b;--line:#334155;--text:#e2e8f0;--strong:#f8fafc;--muted:#94a3b8;--faint:#64748b;--btn:#f8fafc;--btn-text:#0f172a;--btn-hover:#cbd5e1;--ok:#6ee7b7;--ok-bg:#022c22;--ok-line:#065f46;--warn:#fcd34d;--warn-bg:#2a1a03;--warn-line:#78350f;--bad:#fda4af;--bad-bg:#2d0a14;--bad-line:#881337;--info-bg:#0f172a;--ring:#475569}}
*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}
body{margin:0;min-height:100vh;background:var(--bg);color:var(--text);font:14px/1.5 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;-webkit-font-smoothing:antialiased}
main{width:100%;max-width:560px;margin:0 auto;padding:28px 16px 40px}
.brand{display:flex;align-items:center;gap:10px;margin-bottom:18px}
.mono{display:flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:4px;background:#0f172a;color:#fff;font-size:14px;font-weight:700;border:1px solid #334155}
.brand b{display:block;font-size:14px;font-weight:600;letter-spacing:-.01em;color:var(--strong)}.brand small{display:block;font-size:12px;font-weight:500;color:var(--faint)}
.lang{margin-left:auto;display:flex;gap:2px;border:1px solid var(--line);border-radius:6px;padding:2px;background:var(--card)}
.lang button{border:0;background:transparent;color:var(--muted);font:600 12px/1 inherit;padding:5px 8px;border-radius:4px;cursor:pointer}.lang button[aria-pressed=true]{background:var(--btn);color:var(--btn-text)}
.card{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:16px;margin-bottom:12px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.card h1,.card h2{margin:0;font-size:16px;font-weight:600;letter-spacing:-.01em;color:var(--strong)}.card h1{font-size:19px}
.card p{margin:6px 0 0;color:var(--muted)}
.label{font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--faint);margin:0 0 8px}
form{display:grid;gap:12px;margin-top:14px}label{display:grid;gap:4px;font-weight:500;font-size:13px}
input[type=password],input[type=text]{width:100%;border:1px solid var(--ring);background:var(--card);color:var(--text);border-radius:6px;padding:8px 10px;font:inherit}
input:focus-visible,button:focus-visible{outline:2px solid var(--faint);outline-offset:1px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:6px;background:var(--btn);color:var(--btn-text);padding:9px 14px;font:600 13px/1.2 inherit;cursor:pointer}.btn:hover{background:var(--btn-hover)}.btn:disabled{opacity:.55;cursor:default}
.btn.ghost{background:transparent;color:var(--text);border:1px solid var(--ring)}.btn.ghost:hover{background:var(--info-bg)}.btn.block{width:100%}.btn.small{padding:6px 10px;font-size:12px}
.err{border:1px solid var(--bad-line);background:var(--bad-bg);color:var(--bad);border-radius:6px;padding:8px 10px;font-size:13px;margin-top:12px}
.steps{list-style:none;margin:0;padding:0}.step{display:grid;grid-template-columns:24px 1fr auto;gap:10px;align-items:start;padding:7px 0;position:relative}
.step+.step:before{content:"";position:absolute;left:11px;top:-9px;height:16px;width:2px;background:var(--line)}
.dot{width:24px;height:24px;border-radius:50%;border:2px solid var(--line);display:flex;align-items:center;justify-content:center;background:var(--card);color:var(--faint);font-size:11px;font-weight:700}
.step.running .dot{border-color:var(--strong);border-top-color:transparent;animation:spin .8s linear infinite}.step.done .dot{background:var(--ok);border-color:var(--ok);color:#fff}
.step.failed .dot{background:var(--bad);border-color:var(--bad);color:#fff}.step.waiting .dot{border-color:var(--warn);color:var(--warn)}
@media (prefers-color-scheme:dark){.step.done .dot,.step.failed .dot{color:#0f172a}}
.step .t{font-weight:600;color:var(--strong);line-height:24px}.step.pending .t{color:var(--muted);font-weight:500}.step .s{font-size:12px;color:var(--muted);margin-top:-2px}
.chip{display:inline-block;border-radius:999px;border:1px solid var(--line);padding:1px 8px;font-size:11px;font-weight:600;color:var(--muted);line-height:18px;margin-top:3px}
.chip.ok{color:var(--ok);background:var(--ok-bg);border-color:var(--ok-line)}.chip.warn{color:var(--warn);background:var(--warn-bg);border-color:var(--warn-line)}.chip.fail{color:var(--bad);background:var(--bad-bg);border-color:var(--bad-line)}
.bar{height:6px;border-radius:999px;background:var(--line);overflow:hidden;margin-top:6px}.bar i{display:block;height:100%;width:0;background:var(--btn);transition:width .25s}
.rows{display:grid;gap:0}.row{display:grid;grid-template-columns:auto 1fr;gap:10px;padding:6px 0;border-top:1px solid var(--line);align-items:start}.row:first-child{border-top:0}.row span:last-child{font-size:13px}
.check{display:flex;gap:10px;align-items:flex-start}.check input{margin-top:3px}.check small{display:block;color:var(--muted);font-weight:400}
.path{display:flex;gap:8px;align-items:center;border:1px solid var(--line);background:var(--info-bg);border-radius:6px;padding:6px 6px 6px 10px;margin:10px 0}.path code{flex:1;font:12px/1.4 var(--mono);word-break:break-all;color:var(--strong)}
.note{font-size:13px;color:var(--muted);margin-top:8px}.note b{color:var(--text)}
.notice{border:1px solid var(--warn-line);background:var(--warn-bg);color:var(--warn);border-radius:6px;padding:8px 10px;font-size:13px;margin-top:10px}
.hide{display:none!important}
@keyframes spin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){.step.running .dot{animation:none}}
CSS;
    }

    private static function shell($body, $nonce, $script = '')
    {
        $strings = self::strings();
        $title = self::h($strings[self::$lang]['title']);
        $langs = '<div class="lang" role="group" aria-label="Language"><button type="button" data-lang="en" aria-pressed="' . (self::$lang === 'en' ? 'true' : 'false') . '">EN</button><button type="button" data-lang="el" aria-pressed="' . (self::$lang === 'el' ? 'true' : 'false') . '">EL</button></div>';
        echo '<!doctype html><html lang="' . self::h(self::$lang) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">'
            . '<title>' . $title . '</title><style nonce="' . $nonce . '">' . self::css() . '</style></head><body><main>'
            . '<div class="brand"><div class="mono">GZ</div><div><b>GnozioCMS</b><small data-i="subtitle">' . self::h(self::t('subtitle')) . '</small></div>' . $langs . '</div>'
            . $body . '</main><script nonce="' . $nonce . '">' . $script . '</script></body></html>';
    }

    private static function langSwitchScript()
    {
        return 'document.querySelectorAll("[data-lang]").forEach(function(b){b.addEventListener("click",function(){document.cookie="gz_lang="+b.getAttribute("data-lang")+";path=/;max-age=31536000;SameSite=Lax";location.reload();});});';
    }

    private static function notice($which)
    {
        $nonce = bin2hex(random_bytes(12));
        self::securityHeaders($nonce);
        http_response_code($which === 'no_key' ? 403 : 409);
        $key = $which === 'no_key' ? 'no_key' : $which;
        $body = '<section class="card"><h1>' . self::h(self::t($key . '_title')) . '</h1><p>' . self::h(self::t($key . '_text')) . '</p></section>';
        self::shell($body, $nonce, self::langSwitchScript());
    }

    private static function keyPage($wrong)
    {
        $nonce = bin2hex(random_bytes(12));
        self::securityHeaders($nonce);
        $body = '<section class="card"><h1>' . self::h(self::t('key_title')) . '</h1><p>' . self::h(self::t('key_help')) . '</p>'
            . ($wrong ? '<div class="err" role="alert">' . self::h(self::t('key_wrong')) . '</div>' : '')
            . '<form method="post" autocomplete="off"><label>' . self::h(self::t('key_label')) . '<input type="password" name="key" autocomplete="off" required autofocus></label>'
            . '<button class="btn block" type="submit">' . self::h(self::t('key_button')) . '</button></form></section>';
        self::shell($body, $nonce, self::langSwitchScript());
    }

    private static function appPage()
    {
        $nonce = bin2hex(random_bytes(12));
        self::securityHeaders($nonce);
        $data = json_encode(['lang' => self::$lang, 'strings' => self::strings(), 'steps' => self::STEPS, 'url' => self::selfPath()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        $body = '<div id="app"></div>';
        self::shell($body, $nonce, 'var GZ=' . $data . ';' . self::clientScript());
    }

    private static function clientScript()
    {
        return <<<'JS'
(function(){
var L=GZ.lang,S=GZ.strings,app=document.getElementById('app');
function t(k,a){var s=(S[L]&&S[L][k])||S.en[k]||k;if(a){for(var n in a){s=s.split('{'+n+'}').join(a[n]);}}return s;}
function el(tag,cls,text){var e=document.createElement(tag);if(cls)e.className=cls;if(text!=null)e.textContent=text;return e;}
var STEP_UI=['check','download','verify','unpack','prepare','docroot','finish'];
var state={};var rowEls={};
function call(step,data){
  var body=new URLSearchParams(data||{});
  return fetch(GZ.url+'?step='+step,{method:'POST',headers:{'X-Gnozio-Installer':'1','Content-Type':'application/x-www-form-urlencoded'},body:body,credentials:'same-origin',cache:'no-store'}).then(function(r){
    if(r.status===403){location.reload();throw {handled:true};}
    return r.json().catch(function(){throw {key:'e_badresponse',args:{status:r.status}};});
  },function(){throw {key:'e_network',args:{}};});
}
function msg(e){if(e&&e.error)return t(e.error.key,e.error.args);if(e&&e.key)return t(e.key,e.args);return t('e_internal',{detail:String(e&&e.message||e)});}
// ---- layout
var stepsCard=el('section','card'),stepsList=el('ol','steps');stepsCard.appendChild(stepsList);
var checkCard=el('section','card hide'),optionsCard=el('section','card hide'),errorBox=el('div','err hide'),errorText=el('div'),retry=el('button','btn small ghost',t('retry'));errorBox.setAttribute('role','alert');retry.style.marginTop='8px';errorBox.appendChild(errorText);errorBox.appendChild(retry);
var docCard=el('section','card hide');
STEP_UI.forEach(function(s){
  var li=el('li','step pending'),dot=el('div','dot'),mid=el('div'),title=el('div','t',t('s_'+s)),sub=el('div','s'),bar=el('div','bar hide'),fill=el('i');bar.appendChild(fill);mid.appendChild(title);mid.appendChild(sub);mid.appendChild(bar);
  var chip=el('span','chip',t('pending'));li.appendChild(dot);li.appendChild(mid);li.appendChild(chip);stepsList.appendChild(li);
  rowEls[s]={li:li,dot:dot,sub:sub,bar:bar,fill:fill,chip:chip};
});
app.appendChild(stepsCard);app.appendChild(checkCard);app.appendChild(optionsCard);app.appendChild(docCard);app.appendChild(errorBox);
function setStep(s,status,sub){
  var r=rowEls[s];r.li.className='step '+status;r.chip.textContent=t(status);r.chip.className='chip'+(status==='done'?' ok':status==='failed'?' fail':status==='waiting'?' warn':'');
  r.dot.textContent=status==='done'?'✓':status==='failed'?'!':status==='waiting'?'…':'';
  if(sub!==undefined)r.sub.textContent=sub;
}
function showError(step,e,from){setStep(step,'failed');errorText.textContent=msg(e);errorBox.classList.remove('hide');retry.onclick=function(){errorBox.classList.add('hide');pipeline(from||step);};}
// ---- check
function runCheck(){
  errorBox.classList.add('hide');optionsCard.classList.add('hide');setStep('check','running',t('checking'));
  return call('check').then(function(r){
    checkCard.textContent='';checkCard.classList.remove('hide');
    checkCard.appendChild(el('p','label',t('check_card')));var rows=el('div','rows');
    var okExt=r.rows.filter(function(x){return x.id.indexOf('ext_')===0&&x.level==='ok';}).map(function(x){return x.args.name;});
    var shown=r.rows.filter(function(x){return !(x.id.indexOf('ext_')===0&&x.level==='ok');});
    if(okExt.length){var at=0;shown.forEach(function(x,i){if(x.id==='php')at=i+1;});shown.splice(at,0,{id:'exts',level:'ok',key:'c_ext_all',args:{list:okExt.join(', ')}});}
    shown.forEach(function(row){var chip=el('span','chip '+(row.level==='ok'?'ok':row.level==='warn'?'warn':'fail'),row.level==='ok'?'OK':row.level==='warn'?'!':'✕');var line=el('div','row');line.appendChild(chip);line.appendChild(el('span',null,t(row.key,row.args)));rows.appendChild(line);});
    checkCard.appendChild(rows);
    if(r.blocking){
      var n=el('div','err',t('check_blocked'));checkCard.appendChild(n);var b=el('button','btn small ghost',t('check_again'));b.style.marginTop='10px';b.onclick=runCheck;checkCard.appendChild(b);
      setStep('check','failed');return;
    }
    setStep('check','done',r.version?('GnozioCMS '+r.version):'');
    showOptions();
  }).catch(function(e){if(!e.handled)showError('check',e);});
}
function showOptions(){
  optionsCard.textContent='';optionsCard.classList.remove('hide');
  optionsCard.appendChild(el('p','label',t('options')));
  var lab=el('label','check'),cb=document.createElement('input');cb.type='checkbox';var box=el('span');box.appendChild(document.createTextNode(t('demo')));box.appendChild(el('small',null,t('demo_help')));lab.appendChild(cb);lab.appendChild(box);optionsCard.appendChild(lab);
  var go=el('button','btn block',t('start'));go.style.marginTop='12px';
  go.onclick=function(){go.disabled=true;call('begin',{demo:cb.checked?'1':''}).then(function(r){if(!r.ok){showError('check',r);go.disabled=false;return;}optionsCard.classList.add('hide');checkCard.classList.add('hide');pipeline('download');}).catch(function(e){if(!e.handled){showError('check',e);go.disabled=false;}});};
  optionsCard.appendChild(go);
}
// ---- pipeline
var ORDER=['download','verify','unpack','prepare'];
function mb(n){return (n/1048576).toFixed(1);}
function pipeline(from){
  var i=ORDER.indexOf(from);
  if(i<0){if(from==='docroot'||from==='finish'){return from==='docroot'?waitDocroot():finish();}return runCheck();}
  return runStep(ORDER[i]).then(function(ok){if(!ok)return;if(i+1<ORDER.length)return pipeline(ORDER[i+1]);return waitDocroot();});
}
function runStep(s){
  setStep(s,'running','');
  if(s==='download'){var r=rowEls.download;r.bar.classList.remove('hide');
    var loop=function(){return call('download').then(function(j){
      if(!j.ok){r.bar.classList.add('hide');showError('download',j);return false;}
      r.fill.style.width=Math.round(j.downloaded/j.total*100)+'%';r.sub.textContent=t('dl_progress',{done:mb(j.downloaded),total:mb(j.total)});
      if(!j.done)return loop();
      setStep('download','done',t('dl_progress',{done:mb(j.total),total:mb(j.total)}));return true;
    }).catch(function(e){if(!e.handled){r.bar.classList.add('hide');showError('download',e);}return false;});};
    return loop();
  }
  return call(s).then(function(j){
    if(!j.ok){var back=j.error&&j.error.args&&j.error.args.restart;if(back){setStep('download','pending','');rowEls.download.fill.style.width='0';}showError(s,j,back||s);return false;}
    if(s==='prepare'&&j.docroot)state.docroot=j.docroot;setStep(s,'done',s==='verify'?t('verify_ok'):s==='unpack'?t('unpack_ok',{files:j.files}):'');return true;
  }).catch(function(e){if(!e.handled)showError(s,e);return false;});
}
// ---- document root
function waitDocroot(root){
  setStep('docroot','waiting','');docCard.textContent='';docCard.classList.remove('hide');
  var path=root||state.docroot;
  docCard.appendChild(el('h2',null,t('docroot_title')));docCard.appendChild(el('p',null,t('docroot_text')));
  docCard.appendChild(el('p','label',t('docroot_path')));docCard.lastChild.style.marginTop='12px';
  var box=el('div','path'),code=el('code',null,path),cp=el('button','btn small ghost',t('copy'));cp.type='button';
  cp.onclick=function(){var done=function(){cp.textContent=t('copied');setTimeout(function(){cp.textContent=t('copy');},1500);};
    if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(path).then(done);}else{var ta=document.createElement('textarea');ta.value=path;document.body.appendChild(ta);ta.select();try{document.execCommand('copy');done();}catch(e){}document.body.removeChild(ta);}};
  box.appendChild(code);box.appendChild(cp);docCard.appendChild(box);
  docCard.appendChild(el('p','note',t('docroot_cp')));docCard.appendChild(el('p','note',t('docroot_other')));docCard.appendChild(el('p','note',t('docroot_same')));
  var warn=el('div','notice hide'),btn=el('button','btn block',t('docroot_button'));btn.style.marginTop='14px';
  btn.onclick=function(){btn.disabled=true;btn.textContent=t('docroot_checking');warn.classList.add('hide');
    call('docroot').then(function(j){
      if(!j.ok){warn.textContent=msg(j);warn.classList.remove('hide');btn.disabled=false;btn.textContent=t('docroot_button');return;}
      if(!j.changed){warn.textContent=t(j.servedFromPublic?'docroot_not_yet':'docroot_not_public');warn.classList.remove('hide');btn.disabled=false;btn.textContent=t('docroot_button');return;}
      setStep('docroot','done','');docCard.classList.add('hide');finish();
    }).catch(function(e){if(!e.handled){warn.textContent=msg(e);warn.classList.remove('hide');btn.disabled=false;btn.textContent=t('docroot_button');}});};
  docCard.appendChild(warn);docCard.appendChild(btn);
}
function finish(){
  setStep('finish','running',t('finishing'));
  call('finish').then(function(j){
    if(!j.ok){showError('finish',j);return;}
    setStep('finish','done',t('finish_ok'));var m=el('section','card');m.appendChild(el('p',null,t('redirecting')));m.firstChild.style.margin='0';
    if(j.left&&j.left.length){m.appendChild(el('div','notice',t('finish_left',{left:j.left.join(', ')})));}
    app.appendChild(m);setTimeout(function(){location.href=j.redirect;},j.left&&j.left.length?6000:1200);
  }).catch(function(e){if(!e.handled)showError('finish',e);});
}
// ---- start
document.querySelectorAll('[data-lang]').forEach(function(b){b.addEventListener('click',function(){document.cookie='gz_lang='+b.getAttribute('data-lang')+';path=/;max-age=31536000;SameSite=Lax';location.reload();});});
call('status').then(function(st){
  state=st;
  (st.done||[]).forEach(function(s){setStep(s,'done');});
  if(st.phase==='fresh'){runCheck();return;}
  if(st.phase==='waiting'){['download','verify','unpack','prepare'].forEach(function(s){setStep(s,'done');});waitDocroot(st.docroot);return;}
  var next=ORDER.filter(function(s){return (st.done||[]).indexOf(s)<0;})[0];
  if(next){if(next==='download'){rowEls.download.fill.style.width=Math.round((st.downloaded||0)/st.size*100)+'%';}pipeline(next);}else{waitDocroot(st.docroot);}
}).catch(function(e){if(!e.handled)showError('check',e);});
})();
JS;
    }
}
