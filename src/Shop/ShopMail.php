<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

/**
 * The shop's emails (docs/shop-plan.md, section 8), in the customer's language: the order received (with the bank accounts for
 * a transfer) and a notice of it to the shop; the order paid (with its downloads), shipped (with its tracking), cancelled or
 * refunded; the account's links (confirm the address, set a new password); a subscription's renewal refused or given up.
 *
 * Each email is built once as a few parts (paragraphs, the order, a box, links, a button) and sent as plain text and, unless
 * Shop settings > Emails says otherwise, as HTML beside it: a simple layout in the shop's colour, with its logo and footer line.
 * The subject and the opening text of each kind can be written there in each language; blank, the theme's own words are used.
 * The sender is the site's, replies go to the shop's email.
 */
final class ShopMail
{
    /** Statuses the customer is told about. */
    public const TOLD = ['paid', 'shipped', 'cancelled', 'refunded'];
    /** The emails whose subject and text Shop settings > Emails can change, and the {placeholders} each may use. */
    public const KINDS = ['received', 'paid', 'shipped', 'cancelled', 'refunded', 'verify', 'reset', 'sub_failed', 'sub_cancelled'];
    public const PLACEHOLDERS = [
        'received' => ['store', 'number', 'name'], 'paid' => ['store', 'number', 'name'], 'shipped' => ['store', 'number', 'name'],
        'cancelled' => ['store', 'number', 'name'], 'refunded' => ['store', 'number', 'name'], 'verify' => ['store', 'name'],
        'reset' => ['store', 'name'], 'sub_failed' => ['store', 'product', 'amount', 'date', 'name'], 'sub_cancelled' => ['store', 'product', 'amount', 'name'],
    ];

    /**
     * @param \Closure(): array<string, mixed> $siteSettings
     * @param \Closure(string, string, array<string, string>): string $t a string in a language, with {placeholders} filled
     * @param \Closure(string, string, string, array<string, string>, ?string=): bool $send to, subject, text, headers, HTML
     * @param \Closure(string): ?\GnozioCMS\Shop\Payments\PaymentProvider $provider
     * @param (\Closure(int): list<array<string, mixed>>)|null $downloads an order's downloads
     */
    public function __construct(
        private ShopSettings $settings,
        private \Closure $siteSettings,
        private \Closure $t,
        private \Closure $send,
        private \Closure $provider,
        private ?\Closure $downloads = null,
        private string $siteUrl = ''
    ) {
    }

