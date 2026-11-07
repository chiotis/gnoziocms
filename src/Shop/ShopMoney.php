<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

/**
 * Money in the shop (docs/shop-plan.md, section 1): whole cents, never floats, from the moment a price leaves a product's file.
 * Prices are written with VAT in them, so the VAT of an amount is the part of it the rate makes up.
 */
final class ShopMoney
{
    /** A price as written (145, "145,50") in cents; null when it is not a price. */
    public static function cents(mixed $amount): ?int
    {
        if (is_string($amount)) {
            $amount = str_replace(',', '.', trim($amount));
        }
        if (!is_numeric($amount) || (float)$amount < 0) {
            return null;
        }
        return (int)round((float)$amount * 100);
    }

    /** Cents as the shop writes them: €145, €145,50 in Greek, €145.50 in English. */
    public static function format(int $cents, string $lang): string
    {
        return ($cents < 0 ? '−' : '') . ShopCatalogue::money(abs($cents) / 100, $lang);
    }

    /** Cents with two decimals and a dot, for a CSV or a payment provider: 145.50. */
    public static function plain(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** The VAT in a price that has it in: 24% of €124 is €24. */
    public static function vatIn(int $gross, float $rate): int
    {
        return $rate <= 0 || $gross <= 0 ? 0 : (int)round($gross * $rate / (100 + $rate));
    }

    /**
     * Shares an amount out over weights in proportion, in whole cents that add up to it exactly (the last share takes the
     * remainder): how a discount is spread over the lines it applies to, so each line's VAT is of what was really paid.
     *
     * @param array<array-key, int> $weights
     * @return array<array-key, int>
     */
    public static function spread(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        $shares = array_map(static fn(): int => 0, $weights);
        if ($amount <= 0 || $total <= 0) {
            return $shares;
        }
        $given = 0;
        $keys = array_keys(array_filter($weights, static fn(int $w): bool => $w > 0));
        $last = end($keys);
        foreach ($keys as $key) {
            $share = $key === $last ? $amount - $given : (int)floor($amount * $weights[$key] / $total);
            $shares[$key] = $share;
            $given += $share;
        }
        return $shares;
    }
}
