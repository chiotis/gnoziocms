<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\LoginThrottle;

/**
 * The shop's own pages on the public site (docs/shop-plan.md, sections 3 and 4), in each language under its prefix:
 *   /cart                      the cart: quantities, a coupon
 *   /checkout                  contact, address, shipping, payment, placing the order
 *   /checkout/order/{number}   an order's page (with its key for a guest, or for its customer)
 *   /account …                 signing in and up, a new password, the customer's orders and details
 *   /account/google …          signing in with Google; Google sends the person back to /account/google/callback (no prefix)
 *   /_shop/cart                adding to the cart from a product's page (a form or a script), and /_shop/cart.json
 *   /_shop/pay/viva/…          where Viva Wallet sends the customer back (success, failure) and calls (webhook)
 *   /_shop/pay/cardlink/go     the step before Cardlink's page (installments), which posts the signed form to the bank
 *   /_shop/pay/cardlink/…      where Cardlink posts the result back (confirm, cancel)
 *   /_shop/download/{id}-{sig} a paid order's file, while its count and days allow
 *
 * These pages have their own session (cookie gz_shop), apart from the admin's, so a shopper's other pages stay the same for
 * everyone and can be kept by a cache. The cart is found by its own cookie; the header's count is read by the page's script
 * from gz_cart_n. Adding to the cart comes from pages kept by a cache, so it is checked by where it comes from (Origin), not
 * by a session's token; everything else is a form with the session's token.
 */
final class ShopFront
{
    public const SESSION_NAME = 'gz_shop';
    private const RETURNS = ['account', 'checkout'];

    /**
     * @param \Closure(string, string, array<string, mixed>): void $render a theme template, in a language, with its data
     * @param \Closure(string, string, array<string, string>=): string $t
     * @param \Closure(string): string $absoluteUrl
     * @param \Closure(string): string $prefixFor the address prefix of a language ('' or 'en/')
     * @param \Closure(): bool $csrfValid
     * @param \Closure(): void $notFound
     * @param \Closure(string): string $pageUrl the address of a page, by its slug, in the current language
     */
    public function __construct(
        private ShopCart $carts,
        private ShopPricing $pricing,
        private ShopCheckout $checkout,
        private ShopOrders $orders,
        private ShopCustomers $customers,
        private ShopSettings $settings,
        private ShopMail $mail,
        private LoginThrottle $throttle,
        private \Closure $render,
        private \Closure $t,
        private \Closure $absoluteUrl,
        private \Closure $prefixFor,
        private \Closure $csrfValid,
        private \Closure $notFound,
        private \Closure $pageUrl,
        private bool $https,
        private ?\GnozioCMS\Shop\Payments\VivaWallet $viva = null,
        private ?Payments\Cardlink $cardlink = null,
        private ?ShopDownloads $downloads = null,
        private ?ShopSubscriptions $subscriptions = null,
        private ?ShopMarketing $marketing = null,
        private ?\GnozioCMS\GoogleSignIn $google = null
    ) {
    }

    /** Whether an address (without its language prefix) is one of the shop's. */
    public static function owns(string $rest): bool
    {
        return preg_match('#^(cart|checkout|account)(/|$)#', $rest) === 1 || str_starts_with($rest, '_shop/');
    }

    /** Whether it needs the shop's session: everything but adding to the cart and reading it. */
    public static function needsSession(string $rest): bool
    {
        return !str_starts_with($rest, '_shop/');
    }

    public function handle(string $rest, string $lang): void
    {
        header('Cache-Control: private, no-store');
        header('X-Robots-Tag: noindex');
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $post = $method === 'POST';
        if ($post && self::needsSession($rest) && !($this->csrfValid)()) {
            $this->flash('error', ($this->t)('shop.err_session', $lang));
            $this->go($this->back($rest, $lang));
            return;
        }
        $segments = explode('/', $rest);
        match (true) {
            $rest === '_shop/cart' && $post => $this->add($lang),
            $rest === '_shop/cart.json' => $this->json($this->cartSummary($lang)),
            $rest === 'cart' => $post ? $this->cartAction($lang) : $this->cartPage($lang),
            $rest === 'checkout' => $this->checkoutPage($lang, $post),
            $segments[0] === 'checkout' && ($segments[1] ?? '') === 'order' && isset($segments[2]) && count($segments) === 3 => $this->orderPage($segments[2], $lang, (string)($_GET['key'] ?? '')),
            $segments[0] === 'checkout' && ($segments[1] ?? '') === 'order' && isset($segments[2]) && ($segments[3] ?? '') === 'pay' && $post => $this->payAgain($segments[2], $lang, (string)($_REQUEST['key'] ?? '')),
            $rest === '_shop/pay/viva/webhook' => $this->vivaWebhook($post),
            $rest === '_shop/pay/viva/success', $rest === '_shop/pay/viva/failure' => $this->vivaReturn($segments[3]),
            $rest === '_shop/pay/cardlink/go' => $this->cardlinkGo($post),
            ($rest === '_shop/pay/cardlink/confirm' || $rest === '_shop/pay/cardlink/cancel') && $post => $this->cardlinkReturn(),
            $segments[0] === '_shop' && ($segments[1] ?? '') === 'download' && isset($segments[2]) && count($segments) === 3 => $this->download($segments[2], $lang, $method === 'HEAD'),
            $segments[0] === 'account' => $this->account(array_slice($segments, 1), $lang, $post),
            default => ($this->notFound)(),
        };
    }

    // ---- the cart

