<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Signing in with a Google account: the settings it needs, the address that sends the person to Google, the two calls
 * that turn the answer into a profile, and the rules that decide whether that profile may sign in (a verified email,
 * from the allowed domain, matching an active user). The calls go through `$http`, so they can be replaced.
 */
final class GoogleSignIn
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const PROFILE_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    /**
     * @param callable(): array<string, mixed> $settings the site settings, read each time
     * @param callable(string): string $absolute the full address of a path of this site
     * @param (callable(string, array<int, mixed>, string): array{body: mixed, status: int, error: string})|null $http url, curl options, and the kind of call: replaced in tests
     */
    public function __construct(private $settings, private $absolute, private $http = null)
    {
    }

    /** @return array{enabled: bool, client_id: string, client_secret: string, allowed_domain: string, redirect_uri: string, ready: bool} */
    public function config(): array
    {
        $settings = ($this->settings)();
        $config = is_array($settings['auth']['google'] ?? null) ? $settings['auth']['google'] : [];
        $clientId = trim((string)($config['client_id'] ?? ''));
        $clientSecret = trim((string)($config['client_secret'] ?? ''));
        $enabled = Format::isTruthy($config['enabled'] ?? false);
        return [
            'enabled' => $enabled,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'allowed_domain' => strtolower(trim((string)($config['allowed_domain'] ?? ''))),
            'redirect_uri' => ($this->absolute)('/admin/google-callback'),
            'ready' => $enabled && $clientId !== '' && $clientSecret !== '',
        ];
    }

    /** Where to send the person to choose a Google account. @param array<string, mixed> $config from config() */
    public function authorizationUrl(array $config, string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);
    }

    /**
     * Turns the code Google sent back into an access token.
     *
     * @param array<string, mixed> $config from config()
     * @return array{ok: bool, message?: string, access_token?: string}
     */
    public function exchange(string $code, array $config): array
    {
        if ($this->http === null && !(function_exists('curl_init') && function_exists('curl_exec'))) {
            return ['ok' => false, 'message' => 'PHP cURL is required for Google Sign-In.'];
        }
        $reply = $this->call(self::TOKEN_URL, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $code,
                'client_id' => (string)$config['client_id'],
                'client_secret' => (string)$config['client_secret'],
                'redirect_uri' => (string)$config['redirect_uri'],
                'grant_type' => 'authorization_code',
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ], 'token');
        if (!is_string($reply['body']) || $reply['body'] === '' || $reply['status'] < 200 || $reply['status'] >= 300) {
            return ['ok' => false, 'message' => 'Google token exchange failed.' . ($reply['error'] !== '' ? ' ' . $reply['error'] : '')];
        }
        $data = json_decode($reply['body'], true);
        if (!is_array($data) || empty($data['access_token'])) {
            return ['ok' => false, 'message' => 'Google token response was invalid.'];
        }
        return ['ok' => true, 'access_token' => (string)$data['access_token']];
    }

    /** @return array<string, mixed> the profile with `ok` true, or `ok` false and a `message` */
    public function profile(string $accessToken): array
    {
        if ($this->http === null && !(function_exists('curl_init') && function_exists('curl_exec'))) {
            return ['ok' => false, 'message' => 'PHP cURL is required for Google Sign-In.'];
        }
        $reply = $this->call(self::PROFILE_URL, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken]], 'profile');
        if (!is_string($reply['body']) || $reply['body'] === '' || $reply['status'] < 200 || $reply['status'] >= 300) {
            return ['ok' => false, 'message' => 'Google profile lookup failed.'];
        }
        $data = json_decode($reply['body'], true);
        if (!is_array($data)) {
            return ['ok' => false, 'message' => 'Google profile response was invalid.'];
        }
        $data['ok'] = true;
        return $data;
    }

    /**
     * Whether a profile may sign in: the email must be verified and from the allowed domain (when there is one).
     * Matching an active user is the caller's.
     *
     * @param array<string, mixed> $profile from profile()
     * @param array<string, mixed> $config from config()
     * @return array{ok: bool, message: string, email: string, sub: string}
     */
    public function accept(array $profile, array $config): array
    {
        $email = strtolower(trim((string)($profile['email'] ?? '')));
        $sub = trim((string)($profile['sub'] ?? ''));
        if ($email === '' || !(bool)($profile['email_verified'] ?? false)) {
            return ['ok' => false, 'message' => 'Google account email is not verified.', 'email' => $email, 'sub' => $sub];
        }
        if ($config['allowed_domain'] !== '' && !str_ends_with($email, '@' . $config['allowed_domain'])) {
            return ['ok' => false, 'message' => 'This Google account is not allowed for this GnozioCMS installation.', 'email' => $email, 'sub' => $sub];
        }
        return ['ok' => true, 'message' => '', 'email' => $email, 'sub' => $sub];
    }

    /** @return array{body: mixed, status: int, error: string} */
    private function call(string $url, array $options, string $kind): array
    {
        if ($this->http !== null) {
            return ($this->http)($url, $options, $kind);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $options + [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $body = curl_exec($ch);
        return ['body' => $body, 'status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'error' => curl_error($ch)];
    }
}
