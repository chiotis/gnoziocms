<?php

declare(strict_types=1);

namespace GnozioCMS\Shop\Payments;

use GnozioCMS\Shop\ShopHttp;
use GnozioCMS\Shop\ShopMoney;
use GnozioCMS\Shop\ShopOrders;
use GnozioCMS\Shop\ShopPayments;
use GnozioCMS\Shop\ShopSecrets;

/**
 * Cardlink's redirect checkout (docs/shop-plan.md, section 5), for the three acquirers that use it: Cardlink itself, Nexi (Alpha
 * Bank) and Worldline (formerly Eurobank's acquiring). Behaves as Cardlink's own WooCommerce plugin does, so a merchant's account
 * moves over as it is: the same Merchant ID and shared secret.
 *
 * Paying: placing the order sends the customer to /_shop/pay/cardlink/go, a page of this site that (after asking for the
 * installments, where the amount allows them) posts a signed form to the acquirer's /vpos/shophandlermpi. The fields go in the
 * spec's fixed order, and the digest is base64(sha256(the values one after another, then the shared secret)). The acquirer posts
 * the result back to /_shop/pay/cardlink/confirm or /cancel, signed the same way over the fields in the order they came; nothing
 * is believed before that digest is checked, and the amount must be the order's.
 *
 * Cardlink calls no address of its own: a payment whose answer never came is asked about with the XML API's StatusRequest
 * (/vpos/xmlpayvpos) before the order is let go (ChecksPayments). Refunds use the same API: RefundRequest, or on the day of the
 * payment a CancelRequest (a void) when a refund is not yet possible.
 *
 * Test mode uses the acquirer's -test host. A test may point all of it at a fake on this machine (the `base` secret, which no
 * screen can set).
 */
final class Cardlink implements PaymentProvider, ChecksPayments
{
    public const ID = 'cardlink';
    public const ACQUIRERS = [
        'cardlink' => ['label' => 'Cardlink', 'live' => 'ecommerce.cardlink.gr', 'test' => 'ecommerce-test.cardlink.gr'],
        'nexi' => ['label' => 'Nexi (Alpha Bank)', 'live' => 'www.alphaecommerce.gr', 'test' => 'alphaecommerce-test.cardlink.gr'],
        'worldline' => ['label' => 'Worldline (Eurobank)', 'live' => 'vpos.eurocommerce.gr', 'test' => 'eurocommerce-test.cardlink.gr'],
    ];
    public const PAID = ['CAPTURED'];
    public const FAILED = ['CANCELED', 'REFUSED', 'ERROR'];
    /** Fields of an answer that its digest does not cover (the loyalty "bonus" ones have their own). */
    private const NOT_SIGNED = ['_charset_', 'digest', 'submitButton', 'xlsbonusadjamt', 'xlsbonustxid', 'xlsbonusstatus', 'xlsbonusdetails', 'xlsbonusdigest'];
    private const XMLNS = 'http://www.modirum.com/schemas/vposxmlapi41';
    private const XMLNS_SIG = 'http://www.w3.org/2000/09/xmldsig#';

