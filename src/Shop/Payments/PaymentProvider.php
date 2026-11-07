<?php

declare(strict_types=1);

namespace GnozioCMS\Shop\Payments;

/**
 * A way to pay at the checkout (docs/shop-plan.md, section 5). The offline ones (bank transfer, cash on delivery) come first;
 * Viva Wallet and Cardlink add a redirect to their pages and the confirmation that comes back (phases 4 and 5).
 */
interface PaymentProvider
{
    /** The provider's id, kept on the order: bank_transfer, cod, viva, cardlink. */
    public function id(): string;

    /**
     * Whether the checkout offers it for an order going to this shipping zone.
     *
     * @param array<string, mixed> $settings the provider's own, from Shop settings > Payments
     */
    public function offered(array $settings, string $zoneId): bool;

    /** What the customer pays for using it, in cents (with VAT). */
    public function fee(array $settings): int;

    /**
     * What happens when an order is placed with it: the status the order takes, and where the customer goes next ('' for the
     * order's own page).
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $settings
     * @return array{status: string, redirect: string}
     */
    public function start(array $order, array $settings): array;

    /**
     * What the order's page and its email tell the customer to do next, if anything (the shop's bank accounts).
     *
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    public function instructions(array $settings): array;
}
