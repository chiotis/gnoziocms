<?php

declare(strict_types=1);

namespace GnozioCMS\Shop\Payments;

/**
 * A provider that can charge a customer again without them, with the token of a payment they made allowing it (Viva Wallet's
 * recurring payments). Only these are offered for a cart with a subscription (docs/shop-plan.md, section 7).
 */
interface RecurringPayments
{
    /**
     * Charges an amount again on the card of the payment `$token` names.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $order the renewal order it pays
     * @return array{ok: bool, error: string, transaction_id: string}
     */
    public function charge(array $settings, string $token, int $cents, array $order): array;
}