    /** @param (\Closure(string, string, string[], ?string, int): array{status: int, body: string, error: string})|null $transport */
    public function __construct(
        private ShopSecrets $secrets,
        private ShopPayments $payments,
        private ShopOrders $orders,
        private string $siteUrl,
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

    public function configured(array $settings): bool
    {
        return (string)($settings['merchant_id'] ?? '') !== '' && $this->secret() !== '';
    }

    /**
     * A new payment for the order (each attempt has its own order id at Cardlink, which refuses one it has seen), and the
     * customer is sent to this site's page that posts the form.
     */
    public function start(array $order, array $settings): array
    {
        if (!$this->configured($settings)) {
            return ['status' => 'pending_payment', 'redirect' => '', 'error' => 'Cardlink is not set up (Merchant ID and shared secret).'];
        }
        $reference = self::reference((string)$order['number']);
        $this->payments->start((int)$order['id'], self::ID, $reference, (int)$order['total_cents']);
        return ['status' => 'pending_payment', 'redirect' => '/_shop/pay/cardlink/go?' . http_build_query(['ref' => $reference, 'k' => $this->sign($reference)]), 'error' => ''];
    }

    /** Cardlink's order id for one attempt: the order number's letters and digits, then a mark of the moment. */
    public static function reference(string $number): string
    {
        $base = substr((string)preg_replace('/[^A-Za-z0-9]/', '', $number), 0, 30);
        return $base . 'T' . strtoupper(base_convert((string)time(), 10, 36)) . strtoupper(bin2hex(random_bytes(2)));
    }

    /** Whether the go page's address was made by this site for that payment. */
    public function signed(string $reference, string $key): bool
    {
        return $this->secret() !== '' && hash_equals($this->sign($reference), $key);
    }

    /**
     * What the go page needs for a payment still waiting: the order, the most installments its amount allows. Null when the
     * payment is not one this site started, or no longer waits.
     *
     * @return array{payment: array<string, mixed>, order: array<string, mixed>, max_installments: int}|null
     */
    public function pending(string $reference, array $settings): ?array
    {
        $payment = $this->payments->byReference(self::ID, $reference);
        $order = $payment !== null ? $this->orders->get((int)$payment['order_id']) : null;
        if ($payment === null || $order === null || $payment['status'] !== ShopPayments::STARTED || $order['status'] !== 'pending_payment') {
            return null;
        }
        $table = is_array($settings['installments'] ?? null) ? $settings['installments'] : [];
        return ['payment' => $payment, 'order' => $order, 'max_installments' => VivaWallet::maxInstallments($table, (int)$payment['amount_cents'])];
    }

    /**
     * The form posted to the acquirer, its fields in the spec's order with the digest last.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $payment
     * @return array{action: string, fields: array<string, string>}
     */
    public function form(array $order, array $payment, array $settings, int $installments = 0): array
    {
        $billing = is_array($order['billing'] ?? null) ? $order['billing'] : [];
        $shipping = is_array($order['shipping'] ?? null) && ($order['shipping']['address'] ?? '') !== '' ? $order['shipping'] : $billing;
        $text = static fn(mixed $value, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', is_scalar($value) ? (string)$value : '') ?? ''), 0, $max);
        $fields = [
            'version' => '2',
            'mid' => (string)$settings['merchant_id'],
            'lang' => (string)$order['lang'] === 'el' ? 'el' : 'en',
            'orderid' => (string)$payment['reference'],
            'orderDesc' => $text('Order ' . $order['number'], 128),
            'orderAmount' => ShopMoney::plain((int)$payment['amount_cents']),
            'currency' => 'EUR',
            'payerEmail' => $text($order['email'] ?? '', 64),
            'payerPhone' => (string)preg_replace('/[^0-9+]/', '', (string)($billing['phone'] ?? '')),
            'billCountry' => $text($billing['country'] ?? '', 2),
            'billZip' => $text($billing['postcode'] ?? '', 16),
            'billCity' => $text($billing['city'] ?? '', 64),
            'billAddress' => $text($billing['address'] ?? '', 100),
            'shipCountry' => $text($shipping['country'] ?? '', 2),
            'shipZip' => $text($shipping['postcode'] ?? '', 16),
            'shipCity' => $text($shipping['city'] ?? '', 64),
            'shipAddress' => $text($shipping['address'] ?? '', 100),
            'trType' => '1',
        ];
        if ($installments > 1) {
            $fields['extInstallmentoffset'] = '0';
            $fields['extInstallmentperiod'] = (string)$installments;
        }
        $fields['confirmUrl'] = rtrim($this->siteUrl, '/') . '/_shop/pay/cardlink/confirm';
        $fields['cancelUrl'] = rtrim($this->siteUrl, '/') . '/_shop/pay/cardlink/cancel';
        $fields = array_filter($fields, static fn(string $v): bool => $v !== '');
        $fields['digest'] = self::digest(implode('', $fields), $this->secret());
        return ['action' => $this->host($settings) . '/vpos/shophandlermpi', 'fields' => $fields];
    }

    public static function digest(string $values, string $secret): string
    {
        return base64_encode(hash('sha256', $values . $secret, true));
    }

    /**
     * The result the acquirer posted back, once its digest is checked: in the shape ShopCheckout::confirm() takes.
     *
     * @param array<string, mixed> $post the fields in the order they came
     * @return array<string, mixed>
     */
    public function answer(array $post, array $settings): array
    {
        $values = '';
        foreach ($post as $key => $value) {
            if (!in_array((string)$key, self::NOT_SIGNED, true) && is_scalar($value)) {
                $values .= (string)$value;
            }
        }
        $reference = (string)($post['orderid'] ?? '');
        $status = strtoupper((string)($post['status'] ?? ''));
        $tx = [
            'ok' => false,
            'error' => '',
            'transaction_id' => (string)($post['txId'] ?? ''),
            'reference' => $reference,
            'paid' => in_array($status, self::PAID, true),
            'failed' => in_array($status, self::FAILED, true),
            'amount_cents' => ShopMoney::cents($post['orderAmount'] ?? null) ?? -1,
            'currency' => (string)($post['currency'] ?? ''),
            'merchant_trns' => null,
            'status' => $status,
            'installments' => 0,
            'payment_ref' => (string)($post['paymentRef'] ?? ''),
            'message' => mb_substr((string)($post['message'] ?? ''), 0, 200),
        ];
        $secret = $this->secret();
        if ($secret === '' || !is_string($post['digest'] ?? null) || !hash_equals(self::digest($values, $secret), (string)$post['digest'])) {
            return ['error' => 'The answer\'s digest is not right.'] + $tx;
        }
        if ((string)($post['mid'] ?? '') !== (string)($settings['merchant_id'] ?? '')) {
            return ['error' => 'The answer is for another merchant.'] + $tx;
        }
        if (is_string($post['xlsbonusdigest'] ?? null) && $post['xlsbonusdigest'] !== '') {
            $bonus = ($post['xlsbonusadjamt'] ?? '') . ($post['xlsbonustxid'] ?? '') . ($post['xlsbonusstatus'] ?? '') . ($post['xlsbonusdetails'] ?? '');
            if (!hash_equals(self::digest((string)$bonus, $secret), $post['xlsbonusdigest'])) {
                return ['error' => 'The answer\'s bonus digest is not right.'] + $tx;
            }
        }
        if ($tx['transaction_id'] === '') {
            $tx['transaction_id'] = $tx['payment_ref'];
        }
        return ['ok' => true] + $tx;
    }

    /**
     * Asks the acquirer about a payment no answer came for (XML StatusRequest).
     *
     * @return array<string, mixed>
     */
    public function lookup(array $payment, array $settings): array
    {
        $none = ['ok' => false, 'error' => '', 'transaction_id' => '', 'reference' => (string)$payment['reference'], 'paid' => false, 'failed' => false, 'amount_cents' => -1, 'currency' => '', 'merchant_trns' => null, 'status' => '', 'installments' => 0];
        $answer = $this->xml($settings, 'StatusRequest', '<TransactionInfo><OrderId>' . self::esc((string)$payment['reference']) . '</OrderId></TransactionInfo>');
        if ($answer['error'] !== '') {
            return ['error' => $answer['error']] + $none;
        }
        $details = $answer['data']['TransactionDetails'] ?? $answer['data'];
        $status = strtoupper((string)($details['Status'] ?? $details['TxStatus'] ?? ''));
        if ($status === '') {
            // No such order at the acquirer: the customer never got as far as paying.
            $error = (string)($answer['data']['ErrorMessage'] ?? $answer['data']['Description'] ?? '');
            return ['ok' => true, 'failed' => true, 'status' => 'NONE', 'error' => $error] + $none;
        }
        return [
            'ok' => true,
            'transaction_id' => (string)($details['TxId'] ?? $details['PaymentRef'] ?? ''),
            'paid' => in_array($status, self::PAID, true),
            'failed' => in_array($status, self::FAILED, true),
            'amount_cents' => ShopMoney::cents($details['OrderAmount'] ?? null) ?? -1,
            'currency' => (string)($details['Currency'] ?? ''),
            'status' => $status,
        ] + $none;
    }

    /**
     * Gives money back: a RefundRequest; when the acquirer refuses it on the day of the payment (not settled yet) and all of it is
     * given back, a CancelRequest (a void) instead.
     *
     * @param array<string, mixed> $payment
     * @return array{ok: bool, error: string, transaction_id: string}
     */
    public function refund(array $settings, array $payment, int $cents): array
    {
        $info = '<OrderInfo><OrderId>' . self::esc((string)$payment['reference']) . '</OrderId><OrderAmount>' . ShopMoney::plain($cents) . '</OrderAmount><Currency>EUR</Currency></OrderInfo>';
        $answer = $this->xml($settings, 'RefundRequest', $info);
        $refused = $answer['error'] !== '' ? $answer['error'] : self::refusal($answer['data']);
        if ($refused === '') {
            return ['ok' => true, 'error' => '', 'transaction_id' => (string)($answer['data']['TxId'] ?? '')];
        }
        $sameDay = date('Y-m-d', (int)$payment['updated_at']) === date('Y-m-d');
        if ($sameDay && $cents === (int)$payment['amount_cents'] && (int)$payment['refunded_cents'] === 0) {
            $void = $this->xml($settings, 'CancelRequest', $info);
            $voidRefused = $void['error'] !== '' ? $void['error'] : self::refusal($void['data']);
            if ($voidRefused === '') {
                return ['ok' => true, 'error' => '', 'transaction_id' => (string)($void['data']['TxId'] ?? '')];
            }
        }
        return ['ok' => false, 'error' => $refused, 'transaction_id' => ''];
    }

    /**
     * Check connection: a StatusRequest for an order that does not exist. A wrong Merchant ID or secret is refused as such; the
     * right ones get "no such order".
     *
     * @return array{ok: bool, message: string}
     */
    public function check(array $settings): array
    {
        if (!$this->configured($settings)) {
            return ['ok' => false, 'message' => 'Fill in the Merchant ID and the shared secret first.'];
        }
        $answer = $this->xml($settings, 'StatusRequest', '<TransactionInfo><OrderId>GZCHECK' . time() . '</OrderId></TransactionInfo>');
        if ($answer['error'] !== '') {
            return ['ok' => false, 'message' => 'Cardlink could not be asked: ' . $answer['error']];
        }
        $said = (string)($answer['data']['ErrorMessage'] ?? $answer['data']['Description'] ?? '');
        if (preg_match('/digest|auth|merchant|\bmid\b|signature|permission|denied/i', $said) === 1) {
            return ['ok' => false, 'message' => 'Cardlink did not accept the Merchant ID and shared secret: ' . mb_substr($said, 0, 160)];
        }
        $where = self::ACQUIRERS[self::acquirer($settings)]['label'] . (!empty($settings['test']) ? ', test' : ', live');
        return ['ok' => true, 'message' => 'Cardlink answers (' . $where . ')' . ($said !== '' ? ': "' . mb_substr($said, 0, 120) . '"' : '') . '. The keys look right; a test payment proves it.'];
    }

    public static function acquirer(array $settings): string
    {
        return isset(self::ACQUIRERS[(string)($settings['acquirer'] ?? '')]) ? (string)$settings['acquirer'] : 'cardlink';
    }

    /**
     * Sends one message of the XML API, signed: the digest is base64(sha256(the Message element as sent, with the namespaces,
     * then the shared secret)).
     *
     * @return array{data: array<string, mixed>, error: string}
     */
    private function xml(array $settings, string $request, string $info): array
    {
        $id = 'M' . (int)(microtime(true) * 1000);
        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Athens')))->format('Y-m-d\TH:i:s.vP');
        $content = '<' . $request . '><Authentication><Mid>' . self::esc((string)($settings['merchant_id'] ?? '')) . '</Mid></Authentication>' . $info . '</' . $request . '>';
        $attributes = 'messageId="' . $id . '" timeStamp="' . $stamp . '" version="2.1"';
        $signed = '<Message xmlns="' . self::XMLNS . '" xmlns:ns2="' . self::XMLNS_SIG . '" ' . $attributes . '>' . $content . '</Message>';
        $body = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<VPOS xmlns="' . self::XMLNS . '" xmlns:ns2="' . self::XMLNS_SIG . '"><Message ' . $attributes . '>' . $content . '</Message><Digest>' . self::digest($signed, $this->secret()) . '</Digest></VPOS>';
        $answer = $this->http()->raw('POST', $this->host($settings) . '/vpos/xmlpayvpos', $body, 'application/xml; charset=UTF-8');
        if (!$answer['ok']) {
            return ['data' => [], 'error' => $answer['error']];
        }
        $data = self::parse($answer['body']);
        return $data === null ? ['data' => [], 'error' => 'Cardlink\'s answer was not XML.'] : ['data' => $data, 'error' => ''];
    }

    /**
     * The interesting part of an XML answer: the children of its …Response element (TransactionDetails kept as its own array),
     * or of its ErrorMessage.
     *
     * @return array<string, mixed>|null
     */
    public static function parse(string $xml): ?array
    {
        if (trim($xml) === '') {
            return null;
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $dom->documentElement === null) {
            return null;
        }
        $data = [];
        foreach ($dom->getElementsByTagName('*') as $node) {
            $name = $node->localName;
            if ($name === 'ErrorMessage') {
                $children = self::children($node);
                $data['ErrorMessage'] = $children !== [] ? (string)($children['Description'] ?? implode(' ', $children)) : trim($node->textContent);
                $data += $children;
            } elseif (str_ends_with((string)$name, 'Response') && $node->parentNode?->localName === 'Message') {
                $data += self::children($node);
            } elseif ($name === 'TransactionDetails' && !isset($data['TransactionDetails'])) {
                $data['TransactionDetails'] = self::children($node);
            }
        }
        return $data;
    }

    /** @return array<string, string> an element's leaf children, by name */
    private static function children(\DOMElement $node): array
    {
        $out = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $leaf = true;
                foreach ($child->childNodes as $grand) {
                    if ($grand instanceof \DOMElement) {
                        $leaf = false;
                        break;
                    }
                }
                if ($leaf) {
                    $out[$child->localName] = trim($child->textContent);
                }
            }
        }
        return $out;
    }

