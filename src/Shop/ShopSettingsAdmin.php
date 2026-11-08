<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

/**
 * System > Shop settings (docs/shop-plan.md, section 8): the screen and its forms. General (the store, VAT, orders), Shipping
 * (zones and their methods), Payments (Viva Wallet, Cardlink, bank transfer, cash on delivery) and Accounts.
 */
final class ShopSettingsAdmin
{
    public const TABS = ['general' => 'General', 'shipping' => 'Shipping', 'payments' => 'Payments', 'accounts' => 'Accounts'];

    /**
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log
     * @param \Closure(): array<string, string> $pages the site's pages, slug => title, for the terms and returns links
     * @param string[] $languages the site's languages, the default first: a shipping method's name is written in each
     */
    public function __construct(
        private ShopSettings $settings,
        private \Closure $log,
        private \Closure $pages,
        private array $languages = ['en'],
        private ?ShopSecrets $secrets = null,
        private ?Payments\VivaWallet $viva = null,
        private string $siteUrl = '',
        private ?Payments\Cardlink $cardlink = null
    )
    {
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, tab: string, message: string}
     */
    public function act(array $post): array
    {
        $tab = isset(self::TABS[(string)($post['tab'] ?? '')]) ? (string)$post['tab'] : 'general';
        if ($tab === 'payments' && ($post['viva_action'] ?? '') === 'check' && $this->viva !== null) {
            // Check connection checks what is stored, after saving what the form holds.
            $this->settings->savePayments($post);
            $this->secrets?->save(Payments\VivaWallet::ID, is_array($post['viva_secret'] ?? null) ? $post['viva_secret'] : []);
            $result = $this->viva->check($this->settings->all()['payments']['viva']);
            ($this->log)('shop.viva_check', $result['ok'] ? 'info' : 'warning', 'shop', 'viva', $result['message'], []);
            return ['ok' => $result['ok'], 'tab' => $tab, 'message' => $result['message']];
        }
        if ($tab === 'payments' && ($post['cardlink_action'] ?? '') === 'check' && $this->cardlink !== null) {
            $this->settings->savePayments($post);
            $this->secrets?->save(Payments\Cardlink::ID, is_array($post['cardlink_secret'] ?? null) ? $post['cardlink_secret'] : []);
            $result = $this->cardlink->check($this->settings->all()['payments']['cardlink']);
            ($this->log)('shop.cardlink_check', $result['ok'] ? 'info' : 'warning', 'shop', 'cardlink', $result['message'], []);
            return ['ok' => $result['ok'], 'tab' => $tab, 'message' => $result['message']];
        }
        if ($tab === 'payments') {
            $this->secrets?->save(Payments\VivaWallet::ID, is_array($post['viva_secret'] ?? null) ? $post['viva_secret'] : [], !empty($post['viva_clear']));
            $this->secrets?->save(Payments\Cardlink::ID, is_array($post['cardlink_secret'] ?? null) ? $post['cardlink_secret'] : [], !empty($post['cardlink_clear']));
        }
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
            'viva_secrets' => $this->secrets?->view(Payments\VivaWallet::ID) ?? [],
            'viva_installments' => Payments\VivaWallet::installmentsText($this->settings->all()['payments']['viva']['installments']),
            'viva_urls' => [
                'success' => rtrim($this->siteUrl, '/') . '/_shop/pay/viva/success',
                'failure' => rtrim($this->siteUrl, '/') . '/_shop/pay/viva/failure',
                'webhook' => rtrim($this->siteUrl, '/') . '/_shop/pay/viva/webhook',
            ],
            'cardlink_secrets' => $this->secrets?->view(Payments\Cardlink::ID) ?? [],
            'cardlink_installments' => Payments\VivaWallet::installmentsText($this->settings->all()['payments']['cardlink']['installments']),
            'cardlink_acquirers' => array_map(static fn(array $a): string => $a['label'], Payments\Cardlink::ACQUIRERS),
            'cardlink_urls' => [
                'confirm' => rtrim($this->siteUrl, '/') . '/_shop/pay/cardlink/confirm',
                'cancel' => rtrim($this->siteUrl, '/') . '/_shop/pay/cardlink/cancel',
            ],
            'ship_kinds' => ShopShipping::KINDS,
            'table_text' => array_map(static fn(array $zone): array => array_map(static fn(array $m): string => ShopShipping::tableText($m['table']), $zone['methods']), $this->settings->all()['shipping']),
            'done' => (string)($get['done'] ?? ''),
            'message' => mb_substr((string)($get['msg'] ?? ''), 0, 200),
        ];
    }
}
