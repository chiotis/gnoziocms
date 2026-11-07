<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

/**
 * System > Shop settings (docs/shop-plan.md, section 8): the screen and its forms. General (the store, VAT, orders), Shipping
 * (zones and their methods), Payments (bank transfer, cash on delivery; the online providers join it in phase 4) and Accounts.
 */
final class ShopSettingsAdmin
{
    public const TABS = ['general' => 'General', 'shipping' => 'Shipping', 'payments' => 'Payments', 'accounts' => 'Accounts'];

    /**
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log
     * @param \Closure(): array<string, string> $pages the site's pages, slug => title, for the terms and returns links
     * @param string[] $languages the site's languages, the default first: a shipping method's name is written in each
     */
    public function __construct(private ShopSettings $settings, private \Closure $log, private \Closure $pages, private array $languages = ['en'])
    {
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, tab: string, message: string}
     */
    public function act(array $post): array
    {
        $tab = isset(self::TABS[(string)($post['tab'] ?? '')]) ? (string)$post['tab'] : 'general';
        match ($tab) {
            'shipping' => $this->settings->saveShipping($post, $this->languages),
            'payments' => $this->settings->savePayments($post),
            'accounts' => $this->settings->saveAccounts($post),
            default => $this->settings->saveGeneral($post),
        };
        ($this->log)('shop.settings', 'info', 'shop', $tab, 'Shop settings saved (' . self::TABS[$tab] . ').', []);
        return ['ok' => true, 'tab' => $tab, 'message' => 'Shop settings saved.'];
    }

    /**
     * @param array<string, mixed> $get
     * @return array<string, mixed>
     */
    public function screen(array $get): array
    {
        $tab = isset(self::TABS[(string)($get['tab'] ?? '')]) ? (string)$get['tab'] : 'general';
        return [
            'tab' => $tab,
            'tabs' => self::TABS,
            'shop' => $this->settings->view(),
            'currencies' => ShopSettings::CURRENCIES,
            'tax_labels' => ShopSettings::TAX_LABELS,
            'pages' => ($this->pages)(),
            'languages' => $this->languages,
            'ship_kinds' => ShopShipping::KINDS,
            'table_text' => array_map(static fn(array $zone): array => array_map(static fn(array $m): string => ShopShipping::tableText($m['table']), $zone['methods']), $this->settings->all()['shipping']),
            'done' => (string)($get['done'] ?? ''),
            'message' => mb_substr((string)($get['msg'] ?? ''), 0, 200),
        ];
    }
}