    /** '' when a secondary request went through; what was said otherwise. @param array<string, mixed> $data */
    private static function refusal(array $data): string
    {
        $status = strtoupper((string)($data['Status'] ?? ''));
        if (isset($data['ErrorMessage']) || isset($data['ErrorCode'])) {
            return 'Cardlink refused it: ' . mb_substr((string)($data['ErrorMessage'] ?? $data['Description'] ?? $data['ErrorCode']), 0, 160);
        }
        if (in_array($status, ['CAPTURED', 'REFUNDED', 'REFUNDEDPARTIALLY', 'CANCELED', 'REVERSED', 'APPROVED', 'SUCCESS'], true)) {
            return '';
        }
        return 'Cardlink answered ' . ($status !== '' ? $status : 'without a status') . (isset($data['Description']) ? ': ' . mb_substr((string)$data['Description'], 0, 160) : '') . '.';
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function sign(string $reference): string
    {
        return substr(hash_hmac('sha256', 'go:' . $reference, $this->secret()), 0, 32);
    }

    private function secret(): string
    {
        return $this->secrets->of(self::ID)['shared_secret'] ?? '';
    }

    /** The acquirer's host, live or test (or a fake on this machine for tests). */
    private function host(array $settings): string
    {
        $base = $this->secrets->of(self::ID)['base'] ?? '';
        if ($base !== '' && ShopHttp::isLocal((string)parse_url($base, PHP_URL_HOST))) {
            return rtrim($base, '/');
        }
        return 'https://' . self::ACQUIRERS[self::acquirer($settings)][!empty($settings['test']) ? 'test' : 'live'];
    }

    private function http(): ShopHttp
    {
        $hosts = [];
        foreach (self::ACQUIRERS as $acquirer) {
            $hosts[] = $acquirer['live'];
            $hosts[] = $acquirer['test'];
        }
        $base = $this->secrets->of(self::ID)['base'] ?? '';
        if ($base !== '' && ShopHttp::isLocal((string)parse_url($base, PHP_URL_HOST))) {
            $hosts[] = (string)parse_url($base, PHP_URL_HOST);
        }
        return new ShopHttp($hosts, array_values(array_filter([$this->secret()])), $this->transport);
    }
}
