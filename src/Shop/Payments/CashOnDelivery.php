<?php

declare(strict_types=1);

namespace GnozioCMS\Shop\Payments;

/**
 * Cash on delivery (αντικαταβολή): the order goes to processing at once and is paid to the courier; the shop marks it paid
 * or completed when the courier settles. It can have a fee and be offered only in some shipping zones.
 */
final class CashOnDelivery implements PaymentProvider
{
    public function id(): string
    {
        return 'cod';
    }

    public function offered(array $settings, string $zoneId): bool
    {
        if (empty($settings['enabled'])) {
            return false;
        }
        $zones = is_array($settings['zones'] ?? null) ? $settings['zones'] : [];
        return $zones === [] || in_array($zoneId, $zones, true);
    }

    public function fee(array $settings): int
    {
        return max(0, (int)($settings['fee'] ?? 0));
    }

    public function start(array $order, array $settings): array
    {
        return ['status' => 'processing', 'redirect' => ''];
    }

    public function instructions(array $settings): array
    {
        return [];
    }
}
