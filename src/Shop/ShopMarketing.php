<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\SystemDatabase;
use PDO;

/**
 * Shop settings › Marketing: the product feed for Meta Commerce Manager and Google Merchant Center (ShopFeed), the Meta Pixel with
 * the shop's events, and the same purchase sent from the server (Meta's Conversions API), so a sale still counts when a browser
 * blocks the Pixel.
 *
 * Consent: in the EU the Pixel may run only after the visitor agrees. The site's own small banner asks (the default), or the site's
 * own consent tool calls `gnozioConsent.grant()`. The choice is the cookie `gz_consent`. An order remembers what the server needs
 * for its purchase (address, browser, Meta's cookies) only when the visitor agreed, and forgets it once the purchase is sent.
 */
final class ShopMarketing
{
    /** Meta's Graph API version for the Conversions API. */
    public const GRAPH = 'https://graph.facebook.com/v23.0/';
    public const COOKIE = 'gz_consent';
    public const CONSENT = [
        'banner' => 'Ask with the site\'s own banner',
        'external' => 'My own consent tool calls gnozioConsent.grant()',
        'none' => 'Do not ask (only where no consent is needed)',
    ];
    /** How long what an order remembers for its purchase event is kept when it is never paid. */
    private const KEEP_DAYS = 30;

    /**
     * @param \Closure(string, string): ?\GnozioCMS\ContentItem $product a product by id in a language
     * @param (\Closure(string, string, string[], ?string, int): array{status: int, body: string, error: string})|null $transport
     */
    public function __construct(
        private ShopSettings $settings,
        private ShopSecrets $secrets,
        private ShopOrders $orders,
        private SystemDatabase $database,
        private \Closure $product,
        private string $defaultLang,
        private ?\Closure $transport = null
    ) {
    }

