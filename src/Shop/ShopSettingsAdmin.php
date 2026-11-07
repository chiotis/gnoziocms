<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

/**
 * System > Shop settings (docs/shop-plan.md, section 8): the screen and its form. Only the General tab exists in the first
 * phase; Payments, Shipping, Taxes, Emails and Accounts are added with the phases that use them.
 */
final class ShopSettingsAdmin
{
    public const TABS = ['general' => 'General'];

    /**
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log
     * @param \Closure(): array<string, string> $pages the site's pages, slug => title, for the terms and returns links
     */
    public function __construct(private ShopSettings $settings, private \Closure $log, private \Closure $pages)
    {
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, tab: string, message: string}
     */
    public function act(array $post): array
    {
        $tab = isset(self::TABS[(string)($post['tab'] ?? '')]) ? (string)$post['tab'] : 'general';
        if ($tab === 'general') {
            $this->settings->saveGeneral($post);
            ($this->log)('shop.settings', 'info', 'shop', 'general', 'Shop settings saved.', []);
            return ['ok' => true, 'tab' => $tab, 'message' => 'Shop settings saved.'];
        }
        return ['ok' => false, 'tab' => $tab, 'message' => 'Nothing to save.'];
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
            'done' => (string)($get['done'] ?? ''),
            'message' => mb_substr((string)($get['msg'] ?? ''), 0, 200),
        ];
    }
}
