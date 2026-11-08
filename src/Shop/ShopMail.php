<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

/**
 * The shop's emails (docs/shop-plan.md, section 8), in the customer's language: the order received (with the bank accounts for
 * a transfer) and a notice of it to the shop; the order paid, shipped (with its tracking), cancelled or refunded; and the
 * account's links (confirm the address, set a new password). Plain text, through the site's email settings; the sender is the
 * site's, replies go to the shop's email.
 */
final class ShopMail
{
    /** Statuses the customer is told about. */
    public const TOLD = ['paid', 'shipped', 'cancelled', 'refunded'];

    /**
     * @param \Closure(): array<string, mixed> $siteSettings
     * @param \Closure(string, string, array<string, string>): string $t a string in a language, with {placeholders} filled
     * @param \Closure(string, string, string, array<string, string>): bool $send to, subject, body, headers
     * @param \Closure(string): ?\GnozioCMS\Shop\Payments\PaymentProvider $provider
     */
    public function __construct(
        private ShopSettings $settings,
        private \Closure $siteSettings,
        private \Closure $t,
        private \Closure $send,
        private \Closure $provider,
        private ?\Closure $downloads = null
    ) {
    }

    /**
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $lines
     */
    public function orderPlaced(array $order, array $lines, string $viewUrl, string $adminUrl): bool
    {
        $lang = (string)$order['lang'];
        $name = ShopAddress::name($order['billing']);
        $body = $this->t('shop.mail_hello', $lang, ['name' => $name !== '' ? $name : $order['email']]) . "\n\n"
            . $this->t('shop.mail_received_text', $lang, ['number' => $order['number']]) . "\n\n"
            . $this->summary($order, $lines, $lang)
            . $this->next($order, $lang)
            . $this->files($order, $lang)
            . "\n" . $this->t('shop.mail_view', $lang, []) . "\n" . $viewUrl . "\n" . $this->signature();
        $sent = $this->mail((string)$order['email'], $this->t('shop.mail_received_subject', $lang, ['number' => $order['number'], 'store' => $this->storeName()]), $body);

        $shop = (string)$this->settings->all()['store_email'];
        if ($shop !== '') {
            $notice = 'New order ' . $order['number'] . ' from ' . ($name !== '' ? $name . ' <' . $order['email'] . '>' : $order['email']) . ".\n\n"
                . $this->summary($order, $lines, 'en') . "\n" . $adminUrl . "\n";
            $this->mail($shop, 'New order ' . $order['number'] . ' · ' . ShopMoney::format((int)$order['total_cents'], 'en'), $notice, (string)$order['email']);
        }
        return $sent;
    }

    /**
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $lines
     */
    public function statusChanged(array $order, array $lines, string $status, string $viewUrl): bool
    {
        if (!in_array($status, self::TOLD, true)) {
            return false;
        }
        $lang = (string)$order['lang'];
        $name = ShopAddress::name($order['billing']);
        $body = $this->t('shop.mail_hello', $lang, ['name' => $name !== '' ? $name : $order['email']]) . "\n\n"
            . $this->t('shop.mail_' . $status . '_text', $lang, ['number' => $order['number']]) . "\n";
        if ($status === 'shipped' && (string)$order['tracking'] !== '') {
            $body .= "\n" . $this->t('shop.mail_tracking', $lang, ['carrier' => (string)$order['carrier'], 'tracking' => (string)$order['tracking']]) . "\n";
        }
        $body .= ($status === 'paid' || $status === 'completed' ? $this->files($order, $lang) : '');
        $body .= "\n" . $this->summary($order, $lines, $lang) . "\n" . $this->t('shop.mail_view', $lang, []) . "\n" . $viewUrl . "\n" . $this->signature();
        return $this->mail((string)$order['email'], $this->t('shop.mail_' . $status . '_subject', $lang, ['number' => $order['number'], 'store' => $this->storeName()]), $body);
    }

    /** An account's link: 'verify' (confirm the address, also sent on signing up) or 'reset' (a new password). */
    public function accountLink(string $kind, string $email, string $name, string $lang, string $url): bool
    {
        $body = $this->t('shop.mail_hello', $lang, ['name' => $name !== '' ? $name : $email]) . "\n\n"
            . $this->t('shop.mail_' . $kind . '_text', $lang, ['store' => $this->storeName()]) . "\n\n" . $url . "\n\n"
            . $this->t('shop.mail_' . $kind . '_note', $lang, []) . "\n" . $this->signature();
        return $this->mail($email, $this->t('shop.mail_' . $kind . '_subject', $lang, ['store' => $this->storeName()]), $body);
    }

