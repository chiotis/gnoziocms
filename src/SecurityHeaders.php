<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The second line of defence in the response headers (docs/security.md, *Response Headers*; audit S7):
 *
 * - HSTS, a setting (Settings > General, off by default), sent only over HTTPS: once a browser has seen it, it asks for the site
 *   only over HTTPS for a year. Turning it on is a promise the site keeps HTTPS, so it is the owner's choice.
 * - A Content-Security-Policy for the admin and for the shop's pages (cart, checkout, account, payment pages), as
 *   Report-Only for now: a browser reports in its console what the policy would block, and blocks nothing. The policy lists what
 *   those pages use as they are (the site itself, inline scripts and styles, Google sign-in, the payment providers, the Meta Pixel
 *   and the Google tag); once no page reports anything it can be enforced.
 * - A Permissions-Policy on every page: no camera, microphone or USB; payments only from the site itself.
 */
final class SecurityHeaders
{
    public const HSTS = 'max-age=31536000';
    public const PERMISSIONS = 'camera=(), microphone=(), usb=(), payment=(self)';

    /** The payment pages the checkout sends a shopper to, by a form (Cardlink) or a redirect after one (Viva, Google). */
    private const PAYMENT_HOSTS = [
        'https://www.vivapayments.com', 'https://demo.vivapayments.com',
        'https://ecommerce.cardlink.gr', 'https://ecommerce-test.cardlink.gr',
        'https://www.alphaecommerce.gr', 'https://alphaecommerce-test.cardlink.gr',
        'https://vpos.eurocommerce.gr', 'https://eurocommerce-test.cardlink.gr',
    ];
    private const GOOGLE_SIGN_IN = 'https://accounts.google.com';
    private const TRACKING_SCRIPTS = ['https://connect.facebook.net', 'https://www.googletagmanager.com'];
    private const TRACKING_CONNECT = ['https://www.facebook.com', 'https://connect.facebook.net', 'https://www.googletagmanager.com', 'https://*.google-analytics.com', 'https://*.analytics.google.com'];

    /** @return array<string, string> the headers for a response: name => value */
    public static function for(string $kind, bool $https, bool $hsts): array
    {
        $out = ['Permissions-Policy' => self::PERMISSIONS];
        if ($hsts && $https) {
            $out['Strict-Transport-Security'] = self::HSTS;
        }
        $policy = self::policy($kind);
        if ($policy !== '') {
            $out['Content-Security-Policy-Report-Only'] = $policy;
        }
        return $out;
    }

    /** The Content-Security-Policy of the admin ('admin') or the shop's pages ('shop'); '' for other pages. */
    public static function policy(string $kind): string
    {
        $common = [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'img-src' => ["'self'", 'data:', 'blob:', 'https:'],
            'font-src' => ["'self'", 'data:'],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'media-src' => ["'self'", 'blob:'],
        ];
        if ($kind === 'admin') {
            // The admin's stylesheet (public/assets/css/app.css) takes its font, Inter Tight, from Google Fonts.
            $common['style-src'][] = 'https://fonts.googleapis.com';
            $common['font-src'][] = 'https://fonts.gstatic.com';
            $rules = $common + [
                'script-src' => ["'self'", "'unsafe-inline'"],
                'connect-src' => ["'self'"],
                // The editor's previews of videos and maps.
                'frame-src' => ["'self'", 'https://www.youtube.com', 'https://www.youtube-nocookie.com', 'https://player.vimeo.com'],
                'form-action' => ["'self'", self::GOOGLE_SIGN_IN],
                'frame-ancestors' => ["'none'"],
            ];
        } elseif ($kind === 'shop') {
            $rules = $common + [
                'script-src' => ["'self'", "'unsafe-inline'", ...self::TRACKING_SCRIPTS],
                'connect-src' => ["'self'", ...self::TRACKING_CONNECT],
                'frame-src' => ["'self'", 'https://www.googletagmanager.com'],
                'form-action' => ["'self'", self::GOOGLE_SIGN_IN, ...self::PAYMENT_HOSTS],
                'frame-ancestors' => ["'self'"],
            ];
        } else {
            return '';
        }
        $parts = [];
        foreach ($rules as $name => $sources) {
            $parts[] = $name . ' ' . implode(' ', $sources);
        }
        return implode('; ', $parts);
    }
}
