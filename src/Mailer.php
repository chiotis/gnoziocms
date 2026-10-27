<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Sends plain-text email through SMTP or Amazon SES (API v2) using the
 * `forms.notifications` settings block. Callers log the returned result.
 */
final class Mailer
{
    /** @param array<string, mixed> $config the forms.notifications settings */
    public function __construct(private array $config)
    {
    }

    /** smtp, ses, or none. An explicit driver wins; otherwise the first configured transport. */
    public function provider(): string
    {
        $driver = strtolower(trim((string)($this->config['driver'] ?? '')));
        if ($driver === 'ses' || $driver === 'smtp') {
            return $driver;
        }
        if ($this->sesConfigured()) {
            return 'ses';
        }
        if ($this->smtpConfigured()) {
            return 'smtp';
        }
        return 'none';
    }

    /**
     * @param array<string, string> $headers From, Reply-To, Cc, Bcc
     * @param array<int, array{path: string, name: string, mime?: string}> $attachments files to send along
     * @return array{ok: bool, provider: string, error: string}
     */
    public function send(string $to, string $subject, string $body, array $headers, array $attachments = []): array
    {
        $provider = $this->provider();
        try {
            $result = match ($provider) {
                'ses' => $this->sesConfigured() ? $this->sendViaSes($to, $subject, $body, $headers, $attachments) : ['ok' => false, 'error' => 'SES is not configured.'],
                'smtp' => $this->smtpConfigured() ? $this->sendViaSmtp($to, $subject, $body, $headers, $attachments) : ['ok' => false, 'error' => 'SMTP is not configured.'],
                default => ['ok' => false, 'error' => 'No email provider is configured.'],
            };
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!$result['ok'] && $result['error'] === '') {
            $result['error'] = strtoupper($provider) . ' send failed.';
        }

        return ['ok' => $result['ok'], 'provider' => $provider, 'error' => $result['error']];
    }

    /** @return string[] */
    public static function parseEmailList(string $value): array
    {
        $list = [];
        foreach (preg_split('/[,;]+/', $value) ?: [] as $item) {
            $email = self::extractEmailAddress(trim($item));
            if ($email !== '') {
                $list[] = $email;
            }
        }
        return array_values(array_unique($list));
    }

    public static function extractEmailAddress(string $value): string
    {
        if (preg_match('/<([^>]+)>/', $value, $matches)) {
            $value = $matches[1];
        }
        $value = trim($value);
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
    }

    private function smtpConfigured(): bool
    {
        $smtp = is_array($this->config['smtp'] ?? null) ? $this->config['smtp'] : [];
        return trim((string)($smtp['host'] ?? '')) !== '' && (int)($smtp['port'] ?? 0) > 0;
    }

    private function sesConfigured(): bool
    {
        $ses = is_array($this->config['ses'] ?? null) ? $this->config['ses'] : [];
        return trim((string)($ses['key'] ?? '')) !== ''
            && trim((string)($ses['secret'] ?? '')) !== ''
            && trim((string)($ses['region'] ?? '')) !== '';
    }

