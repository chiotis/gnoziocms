<?php

declare(strict_types=1);

namespace GnozioCMS\Shop\Payments;

/**
 * Bank transfer: the order waits, on hold, with its stock kept, until the shop sees the money and marks it paid. The order's
 * page and email show the shop's accounts and ask for the order number as the transfer's reference.
 */
final class BankTransfer implements PaymentProvider
{
    public function id(): string
    {
        return 'bank_transfer';
    }

    public function offered(array $settings, string $zoneId): bool
    {
        return !empty($settings['enabled']) && trim((string)($settings['accounts'] ?? '')) !== '';
    }

    public function fee(array $settings): int
    {
        return 0;
    }

    public function start(array $order, array $settings): array
    {
        return ['status' => 'on_hold', 'redirect' => ''];
    }

    public function instructions(array $settings): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)($settings['accounts'] ?? '')) ?: []), static fn(string $l): bool => $l !== ''));
    }
}
