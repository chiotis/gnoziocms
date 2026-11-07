<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\SystemMetaRepository;

/**
 * The settings of System > Shop settings (docs/shop-plan.md), kept in `system_meta` under their own name, apart from the site
 * settings, so no settings export carries them. This is the General tab: the store, money and taxes, orders. Payments, shipping
 * and emails join it in later phases, each with its keys kept apart from what a screen is given (view()).
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
        ];
    }
}
