<?php
/*
 * SecurityHeaders (audit S7): HSTS only when it is on and the request is on HTTPS, a Permissions-Policy everywhere, and a
 * Content-Security-Policy (report-only) for the admin and the shop's pages that lists what those pages use; and the setting.
 *   php tests/unit/security-headers.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{SecurityHeaders, SiteSettings};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual) . ' expected ' . json_encode($expected)) . "\n"; }
$has = static fn(string $policy, string $directive, string $source): bool => (bool)preg_match('/(^|; )' . preg_quote($directive, '/') . ' [^;]*' . preg_quote($source, '/') . '(\s|;|$)/', $policy);

// ---- HSTS
check('HSTS when it is on and the request is on HTTPS', SecurityHeaders::for('page', true, true)['Strict-Transport-Security'] ?? null, 'max-age=31536000');
check('never over plain HTTP', isset(SecurityHeaders::for('page', false, true)['Strict-Transport-Security']), false);
check('nor while it is off', isset(SecurityHeaders::for('admin', true, false)['Strict-Transport-Security']), false);

// ---- Permissions-Policy
foreach (['page', 'shop', 'admin'] as $kind) {
    check("a Permissions-Policy on $kind pages", SecurityHeaders::for($kind, false, false)['Permissions-Policy'] ?? '', 'camera=(), microphone=(), usb=(), payment=(self)');
}

// ---- Content-Security-Policy, report-only
check('ordinary pages get none (their blocks embed what the editor chose)', isset(SecurityHeaders::for('page', true, true)['Content-Security-Policy-Report-Only']), false);
$admin = SecurityHeaders::for('admin', true, false)['Content-Security-Policy-Report-Only'] ?? '';
$shop = SecurityHeaders::for('shop', true, false)['Content-Security-Policy-Report-Only'] ?? '';
check('the admin and the shop get one, reported, not enforced', [$admin !== '', $shop !== '', isset(SecurityHeaders::for('admin', true, false)['Content-Security-Policy'])], [true, true, false]);
check('no plugins, no other base address, scripts from the site', [$has($admin, 'object-src', "'none'"), $has($admin, 'base-uri', "'self'"), $has($admin, 'script-src', "'self'"), $has($shop, 'object-src', "'none'")], [true, true, true, true]);
check('the admin is never framed; the shop only by the site', [$has($admin, 'frame-ancestors', "'none'"), $has($shop, 'frame-ancestors', "'self'")], [true, true]);
check('the admin\'s forms go to the site and to Google sign-in', [$has($admin, 'form-action', "'self'"), $has($admin, 'form-action', 'https://accounts.google.com'), $has($admin, 'form-action', 'https://www.vivapayments.com')], [true, true, false]);
check('the checkout may send a shopper to Viva and to every Cardlink acquirer, live and test', [$has($shop, 'form-action', 'https://www.vivapayments.com'), $has($shop, 'form-action', 'https://demo.vivapayments.com'), $has($shop, 'form-action', 'https://vpos.eurocommerce.gr'), $has($shop, 'form-action', 'https://alphaecommerce-test.cardlink.gr'), $has($shop, 'form-action', 'https://ecommerce.cardlink.gr')], [true, true, true, true, true]);
check('the shop\'s pages may run the Meta Pixel and the Google tag', [$has($shop, 'script-src', 'https://connect.facebook.net'), $has($shop, 'script-src', 'https://www.googletagmanager.com'), $has($shop, 'connect-src', 'https://www.facebook.com'), $has($shop, 'connect-src', 'https://*.google-analytics.com')], [true, true, true, true]);
check('the admin runs no tracking script', [$has($admin, 'script-src', 'https://connect.facebook.net'), $has($admin, 'script-src', 'https://www.googletagmanager.com')], [false, false]);
check('pictures from anywhere over HTTPS (map tiles, thumbnails), the shop\'s fonts from the site', [$has($admin, 'img-src', 'https:'), $has($shop, 'font-src', "'self'"), str_contains($shop, 'fonts.googleapis')], [true, true, false]);
check('the admin\'s font from Google Fonts', [$has($admin, 'style-src', 'https://fonts.googleapis.com'), $has($admin, 'font-src', 'https://fonts.gstatic.com')], [true, true]);

// ---- the setting
check('HSTS is off by default', (new ReflectionClass(SiteSettings::class))->newInstanceWithoutConstructor()->defaults()['hsts'], false);
check('the form turns it on and off only when it showed the box', [SiteSettings::formFromPost(['hsts_shown' => '1', 'hsts' => '1'])['hsts'], SiteSettings::formFromPost(['hsts_shown' => '1'])['hsts'], SiteSettings::formFromPost([])['hsts']], [true, false, null]);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
