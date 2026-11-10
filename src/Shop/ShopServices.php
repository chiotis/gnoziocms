<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\Shop\Payments\BankTransfer;
use GnozioCMS\Shop\Payments\Cardlink;
use GnozioCMS\Shop\Payments\CashOnDelivery;
use GnozioCMS\Shop\Payments\PaymentProvider;
use GnozioCMS\Shop\Payments\VivaWallet;
use GnozioCMS\SystemDatabase;
use GnozioCMS\SystemMetaRepository;

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
    private ?ShopPayments $payments = null;
    private ?ShopSecrets $secrets = null;
    private ?VivaWallet $viva = null;
    private ?Cardlink $cardlink = null;
    private ?ShopDownloads $downloads = null;
    private ?ShopSubscriptions $subscriptions = null;
    private ?ShopMarketing $marketing = null;
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
        private string $defaultLang,
        private ?SystemMetaRepository $meta = null,
        private ?\Closure $transport = null,
        private string $siteUrl = ''
    ) {
    }

    public function payments(): ShopPayments
    {
        return $this->payments ??= new ShopPayments($this->database);
    }

    public function secrets(): ShopSecrets
    {
        return $this->secrets ??= new ShopSecrets($this->meta ?? new SystemMetaRepository($this->database));
    }

    public function viva(): VivaWallet
    {
        return $this->viva ??= new VivaWallet($this->secrets(), $this->payments(), $this->meta ?? new SystemMetaRepository($this->database), $this->transport);
    }

    public function cardlink(): Cardlink
    {
        return $this->cardlink ??= new Cardlink($this->secrets(), $this->payments(), $this->orders(), $this->siteUrl, $this->transport);
    }

    public function downloads(): ShopDownloads
    {
        return $this->downloads ??= new ShopDownloads(
            $this->database,
            $this->meta ?? new SystemMetaRepository($this->database),
            dirname($this->database->path(), 2) . '/shop-files',
            $this->siteUrl,
            function (string $productId): array {
                $catalogue = ($this->catalogue)();
                $product = $catalogue->product($productId, $catalogue->defaultLanguage());
                $custom = is_array($product?->meta['custom_fields'] ?? null) ? $product->meta['custom_fields'] : [];
                return ['limit' => max(0, (int)($custom['download_limit'] ?? 5)), 'days' => max(0, (int)($custom['download_days'] ?? 30))];
            }
        );
    }

    public function subscriptions(): ShopSubscriptions
    {
        return $this->subscriptions ??= new ShopSubscriptions(
            $this->database,
            $this->orders(),
            $this->payments(),
            $this->settings,
            fn(): ShopCheckout => $this->checkout(),
            fn(): array => $this->providers(),
            function (string $kind, array $sub, ?array $order): void {
                $customer = $this->customers()->get((int)$sub['customer_id']);
                if ($customer !== null) {
                    $lang = $sub['lang'] !== '' ? (string)$sub['lang'] : $this->defaultLang;
                    $this->mail()->subscriptionNotice($kind, $sub, (string)$customer['email'], (string)$customer['name'], rtrim($this->siteUrl, '/') . '/' . ($lang !== $this->defaultLang ? $lang . '/' : '') . 'account#subscriptions');
                }
            }
        );
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
            foreach ([$this->viva(), $this->cardlink(), new BankTransfer(), new CashOnDelivery()] as $provider) {
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
            $this->defaultLang,
            $this->payments(),
            $this->downloads(),
            $this->subscriptions(),
            fn(int $orderId) => $this->marketing()->purchase($orderId)
        );
    }

    /** The product feed's settings, the Meta Pixel and the purchases sent to Meta (ShopMarketing). */
    public function marketing(): ShopMarketing
    {
        return $this->marketing ??= new ShopMarketing(
            $this->settings,
            $this->secrets(),
            $this->orders(),
            $this->database,
            fn(string $id, string $lang) => ($this->catalogue)()->product($id, $lang),
            $this->defaultLang,
            $this->transport
        );
    }

    public function mail(): ShopMail
    {
        return $this->mail ??= new ShopMail(
            $this->settings,
            $this->siteSettings,
            $this->t,
            $this->send,
            fn(string $id): ?PaymentProvider => $this->providers()[$id] ?? null,
            fn(int $orderId): array => $this->downloads()->forOrder($orderId),
            $this->siteUrl
        );
    }
}