    /**
     * Adding to the cart: from a product's form (the product, its options, a quantity) or a look's (items[…][product],
     * items[…][variant], one of each), then back to the cart; or from the page's script, which is answered with JSON.
     */
    private function add(string $lang): void
    {
        $wantsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        if (!$this->sameOrigin()) {
            $this->json(['ok' => false, 'message' => ($this->t)('shop.err_session', $lang)], 403);
            return;
        }
        $wanted = [];
        if (is_array($_POST['items'] ?? null)) {
            foreach (array_slice($_POST['items'], 0, 12) as $row) {
                if (is_array($row)) {
                    $wanted[] = [substr((string)($row['product'] ?? ''), 0, 64), ShopStock::variantKey((string)($row['variant'] ?? '')), 1];
                }
            }
        } else {
            $options = is_array($_POST['options'] ?? null) ? array_map(static fn(mixed $v): string => is_scalar($v) ? (string)$v : '', $_POST['options']) : [];
            ksort($options);
            $variant = ShopStock::variantKey(isset($_POST['variant']) ? (string)$_POST['variant'] : implode(' / ', $options));
            $wanted[] = [substr((string)($_POST['product'] ?? ''), 0, 64), $variant, max(1, min(ShopCart::MAX_QUANTITY, (int)($_POST['quantity'] ?? 1)))];
        }
        $token = $this->token(true);
        $cart = $this->carts->load($token);
        $lines = $cart['lines'];
        $added = [];
        $refused = '';
        foreach ($wanted as [$productId, $variant, $quantity]) {
            $check = $this->pricing->lines([['product_id' => $productId, 'variant_key' => $variant, 'quantity' => $quantity]], $lang);
            if ($check['lines'] === []) {
                $refused = $refused !== '' ? $refused : ($check['changes'][0]['reason'] ?? 'gone');
                continue;
            }
            $lines = ShopCart::add($lines, $productId, $variant, $quantity);
            $added[] = $check['lines'][0]['title'];
        }
        $ok = $added !== [];
        $message = ($this->t)('shop.err_add_' . ($refused !== '' ? $refused : 'gone'), $lang);
        if ($ok) {
            $priced = $this->pricing->lines($lines, $lang);
            $this->carts->save($token, $priced['kept'], $cart['coupon'], $cart['customer_id']);
            $this->countCookie($priced['count']);
            $message = $priced['changes'] !== [] ? ($this->t)('shop.err_add_reduced', $lang) : ($this->t)('shop.added', $lang, ['title' => implode(', ', $added)]);
        }
        if ($wantsJson) {
            $track = [];
            if ($ok && $this->marketing !== null && $this->marketing->config()['pixel_id'] !== '') {
                $bought = [];
                foreach ($wanted as [$productId, $variant, $quantity]) {
                    $bought = array_merge($bought, $this->pricing->lines([['product_id' => $productId, 'variant_key' => $variant, 'quantity' => $quantity]], $lang)['lines']);
                }
                $track = ShopMarketing::event('AddToCart', $bought, array_sum(array_column($bought, 'total_cents')), (string)$this->settings->all()['currency']);
            }
            $this->json(['ok' => $ok, 'message' => $message, 'track' => $track] + $this->cartSummary($lang), $ok ? 200 : 422);
            return;
        }
        $this->flash($ok ? 'success' : 'error', $message);
        $this->go('/' . ($this->prefixFor)($lang) . 'cart');
    }

    /** @return array{count: int, lines: list<array<string, mixed>>, subtotal: string, cart_url: string, checkout_url: string} */
    private function cartSummary(string $lang): array
    {
        $token = $this->token(false);
        $priced = $this->pricing->lines($token === '' ? [] : $this->carts->load($token)['lines'], $lang);
        $this->countCookie($priced['count']);
        $prefix = '/' . ($this->prefixFor)($lang);
        return [
            'count' => $priced['count'],
            'lines' => array_map(static fn(array $l): array => ['title' => $l['title'], 'variant' => $l['variant'], 'quantity' => $l['quantity'], 'image' => $l['image'], 'url' => $l['url'], 'total' => ShopMoney::format($l['total_cents'], $lang)], $priced['lines']),
            'subtotal' => ShopMoney::format($priced['items_cents'], $lang),
            'cart_url' => $prefix . 'cart',
            'checkout_url' => $prefix . 'checkout',
        ];
    }

    private function cartAction(string $lang): void
    {
        $token = $this->token(true);
        $cart = $this->carts->load($token);
        $lines = $cart['lines'];
        $coupon = $cart['coupon'];
        if (isset($_POST['remove'])) {
            $lines = ShopCart::set($lines, (string)$_POST['remove'], 0);
        } elseif (isset($_POST['coupon_remove'])) {
            $coupon = '';
        } else {
            foreach (is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [] as $key => $qty) {
                $lines = ShopCart::set($lines, (string)$key, (int)$qty);
            }
            if (isset($_POST['coupon']) && trim((string)$_POST['coupon']) !== '') {
                $code = ShopCoupons::normalizeCode($_POST['coupon']);
                $priced = $this->pricing->lines($lines, $lang);
                $applied = $this->checkout->quote($priced['kept'], $code, [], '', '', $lang)['coupon'];
                if ($applied !== null && $applied['ok']) {
                    $coupon = $code;
                    $this->flash('success', ($this->t)('shop.coupon_applied', $lang, ['code' => $code]));
                } else {
                    $this->flash('error', ($this->t)('shop.err_coupon_' . ($applied['error'] ?? 'unknown'), $lang));
                }
            }
        }
        $priced = $this->pricing->lines($lines, $lang);
        $this->carts->save($token, $priced['kept'], $coupon, $cart['customer_id']);
        $this->countCookie($priced['count']);
        $this->go('/' . ($this->prefixFor)($lang) . 'cart');
    }

    private function cartPage(string $lang): void
    {
        $token = $this->token(false);
        $cart = $token === '' ? ['lines' => [], 'coupon' => '', 'customer_id' => null] : $this->carts->load($token);
        $quote = $this->checkout->quote($cart['lines'], $cart['coupon'], [], '', '', $lang);
        $priced = $quote['priced'];
        if ($priced['changes'] !== [] && $token !== '') {
            $this->carts->save($token, $priced['kept'], $cart['coupon'], $cart['customer_id']);
        }
        $this->countCookie($priced['count']);
        ($this->render)('templates/shop/cart.twig', $lang, [
            'document_title' => ($this->t)('shop.cart', $lang),
            'cart' => $this->view($quote, $lang),
            'changes' => $this->changes($priced['changes'], $lang),
            'coupon' => $cart['coupon'],
            'flash' => $this->takeFlash(),
        ]);
    }

    // ---- the checkout

