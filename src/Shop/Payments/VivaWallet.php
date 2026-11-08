<?php

declare(strict_types=1);

namespace GnozioCMS\Shop\Payments;

use GnozioCMS\Shop\ShopAddress;
use GnozioCMS\Shop\ShopHttp;
use GnozioCMS\Shop\ShopMoney;
use GnozioCMS\Shop\ShopPayments;
use GnozioCMS\Shop\ShopSecrets;
use GnozioCMS\SystemMetaRepository;

/**
 * Viva Wallet Smart Checkout (docs/shop-plan.md, section 5). Placing an order creates a payment order at Viva (POST
 * /checkout/v2/orders with an OAuth token) and sends the customer to Viva's page, where cards, Apple Pay, Google Pay, IRIS and
 * installments are offered. Viva sends the customer back to the success or failure address of the payment source, and calls the
 * webhook; either way the transaction is asked of Viva's API (GET /checkout/v2/transactions/{id}) before anything is believed.
 * Refunds use the Merchant ID and the API key (DELETE /api/transactions/{id}?amount=).
 *
 * Demo mode uses Viva's demo hosts. The hosts may be replaced by a fake on this machine for tests (the `base` secret, which no
 * screen can set).
 */
final class VivaWallet implements PaymentProvider, RecurringPayments
{
    public const ID = 'viva';
    public const EVENT_PAID = 1796;
    public const EVENT_FAILED = 1798;
    /** Status of a transaction that is paid (F, finished) or captured (C, a pre-authorisation taken). */
    public const PAID_STATUSES = ['F', 'C'];
    public const FAILED_STATUSES = ['E', 'X'];
    private const TOKEN_META = 'shop_viva_token';
    private const KEY_META = 'shop_viva_webhook_key';

