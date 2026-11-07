<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\Shop\Payments\BankTransfer;
use GnozioCMS\Shop\Payments\CashOnDelivery;
use GnozioCMS\Shop\Payments\PaymentProvider;
use GnozioCMS\SystemDatabase;

/**
 * The shop's parts, each made the first time it is needed, so App only hands over what they share (the database, the
 * catalogue, strings, email) and its own code stays small.
 */
final class ShopServices
{
    private ?ShopCart $cart = null;
    private ?ShopPricing $pricing = null;
    private ?ShopCoupons $coupons = null;
    private ?ShopOrders $orders = null;
    private ?ShopCustomers $customers = null;
    private ?ShopCheckout $checkout = null;
    private ?ShopMail $mail = null;
    /** @var array<string, PaymentProvider>|null */
    private ?array $providers = null;

    /**
     * @param \Closure(): ShopCatalogue $catalogue
     * @param \Closure(\GnozioCMS\ContentItem): string $productUrl
     * @param \Closure(string, string, array<string, string>=): string $t
     * @param \Closure(string, string, string, array<string, string>): bool $send
     * @param \Closure(): array<string, mixed> $siteSettings
     */
    public function __construct(
        private SystemDatabase $database,
        private ShopStock $stock,
        private ShopSettings $settings,
        private \Closure $catalogue,
        private \Closure $productUrl,
        private \Closure $t,
        private \Closure $send,
        private \Closure $siteSettings,
        private string $defaultLang
    ) {
    }

    public function settings(): ShopSettings
    {
        return $this->settings;
    }

    public function stock(): ShopStock
    {
        return $this->stock;
    }

    public function cart(): ShopCart
    {
        return $this->cart ??= new ShopCart($this->database);
    }

    public function pricing(): ShopPricing
    {
        return $this->pricing ??= new ShopPricing(($this->catalogue)(), $this->stock, $this->settings, $this->productUrl);
    }

    public function coupons(): ShopCoupons
    {
        return $this->coupons ??= new ShopCoupons($this->database);
    }

    public function orders(): ShopOrders
    {
        return $this->orders ??= new ShopOrders($this->database);
    }

    public function customers(): ShopCustomers
    {
        return $this->customers ??= new ShopCustomers($this->database);
    }

    /** @return array<string, PaymentProvider> the ways to pay, in the order the checkout offers them */
    public function providers(): array
    {
        if ($this->providers === null) {
            $this->providers = [];
            foreach ([new BankTransfer(), new CashOnDelivery()] as $provider) {
                $this->providers[$provider->id()] = $provider;
            }
        }
        return $this->providers;
    }

    public function checkout(): ShopCheckout
    {
        return $this->checkout ??= new ShopCheckout(
            $this->pricing(),
            $this->coupons(),
            $this->orders(),
            $this->stock,
            $this->settings,
            $this->providers(),
            fn(string $key, string $lang): string => ($this->t)($key, $lang, []),
            $this->defaultLang
        );
    }

    public function mail(): ShopMail
    {
        return $this->mail ??= new ShopMail(
            $this->settings,
            $this->siteSettings,
            $this->t,
            $this->send,
            fn(string $id): ?PaymentProvider => $this->providers()[$id] ?? null
        );
    }
}