    private function checkoutPage(string $lang, bool $post): void
    {
        $token = $this->token(false);
        $cart = $token === '' ? ['lines' => [], 'coupon' => '', 'customer_id' => null] : $this->carts->load($token);
        if ($cart['lines'] === []) {
            $this->go('/' . ($this->prefixFor)($lang) . 'cart');
            return;
        }
        $shop = $this->settings->all();
        $customer = $this->customer();
        if ($customer === null && !$shop['guest_checkout']) {
            $this->flash('info', ($this->t)('shop.sign_in_to_order', $lang));
            $this->go('/' . ($this->prefixFor)($lang) . 'account?return=checkout');
            return;
        }
        $input = $post ? $_POST : [];
        if (!$post) {
            $saved = $customer['address'] ?? ShopAddress::clean([]);
            $input = ['email' => $customer['email'] ?? '', 'billing' => $saved + ['phone' => $customer['phone'] ?? ''], 'coupon' => $cart['coupon']];
            if (($input['billing']['phone'] ?? '') === '' && ($customer['phone'] ?? '') !== '') {
                $input['billing']['phone'] = $customer['phone'];
            }
            if ($input['billing']['country'] === '') {
                $countries = ShopShipping::countries($shop['shipping']);
                $input['billing']['country'] = in_array('GR', $countries, true) ? 'GR' : ($countries[0] ?? '');
            }
        }
        $errors = [];
        if ($post && isset($_POST['place'])) {
            $result = $this->checkout->place($input, $cart, $customer, $lang);
            if ($result['ok'] && $result['order'] !== null) {
                $order = $result['order'];
                $this->carts->delete($token);
                $this->countCookie(0);
                if ($customer !== null && ($customer['address']['address'] ?? '') === '') {
                    $this->customers->updateProfile($customer['id'], $customer['name'] !== '' ? $customer['name'] : ShopAddress::name($order['billing']), $customer['phone'] !== '' ? $customer['phone'] : (string)($order['billing']['phone'] ?? ''), $order['billing']);
                }
                // An order sent to pay online is told about once it is paid (vivaReturn, the webhook).
                if ($order['status'] !== 'pending_payment') {
                    $this->mail->orderPlaced($order, $this->orders->lines((int)$order['id']), $this->orderUrl($order), ($this->absoluteUrl)('admin/shop-orders?id=' . $order['id']));
                }
                $_SESSION['shop_orders'] = array_slice(array_merge([(string)$order['number']], (array)($_SESSION['shop_orders'] ?? [])), 0, 10);
                $this->marketing?->remember((int)$order['id'], $_SERVER, array_map('strval', array_filter($_COOKIE, 'is_scalar')), ($this->absoluteUrl)(($this->prefixFor)($lang) . 'checkout'));
                $this->go($result['redirect'] !== '' ? $result['redirect'] : $this->orderPath($order));
                return;
            }
            $errors = $result['errors'];
            if (in_array('cart_changed', $errors, true)) {
                $this->carts->save($token, $result['quote']['priced']['kept'] ?? $cart['lines'], $cart['coupon'], $cart['customer_id']);
            }
        }
        $address = !empty($input['ship_elsewhere']) && is_array($input['shipping'] ?? null) ? ShopAddress::clean($input['shipping']) : ShopAddress::clean(is_array($input['billing'] ?? null) ? $input['billing'] : []);
        $coupon = ShopCoupons::normalizeCode($input['coupon'] ?? $cart['coupon']);
        $quote = $this->checkout->quote($cart['lines'], $coupon, $address, (string)($input['method'] ?? ''), (string)($input['payment'] ?? ''), $lang, (string)($customer['email'] ?? ($input['email'] ?? '')));
        $prefix = ($this->prefixFor)($lang);
        ($this->render)('templates/shop/checkout.twig', $lang, [
            'document_title' => ($this->t)('shop.checkout', $lang),
            'cart' => $this->view($quote, $lang),
            'changes' => $this->changes($quote['priced']['changes'], $lang),
            'input' => [
                'email' => (string)($input['email'] ?? ''),
                'billing' => ShopAddress::clean(is_array($input['billing'] ?? null) ? $input['billing'] : []),
                'shipping' => ShopAddress::clean(is_array($input['shipping'] ?? null) ? $input['shipping'] : []),
                'ship_elsewhere' => !empty($input['ship_elsewhere']),
                'store' => (string)($input['store'] ?? ''),
                'coupon' => $coupon,
                'gift_wrap' => !empty($input['gift_wrap']),
                'gift_note' => (string)($input['gift_note'] ?? ''),
                'note' => (string)($input['note'] ?? ''),
                'terms' => !empty($input['terms']),
            ],
            'errors' => array_map(fn(string $e): string => $this->errorText($e, $lang), $errors),
            'error_fields' => $errors,
            'customer' => $customer,
            'countries' => ShopCountries::options($quote['countries'], $lang),
            'terms_url' => $shop['terms_page'] !== '' ? ($this->pageUrl)($shop['terms_page']) : '',
            'returns_url' => $shop['returns_page'] !== '' ? ($this->pageUrl)($shop['returns_page']) : '',
            'sign_in_url' => '/' . $prefix . 'account?return=checkout',
            'flash' => $this->takeFlash(),
            'shop_track' => $this->marketing !== null && $this->marketing->config()['pixel_id'] !== ''
                ? ShopMarketing::event('InitiateCheckout', $quote['priced']['lines'], (int)($quote['totals']['total'] ?? array_sum(array_column($quote['priced']['lines'], 'total_cents'))), (string)$shop['currency'])
                : null,
        ]);
    }

    private function orderPage(string $number, string $lang, string $key): void
    {
        $order = $this->orders->byNumber($number);
        $customer = $this->customer();
        $mine = $order !== null && (($key !== '' && hash_equals((string)$order['view_key'], $key)) || ($customer !== null && $order['customer_id'] === $customer['id']));
        if (!$mine) {
            ($this->notFound)();
            return;
        }
        ($this->render)('templates/shop/order.twig', $lang, [
            'document_title' => ($this->t)('shop.order', $lang) . ' ' . $order['number'],
            'order' => $this->orderView($order, $lang),
            'just_placed' => in_array($order['number'], (array)($_SESSION['shop_orders'] ?? []), true) && $key !== '',
            'pay_state' => in_array((string)($_GET['pay'] ?? ''), ['paid', 'failed', 'pending'], true) ? (string)$_GET['pay'] : '',
            // An order sent to pay online and not paid yet can be paid again while its stock is held.
            'can_pay' => $order['status'] === 'pending_payment' && (int)$order['reserve_until'] > time() && $this->checkout->provider((string)$order['payment_method']) !== null,
            'pay_url' => '/' . ($this->prefixFor)($lang) . 'checkout/order/' . rawurlencode((string)$order['number']) . '/pay',
            'downloads' => $this->downloads?->forOrder((int)$order['id']) ?? [],
            'shop_track' => $this->purchaseEvent($order),
            'key' => $key,
            'customer' => $customer,
            'flash' => $this->takeFlash(),
        ]);
    }

