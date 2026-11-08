<?php

declare(strict_types=1);

namespace GnozioCMS\Shop\Payments;

/**
 * A provider the shop can ask about a payment it started, when no answer came back (Cardlink has no server notification: a
 * shopper who closes the browser on the bank's page leaves the order waiting). Before such an order is cancelled for not being
 * paid in time, its provider is asked once more.
 */
interface ChecksPayments
{
    /**
     * What the provider says of a payment, in the shape ShopCheckout::confirm() takes.
     *
     * @param array<string, mixed> $payment a row of shop_payments
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function lookup(array $payment, array $settings): array;
}
