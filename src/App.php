<?php

declare(strict_types=1);

namespace GnozioCMS;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment as TwigEnvironment;

final class App
{
    /** How long a cache in front of the site may keep a public page (see sendPublicCacheHeaders). */
    private const PUBLIC_CACHE_SECONDS = 60;
    private bool $updateCheck = false;

    private string $basePath;
    private string $contentDir;
    private array $settings;
    private TwigEnvironment $twig;
    private ContentRepository $content;
    private Auth $auth;
    private SystemDatabase $systemDatabase;
    private SystemMetaRepository $systemMeta;
    private UserRepository $users;
    private PermissionService $permissions;
    private RedirectRepository $redirects;
    private RevisionRepository $revisions;
    private ?HtmlGuard $htmlGuard = null;
    private ?ContentEditor $contentEditor = null;
    private ActivityLogRepository $activityLogs;
    private EmailLogRepository $emailLogs;
    private NotificationRepository $notifications;
    private BackupRunRepository $backupRuns;
    private LoginThrottle $loginThrottle;
    private ?LoginController $loginControllerService = null;
    private BackupService $backups;
    private FormSubmissionRepository $formSubmissions;
    private ContentIndex $contentIndex;
    private MediaLibrary $media;
    private MediaUsage $mediaUsage;
    private Theme $theme;
    private Images $images;
    private MarkdownConverter $markdown;
    private ?BlockRuntime $blockRuntime = null;
    private ?PresetLibrary $presetLibrary = null;
    private ?ContentTypes $contentTypes = null;
    private string $currentLang;
    private array $translations = [];
    private array $formStates = [];
    private ?Menus $menuStore = null;
    private ?Sitemap $sitemapService = null;
    private ?Feed $feedService = null;
    private ?SeoAdmin $seoAdminService = null;
    private ?AnalyticsStore $analyticsStoreService = null;
    private ?AnalyticsCollector $analyticsCollectorService = null;
    private ?AnalyticsAdmin $analyticsAdminService = null;
    private ?TaxonomyPage $taxonomyPageService = null;
    private ?PublicForms $publicFormsService = null;
    private ?FrontCache $frontCacheService = null;
    private ?SettingsAdmin $settingsAdminService = null;
    private ?AdminChrome $adminChromeService = null;
    private ?UpdateInstaller $updateInstallerService = null;
    private ?MenuAdmin $menuAdminService = null;
    /** @var array<string, array<string, string>> the theme's text by language, for the labels the menu editor leaves to the theme */
    private array $menuStrings = [];
    private ?LogAdmin $logAdminService = null;
    private ?AdminNotices $adminNoticesService = null;
    private ?UpdateAdmin $updateAdminService = null;
    private ?RevisionAdmin $revisionAdminService = null;
    private ?ContentAdmin $contentAdminService = null;
    private ?EntryForm $entryFormService = null;
    private ?ContentTransfer $contentTransferService = null;
    private ?StructuredData $structuredDataService = null;
    private ?ContentCsv $contentCsvService = null;
    private ?SiteSettings $siteSettingsService = null;
    private ?BackupManager $backupManagerService = null;
    private ?TaxonomyEditor $taxonomyEditorService = null;
    private ?RedirectAdmin $redirectAdminService = null;
    private ?LanguageAlternates $languageAlternatesService = null;
    private ?EntryTranslations $entryTranslationsService = null;
    private ?ThemeStrings $themeStringsService = null;
    private ?MediaAdmin $mediaAdminService = null;
    private ?SiteLimits $siteLimitsService = null;
    private ?SystemStatus $systemStatusService = null;
    private ?OmniClient $omniClientService = null;
    private ?DashboardData $dashboardDataService = null;
    private ?FormsAdmin $formsAdminService = null;
    private ?FormTranslationMigration $formTranslationMigrationService = null;
    private ?FormProcessor $formProcessorService = null;
    private ?RoleAdmin $roleAdminService = null;
    private ?UserAdmin $userAdminService = null;
    private ?ContentTypeAdmin $contentTypeAdminService = null;
    private ?SingleLayouts $singleLayoutsService = null;
    private ?TemplateResolver $templateResolver = null;
    private ?SiteTwig $siteTwigService = null;
    /** True while the live preview of the editor draws a page: its blocks then carry their place in the list (data-bk-block). */
    private bool $entryPreview = false;
    private ?LayoutsAdmin $layoutsAdminService = null;
    private ?BlockSets $blockSetsService = null;
    private ?SingleTemplateAdmin $singleTemplateAdminService = null;
    private ?ArchiveBuilder $archiveBuilderService = null;
    private ?BackupAdmin $backupAdminService = null;
    private ?PublicPaths $publicPathsService = null;
    private ?Taxonomies $taxonomyStore = null;
    /** @var array<string, mixed>|null */
    private array $themeSettings = [];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
        $this->contentDir = $this->basePath . '/content';

        $this->systemDatabase = new SystemDatabase($this->basePath . '/storage');
        $this->systemDatabase->initialize();
        $this->systemMeta = new SystemMetaRepository($this->systemDatabase);
        $this->settings = $this->siteSettings()->load();
        $this->theme = new Theme($this->basePath, (string)($this->settings['theme'] ?? Theme::DEFAULT_NAME));
        $this->themeSettings = $this->loadThemeSettings();
        $this->menus()->ensureDefaults();
        $this->taxonomies()->ensureDefaults();

        $markdown = $this->markdownConverter();
        $this->markdown = $markdown;

        $this->users = new UserRepository($this->systemDatabase, $this->contentDir . '/users/users.yaml');
        $this->users->importYamlUsersIfEmpty();
        $this->users->ensureSuperadminExists();
        $this->permissions = new PermissionService($this->systemMeta->getJson('role_permissions'), $this->systemMeta->getJson('custom_roles'));
        $this->users->allowRoles(array_keys($this->permissions->customRoles()));
        $this->redirects = new RedirectRepository($this->systemDatabase);
        $this->revisions = new RevisionRepository($this->systemDatabase);
        $this->activityLogs = new ActivityLogRepository($this->systemDatabase);
        $this->emailLogs = new EmailLogRepository($this->systemDatabase);
        $this->notifications = new NotificationRepository($this->systemDatabase);
        $this->backupRuns = new BackupRunRepository($this->systemDatabase);
        $this->loginThrottle = new LoginThrottle($this->systemDatabase);
        $this->backups = new BackupService($this->basePath, $this->systemDatabase);
        $this->formSubmissions = new FormSubmissionRepository($this->contentDir, $this->basePath . '/storage/form-uploads');
        $this->formSubmissions->uploads()->configure(
            fn(): int => $this->siteLimits()->maxUploadBytes(),
            fn(int $bytes): bool => $this->siteLimits()->allows($bytes),
            fn(int $bytes) => $this->siteLimits()->formFilesChanged($bytes)
        );
        $this->contentIndex = new ContentIndex($this->systemDatabase, $this->contentDir);
        $this->media = new MediaLibrary($this->contentDir, $this->basePath . '/public/uploads');
        $this->media->restrictTo(is_array($this->settings['limits']['upload_types'] ?? null) ? $this->settings['limits']['upload_types'] : []);
        $this->mediaUsage = new MediaUsage($this->contentDir, fn(): array => $this->mediaUsageSettingsSources(), (string)($this->settings['languages']['default'] ?? 'en'), $this->systemMeta);
        $this->images = new Images($this->basePath . '/public', $this->contentDir . '/media');
        $this->content = new ContentRepository($this->contentDir, $markdown, $this->settings, fn(): array => $this->contentTypes()->catalogue());
        $this->content->storeParsedIn($this->basePath . '/storage/cache/content', trim((string)@file_get_contents($this->basePath . '/VERSION')));
        $this->content->onUnreadable(fn(string $type, string $path, string $message) => $this->reportUnreadableContent($type, $path, $message));
        $this->auth = new Auth($this->contentDir . '/users/users.yaml', $this->users);