    /**
     * A paid order's purchase for the Meta Pixel, with the event id the server sends it with, so Meta counts it once. The page's
     * script sends it once per browser.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>|null
     */
    private function purchaseEvent(array $order): ?array
    {
        if ($this->marketing === null || $this->marketing->config()['pixel_id'] === '' || !in_array($order['status'], ['paid', 'processing', 'shipped', 'completed'], true)) {
            return null;
        }
        $lines = $this->marketing->withContentIds($this->orders->lines((int)$order['id']));
        return ShopMarketing::event('Purchase', $lines, (int)$order['total_cents'], (string)$order['currency'], ShopMarketing::purchaseId($order)) + ['once' => true];
    }

    // ---- paying online

    /** Pay now, on the page of an order still waiting for its payment: off to the provider again. */
    private function payAgain(string $number, string $lang, string $key): void
    {
        $order = $this->orders->byNumber($number);
        $customer = $this->customer();
        $mine = $order !== null && (($key !== '' && hash_equals((string)$order['view_key'], $key)) || ($customer !== null && $order['customer_id'] === $customer['id']));
        if (!$mine) {
            ($this->notFound)();
            return;
        }
        $result = $this->checkout->payAgain($order);
        if (!$result['ok']) {
            $this->flash('error', ($this->t)('shop.err_' . $result['error'], $lang));
            $this->go($this->orderPath($order));
            return;
        }
        $this->go($result['redirect']);
    }

    /**
     * Where Viva Wallet sends the customer back: `t` the transaction, `s` the payment order. What the address says is only a hint;
     * the transaction is asked of Viva before the order changes. The customer lands on the order's page either way.
     */
    private function vivaReturn(string $which): void
    {
        $settings = $this->vivaSettings();
        $reference = preg_replace('/\D/', '', (string)($_GET['s'] ?? '')) ?? '';
        $transaction = (string)($_GET['t'] ?? '');
        $order = null;
        $state = 'failed';
        if ($this->viva !== null && $transaction !== '') {
            $tx = $this->viva->transaction($settings, $transaction);
            if ($tx['ok'] && $reference !== '' && $tx['reference'] !== $reference) {
                $tx['ok'] = false;
            }
            $result = $this->checkout->confirm(Payments\VivaWallet::ID, $tx, 'viva');
            $order = $result['order'];
            $state = match ($result['outcome']) {
                'paid', 'already', 'late' => 'paid',
                'pending' => 'pending',
                default => 'failed',
            };
            if ($result['outcome'] === 'paid' && $order !== null) {
                $this->toldPaid($order);
            }
        }
        if ($order === null && $reference !== '') {
            $order = $this->checkout->orderOfPayment(Payments\VivaWallet::ID, $reference);
            if ($order !== null && $which === 'failure') {
                $this->checkout->paymentFailed(Payments\VivaWallet::ID, $reference, 'The customer came back without paying.');
            }
        }
        if ($order === null) {
            ($this->notFound)();
            return;
        }
        $this->go($this->orderPath($order) . '&pay=' . ($which === 'failure' && $state !== 'paid' ? 'failed' : $state));
    }

    /**
     * Viva Wallet's webhook: a GET is answered with the verification key; a POST is an event (1796 paid, 1798 failed), taken once by
     * its id and believed only after asking Viva for the transaction.
     */
    private function vivaWebhook(bool $post): void
    {
        $settings = $this->vivaSettings();
        if ($this->viva === null || empty($settings['enabled'])) {
            $this->json(['error' => 'off'], 404);
            return;
        }
        if (!$post) {
            $this->json(['Key' => $this->viva->webhookKey($settings)]);
            return;
        }
        $event = json_decode((string)file_get_contents('php://input'), true);
        $type = (int)($event['EventTypeId'] ?? 0);
        $transaction = (string)($event['EventData']['TransactionId'] ?? '');
        if (!in_array($type, [Payments\VivaWallet::EVENT_PAID, Payments\VivaWallet::EVENT_FAILED], true) || $transaction === '') {
            $this->json([]);
            return;
        }
        $key = $type . ':' . $transaction;
        $tx = $this->viva->transaction($settings, $transaction);
        if (!$tx['ok']) {
            // Viva calls again when it is not answered with 200; it is, once Viva can be asked.
            $this->json(['error' => 'not now'], 503);
            return;
        }
        $result = $this->checkout->confirm(Payments\VivaWallet::ID, $tx, 'viva webhook');
        $this->checkout->noteEvent(Payments\VivaWallet::ID, $key, 'webhook ' . $type, $result['order'] !== null ? (int)$result['order']['id'] : null, $result['outcome']);
        if ($result['outcome'] === 'paid' && $result['order'] !== null) {
            $this->toldPaid($result['order']);
        }
        $this->json([]);
    }

    /**
     * The step between the checkout and Cardlink's page. Its address is signed for the payment, so no one else's order can be shown
     * here. Where the amount allows installments, the customer picks how many; then the form (signed with the shared secret over
     * the fields, the installments among them) goes to the bank on its own.
     */
    private function cardlinkGo(bool $post): void
    {
        $settings = $this->providerSettings(Payments\Cardlink::ID);
        $reference = (string)($_REQUEST['ref'] ?? '');
        if ($this->cardlink === null || preg_match('/^[A-Za-z0-9]{4,50}$/', $reference) !== 1 || !$this->cardlink->signed($reference, (string)($_REQUEST['k'] ?? ''))) {
            ($this->notFound)();
            return;
        }
        $pending = $this->cardlink->pending($reference, $settings);
        if ($pending === null) {
            $order = $this->checkout->orderOfPayment(Payments\Cardlink::ID, $reference);
            $order !== null ? $this->go($this->orderPath($order)) : ($this->notFound)();
            return;
        }
        $order = $pending['order'];
        $lang = (string)$order['lang'];
        $max = $pending['max_installments'];
        $chosen = $post ? max(0, min($max, (int)($_POST['installments'] ?? 0))) : 0;
        $ready = $max < 2 || $post;
        ($this->render)('templates/shop/pay.twig', $lang, [
            'document_title' => ($this->t)('shop.pay_card', $lang),
            'order' => $this->orderView($order, $lang),
            'installments' => $max >= 2 ? range(2, $max) : [],
            'choose_url' => '/_shop/pay/cardlink/go?' . http_build_query(['ref' => $reference, 'k' => (string)$_REQUEST['k']]),
            'bank' => $ready ? $this->cardlink->form($order, $pending['payment'], $settings, $chosen) : null,
            'back_url' => $this->orderPath($order),
        ]);
    }

