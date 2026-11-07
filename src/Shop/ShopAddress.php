<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

/**
 * An address at the checkout and in an account (docs/shop-plan.md, sections 3 and 4): the name, the street, the city and the
 * postcode, the country, a phone; a company and its VAT number (ΑΦΜ) for an invoice.
 */
final class ShopAddress
{
    public const FIELDS = ['first_name' => 80, 'last_name' => 80, 'company' => 120, 'vat_number' => 30, 'address' => 160, 'address_2' => 160, 'city' => 80, 'postcode' => 16, 'country' => 2, 'phone' => 40];
    public const REQUIRED = ['first_name', 'last_name', 'address', 'city', 'postcode', 'country'];

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function clean(array $input): array
    {
        $address = [];
        foreach (self::FIELDS as $key => $max) {
            $value = is_scalar($input[$key] ?? null) ? (string)$input[$key] : '';
            $address[$key] = mb_substr(trim(preg_replace('/\s+/u', ' ', $value) ?? ''), 0, $max);
        }
        $address['country'] = preg_match('/^[A-Za-z]{2}$/', $address['country']) === 1 ? strtoupper($address['country']) : '';
        return $address;
    }

    /**
     * The fields a complete address is missing.
     *
     * @param array<string, string> $address
     * @return string[]
     */
    public static function missing(array $address, bool $phone = false): array
    {
        $missing = [];
        foreach ($phone ? [...self::REQUIRED, 'phone'] : self::REQUIRED as $key) {
            if (($address[$key] ?? '') === '') {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    /** @param array<string, string> $address its full name */
    public static function name(array $address): string
    {
        return trim(($address['first_name'] ?? '') . ' ' . ($address['last_name'] ?? ''));
    }

    /**
     * The address as lines, for an email, a packing slip and the order's page.
     *
     * @param array<string, string> $address
     * @return string[]
     */
    public static function lines(array $address, string $lang): array
    {
        $lines = [
            self::name($address),
            $address['company'] ?? '',
            ($address['vat_number'] ?? '') !== '' ? 'ΑΦΜ / VAT ' . $address['vat_number'] : '',
            $address['address'] ?? '',
            $address['address_2'] ?? '',
            trim(($address['postcode'] ?? '') . ' ' . ($address['city'] ?? '')),
            ($address['country'] ?? '') !== '' ? ShopCountries::name($address['country'], $lang) : '',
            $address['phone'] ?? '',
        ];
        return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
    }
}
