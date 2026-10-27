<?php

declare(strict_types=1);

namespace GnozioCMS;

final class S3BackupStorage
{
    /** Only archives GnozioCMS created itself are eligible for remote pruning. */
    private const BACKUP_FILENAME_PATTERN = '/-(?:database-)?backup-\d{8}-\d{6}-[0-9a-f]{6}\.zip$/';

    /** @param array<string, mixed> $config */
    public function __construct(private array $config)
    {
    }

    public function isEnabled(): bool
    {
        return $this->truthy($this->config['enabled'] ?? false);
    }

    /** @return array{ok: bool, message: string} */
    public function validate(): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'message' => 'Remote storage is disabled.'];
        }
        // A host can switch curl_exec off while the extension is there; calling it then is a fatal error.
        if (!function_exists('curl_init') || !function_exists('curl_exec')) {
            return ['ok' => false, 'message' => 'The PHP cURL extension is required for S3-compatible remote backups.'];
        }

        foreach (['bucket', 'access_key', 'secret_key'] as $key) {
            if (trim((string)($this->config[$key] ?? '')) === '') {
                return ['ok' => false, 'message' => 'Remote storage is missing ' . str_replace('_', ' ', $key) . '.'];
            }
        }

        if ($this->endpoint() === '') {
            return ['ok' => false, 'message' => 'Remote storage endpoint is missing.'];
        }

        return ['ok' => true, 'message' => 'Remote storage is configured.'];
    }

    /** @return array{ok: bool, message: string, object_key?: string} */
    public function upload(string $localPath, string $filename): array
    {
        $validation = $this->validate();
        if (($validation['ok'] ?? false) !== true) {
            return $validation;
        }
        if (!is_file($localPath)) {
            return ['ok' => false, 'message' => 'Backup file was not found for remote upload.'];
        }

        $objectKey = $this->objectKey($filename);
        $response = $this->request('PUT', $objectKey, '', [
            'content-type' => 'application/zip',
        ], [], $localPath);
        if (($response['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'message' => (string)($response['message'] ?? 'Unknown S3 error.'),
                'object_key' => $objectKey,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Remote upload completed.',
            'object_key' => $objectKey,
        ];
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        $validation = $this->validate();
        if (($validation['ok'] ?? false) !== true) {
            return $validation;
        }

        $key = $this->objectKey('.gnoziocms-connection-test-' . date('YmdHis') . '.txt');
        $response = $this->request('PUT', $key, 'GnozioCMS backup connection test ' . date('c') . "\n", [
            'content-type' => 'text/plain; charset=utf-8',
        ]);
        if (($response['ok'] ?? false) !== true) {
            return ['ok' => false, 'message' => 'Connection test failed: ' . (string)($response['message'] ?? 'Unknown S3 error.')];
        }

        $this->request('DELETE', $key, '');
        return ['ok' => true, 'message' => 'Remote storage connection works.'];
    }

    /** @return array{ok: bool, message: string, objects?: array<int, array{key: string, modified: string, size: int}>} */
    public function listBackups(): array
    {
        $validation = $this->validate();
        if (($validation['ok'] ?? false) !== true) {
            return $validation;
        }

        $prefix = trim((string)($this->config['prefix'] ?? ''), '/');
        $keyPrefix = $prefix !== '' ? $prefix . '/' : '';
        $objects = [];
        $continuation = '';
        // ListObjectsV2 returns at most 1000 keys per page.
        for ($page = 0; $page < 50; $page++) {
            $query = ['list-type' => '2'];
            if ($keyPrefix !== '') {
                $query['prefix'] = $keyPrefix;
            }
            if ($continuation !== '') {
                $query['continuation-token'] = $continuation;
            }
            $response = $this->request('GET', '', '', [], $query);
            if (($response['ok'] ?? false) !== true) {
                return ['ok' => false, 'message' => (string)($response['message'] ?? 'Could not list remote backups.')];
            }
            $listing = $this->parseListObjects((string)($response['body'] ?? ''));
            foreach ($listing['objects'] as $object) {
                $relative = substr($object['key'], strlen($keyPrefix));
                if ($relative === '' || str_contains($relative, '/') || !preg_match(self::BACKUP_FILENAME_PATTERN, $relative)) {
                    continue;
                }
                $objects[] = $object;
            }
            if (!$listing['truncated'] || $listing['next'] === '') {
                break;
            }
            $continuation = $listing['next'];
        }

        usort($objects, static fn(array $a, array $b): int => strcmp($b['modified'], $a['modified']));
        return ['ok' => true, 'message' => 'Remote backups listed.', 'objects' => $objects];
    }

    /**
     * Streams one of GnozioCMS's own archives from the bucket into $destination (a file this writes; it is removed again when the
     * download fails). Only a name that listBackups() would list is accepted, so nothing else in the bucket can be fetched.
     *
     * @return array{ok: bool, message: string, size?: int}
     */
    public function download(string $filename, string $destination): array
    {
        $validation = $this->validate();
        if (($validation['ok'] ?? false) !== true) {
            return $validation;
        }
        if ($filename !== basename($filename) || !preg_match(self::BACKUP_FILENAME_PATTERN, $filename)) {
            return ['ok' => false, 'message' => 'That is not a GnozioCMS backup name.'];
        }

        $response = $this->request('GET', $this->objectKey($filename), '', [], [], null, $destination);
        if (($response['ok'] ?? false) !== true) {
            @unlink($destination);
            return ['ok' => false, 'message' => 'Download failed: ' . (string)($response['message'] ?? 'Unknown S3 error.')];
        }

        $size = (int)(@filesize($destination) ?: 0);
        if ($size === 0) {
            @unlink($destination);
            return ['ok' => false, 'message' => 'Download failed: the remote file is empty.'];
        }
        return ['ok' => true, 'message' => 'Remote download completed.', 'size' => $size];
    }

    /** @return array{ok: bool, message: string, deleted?: int} */
    public function prune(int $keep): array
    {
        $keep = max(1, $keep);
        $listing = $this->listBackups();
        if (($listing['ok'] ?? false) !== true) {
            return ['ok' => false, 'message' => 'Remote pruning skipped: ' . (string)($listing['message'] ?? 'Could not list remote backups.')];
        }

        $objects = $listing['objects'] ?? [];
        if (count($objects) <= $keep) {
            return ['ok' => true, 'message' => 'Remote retention is already within policy.', 'deleted' => 0];
        }

        $deleted = 0;
        foreach (array_slice($objects, $keep) as $object) {
            $delete = $this->request('DELETE', (string)$object['key'], '');
            if (($delete['ok'] ?? false) === true) {
                $deleted++;
            }
        }

        return ['ok' => true, 'message' => 'Remote retention pruned ' . $deleted . ' backup(s).', 'deleted' => $deleted];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     * @param string|null $bodyFile Streams this file as the request body instead of $body.
     * @param string|null $sinkFile Streams the response into this file instead of returning it (the file holds the error text when the status is not a success).
     */
    private function request(string $method, string $key, string $body = '', array $headers = [], array $query = [], ?string $bodyFile = null, ?string $sinkFile = null): array
    {
        $method = strtoupper($method);
        $url = $this->objectUrl($key, $query);
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return ['ok' => false, 'message' => 'Invalid S3 endpoint URL.'];
        }

        $host = (string)$parts['host'] . (isset($parts['port']) ? ':' . (string)$parts['port'] : '');
        $amzDate = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $payloadHash = $bodyFile !== null ? (string)hash_file('sha256', $bodyFile) : hash('sha256', $body);
        $allHeaders = array_change_key_case($headers, CASE_LOWER);
        $allHeaders['host'] = $host;
        $allHeaders['x-amz-content-sha256'] = $payloadHash;
        $allHeaders['x-amz-date'] = $amzDate;
        ksort($allHeaders);

        $canonicalHeaders = '';
        foreach ($allHeaders as $name => $value) {
            $canonicalHeaders .= strtolower($name) . ':' . trim((string)$value) . "\n";
        }
        $signedHeaders = implode(';', array_keys($allHeaders));
        $canonicalQuery = $this->canonicalQuery($query);
        $canonicalUri = (string)($parts['path'] ?? '/');
        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $region = $this->region();
        $scope = $date . '/' . $region . '/s3/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($date, $region));
        $allHeaders['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKey() . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

        $curlHeaders = [];
        foreach ($allHeaders as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'message' => 'Could not initialize cURL.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $bodyFile !== null ? 900 : 60,
        ]);
        $fileHandle = null;
        if ($bodyFile !== null) {
            $fileHandle = fopen($bodyFile, 'rb');
            if ($fileHandle === false) {
                return ['ok' => false, 'message' => 'Could not open backup file for upload.'];
            }
            curl_setopt_array($ch, [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $fileHandle,
                CURLOPT_INFILESIZE => (int)(filesize($bodyFile) ?: 0),
            ]);
        } elseif (in_array($method, ['PUT', 'POST'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $sinkHandle = null;
        if ($sinkFile !== null) {
            $sinkHandle = fopen($sinkFile, 'wb');
            if ($sinkHandle === false) {
                return ['ok' => false, 'message' => 'Could not write the downloaded file.'];
            }
            curl_setopt($ch, CURLOPT_FILE, $sinkHandle);
        }
        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (is_resource($fileHandle)) {
            fclose($fileHandle);
        }
        if (is_resource($sinkHandle)) {
            fclose($sinkHandle);
        }

        if ($responseBody === false) {
            return ['ok' => false, 'message' => $error !== '' ? $error : 'S3 request failed.'];
        }
        if ($status < 200 || $status >= 300) {
            $errorBody = $sinkFile !== null ? (string)@file_get_contents($sinkFile, false, null, 0, 8192) : (string)$responseBody;
            return ['ok' => false, 'message' => 'S3 returned HTTP ' . $status . $this->extractS3Error($errorBody)];
        }
        if ($sinkFile !== null) {
            return ['ok' => true, 'message' => 'S3 request completed.'];
        }

        return ['ok' => true, 'message' => 'S3 request completed.', 'body' => (string)$responseBody];
    }

    /** @param array<string, string> $query */
    private function objectUrl(string $key, array $query = []): string
    {
        $endpoint = rtrim($this->endpoint(), '/');
        $parts = parse_url($endpoint);
        $scheme = (string)($parts['scheme'] ?? 'https');
        $host = (string)($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . (string)$parts['port'] : '';
        $basePath = trim((string)($parts['path'] ?? ''), '/');
        $bucket = $this->bucket();
        $pathStyle = $this->truthy($this->config['path_style'] ?? false);

        $segments = [];
        if ($basePath !== '') {
            $segments[] = $basePath;
        }
        if ($pathStyle) {
            $segments[] = $bucket;
        } else {
            $host = $bucket . '.' . $host;
        }
        if ($key !== '') {
            foreach (explode('/', $key) as $segment) {
                if ($segment !== '') {
                    $segments[] = rawurlencode($segment);
                }
            }
        }

        $url = $scheme . '://' . $host . $port . '/' . implode('/', $segments);
        $canonicalQuery = $this->canonicalQuery($query);
        return $canonicalQuery !== '' ? $url . '?' . $canonicalQuery : $url;
    }

    private function objectKey(string $filename): string
    {
        $filename = basename($filename);
        $prefix = trim((string)($this->config['prefix'] ?? ''), '/');
        return $prefix !== '' ? $prefix . '/' . $filename : $filename;
    }

    /** @param array<string, string> $query */
    private function canonicalQuery(array $query): string
    {
        if (empty($query)) {
            return '';
        }
        ksort($query);
        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
        }
        return implode('&', $pairs);
    }

    private function signingKey(string $date, string $region): string
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey(), true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /** @return array{objects: array<int, array{key: string, modified: string, size: int}>, truncated: bool, next: string} */
    private function parseListObjects(string $xml): array
    {
        $result = ['objects' => [], 'truncated' => false, 'next' => ''];
        if ($xml === '' || !function_exists('simplexml_load_string')) {
            return $result;
        }
        $parsed = @simplexml_load_string($xml);
        if ($parsed === false) {
            return $result;
        }
        foreach ($parsed->Contents ?? [] as $item) {
            $result['objects'][] = [
                'key' => (string)($item->Key ?? ''),
                'modified' => (string)($item->LastModified ?? ''),
                'size' => (int)($item->Size ?? 0),
            ];
        }
        $result['truncated'] = strtolower(trim((string)($parsed->IsTruncated ?? 'false'))) === 'true';
        $result['next'] = trim((string)($parsed->NextContinuationToken ?? ''));
        return $result;
    }

    private function extractS3Error(string $body): string
    {
        $message = '';
        if ($body !== '' && function_exists('simplexml_load_string')) {
            $xml = @simplexml_load_string($body);
            if ($xml !== false && isset($xml->Message)) {
                $message = trim((string)$xml->Message);
            }
        }
        return $message !== '' ? ' (' . $message . ')' : '';
    }

    private function endpoint(): string
    {
        $endpoint = trim((string)($this->config['endpoint'] ?? ''));
        if ($endpoint !== '' && !str_contains($endpoint, '://')) {
            $endpoint = 'https://' . $endpoint;
        }
        if ($endpoint !== '') {
            return $endpoint;
        }
        if (($this->config['provider'] ?? '') === 'aws_s3' && $this->region() !== '') {
            return 'https://s3.' . $this->region() . '.amazonaws.com';
        }
        return '';
    }

    private function region(): string
    {
        $region = trim((string)($this->config['region'] ?? ''));
        return $region !== '' ? $region : 'us-east-1';
    }

    private function bucket(): string
    {
        return trim((string)($this->config['bucket'] ?? ''));
    }

    private function accessKey(): string
    {
        return trim((string)($this->config['access_key'] ?? ''));
    }

    private function secretKey(): string
    {
        return (string)($this->config['secret_key'] ?? '');
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