    /**
     * Where Cardlink posts the result (confirm or cancel, the same either way): believed only when its digest, made with the
     * shared secret, is right and it is for this merchant. The customer lands on the order's page.
     */
    private function cardlinkReturn(): void
    {
        $settings = $this->providerSettings(Payments\Cardlink::ID);
        if ($this->cardlink === null) {
            ($this->notFound)();
            return;
        }
        $tx = $this->cardlink->answer($_POST, $settings);
        $order = $this->checkout->orderOfPayment(Payments\Cardlink::ID, (string)$tx['reference']);
        if ($order === null) {
            ($this->notFound)();
            return;
        }
        if (!$tx['ok']) {
            $this->orders->addEvent((int)$order['id'], 'payment', 'An answer from the bank was ignored: ' . $tx['error'], 'cardlink');
            $this->go($this->orderPath($order) . '&pay=pending');
            return;
        }
        $result = $this->checkout->confirm(Payments\Cardlink::ID, $tx, 'cardlink');
        if ($tx['status'] === 'AUTHORIZED') {
            $this->orders->addEvent((int)$order['id'], 'payment', 'The bank only authorised the payment (' . $tx['transaction_id'] . '): capture it in Cardlink\'s back office, then mark the order paid.', 'cardlink');
        }
        if ($result['outcome'] === 'paid' && $result['order'] !== null) {
            $this->toldPaid($result['order']);
        }
        $state = match ($result['outcome']) {
            'paid', 'already', 'late' => 'paid',
            'pending' => 'pending',
            default => 'failed',
        };
        $this->go($this->orderPath($result['order'] ?? $order) . '&pay=' . $state);
    }

    /** @return array<string, mixed> */
    private function providerSettings(string $id): array
    {
        $settings = $this->settings->all()['payments'][$id] ?? [];
        return is_array($settings) ? $settings : [];
    }

    /**
     * A paid order's file, by its signed link: counted, then sent as an attachment. A link that can no longer be used says why
     * (turned off with a cancel or refund, used up, past its days); one that never was is not found.
     */
    private function download(string $token, string $lang, bool $head): void
    {
        $open = $this->downloads?->open($token) ?? ['ok' => false, 'reason' => 'gone', 'download' => null, 'file' => null];
        if ($open['reason'] === 'gone') {
            ($this->notFound)();
            return;
        }
        if ($open['ok'] && !$head && !$this->downloads->count($open['download']['id'])) {
            $open = ['ok' => false, 'reason' => 'used'] + $open;
        }
        if (!$open['ok']) {
            http_response_code(410);
            ($this->render)('templates/shop/download.twig', $lang, [
                'document_title' => ($this->t)('shop.download', $lang),
                'reason' => $open['reason'],
                'name' => $open['file']['name'] ?? '',
                'account_url' => '/' . ($this->prefixFor)($lang) . 'account',
            ]);
            return;
        }
        $file = $open['file'];
        $path = $this->downloads->path($file);
        $ascii = (string)preg_replace('/[^A-Za-z0-9._ ()-]/', '_', (string)$file['name']);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode((string)$file['name']));
        header('Content-Length: ' . (string)filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; sandbox');
        if (!$head) {
            readfile($path);
        }
    }

    /** @param array<string, mixed> $order the order is paid: the customer gets its email, the shop its notice */
    private function toldPaid(array $order): void
    {
        $this->mail->orderPlaced($order, $this->orders->lines((int)$order['id']), $this->orderUrl($order), ($this->absoluteUrl)('admin/shop-orders?id=' . $order['id']));
    }

    /** @return array<string, mixed> */
    private function vivaSettings(): array
    {
        $settings = $this->settings->all()['payments']['viva'] ?? [];
        return is_array($settings) ? $settings : [];
    }

    // ---- accounts