    /**
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $lines
     */
    public function orderPlaced(array $order, array $lines, string $viewUrl, string $adminUrl): bool
    {
        $message = $this->orderMessage('received', $order, $lines, $viewUrl);
        $sent = $this->deliver((string)$order['email'], $message);

        $shop = (string)$this->settings->all()['store_email'];
        if ($shop !== '') {
            $name = ShopAddress::name($order['billing']);
            $notice = [
                'subject' => 'New order ' . $order['number'] . ' · ' . ShopMoney::format((int)$order['total_cents'], 'en'),
                'lang' => 'en',
                'parts' => [
                    ['p', 'New order ' . $order['number'] . ' from ' . ($name !== '' ? $name . ' <' . $order['email'] . '>' : $order['email']) . '.'],
                    ['order', $order, $lines],
                    ['button', 'Open it in the admin', $adminUrl],
                ],
            ];
            $this->deliver($shop, $notice, (string)$order['email']);
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
        return $this->deliver((string)$order['email'], $this->orderMessage($status, $order, $lines, $viewUrl));
    }

    /** An account's link: 'verify' (confirm the address, also sent on signing up) or 'reset' (a new password). */
    public function accountLink(string $kind, string $email, string $name, string $lang, string $url): bool
    {
        $values = ['store' => $this->storeName(), 'name' => $name !== '' ? $name : $email];
        return $this->deliver($email, [
            'subject' => $this->say($kind, 'subject', $lang, $values),
            'lang' => $lang,
            'parts' => [
                ['hello', $values['name']],
                ['p', $this->say($kind, 'text', $lang, $values)],
                ['button', $this->t('shop.mail_' . $kind . '_button', $lang, []), $url],
                ['small', $this->t('shop.mail_' . $kind . '_note', $lang, [])],
            ],
        ]);
    }

    /**
     * A subscription's renewal could not be charged (failed: it is tried again) or it was given up (cancelled), with the account's
     * address where it can be paid or looked at.
     *
     * @param array<string, mixed> $sub
     */
    public function subscriptionNotice(string $kind, array $sub, string $email, string $name, string $accountUrl): bool
    {
        return $this->deliver($email, $this->subscriptionMessage($kind, $sub, $name !== '' ? $name : $email, $accountUrl));
    }

    /**
     * What an email of a kind looks like, with a made-up order, in a language: for the Emails tab's preview and its test email.
     *
     * @return array{subject: string, text: string, html: string}
     */
    public function preview(string $kind, string $lang): array
    {
        $order = [
            'id' => 0, 'number' => 'AL-2026-00042', 'created_at' => time(), 'email' => 'maria@example.com', 'lang' => $lang,
            'items_cents' => 18600, 'discount_cents' => 0, 'shipping_cents' => 400, 'fee_cents' => 0, 'tax_cents' => 3677, 'total_cents' => 19000,
            'coupon' => '', 'payment_method' => $kind === 'received' ? 'bank_transfer' : 'viva', 'shipping_label' => 'Courier', 'gift_note' => '', 'gift_wrap' => 0,
            'billing' => ['first_name' => 'Maria', 'last_name' => 'Patera'], 'shipping' => ['first_name' => 'Maria', 'last_name' => 'Patera', 'address' => 'Ermou 1', 'city' => 'Athens', 'postcode' => '10563', 'country' => 'GR'],
            'carrier' => 'ACS', 'tracking' => '1234567890',
        ];
        $lines = [['quantity' => 1, 'title' => 'Balos earrings', 'variant' => 'Gold', 'total_cents' => 18600]];
        $url = rtrim($this->siteUrl, '/') . '/checkout/order/AL-2026-00042';
        $message = match (true) {
            in_array($kind, ['verify', 'reset'], true) => [
                'subject' => $this->say($kind, 'subject', $lang, ['store' => $this->storeName(), 'name' => 'Maria']),
                'lang' => $lang,
                'parts' => [['hello', 'Maria'], ['p', $this->say($kind, 'text', $lang, ['store' => $this->storeName(), 'name' => 'Maria'])], ['button', $this->t('shop.mail_' . $kind . '_button', $lang, []), rtrim($this->siteUrl, '/') . '/account'], ['small', $this->t('shop.mail_' . $kind . '_note', $lang, [])]],
            ],
            in_array($kind, ['sub_failed', 'sub_cancelled'], true) => $this->subscriptionMessage(substr($kind, 4), ['lang' => $lang, 'title' => 'Reading club', 'variant' => '', 'price_cents' => 1000, 'quantity' => 1, 'next_at' => time() + 86400], 'Maria', rtrim($this->siteUrl, '/') . '/account'),
            default => $this->orderMessage(in_array($kind, self::KINDS, true) ? $kind : 'received', $order, $lines, $url, false),
        };
        return ['subject' => $message['subject'], 'text' => $this->text($message), 'html' => $this->html($message)];
    }

    /** Sends the preview of a kind to an address (the Emails tab's test). */
    public function sendTest(string $kind, string $lang, string $to): bool
    {
        $preview = $this->preview($kind, $lang);
        return $this->mail($to, '[Test] ' . $preview['subject'], $preview['text'], $this->settings->all()['emails']['html'] ? $preview['html'] : null);
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
        $text .= "\n";
        foreach ($this->totals($order, $lang) as [$label, $value]) {
            $text .= $label . ': ' . $value . "\n";
        }
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

    // ---- the messages

    /**
     * An order's email of a kind (received, paid, shipped, cancelled, refunded).
     *
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $lines
     * @return array{subject: string, lang: string, parts: list<array<int, mixed>>}
     */
    private function orderMessage(string $kind, array $order, array $lines, string $viewUrl, bool $real = true): array
    {
        $lang = (string)$order['lang'];
        $name = ShopAddress::name($order['billing']);
        $values = ['store' => $this->storeName(), 'number' => (string)$order['number'], 'name' => $name !== '' ? $name : (string)$order['email']];
        $parts = [['hello', $values['name']], ['p', $this->say($kind, 'text', $lang, $values)]];
        if ($kind === 'shipped' && (string)($order['tracking'] ?? '') !== '') {
            $parts[] = ['box', $this->t('shop.mail_tracking', $lang, ['carrier' => (string)$order['carrier'], 'tracking' => (string)$order['tracking']]), []];
        }
        if ($kind === 'received') {
            $accounts = $this->instructions($order);
            if ($accounts !== []) {
                $parts[] = ['box', $this->t('shop.bank_intro', $lang, ['number' => $order['number'], 'total' => ShopMoney::format((int)$order['total_cents'], $lang)]), $accounts];
            }
        }
        if (in_array($kind, ['received', 'paid'], true) && $real) {
            $files = $this->files($order);
            if ($files !== []) {
                $parts[] = ['links', $this->t('shop.mail_downloads', $lang, []), $files[0], $files[1]];
            }
        }
        $parts[] = ['order', $order, $lines];
        $parts[] = ['button', $this->t('shop.mail_view_button', $lang, []), $viewUrl];
        return ['subject' => $this->say($kind, 'subject', $lang, $values), 'lang' => $lang, 'parts' => $parts];
    }

    /**
     * @param array<string, mixed> $sub
     * @return array{subject: string, lang: string, parts: list<array<int, mixed>>}
     */
    private function subscriptionMessage(string $kind, array $sub, string $name, string $accountUrl): array
    {
        $lang = (string)$sub['lang'];
        $what = $sub['title'] . ($sub['variant'] !== '' ? ' (' . $sub['variant'] . ')' : '');
        $values = ['store' => $this->storeName(), 'name' => $name, 'product' => $what, 'amount' => ShopMoney::format($sub['price_cents'] * $sub['quantity'], $lang), 'date' => $sub['next_at'] > 0 ? date('d/m/Y', $sub['next_at']) : ''];
        return [
            'subject' => $this->say('sub_' . $kind, 'subject', $lang, $values),
            'lang' => $lang,
            'parts' => [['hello', $name], ['p', $this->say('sub_' . $kind, 'text', $lang, $values)], ['button', $this->t('shop.mail_account_button', $lang, []), $accountUrl]],
        ];
    }

    // ---- text and HTML

    /** @param array{subject: string, lang: string, parts: list<array<int, mixed>>} $message */
    private function text(array $message): string
    {
        $lang = $message['lang'];
        $out = [];
        foreach ($message['parts'] as $part) {
            $out[] = match ($part[0]) {
                'hello' => $this->t('shop.mail_hello', $lang, ['name' => (string)$part[1]]),
                'p', 'small' => (string)$part[1],
                'box' => trim($part[1] . "\n" . implode("\n", $part[2])),
                'links' => $part[1] . "\n" . implode("\n", array_map(static fn(array $l): string => '- ' . $l[0] . ($l[2] !== '' ? ' (' . $l[2] . ')' : '') . "\n  " . $l[1], $part[2])) . ($part[3] !== '' ? "\n" . $part[3] : ''),
                'order' => rtrim($this->summary($part[1], $part[2], $lang)),
                'button' => $part[1] . "\n" . $part[2],
                default => '',
            };
        }
        return implode("\n\n", array_filter($out, static fn(string $s): bool => $s !== '')) . "\n" . $this->signature();
    }

    /** @param array{subject: string, lang: string, parts: list<array<int, mixed>>} $message */
    private function html(array $message): string
    {
        $lang = $message['lang'];
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $emails = $this->settings->all()['emails'];
        $accent = $emails['accent'];
        $font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
        $logo = $emails['logo'] !== '' ? (str_starts_with($emails['logo'], '/') ? rtrim($this->siteUrl, '/') . $emails['logo'] : $emails['logo']) : '';
        $head = $logo !== '' ? '<img src="' . $e($logo) . '" alt="' . $e($this->storeName()) . '" style="max-height:48px;max-width:220px;border:0">' : '<span style="font-size:20px;font-weight:600;color:' . $accent . '">' . $e($this->storeName()) . '</span>';
        $body = '';
        foreach ($message['parts'] as $part) {
            $body .= match ($part[0]) {
                'hello' => '<p style="margin:0 0 16px;font-size:16px">' . $e($this->t('shop.mail_hello', $lang, ['name' => (string)$part[1]])) . '</p>',
                'p' => '<p style="margin:0 0 16px;font-size:15px;line-height:1.55">' . nl2br($e((string)$part[1])) . '</p>',
                'small' => '<p style="margin:0 0 16px;font-size:13px;line-height:1.5;color:#6b7280">' . nl2br($e((string)$part[1])) . '</p>',
                'box' => '<div style="margin:0 0 20px;padding:14px 16px;background:#f3f4f6;border-radius:8px;font-size:14px;line-height:1.55"><p style="margin:0' . ($part[2] !== [] ? ' 0 8px' : '') . '">' . $e((string)$part[1]) . '</p>' . implode('', array_map(static fn(string $l): string => '<div style="font-family:Menlo,Consolas,monospace;font-size:13px">' . $e($l) . '</div>', $part[2])) . '</div>',
                'links' => '<div style="margin:0 0 20px"><p style="margin:0 0 8px;font-size:15px;font-weight:600">' . $e((string)$part[1]) . '</p>' . implode('', array_map(static fn(array $l): string => '<p style="margin:0 0 6px;font-size:14px"><a href="' . $e($l[1]) . '" style="color:' . $accent . ';font-weight:600">' . $e($l[0]) . '</a>' . ($l[2] !== '' ? ' <span style="color:#6b7280">' . $e($l[2]) . '</span>' : '') . '</p>', $part[2])) . ($part[3] !== '' ? '<p style="margin:6px 0 0;font-size:13px;color:#6b7280">' . $e($part[3]) . '</p>' : '') . '</div>',
                'order' => $this->orderTable($part[1], $part[2], $lang, $e),
                'button' => '<p style="margin:8px 0 24px"><a href="' . $e((string)$part[2]) . '" style="display:inline-block;padding:11px 20px;background:' . $accent . ';color:#ffffff;text-decoration:none;border-radius:6px;font-size:15px;font-weight:600">' . $e((string)$part[1]) . '</a></p>',
                default => '',
            };
        }
        $shop = $this->settings->all();
        $foot = array_filter([$this->storeName(), (string)$shop['store_phone'], (string)$shop['store_email']]);
        $footer = '<p style="margin:0">' . implode(' · ', array_map($e, $foot)) . '</p>' . ($emails['footer'] !== '' ? '<p style="margin:8px 0 0">' . nl2br($e($emails['footer'])) . '</p>' : '');
        return '<!doctype html><html lang="' . $e($lang) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($message['subject']) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f5f5f4;' . $font . 'color:#111827">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f4"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:10px;border-top:4px solid ' . $accent . '">'
            . '<tr><td style="padding:24px 28px 8px">' . $head . '</td></tr>'
            . '<tr><td style="padding:16px 28px 8px">' . $body . '</td></tr>'
            . '<tr><td style="padding:16px 28px 24px;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.5;color:#6b7280">' . $footer . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /**
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $lines
     */
    private function orderTable(array $order, array $lines, string $lang, \Closure $e): string
    {
        $money = static fn(int $cents): string => ShopMoney::format($cents, $lang);
        $cell = 'padding:6px 0;font-size:14px;border-bottom:1px solid #f3f4f6';
        $rows = '';
        foreach ($lines as $line) {
            $rows .= '<tr><td style="' . $cell . '">' . (int)$line['quantity'] . ' × ' . $e((string)$line['title']) . ((string)$line['variant'] !== '' ? ' <span style="color:#6b7280">' . $e((string)$line['variant']) . '</span>' : '') . '</td><td align="right" style="' . $cell . ';white-space:nowrap">' . $e($money((int)$line['total_cents'])) . '</td></tr>';
        }
        $totals = $this->totals($order, $lang);
        $last = array_key_last($totals);
        foreach ($totals as $i => [$label, $value]) {
            $strong = $i === $last ? 'font-weight:700;font-size:15px;' : 'color:#4b5563;';
            $rows .= '<tr><td style="padding:4px 0;font-size:14px;' . $strong . '">' . $e($label) . '</td><td align="right" style="padding:4px 0;font-size:14px;white-space:nowrap;' . $strong . '">' . $e($value) . '</td></tr>';
        }
        $ship = ShopAddress::lines($order['shipping'], $lang);
        $after = '<p style="margin:12px 0 0;font-size:13px;color:#4b5563">' . $e($this->t('shop.payment', $lang, [])) . ': ' . $e($this->t('shop.pay_' . $order['payment_method'], $lang, [])) . '</p>';
        if ($ship !== []) {
            $after .= '<p style="margin:8px 0 0;font-size:13px;color:#4b5563"><strong>' . $e($this->t('shop.ship_to', $lang, [])) . '</strong><br>' . implode('<br>', array_map($e, $ship)) . '</p>';
        }
        if ((string)$order['gift_note'] !== '' || (int)$order['gift_wrap'] === 1) {
            $after .= '<p style="margin:8px 0 0;font-size:13px;color:#4b5563"><strong>' . $e($this->t('shop.gift', $lang, [])) . '</strong>' . ((int)$order['gift_wrap'] === 1 ? ' · ' . $e($this->t('shop.gift_wrap', $lang, [])) : '') . ((string)$order['gift_note'] !== '' ? '<br>' . nl2br($e((string)$order['gift_note'])) : '') . '</p>';
        }
        return '<div style="margin:0 0 20px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:8px">'
            . '<p style="margin:0 0 8px;font-size:13px;color:#6b7280">' . $e($this->t('shop.order', $lang, []) . ' ' . $order['number'] . ' · ' . date('d/m/Y', (int)$order['created_at'])) . '</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>' . $after . '</div>';
    }

    /** @return list<array{0: string, 1: string}> the totals' rows: subtotal, discount, shipping, fee, total with its VAT */
    private function totals(array $order, string $lang): array
    {
        $money = static fn(int $cents): string => ShopMoney::format($cents, $lang);
        $rows = [[$this->t('shop.subtotal', $lang, []), $money((int)$order['items_cents'])]];
        if ((int)$order['discount_cents'] > 0) {
            $rows[] = [$this->t('shop.discount', $lang, []) . ($order['coupon'] !== '' ? ' (' . $order['coupon'] . ')' : ''), $money(-(int)$order['discount_cents'])];
        }
        $rows[] = [$this->t('shop.shipping', $lang, []) . ($order['shipping_label'] !== '' ? ' (' . $order['shipping_label'] . ')' : ''), $money((int)$order['shipping_cents'])];
        if ((int)$order['fee_cents'] > 0) {
            $rows[] = [$this->t('shop.fee', $lang, []), $money((int)$order['fee_cents'])];
        }
        $rows[] = [$this->t('shop.total', $lang, []), $money((int)$order['total_cents']) . ' (' . $this->t('shop.vat_included', $lang, ['vat' => $money((int)$order['tax_cents'])]) . ')'];
        return $rows;
    }

    // ---- inside

    /**
     * An email's subject or text: what Shop settings > Emails says for this kind and language, or the theme's own words.
     *
     * @param array<string, string> $values
     */
    private function say(string $kind, string $field, string $lang, array $values): string
    {
        $own = (string)($this->settings->all()['emails']['texts'][$kind][$lang][$field] ?? '');
        if ($own === '') {
            return $this->t('shop.mail_' . $kind . '_' . $field, $lang, $values);
        }
        foreach ($values as $name => $value) {
            $own = str_replace('{' . $name . '}', (string)$value, $own);
        }
        return $own;
    }

    /** @param array<string, mixed> $order @return list<string> the shop's bank accounts, for a transfer */
    private function instructions(array $order): array
    {
        $provider = ($this->provider)((string)$order['payment_method']);
        $settings = $this->settings->all()['payments'][(string)$order['payment_method']] ?? [];
        return $provider !== null ? $provider->instructions(is_array($settings) ? $settings : []) : [];
    }

    /**
     * The order's downloads that can be had: each as [name, link, product], and a line of the limits.
     *
     * @param array<string, mixed> $order
     * @return array{0: list<array{0: string, 1: string, 2: string}>, 1: string}|array{}
     */
    private function files(array $order): array
    {
        $files = $this->downloads !== null ? array_values(array_filter(($this->downloads)((int)$order['id']), static fn(array $d): bool => $d['usable'])) : [];
        if ($files === []) {
            return [];
        }
        $lang = (string)$order['lang'];
        $first = $files[0];
        $limits = implode(' ', array_filter([
            $first['max_downloads'] > 0 ? $this->t('shop.mail_downloads_times', $lang, ['n' => (string)$first['max_downloads']]) : '',
            $first['expires_at'] > 0 ? $this->t('shop.mail_downloads_until', $lang, ['date' => date('d/m/Y', $first['expires_at'])]) : '',
        ]));
        return [array_map(static fn(array $f): array => [(string)$f['name'], (string)$f['url'], (string)$f['title']], $files), $limits];
    }

    /** @param array{subject: string, lang: string, parts: list<array<int, mixed>>} $message */
    private function deliver(string $to, array $message, string $replyTo = ''): bool
    {
        return $this->mail($to, $message['subject'], $this->text($message), $this->settings->all()['emails']['html'] ? $this->html($message) : null, $replyTo);
    }

    private function mail(string $to, string $subject, string $text, ?string $html, string $replyTo = ''): bool
    {
        $notifications = (($this->siteSettings)()['forms']['notifications'] ?? []);
        $from = trim((string)($notifications['from'] ?? '')) ?: 'noreply@localhost';
        $fromName = $this->storeName();
        $headers = ['From' => ($fromName !== '' ? $fromName . ' <' . $from . '>' : $from)];
        $reply = $replyTo !== '' ? $replyTo : (string)$this->settings->all()['store_email'];
        if ($reply !== '') {
            $headers['Reply-To'] = $reply;
        }
        return ($this->send)($to, $subject, $text, $headers, $html);
    }

    private function storeName(): string
    {
        $name = (string)$this->settings->all()['store_name'];
        return $name !== '' ? $name : (string)(($this->siteSettings)()['title'] ?? '');
    }

    private function signature(): string
    {
        $shop = $this->settings->all();
        $lines = array_filter([$this->storeName(), $shop['store_phone'], $shop['store_email'], $shop['emails']['footer']]);
        return "\n— \n" . implode("\n", $lines) . "\n";
    }

    /** @param array<string, string|int> $values */
    private function t(string $key, string $lang, array $values): string
    {
        return ($this->t)($key, $lang, array_map('strval', $values));
    }
}
