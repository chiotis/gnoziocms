<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\SystemMetaRepository;

/**
 * The settings of System > Shop settings (docs/shop-plan.md), kept in `system_meta` under their own name, apart from the site
 * settings, so no settings export carries them: the store, money and taxes, orders (General), the shipping zones and their
 * methods (Shipping), the ways to pay (Payments) and the customers' accounts (Accounts). The keys of online payment providers
 * join it in phase 4, kept apart from what a screen is given (view()).
 */
final class ShopSettings
{
    public const META = 'shop_settings';
    public const CURRENCIES = ['EUR' => 'Euro (€)'];
    /** The tax classes a product can have, with the Greek rates as their defaults (percent). */
    public const TAX_CLASSES = ['standard' => 24.0, 'reduced' => 13.0, 'super_reduced' => 6.0, 'zero' => 0.0];
    public const TAX_LABELS = ['standard' => 'Standard', 'reduced' => 'Reduced', 'super_reduced' => 'Super-reduced', 'zero' => 'Zero'];

    public function __construct(private SystemMetaRepository $meta)
    {
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $stored = $this->meta->isAvailable() ? ($this->meta->getJson(self::META) ?? []) : [];
        return self::normalize(is_array($stored) ? $stored : []);
    }

    /** @return array<string, mixed> what a screen may show (there are no secrets on the General tab yet) */
    public function view(): array
    {
        return $this->all();
    }

    /**
     * The Shipping tab's form: the zones and their methods (ShopShipping).
     *
     * @param array<string, mixed> $post
     * @param string[] $languages
     */
    public function saveShipping(array $post, array $languages): void
    {
        if (!$this->meta->isAvailable()) {
            return;
        }
        $stored = $this->meta->getJson(self::META) ?? [];
        $stored['shipping'] = ShopShipping::normalize($post['zones'] ?? [], $languages);
        // Stored as cents from here on.
        $this->meta->setJson(self::META, self::normalize($stored));
    }

    /**
     * The Payments tab's form: the offline ways to pay.
     *
     * @param array<string, mixed> $post
     */
    public function savePayments(array $post): void
    {
        if (!$this->meta->isAvailable()) {
            return;
        }
        $stored = $this->meta->getJson(self::META) ?? [];
        $viva = is_array($post['viva'] ?? null) ? $post['viva'] : [];
        $cardlink = is_array($post['cardlink'] ?? null) ? $post['cardlink'] : [];
        $stored['payments'] = [
            'viva' => [
                'enabled' => !empty($viva['enabled']),
                'demo' => !empty($viva['demo']),
                'merchant_id' => (string)($viva['merchant_id'] ?? ''),
                'client_id' => (string)($viva['client_id'] ?? ''),
                'source_code' => (string)($viva['source_code'] ?? ''),
                'installments' => \GnozioCMS\Shop\Payments\VivaWallet::installmentsTable((string)($viva['installments'] ?? '')),
            ],
            'cardlink' => [
                'enabled' => !empty($cardlink['enabled']),
                'test' => !empty($cardlink['test']),
                'acquirer' => (string)($cardlink['acquirer'] ?? 'cardlink'),
                'merchant_id' => (string)($cardlink['merchant_id'] ?? ''),
                'installments' => \GnozioCMS\Shop\Payments\VivaWallet::installmentsTable((string)($cardlink['installments'] ?? '')),
            ],
            'bank_transfer' => ['enabled' => !empty($post['bank_transfer']['enabled']), 'accounts' => (string)($post['bank_transfer']['accounts'] ?? '')],
            'cod' => ['enabled' => !empty($post['cod']['enabled']), 'fee' => ShopMoney::cents($post['cod']['fee'] ?? '') ?? 0, 'zones' => is_array($post['cod']['zones'] ?? null) ? $post['cod']['zones'] : []],
        ];
        $this->meta->setJson(self::META, self::normalize($stored));
    }

    /**
     * The Marketing tab's form: the product feed and the Meta Pixel (its access token is a secret, ShopSecrets).
     *
     * @param array<string, mixed> $post
     */
    public function saveMarketing(array $post): void
    {
        if (!$this->meta->isAvailable()) {
            return;
        }
        $stored = $this->meta->getJson(self::META) ?? [];
        $m = is_array($post['marketing'] ?? null) ? $post['marketing'] : [];
        $stored['marketing'] = ShopMarketing::normalize($m + ['feed' => false, 'out_of_stock' => false]);
        $this->meta->setJson(self::META, self::normalize($stored));
    }

    /**
     * The Accounts tab's form.
     *
     * @param array<string, mixed> $post
     */
    public function saveAccounts(array $post): void
    {
        if (!$this->meta->isAvailable()) {
            return;
        }
        $stored = $this->meta->getJson(self::META) ?? [];
        $stored['accounts'] = ['signup' => !empty($post['signup']), 'verify' => !empty($post['verify'])];
        $this->meta->setJson(self::META, self::normalize($stored));
    }