    /** @param string[] $segments the parts after /account */
    private function account(array $segments, string $lang, bool $post): void
    {
        $prefix = '/' . ($this->prefixFor)($lang);
        $action = $segments[0] ?? '';
        $customer = $this->customer();
        $shop = $this->settings->all();
        $return = in_array((string)($_REQUEST['return'] ?? ''), self::RETURNS, true) ? (string)$_REQUEST['return'] : 'account';

        if ($action === 'google' && !$post) {
            $this->googleSignIn($segments[1] ?? '', $lang, $return);
            return;
        }
        if ($action === 'signin' && $post) {
            $email = trim((string)($_POST['email'] ?? ''));
            $address = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $limit = $this->throttle->check($address, 'shop:' . mb_strtolower($email));
            if ($limit['blocked']) {
                $this->flash('error', ($this->t)('shop.err_too_many', $lang, ['minutes' => (string)max(1, (int)ceil($limit['retry_after'] / 60))]));
                $this->go($prefix . 'account?return=' . $return);
                return;
            }
            $found = $this->customers->authenticate($email, (string)($_POST['password'] ?? ''));
            if ($found === null || ($shop['accounts']['verify'] && !$found['verified'])) {
                if ($found === null) {
                    $this->throttle->recordFailure($address, 'shop:' . mb_strtolower($email));
                }
                $this->flash('error', ($this->t)($found === null ? 'shop.err_sign_in' : 'shop.err_not_verified', $lang));
                $this->go($prefix . 'account?return=' . $return);
                return;
            }
            $this->throttle->recordSuccess($address, 'shop:' . mb_strtolower($email));
            $this->signIn($found);
            $this->go($prefix . $return);
            return;
        }
        if ($action === 'signup' && $post) {
            if (!$shop['accounts']['signup']) {
                ($this->notFound)();
                return;
            }
            $email = trim((string)($_POST['email'] ?? ''));
            $created = $this->customers->create($email, (string)($_POST['password'] ?? ''), trim((string)($_POST['name'] ?? '')), $lang);
            if (!is_int($created)) {
                $this->flash('error', ($this->t)('shop.err_signup_' . $created, $lang));
                $this->go($prefix . 'account?return=' . $return . '#signup');
                return;
            }
            $new = $this->customers->get($created);
            $this->sendVerify($new, $lang);
            if ($shop['accounts']['verify']) {
                $this->flash('success', ($this->t)('shop.check_email', $lang, ['email' => $email]));
                $this->go($prefix . 'account');
                return;
            }
            $this->signIn($new);
            $this->flash('success', ($this->t)('shop.welcome', $lang));
            $this->go($prefix . $return);
            return;
        }
        if ($action === 'signout' && $post) {
            unset($_SESSION['shop_customer']);
            session_regenerate_id(true);
            $this->go($prefix . 'account');
            return;
        }
        if ($action === 'forgot') {
            if ($post) {
                $found = $this->customers->byEmail(trim((string)($_POST['email'] ?? '')));
                if ($found !== null) {
                    $token = $this->customers->issueToken($found['id'], 'reset');
                    $this->mail->accountLink('reset', $found['email'], $found['name'], $found['lang'] !== '' ? $found['lang'] : $lang, ($this->absoluteUrl)(($this->prefixFor)($found['lang'] !== '' ? $found['lang'] : $lang) . 'account/reset?token=' . $token));
                }
                // The same answer either way, so the form tells nobody who has an account.
                $this->flash('success', ($this->t)('shop.reset_sent', $lang));
                $this->go($prefix . 'account');
                return;
            }
            $this->accountPage($lang, 'forgot', ['return' => $return]);
            return;
        }
        if ($action === 'reset') {
            $token = (string)($_REQUEST['token'] ?? '');
            $found = $this->customers->byToken($token, 'reset');
            if ($found === null) {
                $this->flash('error', ($this->t)('shop.err_link', $lang));
                $this->go($prefix . 'account/forgot');
                return;
            }
            if ($post) {
                if (!$this->customers->setPassword($found['id'], (string)($_POST['password'] ?? ''))) {
                    $this->flash('error', ($this->t)('shop.err_signup_password', $lang));
                    $this->go($prefix . 'account/reset?token=' . $token);
                    return;
                }
                // Following the link proves the address too.
                $this->customers->markVerified($found['id']);
                $this->orders->claim($found['email'], $found['id']);
                $this->signIn($this->customers->get($found['id']) ?? $found);
                $this->flash('success', ($this->t)('shop.password_set', $lang));
                $this->go($prefix . 'account');
                return;
            }
            $this->accountPage($lang, 'reset', ['token' => $token]);
            return;
        }
        if ($action === 'verify') {
            $found = $this->customers->byToken((string)($_GET['token'] ?? ''), 'verify');
            if ($found === null) {
                $this->flash('error', ($this->t)('shop.err_link', $lang));
            } else {
                $this->customers->markVerified($found['id']);
                $claimed = $this->orders->claim($found['email'], $found['id']);
                $this->flash('success', ($this->t)('shop.verified', $lang) . ($claimed > 0 ? ' ' . ($this->t)('shop.claimed', $lang, ['count' => (string)$claimed]) : ''));
            }
            $this->go($prefix . 'account');
            return;
        }

        if ($customer === null) {
            if ($action !== '') {
                $this->go($prefix . 'account');
                return;
            }
            $this->accountPage($lang, 'signin', ['return' => $return, 'signup' => $shop['accounts']['signup'], 'google' => $this->googleReady()]);
            return;
        }

        if ($action === 'verify-again' && $post) {
            $this->sendVerify($customer, $lang);
            $this->flash('success', ($this->t)('shop.check_email', $lang, ['email' => $customer['email']]));
            $this->go($prefix . 'account');
            return;
        }
        if ($action === 'details' && $post) {
            $this->customers->updateProfile($customer['id'], (string)($_POST['name'] ?? ''), (string)($_POST['phone'] ?? ''), is_array($_POST['address'] ?? null) ? $_POST['address'] : []);
            $message = ($this->t)('shop.details_saved', $lang);
            $level = 'success';
            if ((string)($_POST['new_password'] ?? '') !== '') {
                if ($customer['has_password'] && $this->customers->authenticate($customer['email'], (string)($_POST['password'] ?? '')) === null) {
                    [$level, $message] = ['error', ($this->t)('shop.err_password', $lang)];
                } elseif (!$this->customers->setPassword($customer['id'], (string)$_POST['new_password'])) {
                    [$level, $message] = ['error', ($this->t)('shop.err_signup_password', $lang)];
                } else {
                    session_regenerate_id(true);
                    $message = ($this->t)('shop.password_set', $lang);
                }
            }
            $this->flash($level, $message);
            $this->go($prefix . 'account#details');
            return;
        }
        if ($action === 'delete' && $post) {
            // An account made with Google has no password: writing its address confirms instead.
            $confirmed = $customer['has_password']
                ? $this->customers->authenticate($customer['email'], (string)($_POST['password'] ?? '')) !== null
                : mb_strtolower(trim((string)($_POST['confirm_email'] ?? ''))) === mb_strtolower($customer['email']);
            if (!$confirmed) {
                $this->flash('error', ($this->t)('shop.err_password', $lang));
                $this->go($prefix . 'account#delete');
                return;
            }
            $this->orders->unlink($customer['id']);
            $this->customers->delete($customer['id']);
            unset($_SESSION['shop_customer']);
            session_regenerate_id(true);
            $this->flash('success', ($this->t)('shop.deleted', $lang));
            $this->go($prefix . 'account');
            return;
        }
        if ($action === 'subscriptions' && isset($segments[1], $segments[2]) && $post && $this->subscriptions !== null) {
            $id = (int)$segments[1];
            if ($segments[2] === 'cancel') {
                $result = $this->subscriptions->act($id, 'cancel', (int)$customer['id']);
                $this->flash($result['ok'] ? 'success' : 'error', ($this->t)($result['ok'] ? 'shop.sub_done' : 'shop.err_session', $lang));
                $this->go($prefix . 'account#subscriptions');
                return;
            }
            if ($segments[2] === 'pay') {
                $result = $this->subscriptions->payNow($id, (int)$customer['id']);
                if ($result['ok']) {
                    $this->go($result['redirect']);
                    return;
                }
                $this->flash('error', ($this->t)('shop.err_payment_start', $lang));
                $this->go($prefix . 'account#subscriptions');
                return;
            }
        }
        if ($action === 'orders' && isset($segments[1])) {
            $this->orderPage($segments[1], $lang, '');
            return;
        }
        if ($action !== '') {
            ($this->notFound)();
            return;
        }
        $this->accountPage($lang, 'home', [
            'orders' => array_map(fn(array $o): array => $this->orderView($o, $lang, false), $this->orders->forCustomer($customer['id'])),
            'downloads' => $this->downloads?->forOrders(array_column($this->orders->forCustomer($customer['id']), 'id')) ?? [],
            'subscriptions' => array_map(fn(array $s): array => $s + [
                'price' => ShopMoney::format($s['price_cents'] * $s['quantity'], $lang),
                'every' => ($this->t)('shop.every_' . $s['interval'] . ($s['interval_count'] > 1 ? 's' : ''), $lang, ['n' => (string)$s['interval_count']]),
                'status_text' => ($this->t)('shop.sub_' . $s['status'], $lang),
            ], $this->subscriptions?->forCustomer((int)$customer['id']) ?? []),
            'countries' => ShopCountries::options(ShopShipping::countries($shop['shipping']), $lang),
            'verify_pending' => !$customer['verified'],
        ]);
    }