    /**
     * The order as text: its lines, the totals and where it goes.
     *
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $lines
     */
    public function summary(array $order, array $lines, string $lang): string
    {
        $money = static fn(int $cents): string => ShopMoney::format($cents, $lang);
        $text = $this->t('shop.order', $lang, []) . ' ' . $order['number'] . ' · ' . date('d/m/Y', (int)$order['created_at']) . "\n\n";
        foreach ($lines as $line) {
            $text .= $line['quantity'] . ' × ' . $line['title'] . ($line['variant'] !== '' ? ' (' . $line['variant'] . ')' : '') . ' — ' . $money((int)$line['total_cents']) . "\n";
        }
        $text .= "\n" . $this->t('shop.subtotal', $lang, []) . ': ' . $money((int)$order['items_cents']) . "\n";
        if ((int)$order['discount_cents'] > 0) {
            $text .= $this->t('shop.discount', $lang, []) . ($order['coupon'] !== '' ? ' (' . $order['coupon'] . ')' : '') . ': ' . $money(-(int)$order['discount_cents']) . "\n";
        }
        $text .= $this->t('shop.shipping', $lang, []) . ($order['shipping_label'] !== '' ? ' (' . $order['shipping_label'] . ')' : '') . ': ' . $money((int)$order['shipping_cents']) . "\n";
        if ((int)$order['fee_cents'] > 0) {
            $text .= $this->t('shop.fee', $lang, []) . ': ' . $money((int)$order['fee_cents']) . "\n";
        }
        $text .= $this->t('shop.total', $lang, []) . ': ' . $money((int)$order['total_cents']) . ' (' . $this->t('shop.vat_included', $lang, ['vat' => $money((int)$order['tax_cents'])]) . ")\n";
        $text .= $this->t('shop.payment', $lang, []) . ': ' . $this->t('shop.pay_' . $order['payment_method'], $lang, []) . "\n";
        $ship = ShopAddress::lines($order['shipping'], $lang);
        if ($ship !== []) {
            $text .= "\n" . $this->t('shop.ship_to', $lang, []) . ":\n" . implode("\n", $ship) . "\n";
        }
        if ((string)$order['gift_note'] !== '' || (int)$order['gift_wrap'] === 1) {
            $text .= "\n" . $this->t('shop.gift', $lang, []) . ((int)$order['gift_wrap'] === 1 ? ' · ' . $this->t('shop.gift_wrap', $lang, []) : '') . ((string)$order['gift_note'] !== '' ? "\n" . $order['gift_note'] : '') . "\n";
        }
        return $text;
    }

    /**
     * What the customer does next: for a transfer, the accounts and the reference.
     *
     * @param array<string, mixed> $order
     */
    private function next(array $order, string $lang): string
    {
        $provider = ($this->provider)((string)$order['payment_method']);
        $settings = $this->settings->all()['payments'][(string)$order['payment_method']] ?? [];
        $lines = $provider !== null ? $provider->instructions(is_array($settings) ? $settings : []) : [];
        if ($lines === []) {
            return '';
        }
        return "\n" . $this->t('shop.bank_intro', $lang, ['number' => $order['number'], 'total' => ShopMoney::format((int)$order['total_cents'], $lang)]) . "\n" . implode("\n", $lines) . "\n";
    }

    /** The order's downloads, when it has any that can be had: each file with its link. */
    private function files(array $order, string $lang): string
    {
        $files = $this->downloads !== null ? array_filter(($this->downloads)((int)$order['id']), static fn(array $d): bool => $d['usable']) : [];
        if ($files === []) {
            return '';
        }
        $text = "\n" . $this->t('shop.mail_downloads', $lang, []) . "\n";
        foreach ($files as $file) {
            $text .= '- ' . $file['name'] . ' (' . $file['title'] . ")\n  " . $file['url'] . "\n";
        }
        $first = reset($files);
        $limits = array_filter([
            $first['max_downloads'] > 0 ? $this->t('shop.mail_downloads_times', $lang, ['n' => (string)$first['max_downloads']]) : '',
            $first['expires_at'] > 0 ? $this->t('shop.mail_downloads_until', $lang, ['date' => date('d/m/Y', $first['expires_at'])]) : '',
        ]);
        return $text . ($limits !== [] ? implode(' ', $limits) . "\n" : '');
    }

    private function mail(string $to, string $subject, string $body, string $replyTo = ''): bool
    {
        $notifications = (($this->siteSettings)()['forms']['notifications'] ?? []);
        $from = trim((string)($notifications['from'] ?? '')) ?: 'noreply@localhost';
        $fromName = $this->storeName();
        $headers = ['From' => ($fromName !== '' ? $fromName . ' <' . $from . '>' : $from)];
        $reply = $replyTo !== '' ? $replyTo : (string)$this->settings->all()['store_email'];
        if ($reply !== '') {
            $headers['Reply-To'] = $reply;
        }
        return ($this->send)($to, $subject, $body, $headers);
    }

    private function storeName(): string
    {
        $name = (string)$this->settings->all()['store_name'];
        return $name !== '' ? $name : (string)(($this->siteSettings)()['title'] ?? '');
    }

    private function signature(): string
    {
        $shop = $this->settings->all();
        $lines = array_filter([$this->storeName(), $shop['store_phone'], $shop['store_email']]);
        return "\n— \n" . implode("\n", $lines) . "\n";
    }

    /** @param array<string, string|int> $values */
    private function t(string $key, string $lang, array $values): string
    {
        return ($this->t)($key, $lang, array_map('strval', $values));
    }
}