    /** @return array{ok: bool, error: string} */
    private function sendViaSmtp(string $to, string $subject, string $body, array $headers, array $attachments = []): array
    {
        $smtp = is_array($this->config['smtp'] ?? null) ? $this->config['smtp'] : [];
        $host = trim((string)($smtp['host'] ?? ''));
        $port = (int)($smtp['port'] ?? 0);
        $username = (string)($smtp['username'] ?? '');
        $password = (string)($smtp['password'] ?? '');
        $encryption = (string)($smtp['encryption'] ?? '');

        $fromEmail = self::extractEmailAddress((string)($headers['From'] ?? ''));
        if ($fromEmail === '') {
            return ['ok' => false, 'error' => 'The sender address is missing or invalid.'];
        }
        $recipients = array_values(array_unique(array_merge(
            self::parseEmailList($to),
            self::parseEmailList((string)($headers['Cc'] ?? '')),
            self::parseEmailList((string)($headers['Bcc'] ?? ''))
        )));
        if ($recipients === []) {
            return ['ok' => false, 'error' => 'No valid recipient address.'];
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host;
        $fp = @fsockopen($remote, $port, $errno, $errstr, 10);
        if (!$fp) {
            return ['ok' => false, 'error' => 'Could not connect to ' . $host . ':' . $port . ($errstr !== '' ? ' (' . $errstr . ')' : '') . '.'];
        }
        // Without a read timeout a silent server would hold the request forever.
        stream_set_timeout($fp, 15);

        $fail = function (string $step, string $response) use ($fp): array {
            @fwrite($fp, "QUIT\r\n");
            fclose($fp);
            $reply = trim(strtok($response, "\r\n") ?: '');
            return ['ok' => false, 'error' => 'SMTP ' . $step . ' failed' . ($reply !== '' ? ': ' . $reply : ' (no response).')];
        };

        $greeting = $this->smtpRead($fp);
        if ((int)substr($greeting, 0, 3) !== 220) {
            return $fail('greeting', $greeting);
        }
        $hostname = gethostname() ?: 'localhost';
        if (!$this->smtpCommand($fp, 'EHLO ' . $hostname, 250, $response)) {
            return $fail('EHLO', $response);
        }
        if ($encryption === 'tls') {
            if (!$this->smtpCommand($fp, 'STARTTLS', 220, $response)) {
                return $fail('STARTTLS', $response);
            }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return $fail('TLS handshake', '');
            }
            if (!$this->smtpCommand($fp, 'EHLO ' . $hostname, 250, $response)) {
                return $fail('EHLO after STARTTLS', $response);
            }
        }
        if ($username !== '') {
            if (!$this->smtpCommand($fp, 'AUTH LOGIN', 334, $response)
                || !$this->smtpCommand($fp, base64_encode($username), 334, $response)
                || !$this->smtpCommand($fp, base64_encode($password), 235, $response)) {
                return $fail('authentication', $response);
            }
        }
        if (!$this->smtpCommand($fp, 'MAIL FROM:<' . $fromEmail . '>', 250, $response)) {
            return $fail('MAIL FROM', $response);
        }
        foreach ($recipients as $recipient) {
            if (!$this->smtpCommand($fp, 'RCPT TO:<' . $recipient . '>', 250, $response)) {
                return $fail('RCPT TO <' . $recipient . '>', $response);
            }
        }
        if (!$this->smtpCommand($fp, 'DATA', 354, $response)) {
            return $fail('DATA', $response);
        }

        $lines = [
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (strstr($fromEmail, '@') !== false ? substr((string)strstr($fromEmail, '@'), 1) : 'localhost') . '>',
            'To: ' . $this->headerValue($to),
            'Subject: ' . $this->encodeHeader($subject),
        ];
        foreach (['From', 'Reply-To', 'Cc'] as $name) {
            if (isset($headers[$name]) && trim((string)$headers[$name]) !== '') {
                $lines[] = $name . ': ' . $this->encodeAddressHeader((string)$headers[$name]);
            }
        }
        $lines[] = 'MIME-Version: 1.0';
        $files = $this->readAttachments($attachments);
        if ($files === []) {
            $lines[] = 'Content-Type: text/plain; charset=UTF-8';
            $lines[] = 'Content-Transfer-Encoding: 8bit';
            $lines[] = '';
            $lines[] = str_replace(["\r\n", "\r"], "\n", $body);
        } else {
            $boundary = 'gnozio-' . bin2hex(random_bytes(12));
            $lines[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
            $lines[] = '';
            $lines[] = '--' . $boundary;
            $lines[] = 'Content-Type: text/plain; charset=UTF-8';
            $lines[] = 'Content-Transfer-Encoding: 8bit';
            $lines[] = '';
            $lines[] = str_replace(["\r\n", "\r"], "\n", $body);
            foreach ($files as $file) {
                $lines[] = '--' . $boundary;
                $lines[] = 'Content-Type: ' . $file['mime'] . '; name="' . $file['name'] . '"';
                $lines[] = 'Content-Transfer-Encoding: base64';
                $lines[] = 'Content-Disposition: attachment; filename="' . $file['name'] . '"';
                $lines[] = '';
                $lines[] = rtrim(chunk_split(base64_encode($file['data']), 76, "\n"));
            }
            $lines[] = '--' . $boundary . '--';
        }
        $data = str_replace("\n", "\r\n", implode("\n", $lines));
        // Dot-stuffing: a line that starts with "." must be doubled so it does not end DATA early.
        $data = preg_replace('/^\./m', '..', $data) ?? $data;
        fwrite($fp, $data . "\r\n.\r\n");
        $accepted = $this->smtpRead($fp);
        if ((int)substr($accepted, 0, 3) !== 250) {
            return $fail('message delivery', $accepted);
        }
        $this->smtpCommand($fp, 'QUIT', 221, $response);
        fclose($fp);
        return ['ok' => true, 'error' => ''];
    }

    /** @param resource $fp */
    private function smtpCommand($fp, string $command, int $expectCode, ?string &$response = null): bool
    {
        fwrite($fp, $command . "\r\n");
        $response = $this->smtpRead($fp);
        if ($response === '') {
            return false;
        }
        $code = (int)substr($response, 0, 3);
        return $code === $expectCode || ($expectCode === 250 && $code >= 250 && $code < 260);
    }

    /** @param resource $fp */
    private function smtpRead($fp): string
    {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) {
                break;
            }
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    }

