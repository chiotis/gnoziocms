<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

/**
 * The countries a shop ships to when a zone says `*` (everywhere): the EU, the rest of Europe and the countries Greek shops sell
 * to most. Their names come from intl in the visitor's language when PHP has it, otherwise in English.
 */
final class ShopCountries
{
    public const NAMES = [
        'GR' => 'Greece', 'CY' => 'Cyprus', 'AT' => 'Austria', 'BE' => 'Belgium', 'BG' => 'Bulgaria', 'HR' => 'Croatia', 'CZ' => 'Czechia',
        'DK' => 'Denmark', 'EE' => 'Estonia', 'FI' => 'Finland', 'FR' => 'France', 'DE' => 'Germany', 'HU' => 'Hungary', 'IE' => 'Ireland',
        'IT' => 'Italy', 'LV' => 'Latvia', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MT' => 'Malta', 'NL' => 'Netherlands', 'PL' => 'Poland',
        'PT' => 'Portugal', 'RO' => 'Romania', 'SK' => 'Slovakia', 'SI' => 'Slovenia', 'ES' => 'Spain', 'SE' => 'Sweden',
        'AL' => 'Albania', 'CH' => 'Switzerland', 'GB' => 'United Kingdom', 'IS' => 'Iceland', 'ME' => 'Montenegro', 'MK' => 'North Macedonia',
        'NO' => 'Norway', 'RS' => 'Serbia', 'TR' => 'Türkiye', 'UA' => 'Ukraine',
        'AE' => 'United Arab Emirates', 'AU' => 'Australia', 'CA' => 'Canada', 'IL' => 'Israel', 'JP' => 'Japan', 'US' => 'United States',
    ];

    /** The EU's member states, for prices with VAT (and, later, the VAT of the country an order goes to). */
    public const EU = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'];

    public static function name(string $code, string $lang): string
    {
        $code = strtoupper($code);
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-' . $code, $lang);
            if (is_string($name) && $name !== '' && $name !== $code) {
                return $name;
            }
        }
        return self::NAMES[$code] ?? $code;
    }

    /**
     * @param string[] $codes
     * @return array<string, string> code => name, in the order of the names
     */
    public static function options(array $codes, string $lang): array
    {
        $options = [];
        foreach ($codes as $code) {
            $options[$code] = self::name($code, $lang);
        }
        $collator = class_exists(\Collator::class) ? new \Collator($lang) : null;
        uasort($options, static fn(string $a, string $b): int => $collator ? (int)$collator->compare($a, $b) : strcmp($a, $b));
        return $options;
    }
}
