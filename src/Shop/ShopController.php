<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\ActionLimit;
use GnozioCMS\Auth;
use GnozioCMS\ContentItem;
use GnozioCMS\ContentPaths;
use GnozioCMS\ContentRepository;
use GnozioCMS\CronStatus;
use GnozioCMS\FieldSchema;
use GnozioCMS\FrontCache;
use GnozioCMS\FrontRoute;
use GnozioCMS\GoogleSignIn;
use GnozioCMS\ImportedRedirects;
use GnozioCMS\JobRunner;
use GnozioCMS\LoginThrottle;
use GnozioCMS\MediaLibrary;
use GnozioCMS\RedirectRepository;
use GnozioCMS\RelationFields;
use GnozioCMS\Relations;
use GnozioCMS\SecretBox;
use GnozioCMS\SystemDatabase;
use GnozioCMS\SystemMetaRepository;
use GnozioCMS\Taxonomies;
use GnozioCMS\WooCommerceImporter;
use GnozioCMS\WooCommerceReader;
use GnozioCMS\WordPressHttp;

/**
 * The shop as the application sees it: its services, its pages (cart, checkout, account), the product feed, the work that does
 * not wait for a shopper, and the Shop screens of the admin. App routes to it and gives it what it needs of the rest of the site
 * as functions, so this class holds no copy of the site's state. Who may open which screen stays PermissionService's.
 */
final class ShopController
{
    /** The Shop screens of the admin, by the address after /admin, with the method that draws each. */
    public const ADMIN_SCREENS = [
        'shop-settings' => 'settingsScreen',
        'shop-orders' => 'ordersScreen',
        'shop-customers' => 'customersScreen',
        'shop-coupons' => 'couponsScreen',
        'shop-files' => 'filesScreen',
        'shop-subscriptions' => 'subscriptionsScreen',
        'shop-import' => 'importScreen',
    ];

    private ?ShopServices $servicesInstance = null;
    private ?ShopSettingsAdmin $settingsAdminInstance = null;
    private ?WooCommerceImport $wooImportInstance = null;

    /**
     * @param \Closure(): array<string, mixed> $settings the site's settings as they are now
     * @param \Closure(string, string, array<string, mixed>): string $shopText a string of the theme's, with its {placeholders} filled
     * @param \Closure(): ShopStock $shopStock
     * @param \Closure(): ShopSettings $shopSettings
     * @param \Closure(bool): ShopCatalogue $shopCatalogue with drafts for someone signed in, or not
     * @param \Closure(string, string, string, array<int, string>, ?string): bool $sendEmailMessage to, subject, text, headers, html
     * @param \Closure(): SecretBox $secretBox
     * @param \Closure(string, string, ?string, ?string, ?string, array<string, mixed>): void $logActivity
     * @param \Closure(string, array<string, mixed>): void $render a template with its data
     * @param \Closure(): void $render404
     * @param \Closure(string): void $redirect
     * @param \Closure(string): string $siteLink an address of the site as a visitor follows it
     * @param \Closure(string): string $buildAbsoluteUrl
     * @param \Closure(): string $getBaseUrl
     * @param \Closure(string): void $setLanguage the language the page is drawn in
     * @param \Closure(): void $configureSession the session's cookie and lifetime, before it starts
     * @param \Closure(bool, bool): void $sendSecurityHeaders admin, shop
     * @param \Closure(): bool $isValidCsrfRequest
     * @param \Closure(): bool $isHttpsRequest
     * @param \Closure(string, string): array<string, mixed> $themeMenus the theme's menus for a language and address, for the visitor
     * @param \Closure(): string $currentUsername
     * @param \Closure(): Relations $relations
     * @param \Closure(): Taxonomies $taxonomies
     * @param \Closure(): JobRunner $jobRunner
     * @param \Closure(): void $contentChanged the content index made again and the kept pages emptied (after an import)
     * @param \Closure(): string $visitorAddress the visitor's address for the shop's limits, '' when it cannot be told
     */
    public function __construct(
        private string $basePath,
        private string $contentDir,
        private ContentRepository $content,
        private Auth $auth,
        private SystemDatabase $systemDatabase,
        private SystemMetaRepository $systemMeta,
        private LoginThrottle $loginThrottle,
        private MediaLibrary $media,
        private RedirectRepository $redirects,
        private \Closure $settings,
        private \Closure $shopText,
        private \Closure $shopStock,
        private \Closure $shopSettings,
        private \Closure $shopCatalogue,
        private \Closure $sendEmailMessage,
        private \Closure $secretBox,
        private \Closure $logActivity,
        private \Closure $render,
        private \Closure $render404,
        private \Closure $redirect,
        private \Closure $siteLink,
        private \Closure $buildAbsoluteUrl,
        private \Closure $getBaseUrl,
        private \Closure $setLanguage,
        private \Closure $configureSession,
        private \Closure $sendSecurityHeaders,
        private \Closure $isValidCsrfRequest,
        private \Closure $isHttpsRequest,
        private \Closure $themeMenus,
        private \Closure $currentUsername,
        private \Closure $relations,
        private \Closure $taxonomies,
        private \Closure $jobRunner,
        private \Closure $contentChanged,
        private \Closure $visitorAddress,
    ) {
    }