    /**
     * The Emails tab's form: HTML or plain text, the colour, a logo and a footer line, and each email's subject and text in each
     * language (blank: the theme's own words).
     *
     * @param array<string, mixed> $post
     */
    public function saveEmails(array $post): void
    {
        if (!$this->meta->isAvailable()) {
            return;
        }
        $stored = $this->meta->getJson(self::META) ?? [];
        $stored['emails'] = [
            'html' => !empty($post['emails']['html']),
            'accent' => (string)($post['emails']['accent'] ?? ''),
            'logo' => (string)($post['emails']['logo'] ?? ''),
            'footer' => (string)($post['emails']['footer'] ?? ''),
            'texts' => is_array($post['emails']['texts'] ?? null) ? $post['emails']['texts'] : [],
        ];
        $this->meta->setJson(self::META, self::normalize($stored));
    }

    /** The rate of a tax class, in percent; an unknown class is the standard one. */
    public function taxRate(string $class): float
    {
        $rates = $this->all()['tax_rates'];
        return (float)($rates[$class] ?? $rates['standard']);
    }

    /**
     * The General tab's form.
     *
     * @param array<string, mixed> $post
     */
    public function saveGeneral(array $post): void
    {
        if (!$this->meta->isAvailable()) {
            return;
        }
        $rates = [];
        foreach (array_keys(self::TAX_CLASSES) as $class) {
            $rates[$class] = $post['tax_rates'][$class] ?? null;
        }
        $values = [
            'store_name' => $post['store_name'] ?? '',
            'store_email' => $post['store_email'] ?? '',
            'store_phone' => $post['store_phone'] ?? '',
            'store_address' => $post['store_address'] ?? '',
            'vat_number' => $post['vat_number'] ?? '',
            'currency' => $post['currency'] ?? 'EUR',
            'tax_rates' => $rates,
            'order_prefix' => $post['order_prefix'] ?? '',
            'reserve_minutes' => $post['reserve_minutes'] ?? 30,
            'guest_checkout' => !empty($post['guest_checkout']),
            'terms_page' => $post['terms_page'] ?? '',
            'returns_page' => $post['returns_page'] ?? '',
        ];
        $this->meta->setJson(self::META, self::normalize(array_replace($this->meta->getJson(self::META) ?? [], $values)));
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    public static function normalize(array $stored): array
    {
        $line = static fn(mixed $value, int $max = 200): string => mb_substr(trim(preg_replace('/\s+/u', ' ', is_scalar($value) ? (string)$value : '') ?? ''), 0, $max);
        $rates = [];
        foreach (self::TAX_CLASSES as $class => $default) {
            $value = $stored['tax_rates'][$class] ?? null;
            $value = is_string($value) ? str_replace(',', '.', trim($value)) : $value;
            $rates[$class] = is_numeric($value) && (float)$value >= 0 && (float)$value <= 100 ? round((float)$value, 2) : $default;
        }
        $email = $line($stored['store_email'] ?? '', 190);
        $currency = (string)($stored['currency'] ?? 'EUR');
        $prefix = strtoupper((string)preg_replace('/[^A-Za-z0-9-]/', '', (string)($stored['order_prefix'] ?? '')));
        $minutes = (int)($stored['reserve_minutes'] ?? 30);
        $page = static fn(mixed $value): string => preg_match('/^[a-z0-9][a-z0-9-]*$/', (string)$value) === 1 ? (string)$value : '';
        $payments = is_array($stored['payments'] ?? null) ? $stored['payments'] : [];
        $bank = is_array($payments['bank_transfer'] ?? null) ? $payments['bank_transfer'] : [];
        $cod = is_array($payments['cod'] ?? null) ? $payments['cod'] : [];
        $viva = is_array($payments['viva'] ?? null) ? $payments['viva'] : [];
        $cardlink = is_array($payments['cardlink'] ?? null) ? $payments['cardlink'] : [];
        $table = static fn(mixed $rows): array => array_values(array_filter(is_array($rows) ? $rows : [], static fn(mixed $r): bool => is_array($r) && count($r) === 2 && is_int($r[0]) && is_int($r[1])));
        $code = static fn(mixed $value, string $pattern): string => preg_match($pattern, trim(is_scalar($value) ? (string)$value : '')) === 1 ? trim((string)$value) : '';
        $accounts = is_array($stored['accounts'] ?? null) ? $stored['accounts'] : [];
        $zones = ShopShipping::normalize($stored['shipping'] ?? [], [], true);
        $zoneIds = array_column($zones, 'id');
        return [
            'store_name' => $line($stored['store_name'] ?? ''),
            'store_email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
            'store_phone' => $line($stored['store_phone'] ?? '', 40),
            'store_address' => mb_substr(trim(str_replace("\r\n", "\n", is_scalar($stored['store_address'] ?? null) ? (string)$stored['store_address'] : '')), 0, 500),
            'vat_number' => $line($stored['vat_number'] ?? '', 30),
            'currency' => isset(self::CURRENCIES[$currency]) ? $currency : 'EUR',
            // Prices are written with VAT in them, the norm for selling to people in the EU.
            'prices_include_vat' => true,
            'tax_rates' => $rates,
            'order_prefix' => substr($prefix, 0, 12),
            'reserve_minutes' => $minutes >= 5 && $minutes <= 240 ? $minutes : 30,
            'guest_checkout' => array_key_exists('guest_checkout', $stored) ? (bool)$stored['guest_checkout'] : true,
            'terms_page' => $page($stored['terms_page'] ?? ''),
            'returns_page' => $page($stored['returns_page'] ?? ''),
            'shipping' => $zones,
            'payments' => [
                'viva' => [
                    'enabled' => !empty($viva['enabled']),
                    'demo' => array_key_exists('demo', $viva) ? (bool)$viva['demo'] : true,
                    'merchant_id' => $code($viva['merchant_id'] ?? '', '/^[A-Za-z0-9-]{4,64}$/'),
                    'client_id' => $code($viva['client_id'] ?? '', '/^[A-Za-z0-9._@-]{4,200}$/'),
                    'source_code' => $code($viva['source_code'] ?? '', '/^[A-Za-z0-9_-]{1,20}$/'),
                    'installments' => array_values(array_filter(is_array($viva['installments'] ?? null) ? $viva['installments'] : [], static fn(mixed $r): bool => is_array($r) && count($r) === 2 && is_int($r[0]) && is_int($r[1]))),
                ],
                'cardlink' => [
                    'enabled' => !empty($cardlink['enabled']),
                    'test' => array_key_exists('test', $cardlink) ? (bool)$cardlink['test'] : true,
                    'acquirer' => \GnozioCMS\Shop\Payments\Cardlink::acquirer($cardlink),
                    'merchant_id' => $code($cardlink['merchant_id'] ?? '', '/^[0-9]{4,20}$/'),
                    'installments' => $table($cardlink['installments'] ?? []),
                ],
                'bank_transfer' => [
                    'enabled' => !empty($bank['enabled']),
                    'accounts' => mb_substr(trim(str_replace("\r\n", "\n", is_scalar($bank['accounts'] ?? null) ? (string)$bank['accounts'] : '')), 0, 2000),
                ],
                'cod' => [
                    'enabled' => !empty($cod['enabled']),
                    'fee' => max(0, min(100_00, (int)($cod['fee'] ?? 0))),
                    'zones' => array_values(array_intersect(array_map('strval', is_array($cod['zones'] ?? null) ? $cod['zones'] : []), $zoneIds)),
                ],
            ],
            'accounts' => [
                'signup' => array_key_exists('signup', $accounts) ? (bool)$accounts['signup'] : true,
                'verify' => !empty($accounts['verify']),
            ],
            'emails' => self::emails(is_array($stored['emails'] ?? null) ? $stored['emails'] : []),
            'marketing' => ShopMarketing::normalize(is_array($stored['marketing'] ?? null) ? $stored['marketing'] : []),
        ];
    }

    /**
     * @param array<string, mixed> $emails
     * @return array{html: bool, accent: string, logo: string, footer: string, texts: array<string, array<string, array{subject: string, text: string}>>}
     */
    private static function emails(array $emails): array
    {
        $texts = [];
        foreach (is_array($emails['texts'] ?? null) ? $emails['texts'] : [] as $kind => $byLang) {
            if (!in_array((string)$kind, ShopMail::KINDS, true) || !is_array($byLang)) {
                continue;
            }
            foreach ($byLang as $lang => $pair) {
                if (preg_match('/^[a-z]{2}$/', (string)$lang) !== 1 || !is_array($pair)) {
                    continue;
                }
                $subject = mb_substr(trim(preg_replace('/\s+/u', ' ', is_scalar($pair['subject'] ?? null) ? (string)$pair['subject'] : '') ?? ''), 0, 200);
                $text = mb_substr(trim(str_replace("\r\n", "\n", is_scalar($pair['text'] ?? null) ? (string)$pair['text'] : '')), 0, 2000);
                if ($subject !== '' || $text !== '') {
                    $texts[(string)$kind][(string)$lang] = ['subject' => $subject, 'text' => $text];
                }
            }
        }
        $accent = strtolower(trim((string)($emails['accent'] ?? '')));
        $logo = trim((string)($emails['logo'] ?? ''));
        return [
            'html' => array_key_exists('html', $emails) ? (bool)$emails['html'] : true,
            'accent' => preg_match('/^#[0-9a-f]{6}$/', $accent) === 1 ? $accent : '#1f2937',
            'logo' => preg_match('#^(https://|/)[^\s"\'<>]{1,500}$#', $logo) === 1 ? $logo : '',
            'footer' => mb_substr(trim(str_replace("\r\n", "\n", (string)($emails['footer'] ?? ''))), 0, 500),
            'texts' => $texts,
        ];
    }
}
