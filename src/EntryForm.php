<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * Everything the editor screen of one entry shows: its fields read from the front matter, the blocks for the block
 * editor, how it opens, its translations, address and old addresses, the links that still use an address that just
 * changed, its latest versions, and for a form its fields, notifications and stored submissions. An entry that does
 * not exist yet opens with the defaults of its type. The class only reads; saving is `ContentEditor`.
 */
final class EntryForm
{
    /**
     * @param \Closure(): array<string, mixed> $settings
     * @param \Closure(): array<string, mixed> $themeSettings
     * @param \Closure(): Taxonomies $taxonomies
     * @param \Closure(): LinkScanner $linkScanner
     * @param \Closure(): BlockRegistry $blocks
     * @param \Closure(): PresetLibrary $presets
     * @param \Closure(string): string $absoluteUrl the full address of a public path
     */
    public function __construct(
        private string $contentDir,
        private ContentRepository $content,
        private ContentTypes $types,
        private ContentEditor $editor,
        private EntryTranslations $translations,
        private FormsAdmin $forms,
        private RedirectRepository $redirects,
        private RevisionRepository $revisions,
        private RevisionAdmin $history,
        private Theme $theme,
        private \Closure $settings,
        private \Closure $themeSettings,
        private \Closure $taxonomies,
        private \Closure $linkScanner,
        private \Closure $blocks,
        private \Closure $presets,
        private \Closure $absoluteUrl,
        private ?Relations $relations = null
    ) {
    }

    /** The fields that point at other entries get the entries to choose from. @param array<string, array<string, mixed>> $fields @return array<string, array<string, mixed>> */
    private function withChoices(array $fields): array
    {
        return $this->relations !== null ? $this->relations->withChoices($fields) : $fields;
    }