        $this->twig = $this->initTwig();
        $this->currentLang = $this->settings['languages']['default'] ?? 'en';
        $this->translations = $this->loadTranslations($this->currentLang);
    }

    public function handle(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $path = trim($path, '/');

        // A visitor of the public site gets no session (and no cookie), so the page can be kept by a cache in front of the site;
        // the admin, a form that is sent and anyone who already has a session (an editor who sees drafts) get one.
        if (session_status() !== PHP_SESSION_ACTIVE && $this->needsSession($path)) {
            $this->configureSession();
            session_start();
        }

        // Only the current install's token can ask for an uncached proof of the version that actually booted.
        $maintenance = new MaintenanceMode($this->basePath);
        $updateToken = (string)($_SERVER['HTTP_X_FAROS_UPDATE'] ?? '');
        $state = $updateToken !== '' ? $maintenance->state() : null;
        $this->updateCheck = $state !== null && hash_equals($state['token'], $updateToken);
        if ($this->updateCheck) {
            FrontCache::doNotKeep();
            header('Cache-Control: private, no-store');
            $nonce = is_string($_GET['_faros_check'] ?? null) ? $_GET['_faros_check'] : '';
            if (preg_match('/^[a-f0-9]{32}$/', $nonce)) {
                $version = trim((string)file_get_contents($this->basePath . '/VERSION'));
                header(UpdateNetwork::CHECK_HEADER . ': ' . hash_hmac('sha256', $version . '|' . $nonce, $updateToken));
            }
        }

        // While an update replaces the code the public site says so; the admin stays open.
        if (!str_starts_with($path, 'admin') && $maintenance->blocks($updateToken)) {
            FrontCache::doNotKeep();
            http_response_code(503);
            header('Retry-After: 60');
            header('Cache-Control: no-store');
            header('Content-Type: text/html; charset=utf-8');
            echo MaintenanceMode::page();
            return;
        }

        // Forms that older versions kept as one copy for each language are folded into one form, once, the first time the site is opened after an update.
        try {
            $this->formTranslationMigration()->runOnce();
        } catch (\Throwable $e) {
            // The copies stay as they are and the site works as before; the next visit tries again.
        }

        if (str_starts_with($path, 'admin')) {
            $this->sendSecurityHeaders(true);
            $this->handleAdmin($path);
            return;
        }

        // A picture of a video of a playlist, kept on this site.
        if (preg_match('#^_yt/[A-Za-z0-9_-]{11}\.jpg$#', $path) === 1) {
            $this->handleYoutubeThumb($path);
            return;
        }

        // The script of the platform's analytics reports a visit here: no page, no answer.
        if ($path === '_a/collect') {
            $this->handleCollect();
            return;
        }

        $this->sendSecurityHeaders(false);
        $this->sendPublicCacheHeaders();

        // A scheduled backup that is due starts after the page has been sent, so it does not depend on someone opening the admin.
        if (!$this->updateCheck && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            register_shutdown_function(fn() => $this->runScheduledAfterResponse());
        }

        $this->handleFront($path);
    }

    /**
     * After a visit to the public site: the scheduled backup, when it is due. Only where the response can be finished before the work
     * starts (PHP-FPM, LiteSpeed), so no visitor ever waits for a backup; elsewhere the admin and the cron script (src/cron-backup.php) do it.
     */
    private function runScheduledAfterResponse(): void
    {
        try {
            $backupDue = $this->backupManager()->isRunDue();
            if (!$backupDue && !$this->omniClient()->isDue()) {
                return;
            }
            $finish = CronStatus::finishFunction();
            if ($finish === null) {
                return;
            }
            // The visitor's session is let go first (its lock would hold their next page), then the response, then the work.
            @session_write_close();
            $finish();
            @ignore_user_abort(true);
            @set_time_limit(0);
            if ($backupDue) {
                $this->backupManager()->runIfDue();
            }
            // After the backup, so a report that is due tells Omni how it went.
            $this->omniClient()->sendIfDue();
        } catch (\Throwable) {
            // A backup that could not run is told by its own run (Backups, notifications); a visit is never broken by it.
        }
    }

    /**
     * After an admin page: the report to Omni, when it is due. Where the response can be finished first nobody waits for it;
     * elsewhere it is sent before the page ends (at most once in RETRY_AFTER_FAILURE when Omni does not answer).
     */
    private function sendOmniReportAfterResponse(): void
    {
        try {
            if (!$this->omniClient()->isDue()) {
                return;
            }
            $finish = CronStatus::finishFunction();
            if ($finish !== null) {
                @session_write_close();
                $finish();
                @ignore_user_abort(true);
            }
            $this->omniClient()->sendIfDue();
        } catch (\Throwable) {
            // What went wrong is kept with the connection and shown on Admin > Omni.
        }
    }

    /** For the command line (src/cron-backup.php): the scheduled backup when it is due, and what became of it. */
    public function runScheduledBackup(): string
    {
        $result = $this->backupManager()->runIfDue();
        // The System screen says when the cron job was last seen.
        $this->systemMeta->set(CronStatus::META_KEY, date('c'));
        try {
            $this->omniClient()->sendIfDue();
        } catch (\Throwable) {
            // Shown on Admin > Omni; the cron job's own answer is about the backup.
        }
        return $result;
    }

    private function contentEditor(): ContentEditor
    {
        return $this->contentEditor ??= new ContentEditor(
            $this->contentDir,
            $this->settings,
            $this->content,
            $this->contentIndex,
            $this->contentTypes(),
            $this->theme,
            $this->redirects,
            $this->htmlGuard(),
            fn(): BlockRegistry => $this->blockRuntime()->registry(),
            fn(): array => $this->taxonomies()->names(),
            $this->revisions
        );
    }

    private function revisionAdmin(): RevisionAdmin
    {
        return $this->revisionAdminService ??= new RevisionAdmin(
            $this->revisions,
            $this->contentEditor(),
            $this->content,
            $this->contentDir,
            fn(): array => $this->settings,
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function contentAdmin(): ContentAdmin
    {
        return $this->contentAdminService ??= new ContentAdmin(
            $this->contentDir,
            $this->content,
            $this->contentIndex,
            $this->contentTypes(),
            $this->contentEditor(),
            $this->entryTranslations(),
            $this->revisions,
            $this->redirects,
            fn(): array => $this->settings,
            fn(): Taxonomies => $this->taxonomies(),
            fn(): PublicPaths => $this->publicPaths(),
            fn(): LinkScanner => $this->linkScanner(),
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function entryForm(): EntryForm
    {
        return $this->entryFormService ??= new EntryForm(
            $this->contentDir,
            $this->content,
            $this->contentTypes(),
            $this->contentEditor(),
            $this->entryTranslations(),
            $this->formsAdmin(),
            $this->redirects,
            $this->revisions,
            $this->revisionAdmin(),
            $this->theme,
            fn(): array => $this->settings,
            fn(): array => $this->themeSettings,
            fn(): Taxonomies => $this->taxonomies(),
            fn(): LinkScanner => $this->linkScanner(),
            fn(): BlockRegistry => $this->blockRuntime()->registry(),
            fn(): PresetLibrary => $this->presetLibrary(),
            fn(string $path): string => $this->buildAbsoluteUrl($path)
        );
    }

    private function contentTransfer(): ContentTransfer
    {
        return $this->contentTransferService ??= new ContentTransfer(
            $this->contentCsv(),
            $this->content,
            fn(): array => $this->rebuildContentIndex(),
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function menuAdmin(): MenuAdmin
    {
        return $this->menuAdminService ??= new MenuAdmin(
            $this->menus(),
            fn(): array => $this->settings,
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context),
            new MenuSources($this->content, fn(): Taxonomies => $this->taxonomies(), fn(): array => $this->settings),
            function (string $key, string $lang): string {
                $this->menuStrings[$lang] ??= $this->loadTranslations($lang);
                return (string)($this->menuStrings[$lang][$key] ?? '');
            },
            $this->permissions->can($this->auth->user(), 'settings.manage')
                ? fn(string $location, string $menu): bool => $this->siteSettings()->setMenuLocation($location, $menu)
                : null
        );
    }

    private function logAdmin(): LogAdmin
    {
        return $this->logAdminService ??= new LogAdmin(
            $this->activityLogs,
            $this->emailLogs,
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function adminNotices(): AdminNotices
    {
        return $this->adminNoticesService ??= new AdminNotices(
            $this->notifications,
            fn(): UpdateService => $this->updates(),
            fn(): array => $this->systemStatus()->checks($this->siteLimits()->summary())
        );
    }

    private function updateInstaller(): UpdateInstaller
    {
        if ($this->updateInstallerService !== null) {
            return $this->updateInstallerService;
        }
        // Resolve a real public entry before the code swap; the health request must render it with the new code.
        $contentPath = null;
        foreach ($this->publicPaths()->map() as $path => $title) {
            if ($path !== '' && !in_array($path, $this->settings['languages']['available'] ?? [], true)) {
                $contentPath = '/' . $path;
                break;
            }
        }
        $sitemapEnabled = $this->sitemap()->enabled();
        return $this->updateInstallerService ??= new UpdateInstaller(
            $this->basePath,
            $this->systemDatabase,
            new MaintenanceMode($this->basePath),
            fn(string $url, string $destination, int $maxBytes): ?string => UpdateNetwork::download($url, $destination, $maxBytes),
            fn(string $token, string $version): array => UpdateNetwork::checkSite($this->getBaseUrl(), $token, $version, $contentPath, $sitemapEnabled),
            fn(string $level, string $message, array $context) => $this->logActivity('updates.install_step', $level, 'updates', 'install', $message, $context)
        );
    }

    private function updateAdmin(): UpdateAdmin
    {
        return $this->updateAdminService ??= new UpdateAdmin(
            fn(): UpdateService => $this->updates(),
            $this->adminNotices(),
            fn(): BackupAdmin => $this->backupAdmin(),
            fn(): UpdateInstaller => $this->updateInstaller(),
            $this->systemMeta,
            $this->backups,
            $this->contentDir,
            $this->basePath,
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function sitemap(): Sitemap
    {
        return $this->sitemapService ??= new Sitemap(
            $this->content,
            fn(): array => $this->settings,
            fn(string $path): string => $this->buildAbsoluteUrl($path),
            fn(string $lang): array => $this->sitemapTerms($lang)
        );
    }

    private function feed(): Feed
    {
        return $this->feedService ??= new Feed(
            $this->content,
            fn(): array => $this->settings,
            fn(string $path): string => $this->buildAbsoluteUrl($path),
            fn(): Taxonomies => $this->taxonomies(),
            fn(string $taxonomy, string $termId, string $prefix): string => $this->buildTaxonomyPath($taxonomy, $termId, $prefix)
        );
    }

    /**
     * The pages of the terms (categories, tags, a site's own taxonomies) that have entries in a language, with the date of
     * the newest entry: what the sitemap lists of the taxonomies.
     *
     * @return array<int, array{path: string, lastmod: int}>
     */
    private function sitemapTerms(string $lang): array
    {
        $newest = [];
        foreach ($this->content->getTypes() as $type) {
            if (in_array($type, ['pages', 'forms'], true)) {
                continue;
            }
            foreach ($this->content->getItems($type, $lang, false, false) as $item) {
                foreach ($this->taxonomies()->names() as $name) {
                    foreach (Format::list($item->meta[$name] ?? null) as $termId) {
                        $key = $name . "\0" . $termId;
                        $newest[$key] = max($newest[$key] ?? 0, $item->mtime);
                        // The page of a parent lists its sub-terms' entries too, so it has them as well.
                        if ($this->taxonomies()->includesChildren($name)) {
                            foreach ($this->taxonomies()->ancestors($name, (string)$termId) as $above) {
                                $up = $name . "\0" . $above['id'];
                                $newest[$up] = max($newest[$up] ?? 0, $item->mtime);
                            }
                        }
                    }
                }
            }
        }
        $terms = [];
        foreach ($newest as $key => $mtime) {
            [$name, $termId] = explode("\0", $key, 2);
            $path = $this->buildTaxonomyPath($name, $termId, $this->langPrefix($lang));
            if ($path !== '') {
                $terms[] = ['path' => $path, 'lastmod' => $mtime];
            }
        }
        return $terms;
    }

    private function taxonomyPage(): TaxonomyPage
    {
        return $this->taxonomyPageService ??= new TaxonomyPage(
            $this->content,
            $this->theme,
            fn(): Taxonomies => $this->taxonomies(),
            fn(): ArchiveBuilder => $this->archiveBuilder(),
            fn(): LanguageAlternates => $this->languageAlternates(),
            fn(): array => $this->settings,
            fn(string $key, ?string $fallback = null): string => $this->translate($key, $fallback)
        );
    }

    private function publicForms(): PublicForms
    {
        return $this->publicFormsService ??= new PublicForms(
            $this->formProcessor(),
            $this->formSubmissions,
            fn(): array => $this->settings,
            fn(string $key, ?string $fallback = null): string => $this->translate($key, $fallback),
            fn(string $path): string => $this->buildAbsoluteUrl($path),
            fn(string $to, string $subject, string $body, array $headers, array $attachments = []) => $this->sendEmailMessage($to, $subject, $body, $headers, $attachments),
            fn(string $slug, int $seconds): bool => (time() - (int)($_SESSION['form_rate'][$slug] ?? 0)) < $seconds,
            function (string $slug): void {
                $_SESSION['form_rate'][$slug] = time();
            }
        );
    }

    private function settingsAdmin(): SettingsAdmin
    {
        return $this->settingsAdminService ??= new SettingsAdmin(
            $this->siteSettings(),
            $this->media,
            fn(): SiteLimits => $this->siteLimits(),
            fn(): BackupManager => $this->backupManager(),
            fn(): array => $this->settings,
            function (array $settings): void {
                $this->settings = $settings;
            },
            function (?array $input): array {
                $result = $input !== null ? $this->saveThemeSettings($input) : ['ok' => true];
                $this->themeSettings = $this->loadThemeSettings();
                return $result;
            },
            fn(string $to, string $subject, string $body, array $headers): bool => $this->sendEmailMessage($to, $subject, $body, $headers),
            fn(string $isoDate) => $this->updateBackupLastRun($isoDate),
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context),
            function (): array {
                $this->blockRuntime()->resetYoutube();
                return $this->blockRuntime()->youtubePlaylist()->test();
            }
        );
    }

    private function adminChrome(): AdminChrome
    {
        return $this->adminChromeService ??= new AdminChrome(
            $this->notifications,
            $this->users,
            fn(): UpdateService => $this->updates(),
            fn(): SiteLimits => $this->siteLimits(),
            fn(): AdminNotices => $this->adminNotices(),
            fn(): array => $this->settings
        );
    }

    private function htmlGuard(): HtmlGuard
    {
        return $this->htmlGuard ??= new HtmlGuard(fn(): Environment => $this->markdownEnvironment(), fn(): BlockRegistry => $this->blockRuntime()->registry());
    }

    private function markdownConverter(): MarkdownConverter
    {
        return new MarkdownConverter($this->markdownEnvironment());
    }

    private function markdownEnvironment(): Environment
    {
        $environment = new Environment([
            'renderer' => ['soft_break' => "<br />\n"],
            // [text](javascript:…) and similar links lose their address instead of running script when clicked.
            'allow_unsafe_links' => false,
            // [text](address){target=_blank} opens a link in a new tab (with rel noopener); no other attribute is accepted.
            'attributes' => ['allow' => ['target', 'align']],
            // A wide table scrolls inside its own box; tabindex lets keyboard users scroll it.
            'table' => ['wrap' => ['enabled' => true, 'tag' => 'div', 'attributes' => ['class' => 'table-wrap', 'tabindex' => '0']]],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());
        // ~~struck~~ text, which the editor's Strike button writes.
        $environment->addExtension(new StrikethroughExtension());
        $environment->addExtension(new AttributesExtension());
        return $environment;
    }

    private function needsSession(string $path): bool
    {
        if (str_starts_with($path, 'admin') || isset($_COOKIE[session_name()])) {
            return true;
        }
        // The analytics script reports each visit with a POST; it must not hand every visitor a cookie.
        return !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) && $path !== '_a/collect';
    }

    /**
     * A public page seen without a session is the same for everyone, so a cache in front of the site (Varnish, a CDN) may keep it
     * for a minute; the browser asks again each time. With a session (an editor, who may see drafts) nothing keeps it. A response
     * that must never be kept says so itself later (no-store), which replaces this.
     */
    private function sendPublicCacheHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        if ($this->updateCheck || session_status() === PHP_SESSION_ACTIVE) {
            FrontCache::doNotKeep();
        }
        header($this->updateCheck || session_status() === PHP_SESSION_ACTIVE ? 'Cache-Control: private, no-store' : 'Cache-Control: public, max-age=0, s-maxage=' . self::PUBLIC_CACHE_SECONDS);
    }

    private function configureSession(): void
    {
        if (headers_sent()) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function isHttpsRequest(): bool
    {
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    private function sendSecurityHeaders(bool $admin): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        if ($admin) {
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: same-origin');
            header('Cache-Control: no-store, private');
            return;
        }
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    private function csrfToken(): string
    {
        $token = $_SESSION['_csrf_token'] ?? '';
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['_csrf_token'] = $token;
        }
        return $token;
    }

    private function isValidCsrfRequest(): bool
    {
        $expected = $_SESSION['_csrf_token'] ?? '';
        $provided = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        return is_string($expected) && $expected !== '' && is_string($provided) && hash_equals($expected, $provided);
    }

    private function handleFront(string $path): void
    {
        $route = FrontRoute::resolve($path, $this->settings, $this->content->getTypes(), array_values(array_filter($this->taxonomies()->names(), [Taxonomies::class, 'isCustom'])));
        if ($route['kind'] === 'sitemap') {
            $this->renderSitemap();
            return;
        }
        if ($route['kind'] === 'robots') {
            $this->renderRobots();
            return;
        }
        if ($route['kind'] === 'feed') {
            if (!$this->feedIsShadowed($route)) {
                $this->renderFeed($route);
                return;
            }
            // An entry or page with that very name wins: it is read as the address it always was.
            $route = FrontRoute::resolve($path, $this->settings, $this->content->getTypes(), array_values(array_filter($this->taxonomies()->names(), [Taxonomies::class, 'isCustom'])), false);
        }

        $lang = $route['lang'];
        $segments = $route['segments'];
        $homeSlug = $route['home_slug'];
        $this->setLanguage($lang);
        $includeHidden = $this->auth->check();
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_slug']) && ($segments[0] ?? '') !== 'forms') {
            $formSlug = Slug::plain((string)($_POST['form_slug'] ?? ''));
            if ($formSlug !== '') {
                $formItem = FormTranslations::find($this->content, $formSlug, $lang, $includeHidden)['form'];
                if ($formItem) {
                    // A page kept by a cache may still send the old address of a form: it is the same form.
                    $_POST['form_slug'] = $formItem->slug;
                    $this->formStates[$formItem->slug] = $this->handleFormRequest($formItem, $lang, $path);
                }
            }
        }
        $currentUrl = $this->buildAbsoluteUrl($path);
        $viewDefaults = [
            'lang' => $lang,
            'lang_prefix' => $route['lang_prefix'],
            'current_lang' => $lang,
            'path_no_lang' => $route['path_no_lang'],
            'canonical_url' => $currentUrl,
            'theme_menus' => $this->menus()->forTheme($lang, $route['path_no_lang']),
        ];

        if ($route['kind'] === 'not_found') {
            $this->render404();
            return;
        }

        if ($route['kind'] === 'legacy_page') {
            header('Location: ' . $route['location'], true, 301);
            exit;
        }

        if ($route['kind'] === 'taxonomy') {
            $this->handleTaxonomy($segments, $lang, $viewDefaults);
            return;
        }

        if ($route['kind'] === 'search') {
            $this->handleSearch($route, $viewDefaults, $includeHidden);
            return;
        }

        if ($route['kind'] === 'archive' || $route['kind'] === 'entry') {
            $type = $route['type'];
            $slug = $route['slug'];
            if ($route['kind'] === 'archive') {
                $items = $this->content->getItems($type, $lang, $includeHidden, false);
                $alternates = $this->languageAlternates()->forArchive($type);
                $archive = $this->archiveContext($type, $lang, $items);
                if ($archive['page'] > 1 && !$archive['filtered']) {
                    // Each page of a listing is its own page for search engines.
                    $viewDefaults['canonical_url'] = $currentUrl . '?page=' . $archive['page'];
                }
                $this->render($this->templates()->forArchive($type), [
                    'items' => $archive['items'],
                    'archive' => $archive,
                    'type' => $type,
                    'alternate_urls' => $alternates['urls'],
                    'alternate_default' => $alternates['default'],
                    'block_styles' => [$this->theme->blockStylesheetUrl(rtrim((string)($this->settings['base_url'] ?? ''), '/'), ($archive['settings']['layout'] ?? '') === 'map' ? ['latest', 'map'] : ['latest'])],
                    'block_scripts' => ($archive['settings']['layout'] ?? '') === 'map' ? array_values(array_filter([$this->theme->blockScriptUrl(rtrim((string)($this->settings['base_url'] ?? ''), '/'), ['map'])])) : [],
                    'noindex_page' => $archive['filtered'],
                ] + $viewDefaults);
                return;
            }

            if ($type === 'forms') {
                // A form is one thing in every language: its address works in each, and an old address of one translated copy leads to it.
                $found = FormTranslations::find($this->content, $slug, $lang, $includeHidden);
                $item = $found['form'];
                if ($item !== null && $found['alias_lang'] !== '') {
                    header('Location: /' . ContentPaths::build('forms', $item->slug, $found['alias_lang'], $homeSlug, (string)($this->settings['languages']['default'] ?? 'en')), true, 301);
                    exit;
                }
            } else {
                $item = $this->content->find($type, $slug, $lang, $includeHidden, false);
            }
            if (!$item) {
                $this->render404();
                return;
            }
            if (ContentPaths::isRoot($type) && $this->content->find('pages', $slug, $lang, $includeHidden, false) === null) {
                // The old address of a post (/posts/my-post): its address now is at the root of the site. (A post whose address a page
                // of the same name took stays where it is, so it can still be read.)
                header('Location: /' . ContentPaths::build($type, $item->slug, $lang, $homeSlug, (string)($this->settings['languages']['default'] ?? 'en')), true, 301);
                exit;
            }
            $this->renderEntry($type, $item, $lang, $path, $viewDefaults, $currentUrl);
            return;
        }

        $slug = $route['slug'];
        $page = $this->content->find('pages', $slug, $lang, $includeHidden, false);
        if (!$page) {
            // Posts have their address at the root too, after the pages.
            foreach (ContentPaths::ROOT_TYPES as $type) {
                if ($type === 'pages' || !in_array($type, $this->content->getTypes(), true)) {
                    continue;
                }
                $post = $this->content->find($type, $slug, $lang, $includeHidden, false);
                if ($post) {
                    $this->renderEntry($type, $post, $lang, $path, $viewDefaults, $currentUrl);
                    return;
                }
            }
            $this->render404();
            return;
        }
        $page->html = $this->applyShortcodes($page->html, $lang, $path);

        $viewDefaults['canonical_url'] = $this->resolveCanonicalUrl(
            $page->meta['seo']['canonical'] ?? null,
            $currentUrl
        );
        $alternates = $this->languageAlternates()->forItem('pages', $page->slug);
        $languageLinks = $this->languageAlternates()->languageLinks('pages', $page);
        $template = $slug === $homeSlug ? 'templates/home.twig' : $this->templates()->forItem($page);
        $this->render($template, [
            'item' => $page,
            'alternate_urls' => $alternates['urls'],
            'alternate_default' => $alternates['default'],
            'language_links' => $languageLinks,
        ] + $this->frontItemData($page, $lang, $path, $viewDefaults, $template === 'templates/home.twig') + $viewDefaults);
    }

    /**
     * One entry of a content type (a post, a project, a form) drawn with its template.
     *
     * @param array<string, mixed> $viewDefaults
     */
    private function renderEntry(string $type, ContentItem $item, string $lang, string $path, array $viewDefaults, string $currentUrl): void
    {
        $item->html = $this->applyShortcodes($item->html, $lang, $path);

        $viewDefaults['canonical_url'] = $this->resolveCanonicalUrl(
            $item->meta['seo']['canonical'] ?? null,
            $currentUrl
        );
        $alternates = $this->languageAlternates()->forItem($type, $item->slug);
        $languageLinks = $this->languageAlternates()->languageLinks($type, $item);
        if ($type === 'forms') {
            $formState = $this->handleFormRequest($item, $lang, $path);
            $item = $this->publicForms()->localized($item, $lang, fn(string $markdown): string => $this->applyShortcodes((string)$this->markdown->convert($markdown), $lang, $path));
            $this->render($this->templates()->forItem($item), [
                'item' => $item,
                'form_fields' => $formState['fields'],
                'form_values' => $formState['values'],
                'form_errors' => $formState['errors'],
                'form_success' => $formState['success'],
                'form_message' => $formState['message'],
                'form_action' => $formState['action'],
                'form_honeypot' => $formState['honeypot'],
                'form_redirect' => $formState['redirect'],
            ] + $viewDefaults + [
                'alternate_urls' => $alternates['urls'],
                'alternate_default' => $alternates['default'],
                'language_links' => $languageLinks,
            ]);
            return;
        }
        $this->render($this->templates()->forItem($item), [
            'item' => $item,
            'alternate_urls' => $alternates['urls'],
            'alternate_default' => $alternates['default'],
            'language_links' => $languageLinks,
        ] + $this->frontItemData($item, $lang, $path, $viewDefaults, false) + $viewDefaults);
    }

    /**
     * Rendered blocks, their stylesheets, and structured data for a page or single item.
     *
     * @param array<string, mixed> $viewDefaults
     * @return array<string, mixed>
     */
    private function frontItemData(ContentItem $item, string $lang, string $path, array $viewDefaults, bool $isHome): array
    {
        $pageBlocks = null;
        $raw = $item->meta['blocks'] ?? null;
        // The page of a type built with blocks: the blocks of its template, drawn for this entry (its fields, its text, its own blocks).
        $built = $isHome ? null : $this->templates()->builtBlocks($item);
        if ($built !== null) {
            $pageBlocks = $this->blockRuntime()->renderTemplate($built, $item, $lang, $path, $this->twig, $viewDefaults + ['block_preview' => $this->entryPreview]);
        } elseif (is_array($raw) && $raw !== []) {
            // The sidebar template places the text itself, so blocks must not repeat it.
            $bodyInTemplate = !$isHome && $this->templates()->forItem($item) === 'templates/sidebar.twig';
            $pageBlocks = $this->blockRuntime()->renderer($lang, $path, $this->twig)->render(array_values($raw), $viewDefaults + [
                'item' => $item,
                'body_html' => $bodyInTemplate ? '' : $item->html,
                'block_preview' => $this->entryPreview,
            ]);
        }
        $styles = $pageBlocks['styles'] ?? [];
        $scripts = $pageBlocks['scripts'] ?? [];
        if ($this->blockRuntime()->hasPlaceField($item->type)) {
            // The page of a point, a route or a business draws a map: it needs the map's style sheet and script, once.
            $base = rtrim((string)($this->settings['base_url'] ?? ''), '/');
            $types = array_values(array_unique(array_merge($pageBlocks['types'] ?? [], ['map'])));
            $styles = array_values(array_filter([$this->theme->blockStylesheetUrl($base, array_values(array_unique(array_merge($types, array_intersect($pageBlocks['asset_types'] ?? [], [Theme::ROW_STYLES, Theme::CONTAINER_STYLES])))))]));
            $scripts = array_values(array_filter([$this->theme->blockScriptUrl($base, $types)]));
        }
        return [
            'page_blocks' => $pageBlocks,
            'block_styles' => $styles,
            'block_scripts' => $scripts,
            'structured_data' => array_merge(
                $this->structuredData()->forItem($item, $lang, (string)($viewDefaults['canonical_url'] ?? ''), $isHome, (string)($pageBlocks['image'] ?? '')),
                $pageBlocks['structured_data'] ?? []
            ),
            'og_type' => $item->type === 'posts' ? 'article' : 'website',
        ];
    }

    /**
     * What an archive page shows: the items of the requested page after filters and ordering,
     * the filters offered, and pagination. Filters come from the type's definition (filterable
     * select fields and chosen taxonomies) and only list values that some item really has.
     *
     * @param ContentItem[] $items
     * @return array<string, mixed>
     */
    private function archiveContext(string $type, string $lang, array $items): array
    {
        $definition = $this->contentTypes()->definition($type, $lang, $this->defaultLanguage());
        return $this->archiveBuilder()->build($definition['archive'], $definition['fields'], $definition, $lang, $items, $_GET);
    }

    /** A picture of a playlist's video, kept on this site (see YouTubeThumbs). */
    private function handleYoutubeThumb(string $path): void
    {
        $file = $this->blockRuntime()->youtubeThumbFile($path, (string)($_GET['s'] ?? ''));
        if ($file === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            return;
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . (string)filesize($file));
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
    }

    private function handleSearch(array $route, array $viewDefaults, bool $includeHidden): void
    {
        $lang = $route['lang'];
        $documents = new SearchDocument(
            $this->blockRuntime()->registry(),
            $this->contentTypes(),
            fn(string $text): string => (string)$this->markdown->convert($text),
            $this->defaultLanguage()
        );
        $documents->cacheIn($this->basePath . '/storage/cache/search', trim((string)file_get_contents($this->basePath . '/VERSION')));
        $search = (new ContentSearch($this->content, $documents))->search(
            $_GET['q'] ?? '', $lang, $includeHidden, $_GET['type'] ?? '', $_GET['page'] ?? 1
        );
        $base = $this->buildAbsoluteUrl($route['lang_prefix'] . 'search');
        $link = static function (string $type, int $page = 1) use ($base, $search): string {
            $params = ['q' => $search['query']];
            if ($type !== '') {
                $params['type'] = $type;
            }
            if ($page > 1) {
                $params['page'] = $page;
            }
            return $base . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        };
        $search['types'] = [];
        if ($search['counts'] !== [] || $search['type'] !== '') {
            $search['types'][] = ['value' => '', 'label' => $this->translate('search.all_types', 'All types'),
                'count' => array_sum($search['counts']), 'url' => $link(''), 'active' => $search['type'] === ''];
            $counts = $search['counts'];
            if ($search['type'] !== '') {
                $counts[$search['type']] ??= 0;
            }
            ksort($counts);
            foreach ($counts as $type => $count) {
                $search['types'][] = ['value' => $type, 'label' => $this->searchTypeLabel($type, $lang), 'count' => $count,
                    'url' => $link($type), 'active' => $type === $search['type']];
            }
        }
        foreach ($search['hits'] as &$hit) {
            $hit['type_label'] = $this->searchTypeLabel($hit['item']->type, $lang);
        }
        unset($hit);
        $numbers = $search['pages'] > 1
            ? array_values(array_unique(array_merge([1], range(max(1, $search['page'] - 2), min($search['pages'], $search['page'] + 2)), [$search['pages']]))) : [];
        // A gap hiding exactly one page shows that page instead of an ellipsis; wider gaps are flagged so the template can mark them.
        $links = [];
        $previous = 0;
        foreach ($numbers as $n) {
            if ($previous > 0 && $n - $previous === 2) {
                $links[] = ['page' => $previous + 1, 'url' => $link($search['type'], $previous + 1), 'current' => false, 'gap_before' => false];
            }
            $links[] = ['page' => $n, 'url' => $link($search['type'], $n), 'current' => $n === $search['page'], 'gap_before' => $previous > 0 && $n - $previous > 2];
            $previous = $n;
        }
        $search['pagination'] = [
            'page' => $search['page'], 'pages' => $search['pages'],
            'previous' => $search['page'] > 1 ? $link($search['type'], $search['page'] - 1) : '',
            'next' => $search['page'] < $search['pages'] ? $link($search['type'], $search['page'] + 1) : '',
            'links' => $links,
        ];
        $this->render('templates/search.twig', [
            'query' => $search['query'], 'results' => $search['results'], 'search' => $search,
            // Robots follow the SEO setting for search pages (noindex by default).
            'seo_kind' => 'search',
        ] + $viewDefaults);
    }

    private function searchTypeLabel(string $type, string $lang): string
    {
        $definition = $this->contentTypes()->definition($type, $lang, $this->defaultLanguage());
        return $definition['origin'] === '' ? $this->translate('type.' . $type, $definition['label']) : $definition['label'];
    }

    private function blockRuntime(): BlockRuntime
    {
        return $this->blockRuntime ??= new BlockRuntime(
            $this->basePath,
            $this->theme,
            $this->images,
            $this->systemMeta,
            $this->content,
            fn(): array => $this->settings,
            fn(): bool => $this->auth->check(),
            fn(): ContentTypes => $this->contentTypes(),
            fn(): string => $this->currentLang,
            fn(): string => $this->defaultLanguage(),
            fn(string $taxonomy, string $termId, string $lang): string => $this->taxonomyTermLabel($taxonomy, $termId, $lang),
            fn(string $path): string => $this->buildAbsoluteUrl($path),
            fn(string $markdown, string $lang, string $path): string => $this->applyShortcodes((string)$this->markdown->convert($markdown), $lang, $path),
            fn(string $slug, string $lang, string $path): string => $this->renderFormEmbedBySlug($slug, $lang, $path)
        );
    }

    /** Admin addresses served by one handler each, by the first part of the address after /admin. */
    private const ADMIN_ROUTES = [
        'theme' => 'handleTheme',
        'settings' => 'handleSettings',
        'index' => 'handleDashboard',
        'dashboard' => 'handleDashboard',
        'content' => 'handleAdminList',
        'content-bulk' => 'handleContentBulk',
        'search' => 'handleAdminSearch',
        'system' => 'handleSystem',
        'activity-logs' => 'handleActivityLogs',
        'email-logs' => 'handleEmailLogs',
        'backups' => 'handleBackups',
        'updates' => 'handleUpdates',
        'omni' => 'handleOmni',
        'notification-read' => 'handleNotificationRead',
        'notifications-read-all' => 'handleNotificationsReadAll',
        'menus' => 'handleMenusList',
        'menus-new' => 'handleMenusNew',
        'menus-edit' => 'handleMenus',
        'media' => 'handleMedia',
        'forms' => 'handleFormsList',
        'forms-new' => 'handleFormsNew',
        'form-submissions' => 'handleFormSubmissions',
        'forms-export' => 'handleFormsExport',
        'form-file' => 'handleFormFile',
        'form-files-zip' => 'handleFormFilesZip',
        'import' => 'handleContentImport',
        'export' => 'handleContentExport',
        'translations' => 'handleTranslations',
        'content-types' => 'handleContentTypes',
        'taxonomies' => 'handleTaxonomies',
        'roles' => 'handleRoles',
        'redirects' => 'handleRedirects',
        'seo' => 'handleSeo',
        'analytics' => 'handleAnalytics',
        'revisions' => 'handleRevisions',
        'links' => 'handleLinks',
        'users' => 'handleUsersList',
        'users-edit' => 'handleUserEdit',
        'users-delete' => 'handleUserDelete',
        'edit' => 'handleEdit',
        'save' => 'handleSave',
        'block-presets' => 'handleBlockPresets',
        'markdown-visual' => 'handleMarkdownVisual',
        'media-picker' => 'handleMediaPicker',
        'delete' => 'handleDelete',
        'new' => 'handleNew',
    ];

    /** Admin addresses whose POST only reads, previews or exports, so the cache in front of the site stays as it is. */
    private const ADMIN_POSTS_THAT_CHANGE_NOTHING = ['search', 'markdown-visual', 'media-picker', 'block-presets', 'notification-read', 'notifications-read-all', 'forms-export', 'form-file', 'form-files-zip', 'export', 'omni', 'activity-logs', 'email-logs'];

    private function frontCache(): FrontCache
    {
        $host = (string)(parse_url((string)($this->settings['base_url'] ?? ''), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        return $this->frontCacheService ??= new FrontCache($this->systemMeta, $host);
    }

    private function handleAdmin(string $path): void
    {
        $segments = explode('/', $path);
        $action = $segments[1] ?? 'index';
        if ($action === 'index' && (isset($_GET['type']) || isset($_GET['lang']))) {
            $action = 'content';
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$this->isValidCsrfRequest()) {
            $this->rejectInvalidCsrf($action);
            return;
        }

        if ($this->loginController()->handle($action, (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), $_POST, $_GET, (string)($_SERVER['REMOTE_ADDR'] ?? ''))) {
            return;
        }

        if (!$this->auth->check() || !$this->auth->refresh()) {
            $this->redirect('/admin/login');
            return;
        }

        if (!$this->permissions->canAccessAction($this->auth->user(), $action)) {
            $this->logActivity('auth.forbidden', 'warning', 'admin_route', $action, 'Blocked unauthorized admin route.', [
                'action' => $action,
            ]);
            $this->renderForbidden();
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $this->backupManager()->runIfDue();
            register_shutdown_function(fn() => $this->sendOmniReportAfterResponse());
        }

        // Whatever is sent from the admin may change what visitors see: the cache in front of the site is emptied (once, before
        // the handler, which may end the request with a redirect). Screens that only read, preview or export do not count.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !in_array($action, self::ADMIN_POSTS_THAT_CHANGE_NOTHING, true) && !isset($_GET['preview'])) {
            $this->frontCache()->purgeAll();
        }

        if (isset(self::ADMIN_ROUTES[$action])) {
            $this->{self::ADMIN_ROUTES[$action]}();
            return;
        }

        if ($action === 'files') {
            $this->redirect('/admin/media?type=document&view=list');
            return;
        }

        $this->handleAdminList();
    }

    private function rejectInvalidCsrf(string $action): void
    {
        $this->logActivity('security.csrf_rejected', 'warning', 'admin_route', $action, 'Rejected a form submission without a valid CSRF token.', [
            'action' => $action,
        ]);
        http_response_code(419);
        $message = 'This form could not be verified. It may have expired, or the upload was larger than the server allows. Reload the page and try again.';
        if ($action === 'login' || !$this->auth->check()) {
            $this->loginController()->renderLogin($message);
            return;
        }
        $this->renderForbidden($message, 'Form expired');
    }

    private function loginController(): LoginController
    {
        return $this->loginControllerService ??= new LoginController(
            $this->auth,
            $this->loginThrottle,
            $this->users,
            $this->notifications,
            fn(): array => $this->settings,
            fn(string $path): string => $this->buildAbsoluteUrl($path),
            fn(string $action, string $level, ?string $type, ?string $id, ?string $message, array $context, ?array $actor) => $this->logActivity($action, $level, $type, $id, $message, $context, $actor),
            fn(string $template, array $data) => $this->render($template, $data),
            fn(string $path) => $this->redirect($path),
            static function (int $status, array $headers): void {
                http_response_code($status);
                foreach ($headers as $header) {
                    header($header);
                }
            }
        );
    }

    private function handleDashboard(): void
    {
        $dashboard = $this->dashboardData()->build(
            fn(string $capability): bool => $this->permissions->can($this->auth->user(), $capability),
            fn(string $type): bool => $this->canAccessContentType($type)
        );
        $analytics = null;
        $can = fn(string $capability): bool => $this->permissions->can($this->auth->user(), $capability);
        if ($can('analytics.manage') && AnalyticsSettings::from($this->settings)['mode'] === 'platform' && $this->analyticsStore()->isAvailable()) {
            $analytics = $this->analyticsAdmin()->reportsScreen(['range' => '90d']);
        }
        $this->render('@admin/dashboard.twig', [
            'title' => 'Dashboard',
            'dashboard' => $dashboard,
            'analytics' => $analytics,
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'dashboard',
            'current_type' => 'pages',
            'admin_storage_summary' => $dashboard['storage'],
        ]);
    }

    /** The storage limit typed on the settings form in megabytes, or null when this person may not change it or left it empty. */
    private function submittedStorageLimit(): ?int
    {
        return $this->permissions->can($this->auth->user(), 'limits.manage') ? SiteLimits::storageLimitFromForm($_POST) : null;
    }

    /** The active super admin people can write to when the storage is nearly full. @return array{name: string, email: string}|null */
    private function handleRoles(): void
    {
        if (!$this->permissions->can($this->auth->user(), 'roles.manage')) {
            $this->renderForbidden();
            return;
        }

        $roles = $this->roleAdmin();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->redirect($roles->apply($this->permissions, $_POST));
            return;
        }

        $screen = $roles->screen($this->permissions);

        $this->render('@admin/roles.twig', $screen + [
            'locked' => ['admin.access', 'users.self'],
            'saved' => isset($_GET['saved']),
            'created' => (string)($_GET['created'] ?? ''),
            'deleted' => isset($_GET['deleted']),
            'error' => (string)($_GET['error'] ?? ''),
            'error_role' => (string)($_GET['role'] ?? ''),
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'roles',
            'current_type' => 'pages',
        ]);
    }

    private function roleAdmin(): RoleAdmin
    {
        return $this->roleAdminService ??= new RoleAdmin(
            $this->systemMeta,
            $this->users,
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    /** The platform's analytics: the script of a page reports a view or an event, and nothing comes back. */
    private function handleCollect(): void
    {
        FrontCache::doNotKeep();
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            return;
        }
        $raw = (string)file_get_contents('php://input', false, null, 0, 4096);
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }
        try {
            $this->analyticsCollector()->collect($payload, $_SERVER, $this->auth->check());
        } catch (\Throwable) {
            // Counting visits must never break a page, or tell anyone what went wrong.
        }
        http_response_code(204);
    }

    /** Counts a goal that happened on the server (a form that was sent) for the visit that caused it. */
    private function analyticsGoal(string $type, string $value, string $path): void
    {
        try {
            $this->analyticsCollector()->collect(['p' => '/' . ltrim($path, '/'), 'e' => $type, 'v' => $value], $_SERVER, $this->auth->check());
        } catch (\Throwable) {
            // A goal that cannot be counted is not worth an error.
        }
    }

    private function analyticsStore(): AnalyticsStore
    {
        return $this->analyticsStoreService ??= new AnalyticsStore($this->systemDatabase, $this->systemMeta, AnalyticsSettings::from($this->settings)['keep_months']);
    }

    private function analyticsCollector(): AnalyticsCollector
    {
        return $this->analyticsCollectorService ??= new AnalyticsCollector(fn(): AnalyticsStore => $this->analyticsStore(), fn(): array => $this->settings);
    }

    private function analyticsAdmin(): AnalyticsAdmin
    {
        return $this->analyticsAdminService ??= new AnalyticsAdmin(
            $this->siteSettings(),
            $this->analyticsStore(),
            fn(): array => $this->settings,
            function (): void {
                $this->settings = $this->siteSettings()->load();
            },
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    /** Admin > Analytics: the settings, and the reports when the platform's analytics are on. */
    private function handleAnalytics(): void
    {
        $admin = $this->analyticsAdmin();
        $mayWriteCode = $this->permissions->can($this->auth->user(), 'content.raw_html');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->redirect($admin->save($_POST, $mayWriteCode));
            return;
        }
        if ((string)($_GET['realtime'] ?? '') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($admin->now());
            return;
        }
        $export = (string)($_GET['export'] ?? '');
        if ($export !== '') {
            $file = $admin->export($export, $_GET);
            if ($file === null) {
                $this->render404();
                return;
            }
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $file['name'] . '"');
            echo $file['body'];
            return;
        }
        $tab = $admin->tab((string)($_GET['tab'] ?? ''));
        $this->render('@admin/analytics.twig', [
            'tab' => $tab,
            'saved' => (string)($_GET['saved'] ?? '') === '1',
            'cleared' => (string)($_GET['cleared'] ?? '') === '1',
            'error' => (string)($_GET['error'] ?? ''),
            'user' => $this->auth->user(),
            'types' => $this->content->getTypes(),
            'admin_section' => 'analytics',
            'current_type' => 'pages',
        ] + ($tab === 'reports' ? $admin->reportsScreen($_GET) : []) + $admin->settingsScreen($mayWriteCode));
    }

    /** Admin > SEO: a tab for each part of how the site meets search engines and social networks. */
    private function handleSeo(): void
    {
        $admin = $this->seoAdmin();
        $tab = SeoAdmin::tab((string)($_GET['tab'] ?? ''));
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->redirect($admin->save($tab, $_POST));
            return;
        }
        $this->render('@admin/seo.twig', $admin->screen($tab) + [
            'saved' => (string)($_GET['saved'] ?? '') === '1',
            'error' => (string)($_GET['error'] ?? ''),
            'user' => $this->auth->user(),
            'types' => $this->content->getTypes(),
            'admin_section' => 'seo',
            'current_type' => 'pages',
        ]);
    }

    private function seoAdmin(): SeoAdmin
    {
        return $this->seoAdminService ??= new SeoAdmin(
            $this->siteSettings(),
            new SeoAudit($this->content, fn(): array => $this->settings),
            fn(): array => $this->settings,
            function (): void {
                $this->settings = $this->siteSettings()->load();
            },
            fn(): array => $this->themeSettings,
            fn(): array => $this->content->getTypes(),
            fn(): int => $this->redirects->isAvailable() ? $this->redirects->notFoundCount() : 0,
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function handleRedirects(): void
    {
        $admin = $this->redirectAdmin();
        $repo = $this->redirects;
        $tab = (string)($_GET['tab'] ?? '') === 'missing' ? 'missing' : 'redirects';
        $showIgnored = $tab === 'missing' && (string)($_GET['show'] ?? '') === 'ignored';
        $form = $admin->blankForm();
        $error = '';
        $importReport = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $result = $admin->apply($_POST, $this->currentUsername());
            if ($result['location'] !== null) {
                $this->redirect($result['location']);
                return;
            }
            $form = $result['form'];
            $error = $result['error'];
            $importReport = $result['report'];
            $tab = $result['tab'] ?? $tab;
        }

        $known = $this->publicPaths()->map();
        $filters = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'origin' => (string)($_GET['origin'] ?? ''),
            'state' => (string)($_GET['state'] ?? ''),
        ];
        $perPage = 100;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $total = $repo->count($filters);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $form = $admin->formFor((int)($_GET['edit'] ?? 0), isset($_GET['source']) ? (string)$_GET['source'] : null, (string)($_GET['target'] ?? ''));
        }

        $rows = $admin->rows($filters, $perPage, $page);
        // Links inside content that still use an old address (only for permanent redirects that are on).
        $linkCounts = null;
        if ($this->permissions->can($this->auth->user(), 'content.manage')) {
            $usable = array_column(array_filter($rows, static fn(array $r): bool => (bool)$r['enabled'] && (int)$r['status_code'] === 301), 'source');
            $linkCounts = $usable !== [] ? $this->linkScanner()->countBySource($usable) : [];
        }
        foreach ($rows as $i => $row) {
            $rows[$i]['links'] = $linkCounts === null ? null : ($linkCounts[$row['source']] ?? 0);
        }

        $this->render('@admin/redirects.twig', [
            'tab' => $tab,
            'rows' => $rows,
            'missing' => $tab === 'missing' ? $admin->missing(trim((string)($_GET['q'] ?? '')), 200, $showIgnored) : [],
            'show_ignored' => $showIgnored,
            'missing_ignored_total' => $repo->notFoundIgnoredCount(),
            'stats' => $repo->stats(),
            'missing_total' => $repo->notFoundCount(),
            'filters' => $filters,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $perPage)),
            'total' => $total,
            'form' => $form,
            'error' => $error !== '' ? $error : (string)($_GET['error'] ?? ''),
            'done' => (string)($_GET['done'] ?? ''),
            'done_count' => (int)($_GET['n'] ?? 0),
            'import_report' => $importReport,
            'known_paths' => array_slice($known, 0, 500, true),
            'store_ok' => $repo->isAvailable(),
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'redirects',
            'current_type' => 'pages',
        ]);
    }

    private function redirectAdmin(): RedirectAdmin
    {
        return $this->redirectAdminService ??= new RedirectAdmin(
            $this->redirects,
            $this->publicPaths(),
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function publicPaths(): PublicPaths
    {
        return $this->publicPathsService ??= new PublicPaths(
            $this->content,
            fn(): Taxonomies => $this->taxonomies(),
            fn(): array => $this->settings,
            fn(): string => $this->getBaseUrl()
        );
    }

    private function linkScanner(): LinkScanner
    {
        $hosts = [
            (string)(parse_url($this->getBaseUrl(), PHP_URL_HOST) ?? ''),
            explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0],
        ];
        return new LinkScanner(
            $this->contentDir,
            array_map('strval', $this->settings['languages']['available'] ?? [$this->defaultLanguage()]),
            $this->defaultLanguage(),
            $hosts
        );
    }

    /**
     * Links inside content that still use an old address. Lists where they are and, on request, points them at the
     * redirect's target, so visitors no longer take the detour. Only the address in the text changes.
     */
    private function handleLinks(): void
    {
        $raw = (string)($_POST['ids'] ?? $_GET['ids'] ?? '');
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), static fn(int $id): bool => $id > 0)));
        $map = $this->redirectAdmin()->linkFixes(array_slice($ids, 0, 50));
        $scanner = $this->linkScanner();
        $may = fn(string $type): bool => $this->canAccessContentType($type);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $map !== []) {
            $result = $this->contentEditor()->updateLinks($scanner, $map, $this->currentUsername(), $may);
            $this->logActivity('content.links_updated', 'info', 'links', implode(',', array_keys($map)), 'Links to changed addresses updated.', [
                'entries' => $result['files'],
                'links' => $result['links'],
                'addresses' => array_keys($map),
            ]);
            $this->redirect('/admin/links?ids=' . implode(',', $ids) . '&done=' . $result['files'] . '-' . $result['links'] . '-' . $result['failed']);
            return;
        }

        $entries = [];
        $total = 0;
        foreach ($scanner->find(array_keys($map)) as $found) {
            if (!$may($found['type'])) {
                continue;
            }
            $item = $this->content->find($found['type'], $found['slug'], $found['lang'], true, false);
            $found['title'] = $item !== null ? (string)($item->meta['title'] ?? $found['slug']) : $found['slug'];
            $found['edit_url'] = '/admin/edit?type=' . urlencode($found['type']) . '&slug=' . urlencode($found['slug']) . '&lang=' . urlencode($found['lang']);
            $entries[] = $found;
            $total += $found['count'];
        }
        $done = array_map('intval', explode('-', (string)($_GET['done'] ?? '')) + [0, 0, 0]);
        $this->render('@admin/links.twig', [
            'fixes' => array_map(fn(string $source, string $target): array => ['source' => $source, 'target' => $target], array_keys($map), array_values($map)),
            'skipped' => count($ids) - count($map),
            'ids' => implode(',', $ids),
            'entries' => $entries,
            'total' => $total,
            'done' => isset($_GET['done']) ? ['entries' => $done[0], 'links' => $done[1], 'failed' => $done[2]] : null,
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'redirects',
            'current_type' => 'pages',
        ]);
    }

    private function handleRevisions(): void
    {
        $seesForms = $this->permissions->can($this->auth->user(), 'forms.manage');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $result = $this->revisionAdmin()->restore($_POST, $this->permissions->can($this->auth->user(), 'content.raw_html'), $seesForms, $this->currentUsername());
            if ($result['denied'] !== '') {
                $this->denyContentType($result['denied']);
                return;
            }
            $this->redirect($result['location']);
            return;
        }

        $screen = $this->revisionAdmin()->screen($_GET, $seesForms);
        if ($screen['denied'] !== '') {
            $this->denyContentType($screen['denied']);
            return;
        }
        if ($screen['location'] !== '') {
            $this->redirect($screen['location']);
            return;
        }
        $this->render($screen['template'], $screen['data'] + ['user' => $this->auth->user()]);
    }

    private function handleUsersList(): void
    {
        if (!$this->permissions->can($this->auth->user(), 'users.manage')) {
            $this->renderForbidden();
            return;
        }

        $filters = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'role' => trim((string)($_GET['role'] ?? '')),
            'status' => trim((string)($_GET['status'] ?? '')),
        ];
        $this->render('@admin/users.twig', [
            'roles' => $this->permissions->allRoles(),
            'users' => $this->users->all($filters),
            'filters' => $filters,
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'users',
            'current_type' => 'pages',
            'saved' => isset($_GET['saved']),
            'deleted' => isset($_GET['deleted']),
        ]);
    }

    private function handleActivityLogs(): void
    {
        $this->render('@admin/activity-logs.twig', $this->logAdmin()->activity($_GET) + ['types' => $this->content->getTypes(), 'user' => $this->auth->user()]);
    }

    private function handleEmailLogs(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && trim((string)($_POST['email_log_action'] ?? '')) === 'clear') {
            $this->redirect('/admin/email-logs?cleared=' . $this->logAdmin()->clearEmail());
            return;
        }
        $this->render('@admin/email-logs.twig', $this->logAdmin()->email($_GET) + ['types' => $this->content->getTypes(), 'user' => $this->auth->user()]);
    }

    private function handleNotificationRead(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/admin');
            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        $target = $this->sanitizeAdminReturnUrl((string)($_POST['target_url'] ?? '/admin'));
        if ($id > 0 && $this->notifications->markRead($id)) {
            $this->logActivity('notification.read', 'info', 'notification', (string)$id, 'Notification marked as read.');
        }
        $this->redirect($target);
    }

    private function handleNotificationsReadAll(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/admin');
            return;
        }

        $count = $this->notifications->markAllRead();
        if ($count > 0) {
            $this->logActivity('notifications.read_all', 'info', 'notification', 'all', 'Notifications marked as read.', [
                'count' => $count,
            ]);
        }
        $this->redirect($this->sanitizeAdminReturnUrl((string)($_POST['return_to'] ?? '/admin')));
    }

    private function handleBackups(): void
    {
        $downloadBackup = trim((string)($_GET['download'] ?? ''));
        if ($downloadBackup !== '') {
            $this->downloadBackupSnapshot($downloadBackup, '/admin/backups');
            return;
        }

        $restoreTarget = trim((string)($_GET['restore'] ?? ''));
        if ($restoreTarget !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->renderBackupRestore($restoreTarget);
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = trim((string)($_POST['backup_action'] ?? ''));
            if ($action === 'restore') {
                $this->handleBackupRestore();
                return;
            }
            if ($action === 'fetch_remote' && !$this->permissions->can($this->auth->user(), 'backups.restore')) {
                $this->renderForbidden('Fetching backups from remote storage is limited to superadmins, as restoring is.');
                return;
            }
            $location = $this->backupAdmin()->apply($action, $_POST);
            if ($location !== null) {
                $this->redirect($location);
                return;
            }
        }

        // The bucket is only read when asked for (a link on the screen), and only for those who can restore from what it holds.
        $remote = null;
        if ((string)($_GET['remote'] ?? '') === '1' && $this->permissions->can($this->auth->user(), 'backups.restore')) {
            $remote = $this->backupAdmin()->remoteBackups();
        }

        $this->render('@admin/backups.twig', $this->backupAdmin()->overview() + [
            'remote_listing' => $remote,
            'title' => 'Backups',
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'backups',
            'current_type' => 'pages',
            'backup_cron_command' => 'php ' . escapeshellarg($this->basePath . '/src/cron-backup.php'),
            'backup_status' => (string)($_GET['backup'] ?? ''),
            'delete_status' => (string)($_GET['deleted'] ?? ''),
            'backup_message' => trim((string)($_GET['backup_msg'] ?? '')),
        ]);
    }

    private function backupAdmin(): BackupAdmin
    {
        return $this->backupAdminService ??= new BackupAdmin(
            $this->backups,
            $this->backupManager(),
            $this->backupRuns,
            $this->notifications,
            $this->systemMeta,
            fn(): array => $this->settings,
            fn(string $isoDate) => $this->updateBackupLastRun($isoDate),
            function (bool $ok): void {
                $this->settings = $this->siteSettings()->load();
                $this->themeSettings = $this->loadThemeSettings();
                $this->menus()->forget();
                $this->taxonomies()->forget();
                if ($ok) {
                    $this->rebuildContentIndex();
                }
            },
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context, ?array $actor) => $this->logActivity($action, $level, $type, $id, $message, $context, $actor)
        );
    }

    private function renderBackupRestore(string $filename, string $error = '', array $selected = []): void
    {
        if (!$this->permissions->can($this->auth->user(), 'backups.restore')) {
            $this->renderForbidden('Restoring backups is limited to superadmins.');
            return;
        }
        $screen = $this->backupAdmin()->restoreScreen($filename, $selected);
        if ($screen === null) {
            $this->redirect('/admin/backups?' . http_build_query(['backup' => 'fail', 'backup_msg' => 'Backup file not found.']));
            return;
        }

        $this->render('@admin/backup-restore.twig', $screen + [
            'title' => 'Restore backup',
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'backups',
            'current_type' => 'pages',
            'error' => $error,
            'current_version' => $this->updates()->currentVersion(),
        ]);
    }

    private function handleBackupRestore(): void
    {
        if (!$this->permissions->can($this->auth->user(), 'backups.restore')) {
            $this->renderForbidden('Restoring backups is limited to superadmins.');
            return;
        }
        $outcome = $this->backupAdmin()->restore($_POST, $this->auth->user());
        if ($outcome['location'] !== null) {
            $this->redirect($outcome['location']);
            return;
        }
        $this->renderBackupRestore($outcome['filename'], $outcome['error'], $outcome['selected']);
    }

    private function handleUpdates(): void
    {
        $admin = $this->updateAdmin();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = trim((string)($_POST['updates_action'] ?? ''));
            if ($action === 'pre_update_backup') {
                if (!$this->permissions->can($this->auth->user(), 'backups.manage')) {
                    $this->renderForbidden();
                    return;
                }
                $this->redirect($admin->backupFirst());
                return;
            }
            if ($action === 'check') {
                $this->redirect($admin->check());
                return;
            }
            if ($action === 'install') {
                $this->redirect($admin->install());
                return;
            }
            if ($action === 'rollback') {
                $this->redirect($admin->rollback());
                return;
            }
        }
        $this->render('@admin/updates.twig', $admin->screen($_GET) + ['types' => $this->content->getTypes(), 'user' => $this->auth->user()]);
    }

    private function handleUserEdit(): void
    {
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        $currentUser = $this->auth->user();
        $currentId = (int)($currentUser['id'] ?? 0);
        $canManageUsers = $this->permissions->can($currentUser, 'users.manage');
        if ($id === 0 && !$this->permissions->canCreateUsers($currentUser)) {
            $this->renderForbidden();
            return;
        }
        if ($id > 0 && !$this->permissions->canEditUser($currentUser, $id)) {
            $this->renderForbidden();
            return;
        }

        $saved = isset($_GET['saved']);
        $error = '';
        $user = $id > 0 ? $this->users->find($id) : null;
        if ($id > 0 && !$user) {
            $this->redirect('/admin/users');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $result = $this->userAdmin()->save($id, $user, $currentUser, $_POST);
            if ($result['ok']) {
                if ($result['password_changed'] && $result['id'] === $currentId) {
                    unset($_SESSION['security_default_password']);
                }
                $this->redirect('/admin/users-edit?id=' . $result['id'] . '&saved=1');
                return;
            }
            $error = $result['error'];
            $user = array_merge($user ?: [], $result['payload'], ['id' => $id]);
        }

        $this->render('@admin/user-edit.twig', [
            'title' => $canManageUsers ? ($id === 0 ? 'Add user' : 'Edit user') : 'Your profile',
            'edited_user' => $user ?: UserAdmin::blank(),
            'roles' => $this->permissions->allRoles(),
            'is_new' => $id === 0,
            'error' => $error,
            'saved' => $saved,
            'can_manage_users' => $canManageUsers,
            'can_change_access' => $this->permissions->canChangeAccessForUser($currentUser, $id),
            'is_self' => $id > 0 && $id === $currentId,
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'users',
            'current_type' => 'pages',
        ]);
    }

    private function userAdmin(): UserAdmin
    {
        return $this->userAdminService ??= new UserAdmin(
            $this->users,
            $this->auth,
            $this->permissions,
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function handleUserDelete(): void
    {
        if (!$this->permissions->can($this->auth->user(), 'users.manage')) {
            $this->renderForbidden();
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/admin/users');
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        $currentId = (int)($this->auth->user()['id'] ?? 0);
        if ($id <= 0 || $id === $currentId) {
            $this->renderForbidden();
            return;
        }
        $this->users->deactivate($id);
        $this->logActivity('users.deactivate', 'warning', 'user', (string)$id, 'User deactivated.');
        $this->redirect('/admin/users?deleted=1');
    }

    private function handleAdminList(): void
    {
        $result = $this->contentAdmin()->listing($_GET, $this->permissions->can($this->auth->user(), 'redirects.manage'));
        if ($result['location'] !== '') {
            $this->redirect($result['location']);
            return;
        }
        $this->render('@admin/list.twig', $result['data'] + ['user' => $this->auth->user()]);
    }

    private function handleContentBulk(): void
    {
        $this->redirect($this->contentAdmin()->bulk(
            $_POST,
            ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST',
            $this->permissions->can($this->auth->user(), 'redirects.manage'),
            $this->currentUsername()
        ));
    }

    private function handleEdit(): void
    {
        if (($_GET['preview'] ?? '') === 'entry' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->previewEntry();
            return;
        }
        $type = $this->sanitizeType((string)($_GET['type'] ?? 'pages'));
        if (!$this->canAccessContentType($type)) {
            $this->denyContentType($type);
            return;
        }
        $slug = Slug::plain((string)($_GET['slug'] ?? ''));
        $lang = Slug::plain((string)($_GET['lang'] ?? $this->defaultLanguage()));
        if ($type === 'forms' && $slug === '') {
            $lang = $this->defaultLanguage(); // a new form is built in the first language
        } elseif ($type === 'forms' && $lang !== $this->defaultLanguage()) {
            // A form is built once, in the first language; its other languages are translations edited in the same builder.
            $query = ['type' => 'forms', 'slug' => $slug, 'lang' => $this->defaultLanguage(), 'translate' => $lang] + array_diff_key($_GET, ['type' => 1, 'slug' => 1, 'lang' => 1]);
            $this->redirect('/admin/edit?' . http_build_query($query));
            return;
        }
        $this->render($type === 'forms' ? '@admin/form-edit.twig' : '@admin/edit.twig', $this->entryForm()->form($type, $slug, $lang, $_GET, $this->permissions->can($this->auth->user(), 'redirects.manage')) + ['user' => $this->auth->user()]);
    }

    /**
     * JSON for the live preview of the content editor: the page the submitted, unsaved form would make, drawn by the theme through
     * the same way a visitor's page is. The form is read exactly as Save reads it (ContentEditor, including who may add raw HTML),
     * but nothing is written: no file, no history, no redirect. Whoever may edit the type may preview it.
     */
    private function previewEntry(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $type = $this->sanitizeType((string)($_POST['type'] ?? 'pages'));
        if (!$this->canAccessContentType($type) || $type === 'forms') {
            http_response_code(403);
            echo json_encode(['error' => 'This cannot be previewed.']);
            return;
        }
        try {
            $result = $this->contentEditor()->save($type, $_POST, null, $this->permissions->can($this->auth->user(), 'content.raw_html'), $this->currentUsername(), true);
        } catch (\Throwable $e) {
            echo json_encode(['error' => 'The front matter cannot be read: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            return;
        }
        $slug = $result['slug'];
        $lang = $result['lang'];
        $item = $this->content->parseText($type, $slug, $lang, (string)$result['payload']);
        $homeSlug = (string)(($this->settings['home_page'] ?? '') !== '' ? $this->settings['home_page'] : 'index');
        $path = ContentPaths::build($type, $slug, $lang, $homeSlug, $this->defaultLanguage());

        $kept = [$_POST, $_GET, $_SERVER['REQUEST_METHOD'] ?? 'GET'];
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->content->showInstead($item);
        $this->entryPreview = true;
        ob_start();
        try {
            $this->handleFront($path);
        } finally {
            $html = (string)ob_get_clean();
            $this->entryPreview = false;
            $this->content->showInstead(null);
            [$_POST, $_GET, $_SERVER['REQUEST_METHOD']] = $kept;
        }
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['html' => $html, 'path' => '/' . $path], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function contentTypes(): ContentTypes
    {
        return $this->contentTypes ??= new ContentTypes($this->theme);
    }

    /**
     * Content of some types needs more than content.manage: forms hold visitors' personal data and decide
     * where notifications are sent, so only those who manage forms may open, change, or delete them.
     */
    private function canAccessContentType(string $type): bool
    {
        return $type !== 'forms' || $this->permissions->can($this->auth->user(), 'forms.manage');
    }

    private function denyContentType(string $type): void
    {
        $this->logActivity('auth.forbidden', 'warning', 'content_type', $type, 'Blocked access to a content type that needs more permission.', [
            'type' => $type,
            'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        ]);
        $this->renderForbidden();
    }

    private function defaultLanguage(): string
    {
        return (string)($this->settings['languages']['default'] ?? 'en');
    }

    private function presetLibrary(): PresetLibrary
    {
        return $this->presetLibrary ??= new PresetLibrary(
            $this->theme,
            $this->blockRuntime()->registry(),
            fn(string $value): string => Slug::plain($value)
        );
    }

    private function handleMediaPicker(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $this->media->ensureDirectories();
        $this->media->migrateLegacyItems();
        echo json_encode($this->mediaAdmin()->picker($_GET), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** The Markdown of the content editor as the visual editor draws it, block by block (JSON out); nothing is stored. */
    private function handleMarkdownVisual(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'POST only.']);
            return;
        }
        $body = (string)($_POST['body'] ?? '');
        if (strlen($body) > 2_000_000) {
            http_response_code(413);
            echo json_encode(['ok' => false, 'message' => 'The text is too long for the visual editor.']);
            return;
        }
        echo json_encode(['ok' => true] + (new VisualMarkdown(fn(): Environment => $this->markdownEnvironment()))->render($body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Saves or deletes a site section from the block editor (JSON in, JSON out). */
    private function handleBlockPresets(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'POST only.']);
            return;
        }
        $action = (string)($_POST['preset_action'] ?? '');
        $lang = Slug::plain((string)($_POST['lang'] ?? ''));
        $defaultLang = (string)($this->settings['languages']['default'] ?? 'en');
        $library = $this->presetLibrary();

        if ($action === 'save') {
            $blocks = json_decode((string)($_POST['blocks_json'] ?? ''), true);
            $result = $library->saveCustom(
                (string)($_POST['name'] ?? ''),
                (string)($_POST['description'] ?? ''),
                $lang,
                is_array($blocks) ? array_values($blocks) : []
            );
            if ($result['ok']) {
                $this->logActivity('presets.save', 'info', 'preset', (string)$result['id'], 'Block section saved.');
                foreach ($library->forEditor($lang, $defaultLang) as $preset) {
                    if ($preset['id'] === $result['id']) {
                        $result['preset'] = $preset;
                    }
                }
            } else {
                http_response_code(422);
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($action === 'delete') {
            $id = (string)($_POST['id'] ?? '');
            $ok = $library->deleteCustom($id);
            if ($ok) {
                $this->logActivity('presets.delete', 'warning', 'preset', $id, 'Block section deleted.');
            } else {
                http_response_code(404);
            }
            echo json_encode(['ok' => $ok]);
            return;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
    }

    private function handleSave(): void
    {
        $type = $this->sanitizeType((string)($_POST['type'] ?? 'pages'));
        if (!$this->canAccessContentType($type)) {
            $this->denyContentType($type);
            return;
        }

        // A main image uploaded with the form replaces the address typed in the field.
        $main = $this->mediaAdmin()->uploadMainImage($_FILES['main_image_upload'] ?? null, $this->currentUsername());
        $uploadedImage = $main['url'];
        $storageBlocked = $main['blocked'];

        $postedFrontMatter = trim((string)($_POST['frontmatter'] ?? ''));
        if ($postedFrontMatter !== '') {
            try {
                Yaml::parse($postedFrontMatter);
            } catch (\Throwable $e) {
                // Nothing is written: the raw front matter must be readable first.
                $this->redirect('/admin/edit?type=' . urlencode($type) . '&slug=' . urlencode((string)($_POST['original_slug'] ?? $_POST['slug'] ?? '')) . '&lang=' . urlencode((string)($_POST['original_lang'] ?? $_POST['lang'] ?? '')) . '&frontmatter_error=' . urlencode($e->getMessage()));
                return;
            }
        }
        $result = $this->contentEditor()->save(
            $type,
            $_POST,
            $uploadedImage,
            $this->permissions->can($this->auth->user(), 'content.raw_html'),
            $this->currentUsername()
        );
        $slug = $result['slug'];
        $lang = $result['lang'];

        if ($result['moved'] && $result['moved_together']) {
            // Menu links follow a page whose address changed in every language.
            $old = $result['original_slug'];
            $defaultLang = $this->defaultLanguage();
            $homeSlug = (string)(($this->settings['home_page'] ?? '') !== '' ? $this->settings['home_page'] : 'index');
            $this->menus()->relink(ContentPaths::build($type, $old, $defaultLang, $homeSlug, $defaultLang), ContentPaths::build($type, $slug, $defaultLang, $homeSlug, $defaultLang));
        }

        $this->logActivity($result['was_existing'] ? 'content.update' : 'content.create', 'info', $type, $slug . ':' . $lang, ($result['was_existing'] ? 'Content updated.' : 'Content created.'), [
            'type' => $type,
            'slug' => $slug,
            'lang' => $lang,
            'title' => $result['title'],
            'status' => $result['status'],
            'renamed_from' => $result['moved'] ? $result['original_slug'] . ':' . $result['original_lang'] : '',
            'translations_renamed' => $result['translations_renamed'],
        ]);
        $this->redirect('/admin/edit?type=' . urlencode($type) . '&slug=' . urlencode($slug) . '&lang=' . urlencode($lang) . '&saved=1'
            . ($type === 'forms' && Slug::plain((string)($_POST['translate_lang'] ?? '')) !== '' ? '&translate=' . urlencode(Slug::plain((string)$_POST['translate_lang'])) : '')
            . ($result['html_neutralized'] ? '&notice=html' : ($storageBlocked ? '&notice=storage' : ''))
            . ($result['moved'] ? '&address=changed' : '')
            . ($result['slug_taken'] !== '' ? '&address_taken=' . urlencode($result['slug_taken']) : ''));
    }

    private function handleDelete(): void
    {
        $post = $_SERVER['REQUEST_METHOD'] === 'POST';
        $source = $post ? $_POST : $_GET;
        $type = $this->sanitizeType((string)($source['type'] ?? 'pages'));
        if (!$this->canAccessContentType($type)) {
            $this->denyContentType($type);
            return;
        }
        $result = $this->contentAdmin()->delete(
            $type,
            $source,
            $post,
            $this->permissions->can($this->auth->user(), 'redirects.manage'),
            $this->permissions->can($this->auth->user(), 'content.manage'),
            $this->currentUsername()
        );
        if ($result['location'] !== '') {
            $this->redirect($result['location']);
            return;
        }
        $this->render('@admin/delete.twig', $result['view'] + ['user' => $this->auth->user()]);
    }

    private function handleNew(): void
    {
        $type = $this->sanitizeType((string)($_GET['type'] ?? 'pages'));
        if (!$this->canAccessContentType($type)) {
            $this->denyContentType($type);
            return;
        }
        $slug = Slug::plain((string)($_GET['slug'] ?? ''));
        $lang = Slug::plain((string)($_GET['lang'] ?? ($this->settings['languages']['default'] ?? 'en')));
        $template = Slug::plain((string)($_GET['template'] ?? ''));
        if ($type === 'forms' && $slug === '' && $template === '') {
            // A new form starts from a ready-made one, or from nothing.
            $this->redirect('/admin/forms-new?lang=' . urlencode($lang));
            return;
        }

        $this->redirect('/admin/edit?type=' . urlencode($type) . '&slug=' . urlencode($slug) . '&lang=' . urlencode($lang) . ($template !== '' ? '&template=' . urlencode($template) : ''));
    }

    private function handleFormsNew(): void
    {
        $languages = array_map('strval', $this->settings['languages']['available'] ?? [$this->defaultLanguage()]);
        // A new form is built in the first language; the others are translations of it.
        $lang = $this->defaultLanguage();
        $this->render('@admin/forms-new.twig', [
            'templates' => FormTemplates::all($lang),
            'lang' => $lang,
            'languages' => $languages,
            'admin_section' => 'forms',
            'current_type' => 'forms',
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
        ]);
    }

    private function handleSettings(): void
    {
        $admin = $this->settingsAdmin();
        $post = $_SERVER['REQUEST_METHOD'] === 'POST';
        if (strtolower((string)($_GET['tab'] ?? '')) === 'theme' && !$post) {
            // The theme has its own page now.
            $this->redirect('/admin/theme');
            return;
        }
        $downloadBackup = trim((string)($_GET['download_backup'] ?? ''));
        if ($downloadBackup !== '') {
            $this->downloadBackupSnapshot($downloadBackup);
            return;
        }
        $canLimits = $this->permissions->can($this->auth->user(), 'limits.manage');
        if ($post && (string)($_POST['storage_action'] ?? '') === 'recalculate') {
            $this->redirect($admin->recalculateStorage($canLimits));
            return;
        }
        if ($post) {
            $upload = $this->submittedUploadSettings();
            $this->redirect($admin->save($_POST, [
                'storage_limit_mb' => $this->submittedStorageLimit(),
                'upload_limit_mb' => $upload['mb'],
                'upload_types' => $upload['types'],
            ], SettingsAdmin::tab((string)($_GET['tab'] ?? 'basics'))));
            return;
        }
        $this->render('@admin/settings.twig', $admin->screen($_GET, $canLimits, $this->listBackupSnapshots()) + [
            'user' => $this->auth->user(),
            'types' => $this->content->getTypes(),
        ]);
    }

    /**
     * Theme settings: one screen, a tab for each section the theme declares, and two tabs that gather how pages look: Single
     * Layouts (a card for each content type) and Archive Layouts (a card for each list of entries).
     */
    private function handleTheme(): void
    {
        $schema = $this->theme->settingsSchema();
        $single = $this->singleLayouts();
        $tabs = [];
        // A theme that declares the design section has the Branding screen: appearance, brand and design in one tab.
        $branding = isset($schema['design']);
        if ($branding && isset($_GET['branding'])) {
            $this->handleBrandingTransfer((string)$_GET['branding']);
            return;
        }
        // The template built with blocks for the page of one content type (Single Layouts > Edit template).
        if (isset($_GET['template'])) {
            $this->handleSingleTemplate((string)$_GET['template']);
            return;
        }
        if ($branding && $_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['preview'] ?? '') === 'styles') {
            // An admin-only specimen, using the site's actual styles. It is never content and stores nothing.
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex, nofollow');
            echo $this->twig->render('@admin/theme-style-samples.twig', [
                'attributes' => Branding::attributes($this->themeSettings),
            ]);
            return;
        }
        // The palette studio of Branding asks for a palette made from two colours, and for the Duo colour pairings (JSON, nothing stored).
        if ($branding && in_array($_GET['palette'] ?? '', ['generate', 'duo', 'copy', 'fix'], true)) {
            $this->paletteRequest((string)$_GET['palette']);
            return;
        }
        if ($branding && $_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['preview'] ?? '') === 'specimen') {
            // The three button styles in their states and a paragraph with a link, in light and dark, drawn with the site's own styles.
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex, nofollow');
            echo $this->twig->render('@admin/theme-button-specimen.twig', [
                'attributes' => Branding::attributes($this->themeSettings),
            ]);
            return;
        }
        foreach ($schema as $key => $section) {
            // The sidebar card is part of Single Layouts when the theme has them.
            if ($key === 'sidebar' && $single->declared()) {
                continue;
            }
            if ($branding && in_array($key, ['appearance', 'brand', 'design'], true)) {
                if (!in_array('branding', $tabs, true)) {
                    $tabs[] = 'branding';
                }
                continue;
            }
            if (array_filter($section['fields'], static fn(array $field): bool => !$field['hidden']) !== []) {
                $tabs[] = $key;
            }
        }
        // The blocks above the footer have a tab of their own, right after the footer's.
        $footerAt = array_search('footer', $tabs, true);
        array_splice($tabs, $footerAt === false ? count($tabs) : $footerAt + 1, 0, ['footer_blocks']);
        // The two tabs that gather how pages look come after the header's.
        $at = array_search('header', $tabs, true);
        array_splice($tabs, $at === false ? min(1, count($tabs)) : $at + 1, 0, $single->declared() ? ['single_layouts', 'archive_layouts'] : ['archive_layouts']);
        // The site's own stylesheet has the last tab.
        $tabs[] = 'custom_css';
        $activeTab = (string)($_GET['tab'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // The fonts of the Branding screen: a file uploaded, or the old single font file read as a family (nothing is saved yet).
            if ($branding && ($_GET['preview'] ?? '') === 'fonts') {
                $this->brandingFontsRequest();
                return;
            }
            // The live preview of Branding asks what the unsaved choices make of the pages (nothing is stored).
            if ($branding && ($_GET['preview'] ?? '') === 'branding') {
                $this->previewBranding(is_array($_POST['theme_settings'] ?? null) ? $_POST['theme_settings'] : []);
                return;
            }
            // Header and Footer ask for a whole page drawn with the unsaved choices.
            if (($_GET['preview'] ?? '') === 'page') {
                $this->previewPage(is_array($_POST['theme_settings'] ?? null) ? $_POST['theme_settings'] : [], (string)($_POST['preview_path'] ?? ''));
                return;
            }
            $activeTab = (string)($_POST['active_tab'] ?? $activeTab);
            $save = $this->saveThemeSettings(
                is_array($_POST['theme_settings'] ?? null) ? $_POST['theme_settings'] : [],
                is_array($_POST['single_layouts'] ?? null) ? $_POST['single_layouts'] : null,
                ($_POST['blocks_editor'] ?? '') === '1' ? json_decode((string)($_POST['blocks_json'] ?? ''), true) : null,
                is_array($_POST['archive_search'] ?? null) ? $_POST['archive_search'] : null,
                is_array($_POST['single_template'] ?? null) ? $_POST['single_template'] : null
            );
            $this->themeSettings = $this->loadThemeSettings();
            if (($save['ok'] ?? false) === true && is_string($_POST['custom_css'] ?? null)) {
                $save = (new CustomCss($this->theme->customPath()))->save($_POST['custom_css']);
            }
            if (($save['ok'] ?? false) === true && (is_array($_POST['archive_types'] ?? null) || is_array($_POST['archive_taxonomies'] ?? null))) {
                $failed = $this->layoutsAdmin()->saveArchives(
                    is_array($_POST['archive_types'] ?? null) ? $_POST['archive_types'] : [],
                    is_array($_POST['archive_taxonomies'] ?? null) ? $_POST['archive_taxonomies'] : [],
                    $this->defaultLanguage()
                );
                if ($failed !== []) {
                    $save = ['ok' => false, 'message' => 'Could not write the archive layout of ' . implode(', ', $failed) . '. Check that the custom/ folder is writable.'];
                }
            }
            if (($save['ok'] ?? false) !== true) {
                $this->redirect('/admin/theme?theme=fail&tab=' . urlencode($activeTab) . '&theme_msg=' . urlencode((string)($save['message'] ?? 'Theme settings could not be saved.')));
                return;
            }
            $this->logActivity('theme.update', 'info', 'settings', $this->theme->name(), 'Theme settings updated.', ['tab' => $activeTab]);
            $this->redirect('/admin/theme?saved=1&tab=' . urlencode($activeTab));
            return;
        }
        if (!in_array($activeTab, $tabs, true)) {
            $activeTab = $tabs[0] ?? '';
        }
        $default = $this->defaultLanguage();
        $this->render('@admin/theme.twig', [
            'user' => $this->auth->user(),
            'types' => $this->content->getTypes(),
            'admin_section' => 'theme',
            'saved' => isset($_GET['saved']),
            'theme_status' => (string)($_GET['theme'] ?? ''),
            'theme_message' => trim((string)($_GET['theme_msg'] ?? '')),
            'theme_schema' => $schema,
            'theme_tabs' => $tabs,
            'tab_labels' => ['branding' => 'Branding', 'single_layouts' => 'Single Layouts', 'archive_layouts' => 'Archive Layouts', 'footer_blocks' => 'Footer Blocks', 'custom_css' => 'Custom CSS'],
            'custom_css' => (new CustomCss($this->theme->customPath()))->read(),
            'footer_blocks_json' => $this->footerBlocksEditorJson($default),
            'branding' => $branding ? $this->brandingScreen() : null,
            'preview_url' => (string)($this->settings['base_url'] ?? '') . '/',
            'theme_values' => $this->themeSettings,
            'theme_info' => [
                'name' => $this->theme->name(),
                'label' => $this->theme->label(),
                'version' => $this->theme->version(),
                'custom_dir' => is_dir($this->theme->customPath()),
            ],
            'active_tab' => $activeTab,
            'single_declared' => $single->declared(),
            'single_fields' => $single->fields(),
            'single_templates' => $single->templates(),
            'single_cards' => $single->declared() ? $this->layoutsAdmin()->singleCards($this->themeSettings, $default) : [],
            'archive_cards' => $this->layoutsAdmin()->archiveCards($default),
            'archive_layouts' => ContentTypes::LAYOUTS,
            'archive_title_layouts' => ContentTypes::TITLE_LAYOUTS,
            'search_page' => SearchPage::values($this->themeSettings),
            'archive_columns' => ['2', '3', '4'],
            'sidebar_section' => $schema['sidebar'] ?? null,
        ]);
    }

    /** Export, import (preview and apply), undo and reset of Branding (the whole of /admin/theme?branding=…; the route already needs the right to change the theme). */
    private function handleBrandingTransfer(string $action): void
    {
        $transfer = $this->brandingTransfer();
        if ($action === 'export' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $path = tempnam(sys_get_temp_dir(), 'branding');
            if ($path === false) {
                $this->redirect('/admin/theme?theme=fail&tab=branding&theme_msg=' . urlencode('The export could not be made.'));
                return;
            }
            $transfer->export($path);
            $this->logActivity('theme.branding_export', 'info', 'settings', $this->theme->name(), 'Branding exported.');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="branding-' . gmdate('Y-m-d') . '.zip"');
            header('Content-Length: ' . (string)(filesize($path) ?: 0));
            header('Cache-Control: no-store');
            readfile($path);
            @unlink($path);
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $answer = static function (array $data, int $status = 200): void {
            http_response_code($status);
            echo json_encode($data + ['ok' => $status < 400], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        };
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $answer(['error' => 'Use the buttons of the Branding screen.'], 405);
            return;
        }
        if ($action === 'preview' || $action === 'import') {
            $upload = $_FILES['branding_file'] ?? null;
            $tmp = is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string)$upload['tmp_name']) && (int)$upload['size'] <= 20 * 1048576 ? (string)$upload['tmp_name'] : '';
            $read = $tmp !== '' ? $transfer->read((string)file_get_contents($tmp)) : ['ok' => false, 'error' => 'Choose a Branding file (the zip from Export).'];
            if (($read['ok'] ?? false) !== true) {
                $answer(['error' => (string)($read['error'] ?? 'The file could not be read.')], 422);
                return;
            }
            if ($action === 'preview') {
                $answer($transfer->preview($read));
                return;
            }
            $result = $transfer->apply($read);
            $this->themeSettings = $this->loadThemeSettings();
            $this->logActivity('theme.branding_import', 'info', 'settings', $this->theme->name(), 'Branding imported.', ['changed' => $result['changed'] ?? 0]);
            $answer($result, ($result['ok'] ?? false) ? 200 : 500);
            return;
        }
        if ($action === 'undo') {
            $done = $transfer->undo();
            $this->logActivity('theme.branding_undo', 'info', 'settings', $this->theme->name(), 'Branding put back as before.');
            $answer(['restored' => $done], $done ? 200 : 404);
            return;
        }
        if ($action === 'reset') {
            $changed = $transfer->reset((string)($_POST['section'] ?? ''));
            if ($changed >= 0) {
                $this->logActivity('theme.branding_reset', 'info', 'settings', $this->theme->name(), 'A Branding section was reset to its defaults.', ['section' => (string)$_POST['section']]);
            }
            $answer(['changed' => max(0, $changed)], $changed >= 0 ? 200 : 422);
            return;
        }
        $answer(['error' => 'Unknown action.'], 404);
    }

    private function brandingTransfer(): BrandingTransfer
    {
        return new BrandingTransfer(
            $this->theme,
            fn(): array => $this->loadThemeSettings(),
            function (array $settings): void {
                $this->setSystemMeta('theme_settings', Yaml::dump($settings, 6, 2));
            },
            $this->systemMeta,
            trim((string)@file_get_contents($this->basePath . '/VERSION'))
        );
    }

    /** JSON for the palette studio: `generate` (POST) a palette from two colours, `duo` (GET) the colour pairings of Duo. */
    private function paletteRequest(string $what): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if ($what === 'generate' || $what === 'copy' || $what === 'fix') {
            $answer = $_SERVER['REQUEST_METHOD'] !== 'POST' ? ['ok' => false, 'message' => 'Use POST.']
                : ($what === 'generate' ? PaletteAdmin::generate($_POST) : ($what === 'copy' ? PaletteAdmin::copy($_POST) : PaletteAdmin::fix($_POST)));
        } else {
            $answer = PaletteAdmin::duo($this->themeSettings, new DuoPairings($this->basePath . '/storage/cache'));
        }
        echo json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    }

    /** What the Branding tab needs besides the settings: the font stacks, the address of the preview, the state of the font file. */
    private function brandingScreen(): array
    {
        $file = trim((string)($this->themeSettings['design']['font_file'] ?? ''));
        $found = null;
        if ($file !== '' && !preg_match('#^(https?://|/)#i', $file) && !str_contains($file, '..')) {
            $found = is_file($this->theme->customPath() . '/assets/' . ltrim($file, '/'));
        }
        $languages = array_map('strval', $this->settings['languages']['available'] ?? []);
        $greek = in_array('el', $languages, true) || $this->defaultLanguage() === 'el';
        return [
            'stacks' => Branding::FONT_STACKS,
            'preview_url' => (string)($this->settings['base_url'] ?? '') . '/',
            'palettes' => BuiltinPalettes::all(),
            'font_found' => $found,
            'backup' => $this->brandingTransfer()->backup(),
            'sections' => array_map(static fn(array $section): string => $section['label'], BrandingTransfer::SECTIONS),
            'fonts' => BrandingFonts::screen(
                is_array($this->themeSettings['design'] ?? null) ? $this->themeSettings['design'] : [],
                $this->theme->customPath(),
                (string)($this->settings['base_url'] ?? ''),
                $greek
            ),
        ];
    }

    /** JSON for the fonts of the Branding screen: takes an uploaded font file in, or reads the single font file of earlier versions. */
    private function brandingFontsRequest(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (($_POST['action'] ?? '') === 'convert') {
            $result = BrandingFonts::describeExisting($this->theme->customPath(), (string)($_POST['path'] ?? ''), (string)($_POST['family'] ?? ''));
        } else {
            $upload = is_array($_FILES['font'] ?? null) ? $_FILES['font'] : [];
            $result = is_string($upload['tmp_name'] ?? null) && is_uploaded_file($upload['tmp_name'])
                ? BrandingFonts::receive($upload, $this->theme->customPath(), (string)($_POST['family'] ?? ''))
                : ['ok' => false, 'error' => 'No file was uploaded. It may be larger than the server accepts.'];
            if ($result['ok']) {
                $this->logActivity('theme.font_upload', 'info', 'settings', $this->theme->name(), 'A font file was uploaded for Branding.', ['file' => $result['entry']['file'] ?? '']);
            }
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    }

    /** JSON for the live preview of the Branding tab: the CSS and attributes the submitted choices would give a page. */
    private function previewBranding(array $input): void
    {
        $data = SitePalettes::followSource(SitePalettes::fold($this->theme->resolveSettings($this->theme->settingsFromInput($input, $this->loadThemeSettings()))));
        $base = (string)($this->settings['base_url'] ?? '');
        $brand = is_array($data['brand'] ?? null) ? $data['brand'] : [];
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'css' => Branding::css($data, $base),
            'attributes' => Branding::attributes($data),
            'logo' => (string)($brand['logo'] ?? ''),
            'logo_dark' => (string)($brand['logo_dark'] ?? ''),
            'show_name' => ($brand['show_name'] ?? false) === true,
            'name' => (string)($this->settings['title'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    }

    /**
     * JSON for the live preview of Header and Footer: the page at `path` (the home page when it is not one a visitor can open)
     * drawn as the site would draw it with the submitted, unsaved theme settings. Nothing is stored and no form is processed:
     * the page is asked for as a visitor's GET.
     */
    private function previewPage(array $input, string $path): void
    {
        $data = $this->theme->resolveSettings($this->theme->settingsFromInput($input, $this->loadThemeSettings()));
        $parts = parse_url('/' . ltrim($path, '/')) ?: [];
        $clean = trim((string)($parts['path'] ?? ''), '/');
        $query = [];
        parse_str((string)($parts['query'] ?? ''), $query);
        $route = FrontRoute::resolve($clean, $this->settings, $this->content->getTypes(), array_values(array_filter($this->taxonomies()->names(), [Taxonomies::class, 'isCustom'])));
        if (str_starts_with($clean, 'admin') || !in_array($route['kind'], ['page', 'archive', 'entry', 'taxonomy', 'search'], true)) {
            $clean = '';
            $query = [];
        }

        $html = $this->drawFront($data, $clean, $query, false);
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['html' => $html, 'path' => '/' . $clean . ($query !== [] ? '?' . http_build_query($query) : '')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function starterContent(): StarterContent
    {
        return new StarterContent($this->basePath, $this->contentDir, $this->basePath . '/public/uploads');
    }

    private function templates(): TemplateResolver
    {
        return $this->templateResolver ??= new TemplateResolver($this->theme, $this->singleLayouts(), fn(): array => $this->themeSettings, fn(): string => $this->defaultLanguage());
    }

    private function singleLayouts(): SingleLayouts
    {
        return $this->singleLayoutsService ??= new SingleLayouts($this->theme, $this->contentTypes());
    }

    private function layoutsAdmin(): LayoutsAdmin
    {
        return $this->layoutsAdminService ??= new LayoutsAdmin(
            $this->theme,
            $this->singleLayouts(),
            $this->contentTypes(),
            $this->contentTypeAdmin(),
            $this->taxonomies(),
            $this->taxonomyEditor(),
            fn(): array => $this->content->getTypes(),
            fn(string $type): int => count($this->content->getItems($type, null, true)),
            fn(string $type): string => TemplateResolver::singular($type)
        );
    }

    private function blockSets(): BlockSets
    {
        return $this->blockSetsService ??= new BlockSets(
            fn(): BlockRegistry => $this->blockRuntime()->registry(),
            fn(): HtmlGuard => $this->htmlGuard(),
            fn(): bool => $this->permissions->can($this->auth->user(), 'content.raw_html'),
            fn(string $lang): array => $this->presetLibrary()->forEditor($lang, $lang),
            fn(): array => $this->settings
        );
    }

    private function singleTemplateAdmin(): SingleTemplateAdmin
    {
        return $this->singleTemplateAdminService ??= new SingleTemplateAdmin(
            $this->theme,
            $this->contentTypes(),
            $this->blockRuntime()->registry(),
            $this->blockSets(),
            fn(): array => $this->content->getTypes(),
            fn(string $type): array => $this->content->getItems($type, null, true),
            fn(string $type, string $slug, string $lang): ?ContentItem => $this->content->find($type, $slug, $lang, true, false),
            fn(): string => $this->defaultLanguage()
        );
    }

    private function handleMenusList(): void
    {
        $this->render('@admin/menus-list.twig', $this->menuAdmin()->overview($_GET) + ['types' => $this->content->getTypes(), 'user' => $this->auth->user()]);
    }

    private function handleMenusNew(): void
    {
        $result = $this->menuAdmin()->create($_GET, $_POST, $_SERVER['REQUEST_METHOD'] === 'POST');
        if ($result['location'] !== '') {
            $this->redirect($result['location']);
            return;
        }
        $this->render('@admin/menus-new.twig', $result['view'] + ['types' => $this->content->getTypes(), 'user' => $this->auth->user()]);
    }

    private function handleMenus(): void
    {
        $result = $this->menuAdmin()->edit($_GET, $_POST, $_SERVER['REQUEST_METHOD'] === 'POST');
        if ($result['location'] !== '') {
            $this->redirect($result['location']);
            return;
        }
        $this->render('@admin/menus.twig', $result['view'] + ['types' => $this->content->getTypes(), 'user' => $this->auth->user()]);
    }

    private function handleTaxonomies(): void
    {
        $default = $this->defaultLanguage();
        $languages = array_map('strval', $this->settings['languages']['available'] ?? [$default]);
        $editor = $this->taxonomyEditor();
        $taxonomy = $editor->selected((string)($_GET['taxonomy'] ?? $_POST['taxonomy'] ?? ''));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['delete_taxonomy'])) {
                $gone = $editor->delete($taxonomy, $this->currentUsername());
                $this->redirect($gone['ok'] ? '/admin/taxonomies?deleted=' . urlencode($gone['title']) : '/admin/taxonomies?taxonomy=' . urlencode($taxonomy));
                return;
            }
            if (isset($_POST['new_taxonomy'])) {
                $made = $editor->create(
                    (string)($_POST['new_title'] ?? ''),
                    (string)($_POST['new_name'] ?? ''),
                    is_array($_POST['new_types'] ?? null) ? $_POST['new_types'] : [],
                    $languages,
                    (string)($this->settings['home_page'] ?? 'index'),
                    $this->currentUsername(),
                    isset($_POST['new_hierarchical'])
                );
                $this->redirect($made['error'] === ''
                    ? '/admin/taxonomies?taxonomy=' . urlencode($made['name']) . '&created=1'
                    : '/admin/taxonomies?taxonomy=' . urlencode($taxonomy) . '&new_error=' . urlencode($made['error']) . '&new=1');
                return;
            }
            $this->redirect($editor->save($taxonomy, $_POST, $languages, $default, $this->currentUsername()));
            return;
        }

        $this->render('@admin/taxonomies.twig', $editor->screen(
            $taxonomy,
            $_GET,
            $languages,
            $default,
            $this->permissions->can($this->auth->user(), 'redirects.manage'),
            fn(string $type): string => $this->contentTypes()->definition($type, 'en', $default)['label'],
            $this->singleLayouts()->titleChoices()
        ) + ['user' => $this->auth->user()]);
    }

    private function taxonomyEditor(): TaxonomyEditor
    {
        return $this->taxonomyEditorService ??= new TaxonomyEditor(
            $this->taxonomies(),
            $this->redirects,
            $this->content,
            fn(): Menus => $this->menus(),
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    /** The settings texts that can point at uploads (logo, social image, and the like), for the usage scan. @return list<array{label: string, url: string, kind: string, text: string}> */
    private function mediaUsageSettingsSources(): array
    {
        $sources = [];
        foreach ([['site_settings', 'Site settings', '/admin/settings', 'settings'], ['theme_settings', 'Theme settings', '/admin/theme', 'theme']] as [$key, $label, $url, $kind]) {
            $text = (string)$this->systemMeta->get($key);
            if ($text !== '') {
                $sources[] = ['label' => $label, 'url' => $url, 'kind' => $kind, 'text' => $text];
            }
        }
        return $sources;
    }

    private function handleMedia(): void
    {
        $this->media->ensureDirectories();
        $this->media->migrateLegacyItems();

        $admin = $this->mediaAdmin();
        $state = $admin->state($_GET, (string)($_SESSION['admin_media_view'] ?? 'thumbs'));
        if ($state['view_asked']) {
            $_SESSION['admin_media_view'] = $state['view'];
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $outcome = $admin->apply($_POST, $_FILES, $this->currentUsername());
            if ($outcome !== null) {
                $this->redirect('/admin/media?' . http_build_query($admin->returnQuery($admin->postState($_POST, $state), $outcome)));
                return;
            }
        }

        $listing = $admin->listing($state);
        $maxUpload = $this->siteLimits()->maxUploadBytes();
        $this->render('@admin/media.twig', $listing + [
            'usage' => $state['usage'],
            'per_page' => $state['per_page'],
            'per_page_options' => MediaAdmin::PER_PAGE_OPTIONS,
            'type' => $state['type'],
            'types_available' => $this->media->typeOptions(),
            'tag' => $state['tag'],
            'q' => $state['q'],
            'view_mode' => $state['view'],
            'available_tags' => $this->media->availableTags(),
            'max_upload_mb' => $maxUpload > 0 ? max(1, (int)floor($maxUpload / 1048576)) : 0,
            'upload_accept' => implode(',', array_map(static fn(string $ext): string => '.' . $ext, $this->media->allowedExtensions())),
            'upload_kinds' => implode(', ', array_map(static fn(string $group): string => strtolower(MediaLibrary::UPLOAD_GROUPS[$group]['label']), $this->media->allowedGroups())),
            'success' => trim((string)($_GET['success'] ?? '')),
            'error' => trim((string)($_GET['error'] ?? '')),
            'user' => $this->auth->user(),
            'types' => $this->content->getTypes(),
            'admin_section' => 'media',
        ]);
    }

    private function mediaAdmin(): MediaAdmin
    {
        return $this->mediaAdminService ??= new MediaAdmin(
            $this->media,
            $this->mediaUsage,
            fn(int $bytes): bool => $this->siteLimits()->allows($bytes),
            fn(): string => $this->siteLimits()->fullMessage(),
            fn(int $bytes) => $this->siteLimits()->uploadsChanged($bytes),
            fn(): int => $this->siteLimits()->maxUploadBytes(),
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context)
        );
    }

    private function handleFormsExport(): void
    {
        $slug = Slug::plain((string)($_GET['slug'] ?? ''));
        $lang = Slug::plain((string)($_GET['lang'] ?? ($this->settings['languages']['default'] ?? 'en')));
        if ($slug === '') {
            $this->redirect('/admin/content?type=forms');
            return;
        }

        $form = FormTranslations::find($this->content, $slug, $this->defaultLanguage(), true)['form'];
        if (!$form) {
            $this->redirect('/admin/forms');
            return;
        }

        $export = $this->formsAdmin()->export($form, (string)($this->settings['title'] ?? ''));
        $this->logActivity('forms.export', 'info', 'forms', $slug . ':' . $lang, 'Form submissions exported.', [
            'slug' => $slug,
            'lang' => $lang,
            'submissions' => $export['count'],
            'filename' => $export['filename'],
        ]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
        $output = fopen('php://output', 'w');
        if ($output === false) {
            return;
        }
        fputcsv($output, $export['headers'], ',', '"', '');
        foreach ($export['rows'] as $row) {
            fputcsv($output, $row, ',', '"', '');
        }
        fclose($output);
        exit;
    }

    /** @return array{ok: bool, indexed: int, removed: int, took_ms: int} */
    private function rebuildContentIndex(): array
    {
        try {
            // A fresh repository avoids this request's cached listings.
            $repository = new ContentRepository($this->contentDir, $this->markdownConverter(), $this->settings, fn(): array => $this->contentTypes()->catalogue());
            return $this->contentIndex->rebuild($repository, $repository->getTypes());
        } catch (\Throwable) {
            return ['ok' => false, 'indexed' => 0, 'removed' => 0, 'took_ms' => 0];
        }
    }

    /** Rebuilds automatically when files changed outside the admin (git pull, FTP) on reasonably small sites. */
    private function ensureContentIndexFresh(): array
    {
        $status = $this->contentIndex->status($this->content->getTypes());
        if ($status['available'] && $status['stale'] && $status['files'] <= 3000) {
            $this->rebuildContentIndex();
            $status = $this->contentIndex->status($this->content->getTypes());
            $status['auto_rebuilt'] = true;
        }
        return $status;
    }

    private function handleAdminSearch(): void
    {
        $query = trim((string)($_GET['q'] ?? ''));
        $results = [];
        if ($query !== '') {
            $this->ensureContentIndexFresh();
            foreach ($this->contentIndex->search($query, 100) as $row) {
                if (!$this->canAccessContentType((string)$row['type'])) {
                    continue;
                }
                $row['edit_url'] = '/admin/edit?' . http_build_query(['type' => $row['type'], 'slug' => $row['slug'], 'lang' => $row['lang']]);
                $results[] = $row;
            }
        }
        $this->render('@admin/admin-search.twig', [
            'title' => 'Search',
            'query' => $query,
            'results' => $results,
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'search',
            'current_type' => 'pages',
        ]);
    }

    private function handleSystem(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['system_action'] ?? '') === 'rebuild_index') {
            $result = $this->rebuildContentIndex();
            $this->logActivity($result['ok'] ? 'system.index_rebuild' : 'system.index_rebuild_failure', $result['ok'] ? 'info' : 'error', 'system', 'content_index', $result['ok'] ? 'Content index rebuilt.' : 'Content index rebuild failed.', $result);
            $this->redirect('/admin/system?' . http_build_query([
                'rebuilt' => $result['ok'] ? 'ok' : 'fail',
                'msg' => $result['ok'] ? 'Indexed ' . $result['indexed'] . ' entries in ' . $result['took_ms'] . ' ms' . ($result['removed'] > 0 ? ', removed ' . $result['removed'] . ' stale' : '') . '.' : 'The content index could not be rebuilt.',
            ]));
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array((string)($_POST['system_action'] ?? ''), ['purge_cache', 'cache_address'], true)) {
            $cache = $this->frontCache();
            if ((string)$_POST['system_action'] === 'cache_address') {
                $address = trim((string)($_POST['cache_address'] ?? ''));
                if (!$cache->setAddress($address)) {
                    $this->redirect('/admin/system?cache=bad_address');
                    return;
                }
                $this->logActivity('system.cache_address', 'info', 'system', 'front_cache', $address === '' ? 'Page cache address removed.' : 'Page cache address saved.', ['address' => $address]);
                $this->redirect('/admin/system?cache=saved');
                return;
            }
            // The purge itself was asked for by handleAdmin (every POST of the admin); here it is told how it went.
            $mode = $cache->mode();
            $status = $mode === 'varnish' ? $cache->sendPurge($cache->address()) : 0;
            $ok = $mode === 'cloudpanel' ? $cache->cloudPanelTag() !== '' : ($status >= 200 && $status < 300);
            $this->logActivity($ok ? 'system.cache_purge' : 'system.cache_purge_failure', $ok ? 'info' : 'warning', 'system', 'front_cache', $ok ? 'Page cache emptied.' : 'The page cache could not be emptied.', ['mode' => $mode, 'status' => $status]);
            $this->redirect('/admin/system?cache=' . ($ok ? 'purged' : 'purge_failed') . ($mode === 'varnish' ? '&cache_status=' . $status : ''));
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['system_action'] ?? '') === 'add_demo') {
            $result = $this->starterContent()->add(is_array($_POST['demo'] ?? null) ? array_map('strval', $_POST['demo']) : []);
            if ($result['added'] > 0) {
                $this->taxonomies()->forget();
                $this->rebuildContentIndex();
                $this->logActivity('system.demo_content', 'info', 'system', 'demo_content', 'Demo content added.', $result);
            }
            $this->redirect('/admin/system?' . http_build_query(['demo' => $result['failed'] > 0 ? 'fail' : 'ok', 'demo_added' => $result['added'], 'demo_failed' => $result['failed']]));
            return;
        }

        $storage = $this->siteLimits()->summary();
        $checks = $this->systemStatus()->checks($storage);
        $auto = is_array($this->settings['backup']['auto'] ?? null) ? $this->settings['backup']['auto'] : [];
        $update = $this->updates()->cachedStatus();

        $this->render('@admin/system.twig', [
            'title' => 'System',
            'checks' => $checks,
            'system_status' => SystemStatus::summarize($checks),
            'index' => $this->contentIndex->status($this->content->getTypes()),
            'extensions' => SystemStatus::extensions(),
            'environment' => $this->systemStatus()->environment($this->updates()->currentVersion(), $this->updates()->currentGitCommit(), $this->isHttpsRequest()),
            'cron' => $this->cronStatus(),
            'tasks' => [
                ['name' => 'Automatic backups', 'schedule' => Format::isTruthy($auto['enabled'] ?? false) ? (string)($auto['schedule'] ?? 'daily') : 'off', 'last' => (string)($auto['last_run'] ?? ''), 'how' => 'Taken by the cron job when it is set up; otherwise after a visit to the site (where the server allows it) or at the first admin page view after it is due.'],
                ['name' => 'Update check', 'schedule' => 'every 12 hours', 'last' => (string)($update['checked_at'] ?? ''), 'how' => 'Runs on an admin page view for users who manage updates.'],
                ['name' => 'Content index', 'schedule' => 'on change', 'last' => (string)($this->contentIndex->status($this->content->getTypes())['last_indexed_at'] ?? ''), 'how' => 'Updated on save, delete, bulk actions, imports, and restores; rebuilt automatically when files change outside the admin.'],
                ['name' => 'Sign-in attempt cleanup', 'schedule' => 'continuous', 'last' => '', 'how' => 'Entries older than a day are removed while new attempts are recorded.'],
            ],
            'storage' => $storage,
            'front_cache' => [
                'mode' => $this->frontCache()->mode(),
                'tag' => $this->frontCache()->cloudPanelTag(),
                'address' => $this->frontCache()->address(),
                'notice' => (string)($_GET['cache'] ?? ''),
                'status' => (int)($_GET['cache_status'] ?? 0),
            ],
            'rebuilt' => (string)($_GET['rebuilt'] ?? ''),
            'rebuilt_message' => trim((string)($_GET['msg'] ?? '')),
            'demo_groups' => $this->starterContent()->available() ? $this->starterContent()->groups() : [],
            'demo' => (string)($_GET['demo'] ?? ''),
            'demo_added' => (int)($_GET['demo_added'] ?? 0),
            'demo_failed' => (int)($_GET['demo_failed'] ?? 0),
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'system',
            'current_type' => 'pages',
        ]);
    }

    /** Admin > Omni: the connection to an Omni and what the site reports to it (docs/omni-protocol.md). Super admin only. */
    private function handleOmni(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $client = $this->omniClient();
            $action = (string)($_POST['omni_action'] ?? '');
            $before = $client->state();
            $result = match ($action) {
                'connect' => $client->connect((string)($_POST['omni_url'] ?? ''), (string)($_POST['omni_code'] ?? '')),
                'send' => $client->send(),
                'disconnect' => $client->disconnect(),
                default => ['ok' => false, 'message' => 'Nothing to do.'],
            };
            if (in_array($action, ['connect', 'disconnect'], true) && $result['ok']) {
                $this->logActivity('omni.' . $action, 'info', 'omni', (string)($action === 'connect' ? ($client->state()['site_id'] ?? '') : ($before['site_id'] ?? '')), $result['message'], ['url' => (string)($client->state()['url'] ?? ($before['url'] ?? ''))]);
            }
            $this->redirect('/admin/omni?' . http_build_query([
                'done' => $result['ok'] ? 'ok' : 'fail',
                'msg' => $result['message'],
                // A refused code keeps the address that was typed.
                'url' => $action === 'connect' && !$result['ok'] ? (string)($_POST['omni_url'] ?? '') : null,
            ]));
            return;
        }

        $report = $this->omniReport();
        $connection = $this->omniClient()->state();
        unset($connection['secret']);
        $this->render('@admin/omni.twig', [
            'title' => 'Omni',
            'connection' => $connection,
            'connected' => $this->omniClient()->isConnected(),
            'omni_url' => trim((string)($_GET['url'] ?? '')) ?: (string)($connection['url'] ?? '') ?: OmniClient::DEFAULT_URL,
            'done' => (string)($_GET['done'] ?? ''),
            'message' => trim((string)($_GET['msg'] ?? '')),
            'report' => $report->build(),
            'report_json' => $report->json(true),
            'storage_label' => (string)$this->siteLimits()->summary()['label'],
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'omni',
            'current_type' => 'pages',
        ]);
    }

    private function omniClient(): OmniClient
    {
        return $this->omniClientService ??= new OmniClient(
            $this->systemMeta,
            [OmniClient::class, 'request'],
            fn(): string => $this->omniReport()->json(),
            fn(): array => [
                'name' => (string)($this->settings['title'] ?? ''),
                'url' => $this->buildAbsoluteUrl('/'),
                'admin_url' => $this->buildAbsoluteUrl('/admin'),
                'version' => $this->updates()->currentVersion(),
            ],
            function (): string {
                // A new backup run or another version sends a report at once.
                $last = $this->backupRuns->recent(1)[0] ?? [];
                return (string)($last['created_at'] ?? '') . '|' . (string)($last['status'] ?? '') . '|' . $this->updates()->currentVersion();
            }
        );
    }

    private function omniReport(): OmniReport
    {
        return new OmniReport(
            fn(): array => $this->settings,
            fn(): array => [
                'name' => (string)($this->settings['title'] ?? ''),
                'url' => $this->buildAbsoluteUrl('/'),
                'admin_url' => $this->buildAbsoluteUrl('/admin'),
            ],
            fn(): array => ['version' => $this->updates()->currentVersion(), 'commit' => $this->updates()->currentGitCommit()],
            fn(): array => ['summary' => $this->siteLimits()->summary(), 'measurement' => $this->siteLimits()->measurement()],
            fn(int $limit): array => $this->backupRuns->recent($limit),
            fn(): array => $this->cronStatus(),
            fn(): ?array => $this->updates()->cachedStatus(),
            fn(): array => [
                'checks' => $this->systemStatus()->checks($this->siteLimits()->summary()),
                'extensions' => SystemStatus::extensions(),
                'environment' => $this->systemStatus()->environment($this->updates()->currentVersion(), $this->updates()->currentGitCommit(), $this->isHttpsRequest()),
            ]
        );
    }

    private function handleFormsList(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['dismiss_migration_report'])) {
            $this->formTranslationMigration()->dismissReport();
            $this->redirect('/admin/forms');
            return;
        }
        $defaultLang = (string)($this->settings['languages']['default'] ?? 'en');
        $languages = array_values(array_map('strval', $this->settings['languages']['available'] ?? [$defaultLang]));
        $filters = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'status' => trim((string)($_GET['status'] ?? '')),
        ];
        $overview = $this->formsAdmin()->overview($filters, (string)($_GET['sort'] ?? 'updated'), $languages, $defaultLang, Format::isTruthy($this->settings['forms']['store_submissions'] ?? true));

        $this->render('@admin/forms-list.twig', [
            'title' => 'Forms',
            'rows' => $overview['rows'],
            'totals' => $overview['totals'],
            'filters' => $filters,
            'sort' => $overview['sort'],
            'filters_active' => $filters['q'] !== '' || $filters['status'] !== '' || $overview['sort'] !== 'updated',
            'languages' => $languages,
            'default_lang' => $defaultLang,
            'deleted' => isset($_GET['deleted']),
            'migration_report' => $this->formsAdmin()->migrationNote($this->formTranslationMigration()->report()),
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'forms',
            'current_type' => 'forms',
        ]);
    }

    private function handleFormSubmissions(): void
    {
        $slug = Slug::plain((string)($_GET['slug'] ?? ($_POST['slug'] ?? '')));
        $defaultLang = (string)($this->settings['languages']['default'] ?? 'en');
        $versions = $this->formsAdmin()->versions($slug);
        if ($slug === '' || $versions === []) {
            $this->redirect('/admin/forms');
            return;
        }
        $form = $versions[$defaultLang] ?? reset($versions);

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $result = $this->formsAdmin()->deleteSubmissions($slug, $_POST);
            $deleted = $result['deleted'];
            if ($deleted > 0) {
                $this->logActivity('forms.submission_delete', 'warning', 'forms', $slug, $deleted === 1 ? 'Form submission deleted.' : 'Form submissions deleted.', [
                    'slug' => $slug,
                    'count' => $deleted,
                    'files' => $result['files'],
                    'ids' => array_slice($result['ids'], 0, 50),
                ]);
            }
            $return = $this->sanitizeAdminReturnUrl((string)($_POST['return_to'] ?? ''));
            parse_str((string)parse_url($return, PHP_URL_QUERY), $query);
            if (!str_starts_with($return, '/admin/form-submissions')) {
                $query = [];
            }
            unset($query['deleted'], $query['error']);
            unset($query['files']);
            $query = ['slug' => $slug] + $query + ($deleted > 0 ? ['deleted' => $deleted] + ($result['files'] > 0 ? ['files' => $result['files']] : []) : ['error' => 'Nothing was deleted.']);
            $this->redirect('/admin/form-submissions?' . http_build_query($query));
            return;
        }

        $page = $this->formsAdmin()->page($form, $_GET);
        $this->render('@admin/form-submissions.twig', [
            'title' => 'Submissions',
            'form' => [
                'slug' => $slug,
                'title' => (string)($form->meta['title'] ?? $slug),
                'lang' => $form->lang,
            ],
            'rows' => $page['rows'],
            'filters' => $page['filters'],
            'filters_active' => array_filter($page['filters']) !== [],
            'total_all' => $page['total_all'],
            'total' => $page['total'],
            'recent' => $page['recent'],
            'page' => $page['page'],
            'total_pages' => $page['total_pages'],
            'per_page' => $page['per_page'],
            'per_page_options' => FormsAdmin::PER_PAGE_OPTIONS,
            'deleted_count' => (int)($_GET['deleted'] ?? 0),
            'deleted_files' => (int)($_GET['files'] ?? 0),
            'has_file_field' => $page['has_file_field'],
            'error' => trim((string)($_GET['error'] ?? '')),
            'current_url' => $this->currentRequestPath(),
            'languages' => array_values(array_map('strval', $this->settings['languages']['available'] ?? [])),
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'forms',
            'current_type' => 'forms',
        ]);
    }

    /** One file a visitor attached to a form, given to someone signed in who may see submissions, as a download and never as a page. */
    private function handleFormFile(): void
    {
        $slug = Slug::plain((string)($_GET['slug'] ?? ''));
        $id = (string)($_GET['id'] ?? '');
        $path = $this->formSubmissions->uploads()->path($slug, $id, (string)($_GET['f'] ?? ''));
        if ($path === null) {
            $this->render404();
            return;
        }
        $name = basename($path);
        $this->logActivity('forms.file_download', 'info', 'forms', $slug, 'Form file downloaded.', ['slug' => $slug, 'submission' => $id, 'file' => $name]);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (int)filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
    }

    /** All the files of one submission as a ZIP. */
    private function handleFormFilesZip(): void
    {
        $slug = Slug::plain((string)($_GET['slug'] ?? ''));
        $id = (string)($_GET['id'] ?? '');
        $zip = $this->formSubmissions->uploads()->zip($slug, $id);
        if ($zip === null) {
            $this->render404();
            return;
        }
        $this->logActivity('forms.file_download', 'info', 'forms', $slug, 'Form files downloaded as a ZIP.', ['slug' => $slug, 'submission' => $id]);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $slug . '-' . $id . '.zip"');
        header('Content-Length: ' . (int)filesize($zip));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($zip);
        @unlink($zip);
    }

    private function formTranslationMigration(): FormTranslationMigration
    {
        return $this->formTranslationMigrationService ??= new FormTranslationMigration($this->content, $this->contentDir, $this->basePath . '/storage/form-translation', $this->settings);
    }

    private function formsAdmin(): FormsAdmin
    {
        return $this->formsAdminService ??= new FormsAdmin($this->content, $this->formSubmissions);
    }

    private function formProcessor(): FormProcessor
    {
        return $this->formProcessorService ??= new FormProcessor(fn(string $key, string $fallback): string => $this->translate($key, $fallback));
    }

    private function handleContentExport(): void
    {
        $type = $this->sanitizeType((string)($_GET['type'] ?? 'pages'));
        if (!in_array($type, $this->content->getTypes(), true) || $type === 'forms') {
            $this->redirect('/admin/content?type=' . urlencode($type));
            return;
        }

        $file = $this->contentTransfer()->export($type, (string)($this->settings['title'] ?? 'site'));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
        $output = fopen('php://output', 'w');
        if ($output === false) {
            return;
        }

        fputcsv($output, $file['headers'], ',', '"', '');
        foreach ($file['rows'] as $row) {
            fputcsv($output, $row, ',', '"', '');
        }

        fclose($output);
        exit;
    }

    private function handleContentImport(): void
    {
        $type = $this->sanitizeType((string)($_GET['type'] ?? ($_POST['type'] ?? 'pages')));
        $types = $this->content->getTypes();
        if (!in_array($type, $types, true)) {
            $type = $types[0] ?? 'pages';
        }
        if ($type === 'forms') {
            $this->redirect('/admin/content?type=forms');
            return;
        }

        if (!isset($_SESSION['content_import_preview']) || !is_array($_SESSION['content_import_preview'])) {
            $_SESSION['content_import_preview'] = [];
        }
        $result = $this->contentTransfer()->import($type, $_SERVER['REQUEST_METHOD'] === 'POST', $_POST, is_array($_FILES['csv_file'] ?? null) ? $_FILES['csv_file'] : null, $_SESSION['content_import_preview']);
        if ($result['location'] !== '') {
            $this->redirect($result['location']);
            return;
        }
        $this->render('@admin/import.twig', $result['view'] + [
            'type' => $type,
            'types' => $types,
            'user' => $this->auth->user(),
            'admin_section' => 'content',
            'current_type' => $type,
            'saved' => isset($_GET['saved']),
        ]);
    }

    /**
     * Admin > Content types: what each type is called, how its archive looks, and the fields its
     * editor form has. Site choices are written to custom/content-types/<type>.yaml as differences
     * from the theme's definition, so theme updates keep flowing through; texts and fields the
     * theme translates are left alone unless they are changed here.
     */
    private function handleContentTypes(): void
    {
        $default = $this->defaultLanguage();
        $manageable = array_values(array_filter($this->content->getTypes(), static fn(string $t): bool => $t !== 'forms'));
        $types = $this->contentTypes();
        $admin = $this->contentTypeAdmin();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['action'] ?? 'save');
            $this->redirect($action === 'toggle' ? $admin->toggle($_POST) : ($action === 'create' ? $admin->create($_POST, $manageable) : $admin->update($_POST, $manageable, $default)));
            return;
        }

        $selected = Slug::plain((string)($_GET['type'] ?? ''));
        $common = [
            'saved' => isset($_GET['saved']),
            'error' => (string)($_GET['error'] ?? ''),
            'custom_file' => 'custom/content-types/',
            'admin_section' => 'content-types',
            // The admin menu lists the types under Content on every screen.
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
        ];

        if ($selected !== '' && in_array($selected, $manageable, true)) {
            $definition = $types->definition($selected, $default, $default);
            $themeDefinition = $types->themeDefinition($selected, $default, $default);
            $this->render('@admin/content-type-edit.twig', [
                'type' => $selected,
                'definition' => $definition,
                'theme_definition' => $themeDefinition,
                'rows' => $admin->fieldRows($definition, $themeDefinition),
                'field_types' => ContentTypes::FORM_FIELD_TYPES,
                // Parts of the type's files the CMS leaves out (a repeater nested too deep), so whoever wrote them can see why.
                'definition_problems' => FieldSchema::problems(is_array($types->raw($selected)['fields'] ?? null) ? $types->raw($selected)['fields'] : []),
                'layouts' => ContentTypes::LAYOUTS,
                'orders' => ContentTypes::orderOptions($definition['fields']),
                'taxonomy_names' => $this->taxonomies()->names(),
                'item_count' => count($this->content->getItems($selected, null, true)),
            ] + $common);
            return;
        }

        $this->render('@admin/content-types.twig', [
            'types_list' => $admin->typeRows($manageable, $default),
            'toggled' => (string)($_GET['toggled'] ?? ''),
            'toggled_type' => Slug::plain((string)($_GET['type_name'] ?? '')),
        ] + $common);
    }

    private function contentTypeAdmin(): ContentTypeAdmin
    {
        return $this->contentTypeAdminService ??= new ContentTypeAdmin(
            $this->contentTypes(),
            $this->contentDir,
            fn(): array => $this->taxonomies()->names(),
            fn(string $key): bool => $this->isReservedFrontmatterKey($key),
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => $this->logActivity($action, $level, $type, $id, $message, $context),
            fn(string $type, bool $on): bool => $this->siteSettings()->setContentType($type, $on)
        );
    }

    private function handleTranslations(): void
    {
        $available = $this->settings['languages']['available'] ?? [];
        $defaultLang = $this->settings['languages']['default'] ?? 'en';
        if (empty($available)) {
            $available = [$defaultLang];
        }

        $lang = Slug::plain((string)($_GET['lang'] ?? $_POST['lang'] ?? $defaultLang));
        if (!in_array($lang, $available, true)) {
            $lang = $defaultLang;
        }

        $strings = $this->themeStrings();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $result = $strings->save($lang, $defaultLang, $_POST);
            if (!$result['ok']) {
                $this->redirect('/admin/translations?lang=' . urlencode($lang) . '&error=write');
                return;
            }
            $this->logActivity('translations.update', 'info', 'translation', $lang, 'Translations updated.', [
                'lang' => $lang,
                'overrides' => $result['overrides'],
            ]);
            $this->redirect('/admin/translations?lang=' . urlencode($lang) . '&saved=1');
            return;
        }

        $screen = $strings->overview($lang, $defaultLang, $available);
        $names = [];
        foreach ($available as $code) {
            $name = class_exists(\Locale::class) ? \Locale::getDisplayLanguage((string)$code, 'en') : '';
            $names[$code] = $name !== '' && $name !== $code ? ucfirst($name) : strtoupper((string)$code);
        }

        $this->render('@admin/translations.twig', [
            'lang' => $lang,
            'languages' => $available,
            'language_names' => $names,
            'default_lang' => $defaultLang,
            'rows' => $screen['rows'],
            'groups' => $screen['groups'],
            'stats' => $screen['stats'],
            'progress' => $screen['progress'],
            'custom_file' => $screen['custom_file'],
            'saved' => isset($_GET['saved']),
            'write_error' => ($_GET['error'] ?? '') === 'write',
            'user' => $this->auth->user(),
            'types' => $this->content->getTypes(),
            'admin_section' => 'translations',
        ]);
    }


    private function initTwig(): TwigEnvironment
    {
        return $this->siteTwig()->create();
    }

    private function siteTwig(): SiteTwig
    {
        return $this->siteTwigService ??= new SiteTwig(
            $this->basePath,
            $this->theme,
            $this->images,
            fn(): array => $this->settings,
            fn(): array => $this->themeSettings,
            fn(): string => $this->currentLang,
            fn(): string => $this->defaultLanguage(),
            fn(): bool => $this->auth->check(),
            fn(string $capability): bool => $this->permissions->can($this->auth->user(), $capability),
            fn(): string => $this->csrfToken(),
            fn(string $key, ?string $fallback): string => $this->translate($key, $fallback),
            fn(string $path): string => $this->buildAbsoluteUrl($path),
            fn(): StructuredData => $this->structuredData(),
            fn(): ContentTypes => $this->contentTypes(),
            fn(): SingleLayouts => $this->singleLayouts(),
            fn(): Taxonomies => $this->taxonomies(),
            fn(string $taxonomy, string $termId, ?string $lang): string => $this->taxonomyTermLabel($taxonomy, $termId, $lang),
            fn(string $taxonomy, string $termId, string $prefix): string => $this->buildTaxonomyPath($taxonomy, $termId, $prefix),
            fn(): BlockRuntime => $this->blockRuntime(),
            fn(): bool => $this->feed()->hasSiteFeed(),
            fn(): array => $this->siteLanguageCodes()
        );
    }

    private function render(string $template, array $data = []): void
    {
        $defaults = [
            'is_admin' => $this->auth->check(),
        ];
        if (str_starts_with($template, '@admin/') && $this->auth->check()) {
            $defaults += $this->adminChrome()->defaults(
                $data,
                $this->permissions->can($this->auth->user(), 'notifications.manage'),
                $this->permissions->can($this->auth->user(), 'updates.manage'),
                ($_SESSION['security_default_password'] ?? false) === true,
                $this->currentRequestPath()
            );
        }

        if (!str_starts_with($template, '@admin/') && !array_key_exists('structured_data', $data)) {
            $defaults['structured_data'] = $this->structuredData()->site();
        }

        $data = array_merge($defaults, $data);
        echo $this->twig->render($template, $data);
    }

    private function renderForbidden(string $message = '', string $title = 'Access denied'): void
    {
        if (http_response_code() < 400) {
            http_response_code(403);
        }
        $this->render('@admin/forbidden.twig', [
            'title' => $title,
            'message' => $message,
            'types' => $this->content->getTypes(),
            'user' => $this->auth->user(),
            'admin_section' => 'forbidden',
            'current_type' => 'pages',
        ]);
    }

    private function logActivity(
        string $action,
        string $level = 'info',
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $message = null,
        array $context = [],
        ?array $actor = null
    ): void
    {
        try {
            $actor ??= $this->auth->user();
            $this->activityLogs->record([
                'level' => $level,
                'action' => $action,
                'actor_username' => (string)($actor['username'] ?? ''),
                'actor_role' => (string)($actor['role'] ?? ''),
                'ip_address' => $this->clientIpAddress(),
                'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'message' => $message,
                'context' => $context,
            ]);
        } catch (\Throwable) {
            // Activity logging must never block the admin workflow.
        }
    }

    private function logEmailAttempt(string $to, string $subject, string $provider, bool $ok, string $error = '', array $context = []): void
    {
        try {
            $this->emailLogs->record([
                'recipient' => $to,
                'subject' => $subject,
                'status' => $ok ? 'sent' : 'failed',
                'provider' => $provider,
                'error_message' => $ok ? '' : $error,
                'context' => $context,
            ]);
            if (!$ok) {
                $this->notifyEmailFailure($to, $subject, $provider, $error);
            }
        } catch (\Throwable) {
            // Email logging must never block sending or form handling.
        }
    }

    /** Tells the admins that a content file has front matter nobody can read, once for each file until it is fixed. */
    private function reportUnreadableContent(string $type, string $path, string $message): void
    {
        try {
            $filename = basename($path, '.md');
            $lang = (string)($this->settings['languages']['default'] ?? 'en');
            $slug = $filename;
            if (preg_match('/^(.*)\.([a-z]{2})$/', $filename, $m)) {
                $slug = $m[1];
                $lang = $m[2];
            }
            $this->notifications->createIfMissing([
                'type' => 'content.unreadable',
                'title' => 'A content file cannot be read',
                'body' => $type . '/' . basename($path) . ': ' . $message,
                'severity' => 'error',
                'target_url' => '/admin/edit?type=' . urlencode($type) . '&slug=' . urlencode($slug) . '&lang=' . urlencode($lang),
                'context' => ['type' => $type, 'file' => basename($path), 'error' => $message],
            ]);
        } catch (\Throwable) {
            // Reporting must never be what breaks a page.
        }
    }

    private function notifyEmailFailure(string $to, string $subject, string $provider, string $error): void
    {
        try {
            $body = trim($error) !== '' ? $error : 'Email delivery failed.';
            $this->notifications->createIfMissing([
                'type' => 'email.failed',
                'title' => 'Email delivery failed',
                'body' => $to . ' - ' . $body,
                'severity' => 'error',
                'target_url' => '/admin/email-logs?status=failed',
                'context' => [
                    'recipient' => $to,
                    'subject' => $subject,
                    'provider' => $provider,
                    'error' => $error,
                ],
            ]);
        } catch (\Throwable) {
            // Notifications must never block the primary workflow.
        }
    }

    private function currentRequestPath(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/admin');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/admin';
        $query = parse_url($uri, PHP_URL_QUERY);
        return $query ? $path . '?' . $query : $path;
    }

    private function sanitizeAdminReturnUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return '/admin';
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '/admin';
        if (!str_starts_with($path, '/admin')) {
            return '/admin';
        }
        $query = parse_url($url, PHP_URL_QUERY);
        return $query ? $path . '?' . $query : $path;
    }

    private function clientIpAddress(): string
    {
        $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            $parts = array_map('trim', explode(',', $forwarded));
            return (string)($parts[0] ?? '');
        }
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /**
     * A request that matched nothing: send it where a redirect says, or remember it so an administrator can fix it.
     * Real pages never get here, so redirects cost nothing on normal requests.
     */
    private function redirectOrRemember404(): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) || !$this->redirects->isAvailable()) {
            return;
        }
        $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        $hit = $this->redirects->resolve($path);
        if ($hit !== null) {
            $this->redirects->recordHit($hit['id']);
            $target = $hit['target'];
            if (!RedirectRepository::isExternal($target)) {
                $suffix = '';
                if (preg_match('/^([^?#]*)([?#].*)$/', $target, $m)) {
                    $target = $m[1];
                    $suffix = $m[2];
                }
                $target = '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($target, '/')))) . $suffix;
                $query = (string)($_SERVER['QUERY_STRING'] ?? '');
                if ($query !== '' && !str_contains($suffix, '?')) {
                    $target = preg_replace('/#.*$/', '', $target) . '?' . $query . (str_contains($suffix, '#') ? substr($suffix, (int)strpos($suffix, '#')) : '');
                }
            }
            header('Location: ' . $target, true, $hit['code']);
            exit;
        }
        // Files and probes are not broken links, so they stay out of the list.
        if (preg_match('#\.(css|js|map|png|jpe?g|gif|svg|webp|avif|ico|woff2?|ttf|eot|php|env|git)$#i', $path) || preg_match('#^/(assets|uploads|\.well-known)/#', $path)) {
            return;
        }
        $referrer = '';
        $ref = parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if (is_array($ref) && isset($ref['host'])) {
            $referrer = $ref['host'] . ($ref['path'] ?? '');
        }
        $this->redirects->recordNotFound($path, $referrer);
    }

    private function render404(): void
    {
        $this->redirectOrRemember404();
        http_response_code(404);
        $lang = $this->currentLang ?: ($this->settings['languages']['default'] ?? 'en');
        // The site's own menus, as on every other page; without them the header falls back to the theme's sample links.
        $menus = $this->menus()->forTheme($lang, '');
        if ($this->theme->hasTemplate('templates/404.twig')) {
            $this->render('templates/404.twig', [
                'lang' => $lang,
                'lang_prefix' => $this->langPrefix($lang),
                'current_lang' => $lang,
                'theme_menus' => $menus,
            ]);
            return;
        }

        $item = new ContentItem('pages', '404', $lang, [
            'title' => 'Not Found',
            'slug' => '404',
            'type' => 'pages',
        ], '', '<p>Sorry, this page does not exist.</p>', '', time());
        $this->render($this->templates()->forItem($item), [
            'item' => $item,
            'lang' => $lang,
            'lang_prefix' => $this->langPrefix($lang),
            'current_lang' => $lang,
            'theme_menus' => $menus,
        ]);
    }

    private function loadThemeSettings(): array
    {
        $raw = $this->siteSettings()->raw('theme_settings', $this->theme->defaultSettings());
        $data = [];
        if (trim($raw) !== '') {
            try {
                $parsed = Yaml::parse($raw);
                $data = is_array($parsed) ? $parsed : [];
            } catch (\Throwable) {
                $data = [];
            }
        }
        // The theme manifest decides what is valid: new fields get defaults, retired values fall back.
        $settings = $this->theme->resolveSettings($data);
        // The per-role colour overrides of earlier versions live in a site palette now. The pages are the same either way, so
        // the settings are folded here every time and stored once; a failed write only means the next load folds them again.
        $folded = SitePalettes::fold($settings);
        if ($folded !== $settings) {
            $settings = $folded;
            try {
                if ($this->systemDatabase->isAvailable() && trim($raw) !== '') {
                    $this->setSystemMeta('theme_settings', Yaml::dump($settings, 6, 2));
                }
            } catch (\Throwable) {
                // Kept for the next load.
            }
        }
        return $settings;
    }

    private function setSystemMeta(string $key, string $value): void
    {
        $this->systemMeta->set($key, $value);
    }

    private function updates(): UpdateService
    {
        $settings = $this->settings['updates'] ?? [];
        return new UpdateService($this->basePath, is_array($settings) ? $settings : [], $this->systemMeta);
    }

    /**
     * @param array<string, mixed> $input submitted `theme_settings[section][field]` values
     * @param array<string, mixed>|null $singleInput submitted `single_layouts[<type>][...]` values, when the form had the Single Layouts cards
     * @param array<string, mixed>|null $builtInput submitted `single_template[<type>]` (`theme` or `built`), when the form had the Single Layouts cards
     * @return array{ok: bool, message?: string}
     */
    private function saveThemeSettings(array $input, ?array $singleInput = null, mixed $footerBlocks = null, ?array $searchInput = null, ?array $builtInput = null): array
    {
        if (!$this->systemDatabase->isAvailable()) {
            return ['ok' => false, 'message' => 'Theme settings could not be saved because the SQLite system database is unavailable.'];
        }

        // Keys the theme does not declare (hand-added options) are kept, not dropped.
        $current = $this->loadThemeSettings();
        $data = SitePalettes::followSource(SitePalettes::fold($this->theme->settingsFromInput($input, $current)));
        if ($singleInput !== null && $this->singleLayouts()->declared()) {
            $data['single_layouts'] = $this->singleLayouts()->fromInput($singleInput, $this->content->getTypes(), $current);
        }
        if ($searchInput !== null) {
            // The look of the search page (its card in Archive Layouts): only what differs from the defaults is kept.
            $keep = SearchPage::fromInput($searchInput, $current);
            if ($keep === []) {
                unset($data['search_page']);
            } else {
                $data['search_page'] = $keep;
            }
        }
        if (is_array($footerBlocks)) {
            // A set of blocks for each language (or one list), each checked like the blocks of an entry.
            $kept = FooterBlocks::toStore($this->blockSets()->clean($footerBlocks, $current['footer_blocks'] ?? null));
            if ($kept === []) {
                unset($data['footer_blocks']);
            } else {
                $data['footer_blocks'] = $kept;
            }
        }
        if ($builtInput !== null) {
            // Which content types draw their pages from the template built with blocks (Single Layouts); the blocks stay stored when it is off.
            $data = SingleTemplates::withChoices($data, $builtInput, $this->content->getTypes());
        }
        $this->setSystemMeta('theme_settings', Yaml::dump($data, 6, 2));
        return ['ok' => true];
    }

    /** The languages of the site, its own first. @return string[] */
    private function siteLanguageCodes(): array
    {
        $own = (string)($this->settings['languages']['default'] ?? 'en');
        return array_values(array_unique(array_merge([$own], array_map('strval', (array)($this->settings['languages']['available'] ?? [])))));
    }

    /** What the block editor of Theme > Footer Blocks starts from: the block types, the blocks now above the footer, the ready-made sections. */
    private function footerBlocksEditorJson(string $lang): string
    {
        return $this->blockSets()->editorJson($lang, $this->themeSettings['footer_blocks'] ?? [], 'footer', $this->blockRuntime()->registry()->editorDefinitions());
    }

    /** Single Layouts > Edit template (`SingleTemplateAdmin`): the screen, saving, and the live preview of a real entry. */
    private function handleSingleTemplate(string $type): void
    {
        $type = $this->sanitizeType($type);
        $admin = $this->singleTemplateAdmin();
        if (!$admin->available($type)) {
            $this->redirect('/admin/theme?tab=single_layouts');
            return;
        }
        $current = $this->loadThemeSettings();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->render('@admin/theme-template.twig', $admin->view($type, $current, $_GET) + ['user' => $this->auth->user(), 'types' => $this->content->getTypes(), 'admin_section' => 'theme']);
            return;
        }
        if (($_GET['preview'] ?? '') === '1') {
            $this->previewTemplate($admin->preview($type, $current, $_POST));
            return;
        }
        if (!$this->systemDatabase->isAvailable()) {
            $this->redirect('/admin/theme?template=' . urlencode($type) . '&fail=1');
            return;
        }
        $this->setSystemMeta('theme_settings', Yaml::dump($admin->saved($type, $current, $_POST), 6, 2));
        $this->themeSettings = $this->loadThemeSettings();
        $this->logActivity('theme.update', 'info', 'settings', $this->theme->name(), 'Template of ' . $type . ' updated.', ['tab' => 'single_layouts', 'type' => $type]);
        $this->redirect('/admin/theme?template=' . urlencode($type) . '&saved=1' . (isset($_POST['preview_entry']) ? '&entry=' . urlencode((string)$_POST['preview_entry']) : ''));
    }

    /** The public page at `path` as a visitor's GET with these theme settings, as markup, nothing stored (`$marked`: blocks carry their place, for the template preview). @param array<string, mixed> $themeSettings @param array<string, mixed> $query */
    private function drawFront(array $themeSettings, string $path, array $query, bool $marked): string
    {
        $this->themeSettings = $themeSettings;
        $this->twig = $this->initTwig();
        $kept = [$_POST, $_GET, $_SERVER['REQUEST_METHOD'] ?? 'GET'];
        [$_POST, $_GET, $_SERVER['REQUEST_METHOD']] = [[], $query, 'GET'];
        $this->entryPreview = $marked;
        ob_start();
        try {
            $this->handleFront($path);
        } finally {
            $html = (string)ob_get_clean();
            $this->entryPreview = false;
            [$_POST, $_GET, $_SERVER['REQUEST_METHOD']] = $kept;
        }
        return $html;
    }

    /** JSON for the live preview of a template: the page of the entry drawn with the unsaved settings (nothing is stored). @param array{item: ContentItem, settings: array<string, mixed>}|null $preview */
    private function previewTemplate(?array $preview): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if ($preview === null) {
            echo json_encode(['html' => SingleTemplateAdmin::NO_ENTRY], JSON_UNESCAPED_UNICODE);
            return;
        }
        $item = $preview['item'];
        $path = ContentPaths::build($item->type, $item->slug, $item->lang, (string)(($this->settings['home_page'] ?? '') !== '' ? $this->settings['home_page'] : 'index'), $this->defaultLanguage());
        $html = $this->drawFront($preview['settings'], $path, [], true);
        echo json_encode(['html' => $html, 'path' => '/' . $path], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function taxonomyTermLabel(string $taxonomy, string $termId, ?string $lang = null): string
    {
        return $this->taxonomies()->label($taxonomy, $termId, $lang !== null && $lang !== '' ? $lang : $this->currentLang);
    }

    private function siteSettings(): SiteSettings
    {
        return $this->siteSettingsService ??= new SiteSettings($this->systemMeta, $this->contentDir);
    }

    private function backupManager(): BackupManager
    {
        return $this->backupManagerService ??= new BackupManager(
            $this->backups,
            $this->systemDatabase,
            $this->backupRuns,
            $this->notifications,
            fn(): array => $this->settings,
            fn(): UpdateService => $this->updates(),
            fn(string $isoDate) => $this->updateBackupLastRun($isoDate),
            fn(array $config) => new S3BackupStorage($config)
        );
    }

    private function languageAlternates(): LanguageAlternates
    {
        return $this->languageAlternatesService ??= new LanguageAlternates(
            $this->content,
            fn(): Taxonomies => $this->taxonomies(),
            fn(): array => $this->settings,
            fn(string $path): string => $this->buildAbsoluteUrl($path)
        );
    }

    private function entryTranslations(): EntryTranslations
    {
        return $this->entryTranslationsService ??= new EntryTranslations($this->content, fn(): array => $this->settings);
    }

    private function themeStrings(): ThemeStrings
    {
        return $this->themeStringsService ??= new ThemeStrings($this->theme);
    }

    private function siteLimits(): SiteLimits
    {
        return $this->siteLimitsService ??= new SiteLimits($this->systemMeta, $this->basePath, $this->contentDir, fn(): array => $this->settings);
    }

    /** What the System screen says about the cron job (see CronStatus). @return array<string, mixed> */
    private function cronStatus(): array
    {
        $auto = is_array($this->settings['backup']['auto'] ?? null) ? $this->settings['backup']['auto'] : [];
        return CronStatus::info($this->basePath, (string)($this->systemMeta->get(CronStatus::META_KEY) ?? ''), Format::isTruthy($auto['enabled'] ?? false));
    }

    private function systemStatus(): SystemStatus
    {
        return $this->systemStatusService ??= new SystemStatus(
            $this->basePath,
            $this->contentDir,
            $this->systemDatabase,
            fn(): string => $this->mailer()->provider(),
            fn(): array => $this->ensureContentIndexFresh(),
            fn(): array => $this->backupManager()->scheduleStatus(),
            fn(): array => $this->cronStatus()['check']
        );
    }

    private function dashboardData(): DashboardData
    {
        return $this->dashboardDataService ??= new DashboardData(
            $this->content,
            $this->users,
            $this->activityLogs,
            $this->emailLogs,
            fn(): array => $this->backupManager()->knownArchives(),
            $this->siteLimits(),
            $this->systemStatus(),
            fn(callable $can): array => $this->dashboardWatch($can)
        );
    }

    /**
     * What only the running site knows and the dashboard shows as something to watch, worked out for what the person may see.
     *
     * @param callable(string): bool $can
     * @return array<string, mixed>
     */
    private function dashboardWatch(callable $can): array
    {
        $facts = ['languages' => array_map('strval', $this->settings['languages']['available'] ?? [$this->defaultLanguage()])];
        if ($can('backups.manage')) {
            $facts['backup_schedule'] = $this->backupManager()->scheduleStatus();
        }
        if ($can('forms.manage')) {
            $facts['submissions'] = $this->formSubmissions->recentCount(7);
        }
        if ($can('updates.manage')) {
            $cached = $this->updates()->cachedStatus();
            if ($cached !== null && $cached['has_update']) {
                $facts['update'] = ['latest' => (string)($cached['latest_version'] ?? ''), 'current' => (string)($cached['current_version'] ?? '')];
            }
        }
        if ($can('seo.manage')) {
            $audit = (new SeoAudit($this->content, fn(): array => $this->settings))->run(0)['checks'];
            $facts['seo'] = [
                'discourage' => SeoSettings::from($this->settings)['discourage'],
                'no_description' => $audit['no_description']['count'] ?? 0,
                'duplicate_titles' => $audit['duplicate_title']['count'] ?? 0,
            ];
        }
        if ($can('redirects.manage')) {
            $facts['not_found'] = $this->redirects->isAvailable() ? $this->redirects->notFoundCount() : 0;
            if ($can('content.manage')) {
                $sources = $this->redirects->permanentSources();
                $found = $sources !== [] ? $this->linkScanner()->countBySource($sources) : [];
                $facts['links'] = array_sum($found ?? []);
            }
        }
        if ($can('analytics.manage')) {
            $a = AnalyticsSettings::from($this->settings);
            $facts['analytics'] = ['mode' => $a['mode'], 'empty' => $a['tag_id'] === '' && trim($a['head_code']) === '' && trim($a['body_code']) === ''];
        }
        if ($can('settings.manage')) {
            $facts['no_site_address'] = trim((string)($this->settings['base_url'] ?? '')) === '';
        }
        return $facts;
    }

    private function archiveBuilder(): ArchiveBuilder
    {
        return $this->archiveBuilderService ??= new ArchiveBuilder(
            fn(): array => $this->taxonomies()->names(),
            fn(string $taxonomy, string $termId, string $lang): string => $this->taxonomyTermLabel($taxonomy, $termId, $lang)
        );
    }

    private function contentCsv(): ContentCsv
    {
        return $this->contentCsvService ??= new ContentCsv(
            $this->content,
            $this->contentDir,
            fn(): array => $this->settings,
            $this->revisions,
            fn(): HtmlGuard => $this->htmlGuard(),
            fn(string $date): string => $this->normalizeDateForStorage($date),
            fn(): string => $this->currentUsername(),
            fn(): bool => $this->permissions->can($this->auth->user(), 'content.raw_html')
        );
    }

    private function structuredData(): StructuredData
    {
        return $this->structuredDataService ??= new StructuredData(
            fn(): array => $this->settings,
            fn(): array => $this->themeSettings,
            fn(string $key, ?string $fallback = null): string => $this->translate($key, $fallback),
            fn(string $path): string => $this->buildAbsoluteUrl($path),
            fn(string $lang): string => $this->langPrefix($lang)
        );
    }

    private function menus(): Menus
    {
        return $this->menuStore ??= new Menus(
            $this->contentDir,
            fn(): array => $this->settings,
            fn(string $key, ?string $fallback = null): string => $this->translate($key, $fallback),
            fn(): array => $this->theme->menuLocations()
        );
    }

    private function taxonomies(): Taxonomies
    {
        return $this->taxonomyStore ??= new Taxonomies($this->contentDir, array_map('strval', $this->settings['languages']['available'] ?? [(string)($this->settings['languages']['default'] ?? 'el')]));
    }

    private function renderSitemap(): void
    {
        // A sitemap switched off (or a site that asked to stay out of search) is not there to be found.
        if (!$this->sitemap()->enabled()) {
            $this->render404();
            return;
        }
        header('Content-Type: application/xml; charset=utf-8');
        echo $this->sitemap()->xml();
    }

    /** Whether a page or an entry is called "feed" where a feed would be, so the address is its and not the feed's. */
    private function feedIsShadowed(array $route): bool
    {
        $base = $route['segments'];
        $lang = $route['lang'];
        $hidden = $this->auth->check();
        if ($base === []) {
            foreach (ContentPaths::ROOT_TYPES as $type) {
                if (in_array($type, $this->content->getTypes(), true) && $this->content->find($type, 'feed', $lang, $hidden, false) !== null) {
                    return true;
                }
            }
            return false;
        }
        return count($base) === 1 && $this->content->find($base[0], 'feed', $lang, $hidden, false) !== null;
    }

    private function renderFeed(array $route): void
    {
        $lang = $route['lang'];
        $scope = $this->feed()->enabled() ? $this->feed()->scope($route['segments'], $lang) : null;
        if ($scope === null) {
            $this->render404();
            return;
        }
        header('Content-Type: application/rss+xml; charset=utf-8');
        echo $this->feed()->xml($scope, $lang, $route['lang_prefix'] . implode('/', array_merge($route['segments'], ['feed'])));
    }

    private function renderRobots(): void
    {
        $seo = SeoSettings::from($this->settings);
        header('Content-Type: text/plain; charset=utf-8');
        echo RobotsTxt::render(
            $this->sitemap()->enabled() ? $this->buildAbsoluteUrl('sitemap.xml') : '',
            $seo['robots_disallow'],
            $seo['discourage'],
            $seo['block_ai'] ? SeoSettings::AI_CRAWLERS : []
        );
    }

    private function handleTaxonomy(array $segments, string $lang, array $viewDefaults): void
    {
        $page = $this->taxonomyPage()->build($segments, $lang, $viewDefaults, $_GET);
        if ($page === null) {
            $this->render404();
            return;
        }
        if (($page['data']['archive']['settings']['layout'] ?? '') === 'map') {
            // A category or tag that lists its entries on a map needs the map's style sheet and script.
            $base = rtrim((string)($this->settings['base_url'] ?? ''), '/');
            $page['data']['block_styles'] = array_values(array_filter(array_merge((array)($page['data']['block_styles'] ?? []), [$this->theme->blockStylesheetUrl($base, ['latest', 'map'])])));
            $page['data']['block_scripts'] = array_values(array_filter(array_merge((array)($page['data']['block_scripts'] ?? []), [$this->theme->blockScriptUrl($base, ['map'])])));
        }
        $this->render($this->templates()->forTaxonomy($page['kind'], $page['slug']), $page['data']);
    }

    private function buildTaxonomyPath(string $taxonomy, string $termId, string $prefix = ''): string
    {
        $kind = Taxonomies::kind($taxonomy);
        $slug = $this->taxonomies()->slug($taxonomy, $termId);
        if ($slug === '') {
            return '';
        }
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            return $kind . '/' . $slug;
        }
        return $prefix . '/' . $kind . '/' . $slug;
    }

    private function isReservedFrontmatterKey(string $key): bool
    {
        return $this->contentEditor()->isReservedKey($key);
    }

    private function normalizeDateForStorage(string $value): string
    {
        return $this->contentEditor()->normalizeDate($value);
    }

    private function resolveCanonicalUrl(?string $override, string $fallback): string
    {
        if ($override === null || trim($override) === '') {
            return $fallback;
        }
        $override = trim($override);
        if (preg_match('#^https?://#i', $override)) {
            return $override;
        }
        return $this->buildAbsoluteUrl($override);
    }

    private function buildAbsoluteUrl(string $path): string
    {
        $base = $this->getBaseUrl();
        $base = rtrim($base, '/');
        if ($path === '' || $path === '/') {
            return $base . '/';
        }
        return $base . '/' . ltrim($path, '/');
    }

    private function getBaseUrl(): string
    {
        $base = trim((string)($this->settings['base_url'] ?? ''));
        if ($base !== '') {
            return $base;
        }

        $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if ($scheme === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        }
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host;
    }

    private function setLanguage(string $lang): void
    {
        if ($lang === $this->currentLang && !empty($this->translations)) {
            return;
        }
        $this->currentLang = $lang;
        $this->translations = $this->loadTranslations($lang);
    }

    private function translate(string $key, ?string $fallback = null): string
    {
        if (isset($this->translations[$key])) {
            return (string)$this->translations[$key];
        }
        return $fallback ?? $key;
    }

    private function loadTranslations(string $lang): array
    {
        return $this->theme->translations($lang, (string)($this->settings['languages']['default'] ?? 'en'));
    }

    private function langPrefix(string $lang): string
    {
        return FrontRoute::langPrefix($lang, $this->defaultLanguage());
    }

    private function sanitizeType(string $value): string
    {
        $value = Slug::plain($value);
        return $value === '' ? 'pages' : $value;
    }

    /** @return array<int, array<string, mixed>> */
    private function listBackupSnapshots(): array
    {
        return $this->backups->list();
    }

    private function currentUsername(): string
    {
        return (string)($this->auth->user()['username'] ?? '');
    }

    /** The upload size and file kinds typed on the settings form, or nothing for what this person may not change. @return array{mb: ?int, types: ?string} */
    private function submittedUploadSettings(): array
    {
        return $this->permissions->can($this->auth->user(), 'limits.manage') ? SiteLimits::uploadSettingsFromForm($_POST) : ['mb' => null, 'types' => null];
    }

    private function downloadBackupSnapshot(string $filename, string $failureBase = '/admin/settings?tab=backup'): void
    {
        $path = $this->backups->pathFor($filename);
        if ($path === null) {
            $this->redirect($failureBase . (str_contains($failureBase, '?') ? '&' : '?') . 'backup=fail&backup_msg=' . urlencode('Backup file not found.'));
            return;
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string)(filesize($path) ?: 0));
        readfile($path);
        exit;
    }

    private function updateBackupLastRun(string $isoDate): void
    {
        $raw = $this->siteSettings()->raw('site_settings', $this->siteSettings()->defaults());
        $data = $this->siteSettings()->parse($raw);
        $data['backup']['auto']['last_run'] = $isoDate;
        $yaml = Yaml::dump($data, 4, 2);
        if ($this->systemDatabase->isAvailable()) {
            $this->setSystemMeta('site_settings', $yaml);
        }
        $this->settings = $this->siteSettings()->load();
    }

    private function handleFormRequest(ContentItem $form, string $lang, string $currentPath): array
    {
        $result = $this->publicForms()->handle($form, $lang, $currentPath, [
            'method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            'get' => $_GET,
            'post' => $_POST,
            'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'files' => $_FILES,
            'overflow' => $this->requestOverflowed(),
        ]);
        // A form that was sent now (not the page that thanks for it, shown again, and not a robot's catch in the honeypot) is a goal.
        $honeypot = (string)($result['state']['honeypot'] ?? '');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($result['state']['success']) && ($honeypot === '' || trim((string)($_POST[$honeypot] ?? '')) === '')) {
            $this->analyticsGoal('form', $form->slug, $currentPath);
        }
        if ($result['location'] !== '') {
            $this->redirect($result['location']);
        }
        return $result['state'];
    }

    /** Whether a form was sent with more than the server takes in one request: PHP then throws the whole request away, and nothing of the form arrives. */
    private function requestOverflowed(): bool
    {
        $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        $cap = SiteLimits::serverPostCap();
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $length > 0 && $cap > 0 && $length > $cap && $_POST === [] && $_FILES === [];
    }

    private function mailer(): Mailer
    {
        $config = $this->settings['forms']['notifications'] ?? [];
        return new Mailer(is_array($config) ? $config : []);
    }

    /** @param array<int, array{path: string, name: string, mime: string}> $attachments */
    private function sendEmailMessage(string $to, string $subject, string $body, array $headers, array $attachments = []): bool
    {
        $result = $this->mailer()->send($to, $subject, $body, $headers, $attachments);
        $this->logEmailAttempt($to, $subject, $result['provider'], $result['ok'], $result['error'], [
            'driver_setting' => (string)($this->settings['forms']['notifications']['driver'] ?? ''),
            'from' => (string)($headers['From'] ?? ''),
            'reply_to' => (string)($headers['Reply-To'] ?? ''),
            'cc' => (string)($headers['Cc'] ?? ''),
            'bcc' => (string)($headers['Bcc'] ?? ''),
            'attachments' => count($attachments),
        ]);
        return $result['ok'];
    }

    private function applyShortcodes(string $html, string $lang, string $currentPath): string
    {
        return PublicForms::replaceShortcodes($html, fn(string $slug): string => $this->renderFormEmbedBySlug($slug, $lang, $currentPath));
    }

    private function renderFormEmbedBySlug(string $slug, string $lang, string $currentPath): string
    {
        $form = FormTranslations::find($this->content, $slug, $lang, $this->auth->check())['form'];
        if (!$form) {
            return '';
        }
        return $this->twig->render('components/form.twig', $this->publicForms()->embed($form, $currentPath, $this->formStates[$form->slug] ?? null, $_GET, $lang, $this->requestOverflowed()));
    }

    private function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }
}