    private function googleReady(): bool
    {
        return $this->google !== null && $this->google->config()['ready'];
    }

    /**
     * Signing in with Google. /account/google sends the person there with a state kept in the session (with the language and where
     * to go after); Google sends them back to /account/google/callback, where the state is checked, the code turned into a profile
     * and the profile into a customer (ShopCustomers::fromGoogle()). A way back that fails, or was cancelled at Google, says so on
     * the sign-in page.
     */
    private function googleSignIn(string $step, string $lang, string $return): void
    {
        if ($this->google === null || !$this->googleReady() || !in_array($step, ['', 'callback'], true)) {
            ($this->notFound)();
            return;
        }
        $config = $this->google->config();
        if ($step === '') {
            $state = bin2hex(random_bytes(16));
            $_SESSION['shop_google'] = ['state' => $state, 'lang' => $lang, 'return' => $return, 'at' => time()];
            $this->go($this->google->authorizationUrl($config, $state));
            return;
        }
        $kept = is_array($_SESSION['shop_google'] ?? null) ? $_SESSION['shop_google'] : [];
        unset($_SESSION['shop_google']);
        $lang = is_string($kept['lang'] ?? null) ? $kept['lang'] : $lang;
        $return = in_array($kept['return'] ?? '', self::RETURNS, true) ? (string)$kept['return'] : 'account';
        $back = '/' . ($this->prefixFor)($lang) . 'account?return=' . $return;
        $state = (string)($_GET['state'] ?? '');
        $code = (string)($_GET['code'] ?? '');
        if ($state === '' || !hash_equals((string)($kept['state'] ?? ''), $state) || time() - (int)($kept['at'] ?? 0) > 900 || $code === '') {
            $this->flash('error', ($this->t)('shop.err_google', $lang));
            $this->go($back);
            return;
        }
        $token = $this->google->exchange($code, $config);
        $profile = $token['ok'] ? $this->google->profile((string)$token['access_token']) : ['ok' => false];
        if (!$profile['ok']) {
            $this->flash('error', ($this->t)('shop.err_google', $lang));
            $this->go($back);
            return;
        }
        $accepted = $this->google->accept($profile, ['allowed_domain' => ''] + $config);
        if (!$accepted['ok']) {
            $this->flash('error', ($this->t)('shop.err_google_email', $lang));
            $this->go($back);
            return;
        }
        $name = is_string($profile['name'] ?? null) ? $profile['name'] : '';
        $found = $this->customers->fromGoogle($accepted['sub'], $accepted['email'], $name, $lang, $this->settings->all()['accounts']['signup']);
        if (is_string($found)) {
            $this->flash('error', ($this->t)($found === 'signup' ? 'shop.err_google_signup' : 'shop.err_google', $lang));
            $this->go($back);
            return;
        }
        $this->orders->claim($found['email'], $found['id']);
        $this->signIn($found);
        if ($found['new']) {
            $this->flash('success', ($this->t)('shop.welcome', $lang));
        }
        $this->go('/' . ($this->prefixFor)($lang) . $return);
    }

    /** @param array<string, mixed> $data */
    private function accountPage(string $lang, string $view, array $data): void
    {
        ($this->render)('templates/shop/account.twig', $lang, $data + [
            'document_title' => ($this->t)('shop.account', $lang),
            'view' => $view,
            'customer' => $this->customer(),
            'flash' => $this->takeFlash(),
        ]);
    }

    /** @param array<string, mixed> $customer */
    private function sendVerify(?array $customer, string $lang): void
    {
        if ($customer === null || $customer['verified']) {
            return;
        }
        $token = $this->customers->issueToken($customer['id'], 'verify');
        $this->mail->accountLink('verify', $customer['email'], $customer['name'], $lang, ($this->absoluteUrl)(($this->prefixFor)($lang) . 'account/verify?token=' . $token));
    }

    /** @param array<string, mixed> $customer */
    private function signIn(array $customer): void
    {
        session_regenerate_id(true);
        $_SESSION['shop_customer'] = (int)$customer['id'];
        $token = $this->token(false);
        if ($token !== '') {
            $this->carts->adopt($token, (int)$customer['id']);
        }
    }

    /** @return array<string, mixed>|null */
    private function customer(): ?array
    {
        $id = (int)($_SESSION['shop_customer'] ?? 0);
        if ($id < 1) {
            return null;
        }
        $customer = $this->customers->get($id);
        if ($customer === null) {
            unset($_SESSION['shop_customer']);
        }
        return $customer;
    }

    // ---- what the templates are given

    /**
     * @param array<string, mixed> $quote ShopCheckout::quote()
     * @return array<string, mixed>
     */
    private function view(array $quote, string $lang): array
    {
        $money = static fn(int $cents): string => ShopMoney::format($cents, $lang);
        $totals = $quote['totals'];
        return [
            'lines' => array_map(static fn(array $l): array => $l + ['unit' => $money($l['unit_cents']), 'total' => $money($l['total_cents'])], $quote['priced']['lines']),
            'count' => $quote['priced']['count'],
            'methods' => array_map(static fn(array $m): array => $m + ['price' => $m['cents'] === 0 ? '' : $money($m['cents'])], $quote['methods']),
            'method' => $quote['method'],
            'payments' => array_map(static fn(array $p): array => $p + ['price' => $p['fee'] > 0 ? $money($p['fee']) : ''], $quote['payments']),
            'payment' => $quote['payment'],
            'coupon' => $quote['coupon'],
            'has_zone' => $quote['zone'] !== null,
            'digital_only' => !empty($quote['digital_only']),
            'gift_wrap' => array_filter($quote['priced']['lines'], static fn(array $l): bool => $l['gift_wrap']) !== [],
            'items' => $money($totals['items']),
            'discount' => $totals['discount'] > 0 ? $money(-$totals['discount']) : '',
            'shipping' => $quote['method'] !== null ? ($totals['shipping'] === 0 ? ($this->t)('shop.free', $lang) : $money($totals['shipping'])) : '',
            'fee' => $totals['fee'] > 0 ? $money($totals['fee']) : '',
            'tax' => $money($totals['tax']),
            'total' => $money($totals['total']),
        ];
    }