    /**
     * The shop's work that does not wait for a shopper: orders whose time to be paid ran out, passwords brought from WordPress
     * and never used here in six months, cleared, the held stock checked against the open orders, renewals stuck half way put back, and subscriptions whose period has come, charged again (docs/shop-plan.md, section 7). From cron, and, at most once an hour, from the shop's admin screens.
     */
    public function tick(bool $throttle = true): int
    {
        try {
            if (!Shop::enabled($this->content->getTypes())) {
                return 0;
            }
            if ($throttle && (int)$this->systemMeta->get('shop_tick_at') >= time() - 3600) {
                return 0;
            }
            $this->systemMeta->set('shop_tick_at', (string)time());
            $services = $this->services();
            $services->checkout()->expire();
            $cleared = $services->customers()->clearStaleImportedHashes();
            if ($cleared > 0) {
                $this->logActivity('shop.imported_passwords_cleared', 'info', 'shop', null, $cleared . ' password(s) brought from WordPress and unused for six months were cleared; those customers set a new one by email.', ['count' => $cleared]);
            }
            $orders = $services->orders();
            foreach ($this->shopStock()->reconcile($orders->heldLines()) as $fix) {
                $this->logActivity('shop.stock_reconciled', 'warning', 'shop', $fix['product_id'], 'The stock held for product ' . $fix['product_id'] . ($fix['variant_key'] !== '' ? ' (' . $fix['variant_key'] . ')' : '') . ' was ' . $fix['was'] . ' but the open orders hold ' . $fix['now'] . '; it was put right.', $fix);
            }
            foreach ($services->subscriptions()->recoverStuck() as $stuck) {
                $this->logActivity('shop.subscription_stuck', 'warning', 'shop', (string)$stuck['id'], 'Subscription #' . $stuck['id'] . '\'s renewal stopped half way and it was paused. Check whether the card was charged' . ($stuck['order'] !== '' ? ' for order ' . $stuck['order'] : '') . ', then resume it.', $stuck);
            }
            return $services->subscriptions()->renewDue();
        } catch (\Throwable $e) {
            $this->logActivity('shop.tick', 'error', 'shop', null, 'The shop\'s scheduled work failed: ' . $e->getMessage(), []);
            return 0;
        }
    }