    /**
     * @param array<string, mixed> $m what was stored
     * @return array{feed: bool, brand: string, category: string, out_of_stock: bool, pixel_id: string, consent: string, privacy_page: string, test_code: string}
     */
    public static function normalize(array $m): array
    {
        $line = static fn(mixed $v, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', is_scalar($v) ? (string)$v : '') ?? ''), 0, $max);
        $pixel = preg_replace('/\D/', '', is_scalar($m['pixel_id'] ?? null) ? (string)$m['pixel_id'] : '') ?? '';
        $consent = (string)($m['consent'] ?? 'banner');
        $test = trim(is_scalar($m['test_code'] ?? null) ? (string)$m['test_code'] : '');
        $page = (string)($m['privacy_page'] ?? '');
        return [
            'feed' => !empty($m['feed']),
            'brand' => $line($m['brand'] ?? '', 70),
            'category' => $line($m['category'] ?? '', 250),
            'out_of_stock' => array_key_exists('out_of_stock', $m) ? (bool)$m['out_of_stock'] : true,
            'pixel_id' => strlen($pixel) >= 5 && strlen($pixel) <= 20 ? $pixel : '',
            'consent' => isset(self::CONSENT[$consent]) ? $consent : 'banner',
            'privacy_page' => preg_match('/^[a-z0-9][a-z0-9-]*$/', $page) === 1 ? $page : '',
            'test_code' => preg_match('/^TEST[A-Za-z0-9]{1,30}$/', $test) === 1 ? $test : '',
        ];
    }

    /**
     * The id of what is sold, the same in the feed and in every event: the product's id, or for a product with variants, its id and
     * a short mark of the variant (the variant's own words can be long and in any script).
     */
    public static function contentId(string $productId, string $variantKey = ''): string
    {
        return $variantKey === '' ? $productId : $productId . '-' . substr(hash('sha256', $variantKey), 0, 8);
    }

    /** Whether a product sells its variants (so each is an item of its own), rather than only options of one stock. @param array<string, mixed> $custom */
    public static function hasVariants(array $custom): bool
    {
        foreach (is_array($custom['variants'] ?? null) ? $custom['variants'] : [] as $variant) {
            if (is_array($variant) && ShopStock::variantKey((string)($variant['values'] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, string> $cookies */
    public static function consented(array $marketing, array $cookies): bool
    {
        return $marketing['consent'] === 'none' || ($cookies[self::COOKIE] ?? '') === 'granted';
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->settings->all()['marketing'];
    }

    /** Whether the server sends purchases: a Pixel and its access token. */
    public function serverSide(): bool
    {
        return $this->config()['pixel_id'] !== '' && ($this->secrets->of('meta')['capi_token'] ?? '') !== '';
    }

    // ---- what the pages tell the Pixel

    /**
     * An event as a page hands it to the Pixel script.
     *
     * @param list<array<string, mixed>> $lines priced lines (ShopPricing) or an order's lines
     * @return array{event: string, data: array<string, mixed>, id: string}
     */
    public static function event(string $name, array $lines, int $valueCents, string $currency, string $id = ''): array
    {
        $contents = [];
        foreach ($lines as $line) {
            $contents[] = [
                'id' => (string)$line['content_id'],
                'quantity' => (int)$line['quantity'],
                'item_price' => round((int)$line['unit_cents'] / 100, 2),
            ];
        }
        return [
            'event' => $name,
            'data' => [
                'content_type' => 'product',
                'content_ids' => array_values(array_unique(array_column($contents, 'id'))),
                'contents' => $contents,
                'num_items' => array_sum(array_column($contents, 'quantity')),
                'value' => round($valueCents / 100, 2),
                'currency' => $currency,
            ],
            'id' => $id,
        ];
    }

    /** The event id of an order's purchase, the same from the page and from the server, so Meta counts it once. */
    public static function purchaseId(array $order): string
    {
        return 'purchase.' . (string)$order['number'];
    }

    /**
     * An order's lines with the id each has in the feed.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    public function withContentIds(array $lines): array
    {
        $variants = [];
        foreach ($lines as $i => $line) {
            $id = (string)$line['product_id'];
            if (!isset($variants[$id])) {
                $product = ($this->product)($id, $this->defaultLang);
                $variants[$id] = $product !== null && self::hasVariants(is_array($product->meta['custom_fields'] ?? null) ? $product->meta['custom_fields'] : []);
            }
            $lines[$i]['content_id'] = self::contentId($id, $variants[$id] ? (string)$line['variant_key'] : '');
        }
        return $lines;
    }

    // ---- the purchase from the server

    /**
     * Keeps, for an order just placed by a visitor who agreed, what Meta matches a purchase with. Nothing is kept without consent,
     * without a Pixel and an access token, or for a visitor who did not agree.
     *
     * @param array<string, mixed> $server $_SERVER
     * @param array<string, string> $cookies $_COOKIE
     */
    public function remember(int $orderId, array $server, array $cookies, string $pageUrl): void
    {
        $m = $this->config();
        if (!$this->serverSide() || !self::consented($m, $cookies)) {
            return;
        }
        $cookie = static fn(string $name): string => preg_match('/^fb\.[0-9]\.[0-9]{6,20}\.[A-Za-z0-9_.-]{1,500}$/', (string)($cookies[$name] ?? '')) === 1 ? (string)$cookies[$name] : '';
        $pdo = $this->pdo();
        $pdo->prepare('DELETE FROM shop_order_tracking WHERE created_at < :old')->execute(['old' => time() - self::KEEP_DAYS * 86400]);
        $pdo->prepare('INSERT OR REPLACE INTO shop_order_tracking (order_id, ip, agent, fbp, fbc, url, created_at) VALUES (:id, :ip, :agent, :fbp, :fbc, :url, :now)')->execute([
            'id' => $orderId,
            'ip' => substr((string)($server['REMOTE_ADDR'] ?? ''), 0, 64),
            'agent' => mb_substr((string)($server['HTTP_USER_AGENT'] ?? ''), 0, 400),
            'fbp' => $cookie('_fbp'),
            'fbc' => $cookie('_fbc'),
            'url' => mb_substr($pageUrl, 0, 500),
            'now' => time(),
        ]);
    }

    /**
     * Sends an order's purchase to Meta, once, when it is paid, if its visitor agreed at the checkout. What was kept for it is then
     * forgotten, sent or not.
     *
     * @return array{ok: bool, message: string}
     */
    public function purchase(int $orderId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_order_tracking WHERE order_id = :id');
        $stmt->execute(['id' => $orderId]);
        $tracking = $stmt->fetch(PDO::FETCH_ASSOC);
        $order = $this->orders->get($orderId);
        if (!is_array($tracking) || $order === null || !$this->serverSide()) {
            return ['ok' => false, 'message' => ''];
        }
        $this->pdo()->prepare('DELETE FROM shop_order_tracking WHERE order_id = :id')->execute(['id' => $orderId]);
        $m = $this->config();
        $event = self::purchasePayload($order, $this->withContentIds($this->orders->lines($orderId)), $tracking, time());
        $result = $this->send([$event], $m['test_code']);
        $this->orders->addEvent($orderId, 'meta', $result['ok'] ? 'The purchase was sent to Meta.' : 'The purchase could not be sent to Meta: ' . $result['message'], 'system');
        return $result;
    }

    /** A test event to Meta's Test events screen, with the test code of the settings. @return array{ok: bool, message: string} */
    public function test(): array
    {
        $m = $this->config();
        if ($m['pixel_id'] === '' || ($this->secrets->of('meta')['capi_token'] ?? '') === '') {
            return ['ok' => false, 'message' => 'Write the Pixel ID and the access token first.'];
        }
        if ($m['test_code'] === '') {
            return ['ok' => false, 'message' => 'Write the test event code from Meta\'s Events Manager › Test events first; then the test shows there.'];
        }
        $result = $this->send([[
            'event_name' => 'PageView',
            'event_time' => time(),
            'event_id' => 'test.' . bin2hex(random_bytes(6)),
            'action_source' => 'website',
            'user_data' => ['client_ip_address' => '127.0.0.1', 'client_user_agent' => 'GnozioCMS test'],
        ]], $m['test_code']);
        return $result['ok'] ? ['ok' => true, 'message' => 'Meta took the test event; it shows in Events Manager › Test events.'] : $result;
    }

    /**
     * The purchase as Meta's Conversions API takes it: who bought (hashed, as Meta asks), from which browser, and what.
     *
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $lines with content_id
     * @param array<string, mixed> $tracking the row kept at the checkout
     * @return array<string, mixed>
     */
    public static function purchasePayload(array $order, array $lines, array $tracking, int $now): array
    {
        $hash = static fn(string $value): string => hash('sha256', $value);
        $billing = is_array($order['billing'] ?? null) ? $order['billing'] : (json_decode((string)($order['billing'] ?? '{}'), true) ?: []);
        $plain = static fn(mixed $v): string => mb_strtolower(trim(is_scalar($v) ? (string)$v : ''));
        $user = ['em' => [$hash($plain($order['email'] ?? ''))]];
        $phone = preg_replace('/\D/', '', (string)($billing['phone'] ?? '')) ?? '';
        if ($phone !== '') {
            // Meta wants the country code; a Greek number written without it gets 30.
            $user['ph'] = [$hash(strlen($phone) === 10 && ($billing['country'] ?? '') === 'GR' ? '30' . $phone : $phone)];
        }
        foreach (['fn' => 'first_name', 'ln' => 'last_name', 'ct' => 'city'] as $key => $field) {
            $value = str_replace(' ', '', $plain($billing[$field] ?? ''));
            if ($value !== '') {
                $user[$key] = [$hash($value)];
            }
        }
        $zip = str_replace(' ', '', $plain($billing['postcode'] ?? ''));
        if ($zip !== '') {
            $user['zp'] = [$hash($zip)];
        }
        if (($billing['country'] ?? '') !== '') {
            $user['country'] = [$hash($plain($billing['country']))];
        }
        if (!empty($order['customer_id'])) {
            $user['external_id'] = [$hash('customer.' . (int)$order['customer_id'])];
        }
        foreach (['client_ip_address' => 'ip', 'client_user_agent' => 'agent', 'fbp' => 'fbp', 'fbc' => 'fbc'] as $key => $field) {
            if ((string)($tracking[$field] ?? '') !== '') {
                $user[$key] = (string)$tracking[$field];
            }
        }
        $event = self::event('Purchase', $lines, (int)$order['total_cents'], (string)($order['currency'] ?? 'EUR'));
        return [
            'event_name' => 'Purchase',
            'event_time' => $now,
            'event_id' => self::purchaseId($order),
            'action_source' => 'website',
            'event_source_url' => (string)($tracking['url'] ?? ''),
            'user_data' => $user,
            'custom_data' => $event['data'] + ['order_id' => (string)$order['number']],
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{ok: bool, message: string}
     */
    private function send(array $events, string $testCode): array
    {
        $token = $this->secrets->of('meta')['capi_token'] ?? '';
        $body = ['data' => $events, 'access_token' => $token];
        if ($testCode !== '') {
            $body['test_event_code'] = $testCode;
        }
        $http = new ShopHttp(['graph.facebook.com'], [$token], $this->transport);
        $answer = $http->send('POST', self::GRAPH . rawurlencode($this->config()['pixel_id']) . '/events', [], $body, null, 8);
        if ($answer['ok']) {
            return ['ok' => true, 'message' => ''];
        }
        $said = is_array($answer['data']['error'] ?? null) ? (string)($answer['data']['error']['message'] ?? '') : '';
        return ['ok' => false, 'message' => $said !== '' ? mb_substr($said, 0, 200) : $answer['error']];
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