    /** What a mistake at the checkout says: a missing field by its name, the others by their own sentence. */
    private function errorText(string $error, string $lang): string
    {
        if (preg_match('/^(billing|shipping)_(.+)$/', $error, $m) === 1) {
            $label = ['address' => 'street', 'address_2' => 'street_2'][$m[2]] ?? $m[2];
            return ($this->t)('shop.err_field', $lang, ['field' => ($this->t)('shop.' . $label, $lang)]) . ($m[1] === 'shipping' ? ' (' . ($this->t)('shop.ship_to', $lang) . ')' : '');
        }
        return ($this->t)('shop.err_' . $error, $lang);
    }

    /**
     * @param list<array{title: string, reason: string}> $changes
     * @return string[]
     */
    private function changes(array $changes, string $lang): array
    {
        return array_map(fn(array $c): string => ($this->t)('shop.change_' . $c['reason'], $lang, ['title' => $c['title']]), $changes);
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function orderView(array $order, string $lang, bool $lines = true): array
    {
        $money = static fn(int $cents): string => ShopMoney::format($cents, $lang);
        $provider = $this->checkout->provider((string)$order['payment_method']);
        $settings = $this->settings->all()['payments'][(string)$order['payment_method']] ?? [];
        return [
            'number' => $order['number'],
            'status' => $order['status'],
            'status_label' => ($this->t)('shop.status_' . $order['status'], $lang),
            'date' => date('d/m/Y', (int)$order['created_at']),
            'url' => '/' . ($this->prefixFor)($lang) . 'account/orders/' . rawurlencode((string)$order['number']),
            'total' => $money((int)$order['total_cents']),
            'items' => $money((int)$order['items_cents']),
            'discount' => (int)$order['discount_cents'] > 0 ? $money(-(int)$order['discount_cents']) : '',
            'coupon' => $order['coupon'],
            'shipping' => (int)$order['shipping_cents'] === 0 ? ($this->t)('shop.free', $lang) : $money((int)$order['shipping_cents']),
            'shipping_label' => $order['shipping_label'],
            'fee' => (int)$order['fee_cents'] > 0 ? $money((int)$order['fee_cents']) : '',
            'tax' => $money((int)$order['tax_cents']),
            'payment' => ($this->t)('shop.pay_' . $order['payment_method'], $lang),
            'instructions' => $order['status'] === 'on_hold' && $provider !== null ? $provider->instructions(is_array($settings) ? $settings : []) : [],
            'billing' => ShopAddress::lines($order['billing'], $lang),
            'ship_to' => ShopAddress::lines($order['shipping'], $lang),
            'email' => $order['email'],
            'carrier' => $order['carrier'],
            'tracking' => $order['tracking'],
            'gift_note' => $order['gift_note'],
            'gift_wrap' => (bool)$order['gift_wrap'],
            'lines' => $lines ? array_map(static fn(array $l): array => $l + ['unit' => $money($l['unit_cents']), 'total' => $money($l['total_cents'])], $this->orders->lines((int)$order['id'])) : [],
        ];
    }

    /** @param array<string, mixed> $order */
    private function orderPath(array $order): string
    {
        return '/' . ($this->prefixFor)((string)$order['lang']) . 'checkout/order/' . rawurlencode((string)$order['number']) . '?key=' . $order['view_key'];
    }

    /** @param array<string, mixed> $order */
    private function orderUrl(array $order): string
    {
        return ($this->absoluteUrl)(ltrim($this->orderPath($order), '/'));
    }

    // ---- cookies, answers

    /** The cart's cookie; a new one when asked to and there is none. */
    private function token(bool $create): string
    {
        $token = (string)($_COOKIE[ShopCart::COOKIE] ?? '');
        if (ShopCart::validToken($token)) {
            return $token;
        }
        if (!$create) {
            return '';
        }
        $token = ShopCart::newToken();
        $_COOKIE[ShopCart::COOKIE] = $token;
        if (!headers_sent()) {
            setcookie(ShopCart::COOKIE, $token, ['expires' => time() + ShopCart::DAYS * 86400, 'path' => '/', 'secure' => $this->https, 'httponly' => true, 'samesite' => 'Lax']);
        }
        return $token;
    }

    private function countCookie(int $count): void
    {
        if (headers_sent() || (string)($_COOKIE[ShopCart::COUNT_COOKIE] ?? '') === (string)$count) {
            return;
        }
        setcookie(ShopCart::COUNT_COOKIE, (string)$count, ['expires' => $count > 0 ? time() + ShopCart::DAYS * 86400 : time() - 3600, 'path' => '/', 'secure' => $this->https, 'httponly' => false, 'samesite' => 'Lax']);
    }

    /** Whether a request was sent by this site's own pages (Origin, or Referer when a browser sends no Origin). */
    private function sameOrigin(): bool
    {
        $from = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($from === '' || $from === 'null') {
            $from = (string)($_SERVER['HTTP_REFERER'] ?? '');
        }
        $host = strtolower((string)parse_url($from, PHP_URL_HOST));
        $port = parse_url($from, PHP_URL_PORT);
        $mine = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        return $host !== '' && ($port ? $host . ':' . $port : $host) === $mine;
    }

    private function flash(string $level, string $message): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['shop_flash'] = ['level' => $level, 'message' => $message];
        }
    }

    /** @return array{level: string, message: string}|null */
    private function takeFlash(): ?array
    {
        $flash = $_SESSION['shop_flash'] ?? null;
        unset($_SESSION['shop_flash']);
        return is_array($flash) ? $flash : null;
    }

    private function back(string $rest, string $lang): string
    {
        $base = explode('/', $rest)[0];
        return '/' . ($this->prefixFor)($lang) . (in_array($base, ['cart', 'checkout', 'account'], true) ? $base : 'cart');
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function go(string $path): void
    {
        header('Location: ' . $path, true, 303);
    }
}
