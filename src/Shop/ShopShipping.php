<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

/**
 * Shipping (docs/shop-plan.md, section 3): zones by country, each with its methods. A zone can be narrowed to postcodes that
 * start with given digits (the Greek islands), and the first zone that matches an address is its zone, so the narrower zones
 * come first. `*` as a country is everywhere else. A method is a flat rate (free over an amount, if it says so), free
 * shipping over an amount, a rate by weight, or pickup at one of the shop's stores.
 */
final class ShopShipping
{
    public const KINDS = ['flat' => 'Flat rate', 'free' => 'Free over an amount', 'weight' => 'By weight', 'pickup' => 'Pickup at a store'];
    public const MAX_ZONES = 12;
    public const MAX_METHODS = 8;

    /**
     * The zones as Shop settings keep them, from what the Shipping tab sends or what was stored.
     *
     * @param mixed $raw
     * @param string[] $languages the site's languages; none: every language a label is written in is kept
     * @param bool $stored whether it was stored (amounts in cents, weight tables as rows)
     * @return list<array<string, mixed>>
     */
    public static function normalize(mixed $raw, array $languages, bool $stored = false): array
    {
        // Amounts as the Shipping tab sends them are in euros; stored, they are cents already.
        $money = static fn(mixed $value): ?int => $stored ? (is_int($value) && $value >= 0 ? $value : null) : ShopMoney::cents($value);
        $zones = [];
        foreach (is_array($raw) ? array_values($raw) : [] as $zone) {
            if (!is_array($zone) || count($zones) >= self::MAX_ZONES) {
                continue;
            }
            $name = self::line($zone['name'] ?? '', 80);
            $countries = self::countryList($zone['countries'] ?? '');
            if ($name === '' && $countries === []) {
                continue;
            }
            $methods = [];
            foreach (is_array($zone['methods'] ?? null) ? array_values($zone['methods']) : [] as $method) {
                if (!is_array($method) || count($methods) >= self::MAX_METHODS) {
                    continue;
                }
                $kind = isset(self::KINDS[(string)($method['kind'] ?? '')]) ? (string)$method['kind'] : 'flat';
                $labels = [];
                $given = is_array($method['label'] ?? null) ? $method['label'] : [($languages[0] ?? 'en') => $method['label'] ?? ''];
                foreach ($given as $lang => $label) {
                    $label = self::line($label, 80);
                    if ($label !== '' && preg_match('/^[a-z]{2}$/', (string)$lang) === 1 && ($languages === [] || in_array($lang, $languages, true))) {
                        $labels[(string)$lang] = $label;
                    }
                }
                $cost = $money($method['cost'] ?? 0) ?? 0;
                $over = $money($method['free_over'] ?? '');
                // As the Shipping tab sends them (lines of text), or as they were stored (lists).
                $table = self::weightTable(is_array($method['table'] ?? null) ? self::tableText(array_values(array_filter($method['table'], static fn(mixed $r): bool => is_array($r) && count($r) === 2))) : (string)($method['table'] ?? ''));
                $stores = self::lines(is_array($method['stores'] ?? null) ? implode("\n", array_map('strval', $method['stores'])) : (string)($method['stores'] ?? ''), 20, 120);
                if ($labels === [] && $cost === 0 && $over === null && $table === [] && $stores === [] && $kind === 'flat' && trim((string)($method['cost'] ?? '')) === '') {
                    continue;
                }
                $id = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($method['id'] ?? ''))) ?: '';
                $methods[] = [
                    'id' => $id !== '' ? $id : substr(bin2hex(random_bytes(4)), 0, 8),
                    'kind' => $kind,
                    'label' => $labels,
                    'cost' => min($cost, 10_000_00),
                    'free_over' => $over,
                    'table' => $table,
                    'stores' => $stores,
                ];
            }
            $id = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($zone['id'] ?? ''))) ?: '';
            $zones[] = [
                'id' => $id !== '' ? $id : substr(bin2hex(random_bytes(4)), 0, 8),
                'name' => $name !== '' ? $name : implode(', ', $countries),
                'countries' => $countries,
                'postcodes' => array_values(array_filter(array_map(static fn(string $p): string => preg_replace('/\s+/', '', $p) ?? '', preg_split('/[\s,;]+/', (string)(is_array($zone['postcodes'] ?? null) ? implode(',', $zone['postcodes']) : ($zone['postcodes'] ?? ''))) ?: []), static fn(string $p): bool => preg_match('/^[A-Za-z0-9-]{1,10}$/', $p) === 1)),
                'methods' => $methods,
            ];
        }
        return $zones;
    }

    /**
     * The zone of an address: the first whose countries hold it (or `*`) and whose postcodes, when it has any, start the postcode.
     *
     * @param list<array<string, mixed>> $zones
     * @return array<string, mixed>|null
     */
    public static function zoneFor(array $zones, string $country, string $postcode): ?array
    {
        $country = strtoupper(trim($country));
        $postcode = preg_replace('/\s+/', '', $postcode) ?? '';
        foreach ($zones as $zone) {
            if (!in_array($country, $zone['countries'], true) && !in_array('*', $zone['countries'], true)) {
                continue;
            }
            if ($zone['postcodes'] !== []) {
                $match = false;
                foreach ($zone['postcodes'] as $prefix) {
                    if ($postcode !== '' && str_starts_with(strtoupper($postcode), strtoupper($prefix))) {
                        $match = true;
                        break;
                    }
                }
                if (!$match) {
                    continue;
                }
            }
            return $zone;
        }
        return null;
    }

    /**
     * The methods a zone offers for an order of these items, with what each costs.
     *
     * @param array<string, mixed> $zone
     * @return list<array{id: string, kind: string, label: string, cents: int, stores: list<string>}>
     */
    public static function methods(array $zone, int $itemsCents, int $grams, string $lang, string $defaultLang, \Closure $translate): array
    {
        $offered = [];
        foreach ($zone['methods'] as $method) {
            $cents = match ($method['kind']) {
                'flat' => $method['free_over'] !== null && $itemsCents >= $method['free_over'] ? 0 : $method['cost'],
                'free' => $method['free_over'] === null || $itemsCents >= $method['free_over'] ? 0 : null,
                'weight' => self::byWeight($method['table'], $grams),
                'pickup' => $method['cost'],
                default => null,
            };
            if ($cents === null) {
                continue;
            }
            $label = $method['label'][$lang] ?? $method['label'][$defaultLang] ?? '';
            $offered[] = [
                'id' => $method['id'],
                'kind' => $method['kind'],
                'label' => $label !== '' ? $label : (string)$translate('shop.ship_' . $method['kind']),
                'cents' => $cents,
                'stores' => $method['stores'],
            ];
        }
        return $offered;
    }

    /**
     * The countries an address can be in: those the zones name, or every country the shop knows when one zone is `*` or no zone
     * names any yet (the list is never empty, so the checkout's required field can always be filled).
     *
     * @param list<array<string, mixed>> $zones
     * @return string[]
     */
    public static function countries(array $zones): array
    {
        $codes = [];
        foreach ($zones as $zone) {
            foreach ($zone['countries'] as $code) {
                if ($code === '*') {
                    return array_keys(ShopCountries::NAMES);
                }
                $codes[$code] = true;
            }
        }
        return $codes !== [] ? array_keys($codes) : array_keys(ShopCountries::NAMES);
    }

    /** @param list<array{0: int, 1: int}> $table grams => cents, the first row heavy enough is the rate; null when it is heavier than all */
    private static function byWeight(array $table, int $grams): ?int
    {
        foreach ($table as [$upTo, $cents]) {
            if ($grams <= $upTo) {
                return $cents;
            }
        }
        return null;
    }

    /** @return list<array{0: int, 1: int}> "500 = 4,50" lines as [grams, cents], lightest first */
    public static function weightTable(string $lines): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', $lines) ?: [] as $line) {
            [$grams, $cost] = array_pad(array_map('trim', explode('=', $line, 2)), 2, '');
            $cents = ShopMoney::cents($cost);
            if (ctype_digit($grams) && $cents !== null) {
                $rows[(int)$grams] = [(int)$grams, $cents];
            }
        }
        ksort($rows);
        return array_slice(array_values($rows), 0, 30);
    }

    /** @param list<array{0: int, 1: int}> $table back to the lines the Shipping tab shows */
    public static function tableText(array $table): string
    {
        return implode("\n", array_map(static fn(array $row): string => $row[0] . ' = ' . ShopMoney::plain($row[1]), $table));
    }

    /** @return string[] ISO codes (GR, CY) or `*`, from "GR, CY" */
    private static function countryList(mixed $value): array
    {
        $codes = [];
        foreach (preg_split('/[\s,;]+/', strtoupper(is_array($value) ? implode(',', $value) : (string)$value)) ?: [] as $code) {
            if ($code === '*' || preg_match('/^[A-Z]{2}$/', $code) === 1) {
                $codes[$code] = true;
            }
        }
        return array_keys($codes);
    }

    private static function line(mixed $value, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', is_scalar($value) ? (string)$value : '') ?? ''), 0, $max);
    }

    /** @return list<string> */
    private static function lines(string $value, int $count, int $max): array
    {
        $lines = array_values(array_filter(array_map(static fn(string $l): string => self::line($l, $max), preg_split('/\R/u', $value) ?: []), static fn(string $l): bool => $l !== ''));
        return array_slice($lines, 0, $count);
    }
}