    /** @param (\Closure(string, string, string[], ?string, int): array{status: int, body: string, error: string})|null $transport */
    public function __construct(
        private ShopSecrets $secrets,
        private ShopPayments $payments,
        private SystemMetaRepository $meta,
        private ?\Closure $transport = null
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function offered(array $settings, string $zoneId): bool
    {
        return !empty($settings['enabled']) && $this->configured($settings);
    }

    public function fee(array $settings): int
    {
        return 0;
    }

    public function instructions(array $settings): array
    {
        return [];
    }

    /** Whether everything Smart Checkout needs is set: the merchant, the client and both secrets. */
    public function configured(array $settings): bool
    {
        $secrets = $this->secrets->of(self::ID);
        return (string)($settings['merchant_id'] ?? '') !== '' && (string)($settings['client_id'] ?? '') !== ''
            && ($secrets['client_secret'] ?? '') !== '' && ($secrets['api_key'] ?? '') !== '';
    }

    public function start(array $order, array $settings): array
    {
        $token = $this->token($settings);
        if ($token['error'] !== '') {
            return ['status' => 'pending_payment', 'redirect' => '', 'error' => $token['error']];
        }
        $billing = is_array($order['billing'] ?? null) ? $order['billing'] : [];
        $total = (int)$order['total_cents'];
        $payload = [
            'amount' => $total,
            'customerTrns' => mb_substr((string)($settings['description'] ?? '') !== '' ? $settings['description'] . ' ' . $order['number'] : (string)$order['number'], 0, 255),
            'customer' => array_filter([
                'email' => (string)$order['email'],
                'fullName' => ShopAddress::name($billing),
                'phone' => preg_replace('/[^0-9+]/', '', (string)($billing['phone'] ?? '')),
                'countryCode' => (string)($billing['country'] ?? ''),
                'requestLang' => (string)$order['lang'] === 'el' ? 'el-GR' : 'en-GB',
            ], static fn(string $v): bool => $v !== ''),
            'paymentTimeout' => max(300, min(65535, (int)($order['reserve_until'] ?? 0) - time())),
            'preauth' => false,
            // A subscription's payment lets the shop charge the same card again for its renewals.
            'allowRecurring' => !empty($order['recurring']),
            'maxInstallments' => self::maxInstallments(is_array($settings['installments'] ?? null) ? $settings['installments'] : [], $total),
            'paymentNotification' => false,
            'disableCash' => true,
            'merchantTrns' => (string)$order['number'],
            'tags' => ['gnozio', 'order-' . $order['number']],
        ];
        if ((string)($settings['source_code'] ?? '') !== '') {
            $payload['sourceCode'] = (string)$settings['source_code'];
        }
        $answer = $this->http()->send('POST', $this->host('api', $settings) . '/checkout/v2/orders', ['Authorization: Bearer ' . $token['token']], $payload);
        $code = (string)($answer['data']['orderCode'] ?? '');
        if (!$answer['ok'] || preg_match('/^\d{10,20}$/', $code) !== 1) {
            return ['status' => 'pending_payment', 'redirect' => '', 'error' => $answer['error'] !== '' ? $answer['error'] : 'Viva did not give a payment order.'];
        }
        $this->payments->start((int)$order['id'], self::ID, $code, $total);
        return ['status' => 'pending_payment', 'redirect' => $this->host('www', $settings) . '/web/checkout?ref=' . $code, 'error' => ''];
    }

    /**
     * A transaction as Viva's API tells it, never as a browser or a notification says.
     *
     * @return array{ok: bool, error: string, transaction_id: string, reference: string, paid: bool, failed: bool, amount_cents: int, currency: string, merchant_trns: string, status: string, installments: int}
     */
    public function transaction(array $settings, string $transactionId): array
    {
        $none = ['ok' => false, 'error' => '', 'transaction_id' => $transactionId, 'reference' => '', 'paid' => false, 'failed' => false, 'amount_cents' => 0, 'currency' => '', 'merchant_trns' => '', 'status' => '', 'installments' => 0];
        if (preg_match('/^[0-9a-fA-F-]{32,40}$/', $transactionId) !== 1) {
            return ['error' => 'Not a Viva transaction.'] + $none;
        }
        $token = $this->token($settings);
        if ($token['error'] !== '') {
            return ['error' => $token['error']] + $none;
        }
        $answer = $this->http()->send('GET', $this->host('api', $settings) . '/checkout/v2/transactions/' . rawurlencode($transactionId), ['Authorization: Bearer ' . $token['token']]);
        if (!$answer['ok'] || !is_array($answer['data'])) {
            return ['error' => $answer['error'] !== '' ? $answer['error'] : 'Viva did not describe the transaction.'] + $none;
        }
        $data = $answer['data'];
        $status = (string)($data['statusId'] ?? '');
        return [
            'ok' => true,
            'error' => '',
            'transaction_id' => $transactionId,
            'reference' => (string)($data['orderCode'] ?? ''),
            'paid' => in_array($status, self::PAID_STATUSES, true),
            'failed' => in_array($status, self::FAILED_STATUSES, true),
            'amount_cents' => ShopMoney::cents($data['amount'] ?? null) ?? -1,
            'currency' => (string)($data['currencyCode'] ?? ''),
            'merchant_trns' => (string)($data['merchantTrns'] ?? ''),
            'status' => $status,
            'installments' => (int)($data['totalInstallments'] ?? 0),
        ];
    }

    /**
     * Gives money back for a transaction: all of it or a part. Viva answers with the refund's own transaction.
     *
     * @return array{ok: bool, error: string, transaction_id: string}
     */
    public function refund(array $settings, string $transactionId, int $cents): array
    {
        $secrets = $this->secrets->of(self::ID);
        $query = ['amount' => $cents];
        if ((string)($settings['source_code'] ?? '') !== '') {
            $query['sourceCode'] = (string)$settings['source_code'];
        }
        $answer = $this->http()->send('DELETE', $this->host('www', $settings) . '/api/transactions/' . rawurlencode($transactionId) . '?' . http_build_query($query), [ShopHttp::basic((string)($settings['merchant_id'] ?? ''), $secrets['api_key'] ?? '')]);
        $data = is_array($answer['data']) ? $answer['data'] : [];
        $ok = $answer['ok'] && (($data['ErrorCode'] ?? 0) === 0 || ($data['ErrorCode'] ?? null) === null) && ($data['Success'] ?? true) !== false;
        return ['ok' => $ok, 'error' => $ok ? '' : ($answer['error'] !== '' ? $answer['error'] : (string)($data['ErrorText'] ?? 'Viva did not make the refund.')), 'transaction_id' => (string)($data['TransactionId'] ?? '')];
    }

    /**
     * Charges again the card of a payment made with allowRecurring (a subscription's renewal): POST /api/transactions/{id} with the
     * Merchant ID and API key. Viva answers with the new transaction.
     */
    public function charge(array $settings, string $token, int $cents, array $order): array
    {
        if (preg_match('/^[0-9a-fA-F-]{32,40}$/', $token) !== 1) {
            return ['ok' => false, 'error' => 'There is no card to charge again.', 'transaction_id' => ''];
        }
        $secrets = $this->secrets->of(self::ID);
        $payload = ['amount' => $cents, 'installments' => 1, 'customerTrns' => mb_substr((string)($settings['description'] ?? '') !== '' ? $settings['description'] . ' ' . $order['number'] : (string)$order['number'], 0, 255), 'merchantTrns' => (string)$order['number']];
        if ((string)($settings['source_code'] ?? '') !== '') {
            $payload['sourceCode'] = (string)$settings['source_code'];
        }
        $answer = $this->http()->send('POST', $this->host('www', $settings) . '/api/transactions/' . rawurlencode($token), [ShopHttp::basic((string)($settings['merchant_id'] ?? ''), $secrets['api_key'] ?? '')], $payload);
        $data = is_array($answer['data']) ? $answer['data'] : [];
        $tx = (string)($data['TransactionId'] ?? '');
        $ok = $answer['ok'] && (int)($data['ErrorCode'] ?? 0) === 0 && ($data['Success'] ?? true) !== false && in_array((string)($data['StatusId'] ?? 'F'), self::PAID_STATUSES, true) && $tx !== '';
        return ['ok' => $ok, 'error' => $ok ? '' : ($answer['error'] !== '' ? $answer['error'] : (string)($data['ErrorText'] ?? 'Viva refused the charge.')), 'transaction_id' => $tx];
    }

    /** The key Viva asks the webhook address to answer with, kept for a day. '' when it cannot be had. */
    public function webhookKey(array $settings): string
    {
        $mode = !empty($settings['demo']) ? 'demo' : 'live';
        $kept = $this->meta->getJson(self::KEY_META) ?? [];
        if (is_array($kept) && ($kept['mode'] ?? '') === $mode && (int)($kept['until'] ?? 0) > time() && (string)($kept['key'] ?? '') !== '') {
            return (string)$kept['key'];
        }
        $secrets = $this->secrets->of(self::ID);
        $answer = $this->http()->send('GET', $this->host('www', $settings) . '/api/messages/config/token', [ShopHttp::basic((string)($settings['merchant_id'] ?? ''), $secrets['api_key'] ?? '')]);
        $key = (string)($answer['data']['Key'] ?? '');
        if ($key !== '') {
            $this->meta->setJson(self::KEY_META, ['mode' => $mode, 'key' => $key, 'until' => time() + 86400]);
        }
        return $key;
    }

    /**
     * Check connection on the Payments tab: a token with the client's credentials, and the webhook key with the merchant's.
     *
     * @return array{ok: bool, message: string}
     */
    public function check(array $settings): array
    {
        if (!$this->configured($settings)) {
            return ['ok' => false, 'message' => 'Fill in the Merchant ID, the API key, the Client ID and the Client secret first.'];
        }
        $this->meta->setJson(self::TOKEN_META, []);
        $this->meta->setJson(self::KEY_META, []);
        $token = $this->token($settings);
        if ($token['error'] !== '') {
            return ['ok' => false, 'message' => 'The Client ID and secret were not accepted: ' . $token['error']];
        }
        if ($this->webhookKey($settings) === '') {
            return ['ok' => false, 'message' => 'The token works, but the Merchant ID and API key were not accepted (the webhook key could not be had).'];
        }
        return ['ok' => true, 'message' => 'Viva Wallet answers: the client and the merchant keys both work (' . (!empty($settings['demo']) ? 'demo' : 'live') . ').'];
    }

    /**
     * The most installments an amount may be paid in: the rows "from this amount, up to N", the highest that applies; 0 for none.
     *
     * @param list<array{0: int, 1: int}> $table cents => installments
     */
    public static function maxInstallments(array $table, int $cents): int
    {
        $max = 0;
        foreach ($table as [$from, $count]) {
            if ($cents >= $from) {
                $max = max($max, $count);
            }
        }
        return min(36, $max);
    }

    /** @return list<array{0: int, 1: int}> "100 = 3" lines as [cents, installments] */
    public static function installmentsTable(string $lines): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', $lines) ?: [] as $line) {
            [$amount, $count] = array_pad(array_map('trim', explode('=', $line, 2)), 2, '');
            $cents = ShopMoney::cents($amount);
            if ($cents !== null && ctype_digit($count) && (int)$count >= 2) {
                $rows[$cents] = [$cents, min(36, (int)$count)];
            }
        }
        ksort($rows);
        return array_values($rows);
    }

    /** @param list<array{0: int, 1: int}> $table */
    public static function installmentsText(array $table): string
    {
        return implode("\n", array_map(static fn(array $r): string => ShopMoney::plain($r[0]) . ' = ' . $r[1], $table));
    }

    /** @return array{token: string, error: string} an OAuth token, kept until a minute before it runs out */
    private function token(array $settings): array
    {
        $mode = !empty($settings['demo']) ? 'demo' : 'live';
        $clientId = (string)($settings['client_id'] ?? '');
        $kept = $this->meta->getJson(self::TOKEN_META) ?? [];
        if (is_array($kept) && ($kept['mode'] ?? '') === $mode && ($kept['client'] ?? '') === $clientId && (int)($kept['until'] ?? 0) > time() + 60) {
            return ['token' => (string)$kept['token'], 'error' => ''];
        }
        $secret = $this->secrets->of(self::ID)['client_secret'] ?? '';
        if ($clientId === '' || $secret === '') {
            return ['token' => '', 'error' => 'Viva Wallet is not set up (Client ID and secret).'];
        }
        $answer = $this->http()->send('POST', $this->host('accounts', $settings) . '/connect/token', [ShopHttp::basic($clientId, $secret)], null, ['grant_type' => 'client_credentials']);
        $token = (string)($answer['data']['access_token'] ?? '');
        if (!$answer['ok'] || $token === '') {
            return ['token' => '', 'error' => $answer['error'] !== '' ? $answer['error'] : 'Viva gave no token.'];
        }
        // The token is kept with the site's other system data; it lasts an hour at most.
        $this->meta->setJson(self::TOKEN_META, ['mode' => $mode, 'client' => $clientId, 'token' => $token, 'until' => time() + max(60, (int)($answer['data']['expires_in'] ?? 3600))]);
        return ['token' => $token, 'error' => ''];
    }

    /** The address of one of Viva's hosts: accounts (tokens), api (orders, transactions) or www (the checkout page, the older API). */
    private function host(string $which, array $settings): string
    {
        $base = $this->secrets->of(self::ID)['base'] ?? '';
        if ($base !== '' && ShopHttp::isLocal((string)parse_url($base, PHP_URL_HOST))) {
            return rtrim($base, '/');
        }
        $demo = !empty($settings['demo']);
        return match ($which) {
            'accounts' => $demo ? 'https://demo-accounts.vivapayments.com' : 'https://accounts.vivapayments.com',
            'api' => $demo ? 'https://demo-api.vivapayments.com' : 'https://api.vivapayments.com',
            default => $demo ? 'https://demo.vivapayments.com' : 'https://www.vivapayments.com',
        };
    }

    private function http(): ShopHttp
    {
        $hosts = ['accounts.vivapayments.com', 'api.vivapayments.com', 'www.vivapayments.com', 'demo-accounts.vivapayments.com', 'demo-api.vivapayments.com', 'demo.vivapayments.com'];
        $base = $this->secrets->of(self::ID)['base'] ?? '';
        if ($base !== '' && ShopHttp::isLocal((string)parse_url($base, PHP_URL_HOST))) {
            $hosts[] = (string)parse_url($base, PHP_URL_HOST);
        }
        return new ShopHttp($hosts, array_values(array_filter($this->secrets->of(self::ID))), $this->transport);
    }
}