    /**
     * @param array<string, mixed> $query the address's query (what the page was opened with: saved, notice, ...)
     * @return array<string, mixed> what the page shows
     */
    public function form(string $type, string $slug, string $lang, array $query, bool $canRedirects): array
    {
        $settings = ($this->settings)();
        $themeSettings = ($this->themeSettings)();
        $defaultLang = (string)($settings['languages']['default'] ?? 'en');
        $saved = isset($query['saved']);
        $deleted = isset($query['deleted']);

        $path = $this->path($type, $slug, $lang);
        $frontmatter = '';
        $body = '';
        $mainImage = '';
        $metaForm = [
            'title' => '',
            'status' => 'published',
            'visible' => true,
            'date' => '',
            'author' => '',
            'seo_title' => '',
            'seo_description' => '',
            'seo_canonical' => '',
            'seo_og_title' => '',
            'seo_og_description' => '',
            'seo_og_image' => '',
            'seo_noindex' => false,
            'main_image' => '',
            'excerpt' => '',
            'translation_id' => '',
            'custom_fields' => [],
            'taxonomy_terms' => [],
        ];
        $formFields = [];
        $formNotifications = [
            'enabled' => false,
            'to' => '',
            'subject' => '',
            'reply_to_field' => '',
            'cc' => '',
            'bcc' => '',
            'auto_reply' => false,
            'auto_reply_include' => false,
            'attach_files' => false,
            'attach_max_mb' => 10,
            'auto_reply_subject' => '',
            'auto_reply_message' => '',
        ];
        $formSettings = [
            'store_submissions' => $settings['forms']['store_submissions'] ?? true,
            'submit_label' => '',
            'success_message' => '',
            'redirect_url' => '',
            'honeypot' => (string)($settings['forms']['antispam']['honeypot'] ?? 'website'),
            'rate_limit_seconds' => (string)($settings['forms']['antispam']['rate_limit_seconds'] ?? 20),
        ];
        $formSubmissions = [];

        $isNew = true;
        if ($slug !== '' && file_exists($path)) {
            [$frontmatter, $body] = FrontMatter::split((string)file_get_contents($path));
            $isNew = false;
        }
        if ($frontmatter === '' && $slug !== '') {
            $frontmatter = $this->defaultFrontMatter($type, $slug);
        }
        $meta = [];
        $frontMatterError = trim((string)($query['frontmatter_error'] ?? ''));
        if ($frontmatter !== '') {
            try {
                $meta = Yaml::parse($frontmatter) ?: [];
            } catch (\Throwable $e) {
                // The editor still opens, with the raw front matter to fix by hand.
                $meta = [];
                $frontMatterError = $e->getMessage();
            }
        }
        $pageBlocks = is_array($meta) && is_array($meta['blocks'] ?? null) ? array_values($meta['blocks']) : [];
        if ($pageBlocks !== [] && $type !== 'forms') {
            // Blocks are edited in the Blocks tab; the raw YAML keeps everything else.
            $withoutBlocks = $meta;
            unset($withoutBlocks['blocks']);
            $frontmatter = $withoutBlocks === [] ? '' : trim(Yaml::dump($withoutBlocks, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        }
        // A new form starts from a ready-made one the person chose.
        $starterName = '';
        if ($type === 'forms' && $isNew && is_array($meta)) {
            $starter = null;
            $template = FormTemplates::get(Slug::plain((string)($query['template'] ?? '')), $lang);
            if ($template !== null) {
                $starter = [
                    'title' => $template['title'], 'fields' => $template['fields'], 'notifications' => $template['notifications'],
                    'submit_label' => $template['submit_label'], 'success_message' => $template['success_message'],
                ];
                $starterName = $template['name'];
            }
            if ($starter !== null) {
                $meta = array_merge($meta, $starter);
            }
        }
        if (is_array($meta)) {
            $mainImage = (string)($meta['main_image'] ?? '');
            $metaForm['title'] = $isNew ? (string)($meta['title'] ?? '') : (string)($meta['title'] ?? Slug::title($slug));
            $metaForm['status'] = (string)($meta['status'] ?? 'published');
            $metaForm['visible'] = (bool)($meta['visible'] ?? true);
            $rawDate = $meta['date'] ?? '';
            $metaForm['date'] = $this->adminDate($rawDate);
            $metaForm['author'] = (string)($meta['author'] ?? '');
            $metaForm['template'] = (string)($meta['template'] ?? '');
            $metaForm['hero_layout'] = (string)($meta['hero_layout'] ?? '');
            $heroParallax = $meta['hero_parallax'] ?? '';
            $metaForm['hero_parallax'] = is_bool($heroParallax) ? ($heroParallax ? 'on' : 'off') : (string)$heroParallax;
            $headerTransparent = $meta['header_transparent'] ?? '';
            $metaForm['header_transparent'] = is_bool($headerTransparent) ? ($headerTransparent ? 'on' : 'off') : (string)$headerTransparent;
            foreach (($this->taxonomies)()->names() as $taxonomyName) {
                $metaForm['taxonomy_terms'][$taxonomyName] = Format::list($meta[$taxonomyName] ?? null);
            }
            $seo = $meta['seo'] ?? [];
            if (is_array($seo)) {
                $metaForm['seo_title'] = (string)($seo['title'] ?? '');
                $metaForm['seo_description'] = (string)($seo['description'] ?? '');
                $metaForm['seo_canonical'] = (string)($seo['canonical'] ?? '');
                $metaForm['seo_og_title'] = (string)($seo['og_title'] ?? '');
                $metaForm['seo_og_description'] = (string)($seo['og_description'] ?? '');
                $metaForm['seo_og_image'] = (string)($seo['og_image'] ?? '');
                $metaForm['seo_noindex'] = Format::isTruthy($seo['noindex'] ?? false);
            }
            $metaForm['main_image'] = $mainImage;
            $metaForm['excerpt'] = (string)($meta['excerpt'] ?? '');
            $metaForm['custom_fields'] = $this->extractCustomFields($meta, $this->types->declaredKeys($type));
            if ($type === 'forms') {
                $formFields = FormFields::forBuilder($meta['fields'] ?? [], !$isNew);
                $notifications = $meta['notifications'] ?? [];
                if (is_array($notifications)) {
                    $formNotifications['enabled'] = Format::isTruthy($notifications['enabled'] ?? false);
                    $formNotifications['to'] = (string)($notifications['to'] ?? '');
                    $formNotifications['subject'] = (string)($notifications['subject'] ?? '');
                    $formNotifications['reply_to_field'] = (string)($notifications['reply_to_field'] ?? '');
                    $formNotifications['cc'] = (string)($notifications['cc'] ?? '');
                    $formNotifications['bcc'] = (string)($notifications['bcc'] ?? '');
                    $formNotifications['auto_reply'] = Format::isTruthy($notifications['auto_reply'] ?? false);
                    $formNotifications['auto_reply_include'] = Format::isTruthy($notifications['auto_reply_include'] ?? false);
                    $formNotifications['attach_files'] = Format::isTruthy($notifications['attach_files'] ?? false);
                    $attachMb = (float)($notifications['attach_max_mb'] ?? 10);
                    $formNotifications['attach_max_mb'] = $attachMb > 0 ? $attachMb : 10;
                    $formNotifications['auto_reply_subject'] = (string)($notifications['auto_reply_subject'] ?? '');
                    $formNotifications['auto_reply_message'] = (string)($notifications['auto_reply_message'] ?? '');
                }
                $formSettings['store_submissions'] = Format::isTruthy($meta['store_submissions'] ?? $formSettings['store_submissions']);
                $formSettings['submit_label'] = (string)($meta['submit_label'] ?? '');
                $formSettings['success_message'] = (string)($meta['success_message'] ?? '');
                $formSettings['redirect_url'] = (string)($meta['redirect_url'] ?? '');
                $antispam = $meta['antispam'] ?? [];
                if (is_array($antispam)) {
                    $formSettings['honeypot'] = (string)($antispam['honeypot'] ?? $formSettings['honeypot']);
                    $formSettings['rate_limit_seconds'] = (string)($antispam['rate_limit_seconds'] ?? $formSettings['rate_limit_seconds']);
                }
            }
        }

        $translationId = '';
        if (is_array($meta)) {
            $translationId = (string)($meta['translation_id'] ?? '');
        }
        if ($translationId === '') {
            $translationId = (string)($query['translation_id'] ?? '');
        }
        if ($translationId === '') {
            $translationId = $this->editor->translationIdForSlug($type, $slug);
        }
        if ($translationId === '') {
            $translationId = ContentEditor::newTranslationId();
        }
        $metaForm['translation_id'] = $translationId;
        $translations = $this->translations->links($type, $slug, $translationId);
        if ($isNew && $metaForm['date'] === '') {
            $metaForm['date'] = date('Y-m-d');
        }
        $formSubmissionsTotal = 0;
        if ($type === 'forms' && $slug !== '') {
            $formSubmissions = $this->forms->recent($slug);
            $formSubmissionsTotal = count($formSubmissions);
            $formSubmissions = array_slice($formSubmissions, 0, 10);
        }
        $frontUrl = '';
        $linkFix = null;
        $itemExists = $slug !== '' && is_file($path);
        $homeSlug = (new ContentPaths($settings))->homeSlug();
                $address = [
            'exists' => $itemExists,
            'prefix' => '/' . str_replace('__slug__', '', ContentPaths::build($type, '__slug__', $lang, $homeSlug, $defaultLang)),
            // The home page and forms keep their address: the site and its stored submissions refer to it by name.
            'locked' => $type === 'forms' || ($type === 'pages' && $slug === $homeSlug && $lang === $defaultLang),
            'siblings' => $itemExists ? array_column($this->editor->translationSiblings($type, $slug, $lang), 'lang') : [],
            'old_addresses' => [],
        ];
        if ($slug !== '') {
            $publicPath = ContentPaths::build($type, $slug, $lang, $homeSlug, $defaultLang);
            $frontUrl = ($this->absoluteUrl)($publicPath);
            if ($itemExists && $canRedirects) {
                $address['old_addresses'] = $this->redirects->pointingTo($publicPath);
            }
            // Just after an address changed: are there links in other content that still use the old one?
            if ($itemExists && (string)($query['address'] ?? '') === 'changed') {
                $permanent = array_values(array_filter($this->redirects->pointingTo($publicPath), static fn(array $r): bool => (bool)$r['enabled'] && (int)$r['status_code'] === 301));
                $counts = $permanent !== [] ? ($this->linkScanner)()->countBySource(array_column($permanent, 'source')) : null;
                if ($counts !== null && array_sum($counts) > 0) {
                    $linkFix = ['count' => array_sum($counts), 'ids' => implode(',', array_column($permanent, 'id'))];
                }
            }
        }

        return [
            'type' => $type,
            'slug' => $slug,
            'lang' => $lang,
            'title_from_slug' => Slug::title($slug),
            'frontmatter' => $frontmatter,
            'front_matter_error' => $frontMatterError,
            'body' => $body,
            'main_image' => $mainImage,
            'meta_form' => $metaForm,
            'type_definition' => $this->types->definition($type, 'en', $defaultLang),
            'type_fields' => $type === 'forms' ? [] : $this->withChoices($this->types->editableFields($type, 'en', $defaultLang)),
            'type_values' => is_array($meta) ? $this->types->values($type, $meta, 'en', $defaultLang) : [],
            'taxonomies' => $this->taxonomyChoices(),
            'translations' => $translations,
            'front_url' => $frontUrl,
            'types' => $this->content->getTypes(),
            'languages' => $settings['languages']['available'] ?? [],
            'saved' => $saved,
            'notice' => (string)($query['notice'] ?? ''),
            'address' => $address,
            'address_changed' => (string)($query['address'] ?? '') === 'changed',
            'link_fix' => $linkFix,
            'restored' => isset($query['restored']),
            'history' => $itemExists ? $this->history->decorate($this->revisions->forItem($type, $slug, $lang, 8)) : [],
            'history_total' => $itemExists ? $this->revisions->countForItem($type, $slug, $lang) : 0,
            'address_taken' => Slug::plain((string)($query['address_taken'] ?? '')),
            'deleted' => $deleted,
            'admin_section' => $type === 'forms' ? 'forms' : 'content',
            'current_type' => $type,
            'form_fields' => $formFields,
            'form_starter' => $starterName,
            'form_translation_status' => $type === 'forms' ? $this->translationStatus($meta, $body, $settings) : [],
            'form_builder_json' => $type === 'forms' ? $this->builderJson($formFields, $slug, $lang, !$isNew, is_array($meta) ? $meta : [], $settings, Slug::plain((string)($query['translate'] ?? ''))) : '',
            'form_notifications' => $formNotifications,
            'form_settings' => $formSettings,
            'form_submissions' => $formSubmissions,
            'form_submissions_total' => $formSubmissionsTotal,
            'block_editor_json' => $type === 'forms' ? '' : $this->blockEditorJson($pageBlocks, $lang),
            'page_templates' => $type === 'forms' ? [] : (new SingleLayouts($this->theme))->templatesFor($type, ($this->themeSettings)()),
            'opening' => $this->openingChoices($type),
        ];
    }

    /**
     * What each other language of a form still lacks, for the Translations tab.
     *
     * @param array<string, mixed>|mixed $meta
     * @param array<string, mixed> $settings
     * @return array<int, array{lang: string, name: string, missing: int, total: int}>
     */
    private function translationStatus(mixed $meta, string $body, array $settings): array
    {
        $default = (string)($settings['languages']['default'] ?? 'en');
        $languages = array_values(array_map('strval', $settings['languages']['available'] ?? [$default]));
        $texts = is_array($meta) ? $meta : [];
        $texts['body'] = $body;
        $rows = [];
        foreach (FormTranslations::overview($texts, $languages, $default) as $lang => $count) {
            $rows[] = ['lang' => $lang, 'name' => MenuAdmin::languageName($lang), 'missing' => $count['missing'], 'total' => $count['total']];
        }
        return $rows;
    }

    /**
     * What the form builder starts with, as JSON for the page: the fields (each with its translations), the languages of the
     * site, the translations of the form's own texts, and the language to open on.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $settings
     */
    private function builderJson(array $fields, string $slug, string $lang, bool $existing, array $meta, array $settings, string $translate): string
    {
        $default = (string)($settings['languages']['default'] ?? 'en');
        $others = array_values(array_diff(array_map('strval', $settings['languages']['available'] ?? [$default]), [$default]));
        return (string)json_encode([
            'fields' => $fields,
            'slug' => $slug,
            'lang' => $lang,
            'existing' => $existing,
            'shortcode' => $slug !== '' ? '[form slug="' . $slug . '"]' : '',
            'default' => $default,
            'default_name' => MenuAdmin::languageName($default),
            'languages' => array_map(static fn(string $code): array => ['code' => $code, 'name' => MenuAdmin::languageName($code)], $others),
            'translations' => (object)FormTranslations::cleanForm($meta['translations'] ?? [], $others),
            'open' => in_array($translate, $others, true) ? $translate : '',
            // What a file field can be limited to here: the server's and the site's limits, and the kinds of file it offers.
            'upload' => [
                'server_file' => SiteLimits::serverUploadCap(),
                'server_post' => SiteLimits::serverPostCap(),
                'site_file' => SiteLimits::uploadMbFrom($settings) * 1048576,
                'max_files' => FormUploads::MAX_FILES,
                'default_mb' => FormUploads::DEFAULT_MAX_MB,
                'presets' => FormUploads::PRESETS,
                'blocked' => FormUploads::BLOCKED,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * What the editor may choose about how an entry opens (title layout, transparent header), with what its
     * content type does today so "follow the settings" can say what that is. Empty when the theme offers neither.
     *
     * @return array{layouts: array<string, string>, transparent: array<string, string>, parallax: array<string, string>, type_layout: string, type_transparent: string, type_parallax: string}
     */
    private function openingChoices(string $type): array
    {
        $themeSettings = ($this->themeSettings)();
        $single = new SingleLayouts($this->theme);
        $values = $single->forType($type, $themeSettings);
        $typeTransparent = (string)$values['header'];
        if (!isset($single->headerChoices()[$typeTransparent])) {
            $typeTransparent = Format::isTruthy($themeSettings['header']['transparent'] ?? false) ? 'on' : 'off';
        }
        return [
            'layouts' => $single->titleChoices(),
            'transparent' => $single->headerChoices(),
            'parallax' => $single->parallaxChoices(),
            'type_layout' => (string)$values['title'],
            'type_transparent' => $typeTransparent,
            'type_parallax' => Format::isTruthy($values['parallax'] ?? false) ? 'on' : 'off',
        ];
    }

    /** Data for the admin block editor, safe to embed in a <script type="application/json">. */
    private function blockEditorJson(array $blocks, string $lang): string
    {
        $settings = ($this->settings)();
        $registry = ($this->blocks)();
        return (string)json_encode([
            'definitions' => $registry->editorDefinitions(),
            // A block saved in a layout that has since become another is shown as the layout it is now.
            'container' => BlockContainer::editorData(),
            'blocks' => $registry->forEditor($blocks),
            'presets' => ($this->presets)()->forEditor($lang, (string)($settings['languages']['default'] ?? 'en')),
            'presets_url' => rtrim((string)($settings['base_url'] ?? ''), '/') . '/admin/block-presets',
            'lang' => $lang,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Free-form custom fields of an item. Fields the content type declares have their own inputs, so
     * they are left out ($exclude).
     *
     * @param string[] $exclude
     */
    private function extractCustomFields(array $meta, array $exclude = []): array
    {
        $custom = [];
        $customFields = $meta['custom_fields'] ?? [];
        if (is_array($customFields)) {
            foreach ($customFields as $key => $value) {
                $key = (string)$key;
                if ($key === '' || $this->editor->isReservedKey($key) || in_array($key, $exclude, true)) {
                    continue;
                }
                $custom[$key] = $this->stringifyCustomValue($value);
            }
        }

        foreach ($meta as $key => $value) {
            $key = (string)$key;
            if ($this->editor->isReservedKey($key) || array_key_exists($key, $custom) || in_array($key, $exclude, true)) {
                continue;
            }
            $custom[$key] = $this->stringifyCustomValue($value);
        }

        $rows = [];
        foreach ($custom as $key => $value) {
            $rows[] = [
                'key' => $key,
                'value' => $value,
            ];
        }
        return $rows;
    }

    private function stringifyCustomValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        return (string)$value;
    }

    private function adminDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_int($value)) {
            return $this->dateText($value);
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return '';
            }
            if (ctype_digit($trimmed)) {
                return $this->dateText((int)$trimmed);
            }
            if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $trimmed)) {
                return $this->dateText($trimmed);
            }
            if (preg_match('/^\\d{4}\\/\\d{2}\\/\\d{2}$/', $trimmed)) {
                return $this->dateText($trimmed);
            }
            return $trimmed;
        }
        return (string)$value;
    }

    private function defaultFrontMatter(string $type, string $slug): string
    {
        $lines = [
            'status: published',
            'visible: true',
        ];

        if ($type !== 'forms') {
            $lines[] = 'main_image: ""';
        }

        if (in_array($type, ['posts', 'projects'], true)) {
            $lines[] = 'date: ' . date('Y-m-d');
        }

        return implode("\n", $lines);
    }

    /** @return array<int, array{name: string, title: string, terms: array<int, array{id: string, slug: string, labels: array<string, string>}>}> */
    private function taxonomyChoices(): array
    {
        $rows = [];
        $taxonomies = ($this->taxonomies)();
        foreach ($taxonomies->names() as $name) {
            $taxonomy = $taxonomies->load($name);
            $rows[] = [
                'name' => $name,
                'title' => (string)$taxonomy['title'],
                // A taxonomy with a tree is offered as a tree (a child after its parent, marked by its depth).
                'terms' => $taxonomy['hierarchical'] ? $taxonomies->tree($name) : $taxonomy['terms'],
            ];
        }
        return $rows;
    }

    private function dateText(mixed $value): string
    {
        return Format::dateValue($value, (string)((($this->settings)())['date_format'] ?? 'd/m/Y'));
    }

    private function path(string $type, string $slug, string $lang): string
    {
        return $this->contentDir . '/' . $type . '/' . (new ContentPaths(($this->settings)()))->filename($slug, $lang);
    }
}