    /** System > Shop settings, the screen's actions (src/Shop/ShopSettingsAdmin.php). */
    public function settingsAdmin(): ShopSettingsAdmin
    {
        return $this->settingsAdminInstance ??= new ShopSettingsAdmin(
            $this->shopSettings(),
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context),
            function (): array {
                $pages = [];
                foreach ($this->content->getItems('pages', $this->defaultLanguage(), true) as $page) {
                    $pages[$page->slug] = (string)($page->meta['title'] ?? $page->slug);
                }
                asort($pages);
                return $pages;
            },
            array_values(array_unique([$this->defaultLanguage(), ...($this->settings()['languages']['available'] ?? [])])),
            $this->services()->secrets(),
            $this->services()->viva(),
            $this->siteAddress(),
            $this->services()->cardlink(),
            $this->services()->mail(),
            fn(string $key, string $lang): string => $this->shopText($key, $lang),
            $this->services()->marketing()
        );
    }

    /** /feeds/products.xml (?lang= for another language of the site): the shop's products for Meta and Google (ShopFeed). */
    public function productFeed(): void
    {
        $languages = $this->siteLanguageCodes();
        $lang = in_array((string)($_GET['lang'] ?? ''), $languages, true) ? (string)$_GET['lang'] : $this->defaultLanguage();
        $feed = new ShopFeed(
            $this->shopCatalogue(),
            $this->shopSettings(),
            fn(string $forLang): array => $this->content->getItems('products', $forLang, false, false),
            fn(ContentItem $product): string => '/' . ContentPaths::build($product->type, $product->slug, $product->lang, (string)(($this->settings()['home_page'] ?? '') !== '' ? $this->settings()['home_page'] : 'index'), $this->defaultLanguage()),
            $this->getBaseUrl(),
            (string)($this->settings()['title'] ?? '')
        );
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=900');
        echo $feed->xml($lang);
    }

    /** The shop's parts (src/Shop/ShopServices.php). */
    public function services(): ShopServices
    {
        return $this->servicesInstance ??= new ShopServices(
            $this->systemDatabase,
            $this->shopStock(),
            $this->shopSettings(),
            fn(): ShopCatalogue => $this->shopCatalogue(),
            fn(ContentItem $product): string => '/' . ContentPaths::build($product->type, $product->slug, $product->lang, (string)(($this->settings()['home_page'] ?? '') !== '' ? $this->settings()['home_page'] : 'index'), $this->defaultLanguage()),
            fn(string $key, string $lang, array $values = []): string => $this->shopText($key, $lang, $values),
            fn(string $to, string $subject, string $body, array $headers, ?string $html = null): bool => $this->sendEmailMessage($to, $subject, $body, $headers, $html),
            $this->settings,
            $this->defaultLanguage(),
            $this->systemMeta,
            null,
            $this->siteAddress(),
            $this->secretBox()
        );
    }

    /**
     * The shop's page an address is, while the shop is on: the address after its language prefix, and the language.
     *
     * @return array{rest: string, lang: string}|null
     */
    public function route(string $path): ?array
    {
        if (!Shop::enabled($this->content->getTypes())) {
            return null;
        }
        $parts = explode('/', $path, 2);
        $lang = $this->defaultLanguage();
        $rest = $path;
        if (in_array($parts[0], $this->settings()['languages']['available'] ?? [], true) && $parts[0] !== $lang) {
            $lang = $parts[0];
            $rest = $parts[1] ?? '';
        }
        return ShopFront::owns($rest) ? ['rest' => $rest, 'lang' => $lang] : null;
    }

    /** The cart, the checkout and the customer's account (src/Shop/ShopFront.php). */
    public function front(string $rest, string $lang): void
    {
        if (ShopFront::needsSession($rest) && session_status() !== PHP_SESSION_ACTIVE) {
            session_name(ShopFront::SESSION_NAME);
            $this->configureSession();
            session_start();
        }
        FrontCache::doNotKeep();
        $this->sendSecurityHeaders(false, true);
        $this->setLanguage($lang);
        $prefix = $this->langPrefix($lang);
        $services = $this->services();
        $front = new ShopFront(
            $services->cart(),
            $services->pricing(),
            $services->checkout(),
            $services->orders(),
            $services->customers(),
            $services->settings(),
            $services->mail(),
            $this->loginThrottle,
            function (string $template, string $pageLang, array $data) use ($rest, $prefix): void {
                $this->render($template, $data + [
                    'lang' => $pageLang,
                    'lang_prefix' => $prefix,
                    'current_lang' => $pageLang,
                    'path_no_lang' => $rest,
                    'canonical_url' => $this->buildAbsoluteUrl($prefix . $rest),
                    'theme_menus' => ($this->themeMenus)($pageLang, $rest),
                    'noindex_page' => true,
                    'shop_page' => explode('/', $rest)[0],
                ]);
            },
            fn(string $key, string $textLang, array $values = []): string => $this->shopText($key, $textLang, $values),
            fn(string $path): string => $this->siteLink($path),
            fn(string $forLang): string => $this->langPrefix($forLang),
            fn(): bool => $this->isValidCsrfRequest(),
            fn() => $this->render404(),
            fn(string $slug): string => '/' . $prefix . $slug,
            $this->isHttpsRequest(),
            $services->viva(),
            $services->cardlink(),
            $services->downloads(),
            $services->subscriptions(),
            $services->marketing(),
            $this->google(),
            new ActionLimit($this->systemDatabase),
            ($this->visitorAddress)()
        );
        // Orders whose time to be paid ran out give their stock back before a shopper sees it (Shop › Orders does too). Where
        // the cron job runs (seen in the last quarter of an hour) it does that every five minutes, so only the checkout, which
        // must not miss a piece just let go, asks here (audit P8).
        $lastCron = strtotime((string)($this->systemMeta->get(CronStatus::META_KEY) ?? '')) ?: 0;
        if ($lastCron < time() - 900 || str_starts_with($rest, 'checkout')) {
            $services->checkout()->expire();
        }
        $front->handle($rest, $lang);
    }

    /**
     * Customers signing in with Google (Shop settings › Accounts): the shop's own client, or the admin's Google sign-in client
     * (Settings) when the shop's client ID is blank. Google sends them back to /account/google/callback.
     */
    private function google(): GoogleSignIn
    {
        return new GoogleSignIn(
            function (): array {
                $accounts = $this->services()->settings()->all()['accounts'];
                $secret = $this->services()->secrets()->of('google')['client_secret'] ?? '';
                $admin = is_array($this->settings()['auth']['google'] ?? null) ? $this->settings()['auth']['google'] : [];
                $own = $accounts['google_client_id'] !== '';
                return ['auth' => ['google' => [
                    'enabled' => $accounts['google'],
                    'client_id' => $own ? $accounts['google_client_id'] : (string)($admin['client_id'] ?? ''),
                    'client_secret' => $own ? $secret : (string)($admin['client_secret'] ?? ''),
                ]]];
            },
            fn(string $path): string => $this->buildAbsoluteUrl($path),
            null,
            '/account/google/callback'
        );
    }

    public function admin(): ShopAdmin
    {
        return new ShopAdmin(
            $this->services(),
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context),
            fn(array $order): string => $this->siteLink($this->langPrefix((string)$order['lang']) . 'checkout/order/' . rawurlencode((string)$order['number']) . '?key=' . $order['view_key'])
        );
    }

    /** The data every Shop screen of the admin is drawn with. */
    private function screen(string $section, string $title): array
    {
        return ['title' => $title, 'types' => $this->content->getTypes(), 'user' => $this->auth->user(), 'admin_section' => $section, 'current_type' => 'pages'];
    }

    /** Shop > Orders (docs/shop-plan.md, section 8): the list, one order, its packing slip, the CSV. */
    public function ordersScreen(): void
    {
        if (!Shop::enabled($this->content->getTypes())) {
            $this->redirect('/admin/content-types?shop=off');
            return;
        }
        $admin = $this->admin();
        $id = (int)($_GET['id'] ?? 0);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $id > 0) {
            $result = $admin->orderAction($id, $_POST, $this->currentUsername());
            $this->redirect('/admin/shop-orders?' . http_build_query(['id' => $id, 'done' => $result['ok'] ? 'ok' : 'fail', 'msg' => $result['message']]));
            return;
        }
        if (($_GET['export'] ?? '') === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="orders-' . date('Y-m-d') . '.csv"');
            echo $admin->csv($_GET);
            return;
        }
        if ($id > 0) {
            $order = $admin->order($id);
            if ($order === null) {
                $this->redirect('/admin/shop-orders');
                return;
            }
            $template = isset($_GET['print']) ? '@admin/shop-packing-slip.twig' : '@admin/shop-order.twig';
            $this->render($template, $order + $this->screen('shop-orders', 'Order ' . $order['order']['number']) + [
                'done' => (string)($_GET['done'] ?? ''),
                'message' => mb_substr((string)($_GET['msg'] ?? ''), 0, 300),
            ]);
            return;
        }
        $this->render('@admin/shop-orders.twig', $admin->orders($_GET) + $this->screen('shop-orders', 'Orders'));
    }

    /** Shop > Customers. */
    public function customersScreen(): void
    {
        if (!Shop::enabled($this->content->getTypes())) {
            $this->redirect('/admin/content-types?shop=off');
            return;
        }
        $admin = $this->admin();
        $id = (int)($_GET['id'] ?? 0);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
            $ok = $admin->deleteCustomer((int)($_POST['id'] ?? 0));
            $this->redirect('/admin/shop-customers?' . http_build_query(['done' => $ok ? 'ok' : 'fail', 'msg' => $ok ? 'The customer\'s account is deleted. Their orders stay.' : 'There is no such customer.']));
            return;
        }
        $view = $id > 0 ? $admin->customer($id) : $admin->customers($_GET);
        if ($view === null) {
            $this->redirect('/admin/shop-customers');
            return;
        }
        $this->render('@admin/shop-customers.twig', $view + $this->screen('shop-customers', 'Customers') + [
            'done' => (string)($_GET['done'] ?? ''),
            'message' => mb_substr((string)($_GET['msg'] ?? ''), 0, 300),
        ]);
    }

    /** Shop > Coupons. */
    public function couponsScreen(): void
    {
        if (!Shop::enabled($this->content->getTypes())) {
            $this->redirect('/admin/content-types?shop=off');
            return;
        }
        $admin = $this->admin();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            // The product picker sends ids, or `@products/slug` for a product that has none yet: the products' own relation field
            // (Complete the look) turns them into ids the same way the content editor does.
            $post = $_POST;
            $post['products'] = RelationFields::idList($this->relations()->normalize(Shop::TYPE, ['complete_the_look' => is_array($_POST['products'] ?? null) ? $_POST['products'] : []])['complete_the_look'] ?? []);
            $result = $admin->couponAction($post);
            $query = ['done' => $result['ok'] ? 'ok' : 'fail', 'msg' => $result['message']];
            if (!$result['ok'] && ($_POST['action'] ?? '') !== 'delete') {
                $query += (string)($_POST['original'] ?? '') !== '' ? ['code' => (string)$_POST['original']] : ['new' => 1];
            }
            $this->redirect('/admin/shop-coupons?' . http_build_query($query));
            return;
        }
        $collections = [];
        foreach ($this->taxonomies()->load(Shop::COLLECTIONS)['terms'] as $term) {
            $collections[$term['id']] = $this->taxonomies()->label(Shop::COLLECTIONS, $term['id'], $this->defaultLanguage());
        }
        $view = $admin->coupons($_GET, $collections);
        // The product picker: the relation field of the content editor, with the titles of the products already chosen.
        $view['product_field'] = $this->relations()->withLabels(
            FieldSchema::normalize(['products' => ['type' => 'relation', 'types' => [Shop::TYPE], 'multiple' => true, 'label' => 'Products', 'placeholder' => 'Add a product: search by name…']]),
            ['products' => $view['editing']['products'] ?? []],
            $this->defaultLanguage()
        )['products'];
        $this->render('@admin/shop-coupons.twig', $view + $this->screen('shop-coupons', 'Coupons') + [
            'done' => (string)($_GET['done'] ?? ''),
            'message' => mb_substr((string)($_GET['msg'] ?? ''), 0, 300),
        ]);
    }

    /** Shop > Subscriptions (docs/shop-plan.md, section 7): the list by status, one with its orders, pause, resume, skip, cancel. */
    public function subscriptionsScreen(): void
    {
        if (!Shop::enabled($this->content->getTypes())) {
            $this->redirect('/admin/content-types?shop=off');
            return;
        }
        $admin = $this->admin();
        $id = (int)($_GET['id'] ?? 0);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $id > 0) {
            $result = $admin->subscriptionAction($id, $_POST, $this->currentUsername());
            $this->redirect('/admin/shop-subscriptions?' . http_build_query(['id' => $id, 'done' => $result['ok'] ? 'ok' : 'fail', 'msg' => $result['message']]));
            return;
        }
        $this->tick();
        $this->render('@admin/shop-subscriptions.twig', $admin->subscriptions($_GET) + $this->screen('shop-subscriptions', 'Subscriptions') + [
            'done' => (string)($_GET['done'] ?? ''),
            'message' => mb_substr((string)($_GET['msg'] ?? ''), 0, 300),
        ]);
    }

    /** The WooCommerce import as a screen (Shop > Import). */
    public function wooImport(): WooCommerceImport
    {
        return $this->wooImportInstance ??= new WooCommerceImport(
            $this->basePath . '/storage/import/woocommerce',
            $this->jobRunner(),
            $this->services()->secrets(),
            function (string $site, string $key, string $secret, array $options): WooCommerceImporter {
                // A shop on this server or its network is read only when the import screen's box says so (audit S18).
                WordPressHttp::$allowLocal = !empty($options['allow_local']);
                return new WooCommerceImporter(
                    new WooCommerceReader($site, static fn(string $url, array $headers): ?array => WordPressHttp::get($url, $headers), $key, $secret),
                    $this->contentDir,
                    $this->media,
                    $this->taxonomies(),
                    static fn(string $url, string $to): int => WordPressHttp::download($url, $to),
                    $this->shopStock(),
                    $this->services()->customers(),
                    $options + ['default_lang' => $this->defaultLanguage()]
                );
            },
            function (array $redirects, bool $apply): array {
                $result = ['added' => 0, 'updated' => 0, 'kept' => 0];
                if ($apply && $redirects !== []) {
                    $result = array_intersect_key((new ImportedRedirects($this->redirects))->apply($redirects, true, 'woocommerce-import'), $result);
                }
                ($this->contentChanged)();
                return $result;
            },
            fn(string $action, string $level, string $message) => $this->logActivity($action, $level, 'shop', null, $message, [])
        );
    }

    /**
     * The WooCommerce import's requests (WooCommerceImport); its screen is Shop settings > Import & export, where the forms and a
     * plain visit come back to. While the shop is read or imported, the
     * screen asks ?work again and again, which works the next slice of the job, and ?status every second, which only says how far
     * it is (the session is let go first, so the two do not wait for each other).
     */
    public function importScreen(): void
    {
        if (!Shop::enabled($this->content->getTypes())) {
            $this->redirect('/admin/content-types?shop=off');
            return;
        }
        $import = $this->wooImport();
        if (isset($_GET['work']) || isset($_GET['status'])) {
            @session_write_close();
            if (isset($_GET['work']) && array_sum(array_map(fn(string $k): int => $this->jobRunner()->pending($k, true), WooCommerceImport::kinds())) > 0) {
                if (function_exists('set_time_limit')) {
                    @set_time_limit(900);
                }
                $this->jobRunner()->work(microtime(true) + 25, WooCommerceImport::kinds(), 1);
            }
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode($import->status(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }
        if (isset($_GET['download'])) {
            $file = $import->reportFile((string)$_GET['download']);
            if ($file === null) {
                $this->redirect('/admin/shop-settings?tab=import');
                return;
            }
            header('Content-Type: ' . (str_ends_with($file, '.json') ? 'application/json' : 'text/plain') . '; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . basename($file) . '"');
            header('Cache-Control: no-store');
            readfile($file);
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $result = match ((string)($_POST['action'] ?? '')) {
                'start' => $import->start($_POST, is_array($_FILES['customers'] ?? null) ? $_FILES['customers'] : null, $this->importLanguages()),
                'confirm' => $import->confirm($_POST),
                'reset' => $import->reset(),
                default => ['ok' => false, 'message' => 'Unknown action.'],
            };
            $this->redirect('/admin/shop-settings?' . http_build_query(['tab' => 'import'] + ($result['ok'] ? [] : ['done' => 'fail', 'msg' => $result['message']])));
            return;
        }
        $this->redirect('/admin/shop-settings?tab=import');
    }

    /** The site's languages for the import, its default first. @return list<string> */
    private function importLanguages(): array
    {
        return array_values(array_unique(array_merge([$this->defaultLanguage()], array_map('strval', $this->settings()['languages']['available'] ?? []))));
    }

    /** Shop > Files: the private files the digital products sell (docs/shop-plan.md, section 6). */
    public function filesScreen(): void
    {
        if (!Shop::enabled($this->content->getTypes())) {
            $this->redirect('/admin/content-types?shop=off');
            return;
        }
        $products = [];
        foreach ($this->content->getItems('products', $this->defaultLanguage(), true) as $product) {
            $custom = is_array($product->meta['custom_fields'] ?? null) ? $product->meta['custom_fields'] : [];
            $id = (string)($product->meta['translation_id'] ?? '');
            if ($id !== '') {
                $products[$id] = [
                    'title' => (string)($product->meta['title'] ?? $product->slug),
                    'url' => '/admin/edit?' . http_build_query(['type' => 'products', 'slug' => $product->slug, 'lang' => $this->defaultLanguage()]),
                    'digital' => ($custom['kind'] ?? 'physical') === 'digital',
                ];
            }
        }
        $admin = $this->admin();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            // From a product's Downloads card: any product, since its Kind may be chosen and not saved yet.
            $fromCard = !empty($_POST['ajax']);
            $allowed = array_map(
                static fn(array $p): string => $p['title'],
                $fromCard ? $products : array_filter($products, static fn(array $p): bool => $p['digital'])
            );
            $result = $admin->fileAction($_POST, is_array($_FILES['file'] ?? null) ? $_FILES['file'] : null, $allowed, $this->currentUsername());
            if ($fromCard) {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                echo json_encode($result + ['files' => $admin->productFiles((string)($_POST['product'] ?? ''))], JSON_UNESCAPED_UNICODE);
                return;
            }
            $this->redirect('/admin/shop-files?' . http_build_query(['done' => $result['ok'] ? 'ok' : 'fail', 'msg' => $result['message']]));
            return;
        }
        $this->render('@admin/shop-files.twig', $admin->files($products) + $this->screen('shop-files', 'Files'));
    }


    /** System > Shop settings (docs/shop-plan.md): there only while the shop is on. */
    public function settingsScreen(): void
    {
        if (!Shop::enabled($this->content->getTypes())) {
            $this->redirect('/admin/content-types?shop=off');
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $result = $this->settingsAdmin()->act($_POST);
            if ($result['tab'] === 'details' && $result['ok']) {
                // Every product page shows them: the kept pages are drawn again.
                ($this->contentChanged)();
            }
            $this->redirect('/admin/shop-settings?' . http_build_query(['tab' => $result['tab'], 'done' => $result['ok'] ? 'ok' : 'fail', 'msg' => $result['message']]));
            return;
        }
        if (isset($_GET['preview'])) {
            $preview = $this->settingsAdmin()->preview((string)$_GET['preview'], (string)($_GET['lang'] ?? ''));
            if ($preview === null) {
                $this->redirect('/admin/shop-settings?tab=emails');
                return;
            }
            // Shown as it would arrive: on its own, without scripts.
            header('Content-Security-Policy: default-src \'none\'; img-src https: data:; style-src \'unsafe-inline\'');
            header('X-Frame-Options: SAMEORIGIN');
            if (($_GET['format'] ?? '') === 'text') {
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Subject: ' . $preview['subject'] . "\n\n" . $preview['text'];
                return;
            }
            header('Content-Type: text/html; charset=utf-8');
            echo $preview['html'];
            return;
        }
        $screen = $this->settingsAdmin()->screen($_GET);
        if ($screen['tab'] === 'import') {
            $screen += $this->wooImport()->view() + ['import_languages' => $this->importLanguages()];
        }
        $this->render('@admin/shop-settings.twig', $screen + [
            'title' => 'Shop settings',
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'shop-settings',
            'current_type' => 'pages',
        ]);
    }
    // ---- what the site gives the shop

    /** @return array<string, mixed> */
    private function settings(): array { return ($this->settings)(); }
    private function defaultLanguage(): string { return (string)($this->settings()['languages']['default'] ?? 'en'); }
    /** The site's languages, its default first. @return list<string> */
    private function siteLanguageCodes(): array
    {
        return array_values(array_unique(array_merge([$this->defaultLanguage()], array_map('strval', (array)($this->settings()['languages']['available'] ?? [])))));
    }
    private function siteAddress(): string { return trim((string)($this->settings()['base_url'] ?? '')); }
    private function langPrefix(string $lang): string { return FrontRoute::langPrefix($lang, $this->defaultLanguage()); }
    /** @param array<string, mixed> $values */
    private function shopText(string $key, string $lang, array $values = []): string { return ($this->shopText)($key, $lang, $values); }
    private function shopStock(): ShopStock { return ($this->shopStock)(); }
    private function shopSettings(): ShopSettings { return ($this->shopSettings)(); }
    private function shopCatalogue(bool $includeHidden = false): ShopCatalogue { return ($this->shopCatalogue)($includeHidden); }
    /** @param array<int, string> $headers */
    private function sendEmailMessage(string $to, string $subject, string $body, array $headers, ?string $html = null): bool { return ($this->sendEmailMessage)($to, $subject, $body, $headers, $html); }
    private function secretBox(): SecretBox { return ($this->secretBox)(); }
    /** @param array<string, mixed> $context */
    private function logActivity(string $action, string $level, ?string $type, ?string $id, ?string $message, array $context): void { ($this->logActivity)($action, $level, $type, $id, $message, $context); }
    /** @param array<string, mixed> $data */
    private function render(string $template, array $data = []): void { ($this->render)($template, $data); }
    private function render404(): void { ($this->render404)(); }
    private function redirect(string $path): void { ($this->redirect)($path); }
    private function siteLink(string $path): string { return ($this->siteLink)($path); }
    private function buildAbsoluteUrl(string $path): string { return ($this->buildAbsoluteUrl)($path); }
    private function getBaseUrl(): string { return ($this->getBaseUrl)(); }
    private function setLanguage(string $lang): void { ($this->setLanguage)($lang); }
    private function configureSession(): void { ($this->configureSession)(); }
    private function sendSecurityHeaders(bool $admin, bool $shop = false): void { ($this->sendSecurityHeaders)($admin, $shop); }
    private function isValidCsrfRequest(): bool { return ($this->isValidCsrfRequest)(); }
    private function isHttpsRequest(): bool { return ($this->isHttpsRequest)(); }
    private function currentUsername(): string { return ($this->currentUsername)(); }
    private function relations(): Relations { return ($this->relations)(); }
    private function taxonomies(): Taxonomies { return ($this->taxonomies)(); }
    private function jobRunner(): JobRunner { return ($this->jobRunner)(); }
}