    /** @return array{ok: bool, error: string} */
    private function sendViaSes(string $to, string $subject, string $body, array $headers, array $attachments = []): array
    {
        $ses = is_array($this->config['ses'] ?? null) ? $this->config['ses'] : [];
        $accessKey = trim((string)($ses['key'] ?? ''));
        $secretKey = trim((string)($ses['secret'] ?? ''));
        $region = trim((string)($ses['region'] ?? ''));
        $fromHeader = (string)($headers['From'] ?? '');
        $fromEmail = self::extractEmailAddress($fromHeader);
        if ($fromEmail === '') {
            return ['ok' => false, 'error' => 'The sender address is missing or invalid.'];
        }

        $payload = [
            // SES accepts a display name here; keep it so recipients see the site name.
            'FromEmailAddress' => $this->encodeAddressHeader($fromHeader),
            'Destination' => ['ToAddresses' => self::parseEmailList($to)],
            'Content' => [
                'Simple' => [
                    'Subject' => ['Data' => $subject, 'Charset' => 'UTF-8'],
                    'Body' => ['Text' => ['Data' => $body, 'Charset' => 'UTF-8']],
                ],
            ],
        ];
        $files = $this->readAttachments($attachments);
        if ($files !== []) {
            $payload['Content']['Simple']['Attachments'] = array_map(static fn(array $file): array => [
                'FileName' => $file['name'],
                'RawContent' => base64_encode($file['data']),
                'ContentType' => $file['mime'],
                'ContentDisposition' => 'ATTACHMENT',
                'ContentTransferEncoding' => 'BASE64',
            ], $files);
        }
        if (($headers['Reply-To'] ?? '') !== '') {
            $payload['ReplyToAddresses'] = self::parseEmailList((string)$headers['Reply-To']);
        }
        if (($headers['Cc'] ?? '') !== '') {
            $payload['Destination']['CcAddresses'] = self::parseEmailList((string)$headers['Cc']);
        }
        if (($headers['Bcc'] ?? '') !== '') {
            $payload['Destination']['BccAddresses'] = self::parseEmailList((string)$headers['Bcc']);
        }
        $payloadJson = json_encode($payload);
        if ($payloadJson === false) {
            return ['ok' => false, 'error' => 'Could not encode the SES request.'];
        }

        $host = 'email.' . $region . '.amazonaws.com';
        $uri = '/v2/email/outbound-emails';
        $amzDate = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $canonicalHeaders = 'content-type:application/json' . "\n" . 'host:' . $host . "\n" . 'x-amz-date:' . $amzDate . "\n";
        $signedHeaders = 'content-type;host;x-amz-date';
        $canonicalRequest = "POST\n" . $uri . "\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . hash('sha256', $payloadJson);
        $scope = $date . '/' . $region . '/ses/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
        $key = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $key = hash_hmac('sha256', $region, $key, true);
        $key = hash_hmac('sha256', 'ses', $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);
        $signature = hash_hmac('sha256', $stringToSign, $key);
        $headersOut = [
            'Content-Type: application/json',
            'Host: ' . $host,
            'X-Amz-Date: ' . $amzDate,
            'Authorization: AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature,
        ];
        $url = 'https://' . $host . $uri;

        if (function_exists('curl_init') && function_exists('curl_exec')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payloadJson,
                CURLOPT_HTTPHEADER => $headersOut,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
            ]);
            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
        } else {
            $context = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headersOut),
                'content' => $payloadJson,
                'ignore_errors' => true,
                'timeout' => 20,
            ]]);
            $response = @file_get_contents($url, false, $context);
            $statusLine = (string)($http_response_header[0] ?? '');
            $status = preg_match('/\s(\d{3})\s/', $statusLine . ' ', $m) ? (int)$m[1] : 0;
            $curlError = '';
        }
        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'error' => ''];
        }
        $message = is_string($response) ? (string)(json_decode($response, true)['message'] ?? '') : '';
        return ['ok' => false, 'error' => 'SES returned HTTP ' . $status . ($message !== '' ? ': ' . $message : ($curlError !== '' ? ': ' . $curlError : '.'))];
    }

    /**
     * The files to send along, read now. A file that cannot be read is left out; the name and kind are made safe for a header.
     *
     * @param array<int, array{path: string, name: string, mime?: string}> $attachments
     * @return array<int, array{name: string, mime: string, data: string}>
     */
    private function readAttachments(array $attachments): array
    {
        $files = [];
        foreach ($attachments as $attachment) {
            $path = (string)($attachment['path'] ?? '');
            $data = $path !== '' && is_file($path) ? file_get_contents($path) : false;
            if ($data === false) {
                continue;
            }
            $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename((string)($attachment['name'] ?? $path))) ?: 'file';
            $mime = strtolower((string)($attachment['mime'] ?? ''));
            $files[] = ['name' => $name, 'mime' => preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mime) ? $mime : 'application/octet-stream', 'data' => $data];
        }
        return $files;
    }

    /** Header values must never carry CR/LF, or a value could inject extra headers. */
    private function headerValue(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }

    private function encodeHeader(string $value): string
    {
        $value = $this->headerValue($value);
        if (!preg_match('/[^\x20-\x7E]/', $value)) {
            return $value;
        }
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }

    /** Encodes the display-name part of "Name <email>" so non-ASCII names survive. */
    private function encodeAddressHeader(string $value): string
    {
        $value = $this->headerValue($value);
        if (preg_match('/^(.*)<([^>]+)>$/', $value, $m)) {
            $name = trim($m[1], " \"");
            return ($name !== '' ? $this->encodeHeader($name) . ' ' : '') . '<' . trim($m[2]) . '>';
        }
        return $value;
    }
}
