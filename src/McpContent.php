<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * The content tools of the MCP server (docs/mcp.md): read, search and write entries as a connection's account. Every write goes
 * through ContentEditor::save, so addresses, translations, the index and the history behave as in the admin. Writes are strict:
 * a job cannot see the page, so an argument, field, term or language the site does not know is an error that names it, never
 * dropped or replaced by a default. The connection's policy (McpStore::policy) limits types, languages and publications a day.
 */
final class McpContent
{
    private const STATUSES = ['draft', ScheduledPublishing::STATUS, 'published'];
    /** Field kinds a job can write; the others (lists, relations, galleries) are kept as they are. */
    private const WRITABLE_FIELDS = ['text', 'textarea', 'markdown', 'number', 'date', 'select', 'toggle', 'url', 'email', 'color'];
    private const MAX_BODY = 200000;
    public const MAX_IMAGE_BYTES = 10 * 1048576;

    /**
     * @param \Closure(string): string $absoluteUrl the full address of a path of the site
     * @param \Closure(array<string, mixed>, array<string, mixed>, bool): void $afterWrite after a save: the entry, the connection, whether it
     *   was published or scheduled by this save (caches, notices)
     * @param \Closure(int): bool $roomFor whether the site's storage limit leaves room for that many bytes
     * @param \Closure(int): void $uploadsChanged told when the library grew
     */
    public function __construct(
        private ContentRepository $content,
        private ContentEditor $editor,
        private ContentIndex $index,
        private ContentTypes $types,
        private Taxonomies $taxonomies,
        private McpStore $store,
        private array $settings,
        private string $publicDir,
        private \Closure $absoluteUrl,
        private \Closure $afterWrite,
        private ?MediaLibrary $media = null,
        private ?SafeHttp $http = null,
        private ?\Closure $roomFor = null,
        private ?\Closure $uploadsChanged = null,
    ) {
    }

    /** @return array<string, array{scopes: string[], permission: ?string, title: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, bool>}> */
    public static function definitions(): array
    {
        $read = [McpOAuth::SCOPE_READ, McpOAuth::SCOPE_WRITE, McpOAuth::SCOPE_PUBLISH];
        $write = [McpOAuth::SCOPE_WRITE, McpOAuth::SCOPE_PUBLISH];
        $ro = ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false];
        $str = static fn(string $description, ?int $max = null): array => ['type' => 'string', 'description' => $description] + ($max !== null ? ['maxLength' => $max] : []);
        $entry = [
            'title' => $str('The title.', 300),
            'body' => $str('The text, in Markdown. HTML is shown as text.', self::MAX_BODY),
            'excerpt' => $str('A short summary for lists and search.', 1000),
            'status' => ['type' => 'string', 'enum' => self::STATUSES, 'description' => 'draft: hidden; published: public now; scheduled: public at publish_at. published and scheduled need the content:publish scope.'],
            'publish_at' => $str('For scheduled: a date and time with its offset, e.g. 2026-11-03T09:00:00+02:00 (one without an offset is read in the site\'s time zone).'),
            'date' => $str('The date shown on the entry, YYYY-MM-DD. Scheduled entries get the day they are published.'),
            'author' => $str('The byline. Without one, the connection\'s byline is used for a new entry.', 120),
            'terms' => ['type' => 'object', 'description' => 'Taxonomy name => list of term ids (see list_terms). Replaces the terms of that taxonomy.', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
            'fields' => ['type' => 'object', 'description' => 'Fields of the content type (see site_info) => value.'],
            'main_image' => $str('A picture already in the media library, as its address (/uploads/...).'),
            'seo' => ['type' => 'object', 'properties' => ['title' => $str('Title for search engines.', 200), 'description' => $str('Description for search engines.', 400)], 'additionalProperties' => false],
        ];
        $create = $entry + [
            'type' => $str('The content type (see site_info).'),
            'lang' => $str('The language (see site_info); the default language when left out.'),
            'source_key' => $str('Where the entry comes from, usually the source article\'s address. An entry made before for the same key is returned instead of a new one.', 200),
            'translation_of' => ['type' => 'object', 'description' => 'Make this the translation of an existing entry of the same type in another language.', 'properties' => ['slug' => ['type' => 'string'], 'lang' => ['type' => 'string']], 'required' => ['slug', 'lang'], 'additionalProperties' => false],
        ];
        $schema = static fn(array $properties, array $required): array => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
        return [
            'list_entries' => [
                'scopes' => $read, 'permission' => 'content.manage', 'title' => 'List entries',
                'description' => 'Entries of a content type, newest first, drafts and scheduled ones included: address, title, status, dates.',
                'inputSchema' => $schema([
                    'type' => $str('The content type.'), 'lang' => $str('Only this language.'),
                    'status' => ['type' => 'string', 'enum' => self::STATUSES], 'since' => $str('Only entries changed since this date or time (ISO 8601).'),
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20], 'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                ], ['type']),
                'annotations' => $ro,
            ],
            'get_entry' => [
                'scopes' => $read, 'permission' => 'content.manage', 'title' => 'Read an entry',
                'description' => 'One entry: its text in Markdown, title, status, dates, terms, fields, translations and updated_at (give it back to update_entry as expected_updated_at).',
                'inputSchema' => $schema(['type' => $str('The content type.'), 'slug' => $str('Its address within the type.'), 'lang' => $str('Its language; the default when left out.')], ['type', 'slug']),
                'annotations' => $ro,
            ],
            'search_entries' => [
                'scopes' => $read, 'permission' => 'content.manage', 'title' => 'Search entries',
                'description' => 'Entries whose title, address or text contain the words, drafts included.',
                'inputSchema' => $schema(['query' => $str('The words.', 200), 'type' => $str('Only this content type.'), 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20]], ['query']),
                'annotations' => $ro,
            ],
            'list_terms' => [
                'scopes' => $read, 'permission' => 'content.manage', 'title' => 'List terms',
                'description' => 'The terms of a taxonomy (categories, tags, ...): id, labels per language, parent.',
                'inputSchema' => $schema(['taxonomy' => $str('The taxonomy name (see site_info).')], ['taxonomy']),
                'annotations' => $ro,
            ],
            'find_by_source' => [
                'scopes' => $read, 'permission' => 'content.manage', 'title' => 'Find by source',
                'description' => 'The entry made earlier for a source_key, if any. Call it before writing about a source, so the same story is never posted twice.',
                'inputSchema' => $schema(['source_key' => $str('The key given to create_entry.', 200)], ['source_key']),
                'annotations' => $ro,
            ],
            'validate_entry' => [
                'scopes' => $write, 'permission' => 'content.manage', 'title' => 'Check an entry',
                'description' => 'The same arguments as create_entry, checked and not saved: every problem, and what would be saved.',
                'inputSchema' => $schema($create, ['type', 'title', 'body', 'status']),
                'annotations' => $ro,
            ],
            'create_entry' => [
                'scopes' => $write, 'permission' => 'content.manage', 'title' => 'Create an entry',
                'description' => 'A new entry (a post, a page, ...). status is required: say draft, published or scheduled as your task says. With source_key, an entry made before for the same source is returned instead.',
                'inputSchema' => $schema($create, ['type', 'title', 'body', 'status']),
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true],
            ],
            'add_image_from_url' => [
                'scopes' => [McpOAuth::SCOPE_MEDIA], 'permission' => 'media.manage', 'title' => 'Add a picture from an address',
                'description' => 'Fetches a picture (JPEG, PNG, WebP, GIF or AVIF, up to 10 MB) from a public address into the media library and gives its address, '
                    . 'for main_image or for the body (![alt](address)). The same picture fetched twice is kept once. Use pictures you may publish.',
                'inputSchema' => $schema([
                    'url' => $str('The picture\'s address (http or https).', 2000),
                    'alt' => $str('What the picture shows, for people who cannot see it.', 300),
                    'name' => $str('A file name to keep, without the extension.', 80),
                ], ['url', 'alt']),
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true],
            ],
            'update_entry' => [
                'scopes' => $write, 'permission' => 'content.manage', 'title' => 'Change an entry',
                'description' => 'Changes what is given and keeps the rest. Changing a published or scheduled entry, or publishing one, needs content:publish. Give expected_updated_at from get_entry so a change made meanwhile is not overwritten.',
                'inputSchema' => $schema($entry + [
                    'type' => $str('The content type.'), 'slug' => $str('Its address within the type.'), 'lang' => $str('Its language; the default when left out.'),
                    'expected_updated_at' => $str('updated_at as get_entry gave it.'),
                ], ['type', 'slug']),
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => true],
            ],
        ];
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    public function call(string $tool, array $args, array $connection): array
    {
        $this->onlyKnown($tool, $args);
        return match ($tool) {
            'list_entries' => $this->listEntries($args),
            'get_entry' => $this->getEntry($args),
            'search_entries' => $this->search($args),
            'list_terms' => $this->listTerms($args),
            'find_by_source' => $this->findBySource($args),
            'validate_entry' => $this->write($args, $connection, null, true),
            'create_entry' => $this->create($args, $connection),
            'update_entry' => $this->update($args, $connection),
            'add_image_from_url' => $this->addImage($args, $connection),
        };
    }

    // ---- reading

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function listEntries(array $args): array
    {
        $type = $this->type($args['type'] ?? null);
        $lang = isset($args['lang']) ? $this->lang($args['lang']) : null;
        $status = isset($args['status']) ? $this->choice('status', $args['status'], self::STATUSES) : null;
        $since = isset($args['since']) ? strtotime((string)$args['since']) : null;
        if ($since === false) {
            throw new \InvalidArgumentException('since must be a date or a time (ISO 8601).');
        }
        $limit = max(1, min(50, (int)($args['limit'] ?? 20)));
        $offset = max(0, (int)($args['offset'] ?? 0));
        $items = array_values(array_filter(
            $this->content->getItems($type, $lang, true, false),
            fn(ContentItem $item): bool => ($status === null || $this->statusOf($item) === $status) && ($since === null || $item->mtime >= $since)
        ));
        return [
            'total' => count($items),
            'entries' => array_map(fn(ContentItem $item): array => $this->summary($item), array_slice($items, $offset, $limit)),
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function getEntry(array $args): array
    {
        $item = $this->existing($args);
        $siblings = array_map(static fn(array $s): array => ['lang' => $s['lang'], 'status' => $s['status']], $this->editor->translationSiblings($item->type, $item->slug, $item->lang));
        $terms = [];
        foreach ($this->taxonomies->names($item->type) as $name) {
            if (isset($item->meta[$name])) {
                $terms[$name] = array_values(array_map('strval', (array)$item->meta[$name]));
            }
        }
        $seo = is_array($item->meta['seo'] ?? null) ? $item->meta['seo'] : [];
        return $this->summary($item) + [
            'body' => $this->bodyOf($item->filePath),
            'author' => (string)($item->meta['author'] ?? ''),
            'main_image' => (string)($item->meta['main_image'] ?? ''),
            'seo' => array_intersect_key($seo, ['title' => 1, 'description' => 1]),
            'terms' => $terms,
            'fields' => $this->types->values($item->type, $item->meta, 'en', $this->defaultLang()),
            'translations' => $siblings,
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function search(array $args): array
    {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            throw new \InvalidArgumentException('query must have some words.');
        }
        $type = isset($args['type']) ? $this->type($args['type']) : null;
        $limit = max(1, min(50, (int)($args['limit'] ?? 20)));
        $found = [];
        foreach ($this->index->search($query, 200) as $row) {
            if ($row['type'] === 'forms' || ($type !== null && $row['type'] !== $type)) {
                continue;
            }
            $item = $this->content->find((string)$row['type'], (string)$row['slug'], (string)$row['lang'], true, false);
            if ($item !== null) {
                $found[] = $this->summary($item);
            }
            if (count($found) >= $limit) {
                break;
            }
        }
        return ['entries' => $found];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function listTerms(array $args): array
    {
        $name = (string)($args['taxonomy'] ?? '');
        if (!in_array($name, $this->taxonomies->names(), true)) {
            throw new \InvalidArgumentException('Unknown taxonomy "' . $name . '". The site has: ' . implode(', ', $this->taxonomies->names()) . '.');
        }
        $taxonomy = $this->taxonomies->load($name);
        return [
            'taxonomy' => $name,
            'title' => $taxonomy['title'],
            'hierarchical' => $taxonomy['hierarchical'],
            'terms' => array_map(static fn(array $term): array => array_filter([
                'id' => (string)($term['id'] ?? ''),
                'labels' => $term['labels'] ?? [],
                'parent' => (string)($term['parent'] ?? ''),
            ], static fn(mixed $value): bool => $value !== '' && $value !== []), $taxonomy['terms']),
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function findBySource(array $args): array
    {
        $key = trim((string)($args['source_key'] ?? ''));
        $source = $key !== '' ? $this->store->source($key) : null;
        $item = $source !== null ? $this->content->find($source['type'], $source['slug'], $source['lang'], true, false) : null;
        return $item !== null ? ['found' => true, 'entry' => $this->summary($item)] : ['found' => false];
    }

    // ---- writing

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function create(array $args, array $connection): array
    {
        $key = trim((string)($args['source_key'] ?? ''));
        if ($key !== '') {
            $found = $this->findBySource(['source_key' => $key]);
            if ($found['found']) {
                return ['existing' => true, 'entry' => $found['entry'], 'warnings' => ['An entry was already made for this source_key; nothing was changed.']];
            }
        }
        return $this->write($args, $connection, null, false);
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function update(array $args, array $connection): array
    {
        $item = $this->existing($args);
        $expected = trim((string)($args['expected_updated_at'] ?? ''));
        if ($expected !== '' && $expected !== self::updatedAt($item)) {
            throw new \InvalidArgumentException('The entry changed since ' . $expected . ' (now ' . self::updatedAt($item) . '). Read it again with get_entry and apply your change to what it is now.');
        }
        if ($this->statusOf($item) !== 'draft' && !McpOAuth::allows((string)$connection['scope'], [McpOAuth::SCOPE_PUBLISH])) {
            throw new \InvalidArgumentException('This entry is ' . $this->statusOf($item) . '; changing it needs the content:publish scope.');
        }
        return $this->write($args, $connection, $item, false);
    }

    /**
     * Checks a create or update and, unless `$dryRun`, saves it.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $connection
     * @return array<string, mixed>
     */
    private function write(array $args, array $connection, ?ContentItem $existing, bool $dryRun): array
    {
        $policy = McpStore::policy($connection);
        $type = $existing?->type ?? $this->type($args['type'] ?? null);
        $lang = $existing?->lang ?? (isset($args['lang']) ? $this->lang($args['lang']) : $this->defaultLang());
        $problems = [];
        $warnings = [];
        if ($policy['types'] !== [] && !in_array($type, $policy['types'], true)) {
            $problems[] = 'This connection may not write ' . $type . ' (it may write: ' . implode(', ', $policy['types']) . ').';
        }
        if ($policy['languages'] !== [] && !in_array($lang, $policy['languages'], true)) {
            $problems[] = 'This connection may not write in ' . $lang . ' (it may write in: ' . implode(', ', $policy['languages']) . ').';
        }

        $meta = $existing?->meta ?? [];
        if ($existing === null && !array_key_exists('status', $args)) {
            $problems[] = 'status is required: draft, published or scheduled, as your task says.';
        }
        $status = array_key_exists('status', $args) ? $this->choice('status', $args['status'], self::STATUSES) : $this->statusOf($existing);
        $publishing = $status !== 'draft' && ($existing === null || $this->statusOf($existing) === 'draft' || array_key_exists('publish_at', $args));
        if ($status !== 'draft' && !McpOAuth::allows((string)$connection['scope'], [McpOAuth::SCOPE_PUBLISH])) {
            $problems[] = 'status ' . $status . ' needs the content:publish scope; this connection may write drafts only.';
        }
        if ($publishing && $policy['daily_limit'] > 0 && $this->store->publishedToday((int)$connection['id']) >= $policy['daily_limit']) {
            $problems[] = 'This connection reached its limit of ' . $policy['daily_limit'] . ' publications in 24 hours. Save it as a draft, or try again later.';
        }

        $title = array_key_exists('title', $args) ? trim((string)$args['title']) : (string)($meta['title'] ?? '');
        if ($title === '') {
            $problems[] = 'title must not be empty.';
        }
        $body = array_key_exists('body', $args) ? (string)$args['body'] : ($existing !== null ? $this->bodyOf($existing->filePath) : '');
        if ($existing === null && trim($body) === '') {
            $problems[] = 'body must not be empty.';
        }
        $zone = SiteSettings::timeZone($this->settings);
        $publishAt = null;
        if ($status === ScheduledPublishing::STATUS) {
            $publishAt = ScheduledPublishing::normalize($args['publish_at'] ?? ($meta['publish_at'] ?? null), $zone);
            if ($publishAt === null) {
                $problems[] = 'status scheduled needs publish_at, a date and time such as 2026-11-03T09:00:00+02:00.';
            } elseif (ScheduledPublishing::moment($publishAt, $zone) <= new \DateTimeImmutable('now', $zone)) {
                $warnings[] = 'publish_at has passed, so the entry is published within minutes.';
            }
        } elseif (array_key_exists('publish_at', $args)) {
            $problems[] = 'publish_at is only for status scheduled.';
        }
        $date = '';
        if (array_key_exists('date', $args)) {
            $date = trim((string)$args['date']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || \DateTimeImmutable::createFromFormat('!Y-m-d', $date) === false) {
                $problems[] = 'date must be YYYY-MM-DD.';
            }
        } elseif (isset($meta['date'])) {
            $date = is_int($meta['date']) ? gmdate('Y-m-d', $meta['date']) : (string)$meta['date'];
        }

        $terms = [];
        foreach ($this->taxonomies->names($type) as $name) {
            $terms[$name] = array_values(array_map('strval', (array)($meta[$name] ?? [])));
        }
        if ($type !== 'pages' && isset($args['terms'])) {
            if (!is_array($args['terms'])) {
                $problems[] = 'terms must be an object: taxonomy => list of term ids.';
            } else {
                foreach ($args['terms'] as $name => $ids) {
                    if (!array_key_exists((string)$name, $terms)) {
                        $problems[] = $type . ' is not filed under "' . $name . '" (it can be: ' . (implode(', ', array_keys($terms)) ?: 'none') . ').';
                        continue;
                    }
                    $known = array_map(static fn(array $term): string => (string)($term['id'] ?? ''), $this->taxonomies->load((string)$name)['terms']);
                    $unknown = array_values(array_diff(array_map('strval', (array)$ids), $known));
                    if ($unknown !== []) {
                        $problems[] = 'Unknown ' . $name . ': ' . implode(', ', $unknown) . '. Use list_terms for the ids; a person adds new terms in the admin.';
                    }
                    $terms[(string)$name] = array_values(array_unique(array_map('strval', (array)$ids)));
                }
            }
        } elseif ($type === 'pages' && isset($args['terms'])) {
            $problems[] = 'Pages are not filed under taxonomies.';
        }

        $declared = $this->types->editableFields($type, 'en', $this->defaultLang());
        $custom = is_array($meta['custom_fields'] ?? null) ? $meta['custom_fields'] : [];
        if (isset($args['fields'])) {
            foreach (is_array($args['fields']) ? $args['fields'] : [] as $key => $value) {
                $field = $declared[$key] ?? null;
                if ($field === null) {
                    $problems[] = $type . ' has no field "' . $key . '" (it has: ' . (implode(', ', array_keys($declared)) ?: 'none') . ').';
                } elseif (!in_array($field['type'], self::WRITABLE_FIELDS, true)) {
                    $problems[] = 'The field "' . $key . '" (' . $field['type'] . ') cannot be written through MCP yet; a person sets it in the admin.';
                } else {
                    $clean = FieldSchema::clean($field, $value);
                    if (!self::same($clean, $value)) {
                        $problems[] = 'The field "' . $key . '" (' . $field['type'] . ') does not take ' . json_encode($value, JSON_UNESCAPED_UNICODE) . '.'
                            . (isset($field['options']) && is_array($field['options']) ? ' Choose one of: ' . implode(', ', array_map('strval', array_keys($field['options']))) . '.' : '');
                    } else {
                        $custom[$key] = $clean;
                    }
                }
            }
        }

        $mainImage = null;
        if (array_key_exists('main_image', $args)) {
            $mainImage = trim((string)$args['main_image']);
            $path = (string)parse_url($mainImage, PHP_URL_PATH);
            if ($mainImage !== '' && (!str_starts_with($path, '/uploads/') || str_contains($path, '..') || !is_file($this->publicDir . $path))) {
                $problems[] = 'main_image must be a picture of the media library (/uploads/...).';
            }
        }

        $translationId = '';
        $slug = $existing?->slug ?? '';
        if ($existing === null && isset($args['translation_of'])) {
            $of = is_array($args['translation_of']) ? $args['translation_of'] : [];
            $original = $this->content->find($type, Slug::plain((string)($of['slug'] ?? '')), (string)($of['lang'] ?? ''), true, false);
            if ($original === null) {
                $problems[] = 'translation_of: there is no ' . $type . ' "' . ($of['slug'] ?? '') . '" in ' . ($of['lang'] ?? '') . '.';
            } elseif ($original->lang === $lang) {
                $problems[] = 'translation_of must be an entry in another language.';
            } elseif ($this->content->find($type, $original->slug, $lang, true, false) !== null) {
                $problems[] = 'That entry already has a translation in ' . $lang . '; change it with update_entry.';
            } else {
                $translationId = (string)($original->meta['translation_id'] ?? '');
                $slug = $original->slug;
            }
        }

        if ($problems !== []) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }

        // What the admin's form would send: the file's own front matter (which keeps what this does not set), then the fields.
        $front = $existing !== null ? $this->frontOf($existing->filePath) : [];
        $front['custom_fields'] = $custom;
        if ($existing === null) {
            $front['generated_by'] = (string)$connection['name'];
        }
        unset($front['publish_at']);
        if ($front['custom_fields'] === []) {
            unset($front['custom_fields']);
        }
        $post = [
            'title' => $title,
            'slug' => $slug,
            'lang' => $lang,
            'status' => $status,
            'publish_at' => $publishAt ?? '',
            'visible' => ($meta['visible'] ?? true) === false ? '0' : '1',
            'date' => $date,
            'author' => array_key_exists('author', $args) ? trim((string)$args['author']) : (string)($meta['author'] ?? ($existing === null ? $policy['byline'] : '')),
            'excerpt' => array_key_exists('excerpt', $args) ? trim((string)$args['excerpt']) : (string)($meta['excerpt'] ?? ''),
            'body' => $body,
            'frontmatter' => Yaml::dump($front, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),
            'taxonomy_terms' => $terms,
            'translation_id' => $translationId !== '' ? $translationId : (string)($meta['translation_id'] ?? ''),
        ] + self::customRows($meta, array_keys($this->types->definition($type)['fields']), $this->editor);
        if ($existing !== null) {
            $post['original_slug'] = $existing->slug;
            $post['original_lang'] = $existing->lang;
        }
        if ($mainImage !== null) {
            $post['main_image'] = $mainImage;
        }
        if (isset($args['seo'])) {
            $seo = (is_array($args['seo']) ? $args['seo'] : []) + (is_array($meta['seo'] ?? null) ? $meta['seo'] : []);
            foreach (['title', 'description', 'canonical', 'og_title', 'og_description', 'og_image'] as $key) {
                $post['seo_' . $key] = trim((string)($seo[$key] ?? ''));
            }
            if (($seo['noindex'] ?? false) === true) {
                $post['seo_noindex'] = '1';
            }
        }

        $saved = $this->editor->save($type, $post, null, false, (string)$connection['username'] . ' (MCP: ' . $connection['name'] . ')', $dryRun);
        if (!empty($saved['html_neutralized'])) {
            $warnings[] = 'HTML in the text is shown as text; write Markdown.';
        }
        if ($saved['slug_taken'] !== '') {
            $warnings[] = 'The address ' . $saved['slug_taken'] . ' was taken, so the entry is at ' . $saved['slug'] . '.';
        }
        if ($dryRun) {
            return ['ok' => true, 'would_save' => ['type' => $type, 'slug' => $saved['slug'], 'lang' => $saved['lang'], 'status' => $saved['status'], 'publish_at' => $publishAt], 'warnings' => $warnings];
        }
        $this->content->forget();
        $item = $this->content->find($type, $saved['slug'], $saved['lang'], true, false);
        if ($item === null) {
            throw new \RuntimeException('The entry was saved but could not be read back.');
        }
        $key = trim((string)($args['source_key'] ?? ''));
        if ($existing === null && $key !== '') {
            $this->store->rememberSource($key, (int)$connection['id'], $type, $item->slug, $item->lang);
        }
        $published = $publishing && $this->statusOf($item) !== 'draft';
        if ($published) {
            $this->store->recordPublication((int)$connection['id'], $type, $item->slug, $item->lang);
        }
        ($this->afterWrite)($this->summary($item), $connection, $published);
        return ['existing' => false, 'entry' => $this->summary($item), 'warnings' => $warnings];
    }

    // ---- pictures

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function addImage(array $args, array $connection): array
    {
        if ($this->media === null || $this->http === null) {
            throw new \InvalidArgumentException('Pictures cannot be added on this site.');
        }
        $url = trim((string)($args['url'] ?? ''));
        $alt = trim((string)($args['alt'] ?? ''));
        if ($url === '' || $alt === '') {
            throw new \InvalidArgumentException('url and alt are both required.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mcpimg');
        if ($tmp === false) {
            throw new \RuntimeException('No temporary file.');
        }
        try {
            $got = $this->http->download($url, $tmp, self::MAX_IMAGE_BYTES);
            if (!$got['ok']) {
                throw new \InvalidArgumentException($got['error']);
            }
            $kind = SafeHttp::imageKind($tmp);
            if ($kind === '') {
                throw new \InvalidArgumentException('That address is not a JPEG, PNG, WebP, GIF or AVIF picture.');
            }
            if (!in_array($kind, $this->media->allowedExtensions(), true) && !($kind === 'jpg' && in_array('jpeg', $this->media->allowedExtensions(), true))) {
                throw new \InvalidArgumentException('This site does not take ' . strtoupper($kind) . ' pictures.');
            }
            $bytes = (int)filesize($tmp);
            // The id is the picture's own fingerprint, so the same picture fetched again is the one already kept.
            $id = substr((string)hash_file('sha256', $tmp), 0, 16);
            $existing = $this->media->find($id);
            if ($existing === null && $this->roomFor !== null && !($this->roomFor)($bytes)) {
                throw new \InvalidArgumentException('The site\'s storage is full; ask the site owner to make room.');
            }
            $base = Slug::fromText(trim((string)($args['name'] ?? '')) ?: pathinfo((string)parse_url($got['url'], PHP_URL_PATH), PATHINFO_FILENAME)) ?: 'picture';
            $this->media->ensureDirectories();
            $item = $this->media->import($tmp, mb_substr($base, 0, 80) . '.' . $kind, $id, $alt, (string)$connection['username'] . ' (MCP: ' . $connection['name'] . ')', self::MAX_IMAGE_BYTES);
            if ($existing === null && $this->uploadsChanged !== null) {
                ($this->uploadsChanged)($bytes);
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
        $path = (string)$item['direct_url'];
        return ['existing' => $existing !== null, 'id' => (string)$item['id'], 'path' => $path, 'url' => ($this->absoluteUrl)($path), 'alt' => (string)($item['alt'] ?? $alt)];
    }

    // ---- helpers

    /** @param array<string, mixed> $args */
    private function onlyKnown(string $tool, array $args): void
    {
        $known = array_keys((array)(self::definitions()[$tool]['inputSchema']['properties'] ?? []));
        $unknown = array_diff(array_map('strval', array_keys($args)), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException($tool . ' does not take ' . implode(', ', $unknown) . '. It takes: ' . implode(', ', $known) . '.');
        }
    }

    private function type(mixed $value): string
    {
        $type = (string)$value;
        $types = array_values(array_diff($this->content->getTypes(), ['forms']));
        if (!in_array($type, $types, true)) {
            throw new \InvalidArgumentException('Unknown content type "' . $type . '". The site has: ' . implode(', ', $types) . '.');
        }
        return $type;
    }

    private function lang(mixed $value): string
    {
        $lang = (string)$value;
        $languages = array_map('strval', (array)($this->settings['languages']['available'] ?? [$this->defaultLang()]));
        if (!in_array($lang, $languages, true)) {
            throw new \InvalidArgumentException('Unknown language "' . $lang . '". The site has: ' . implode(', ', $languages) . '.');
        }
        return $lang;
    }

    /** @param string[] $choices */
    private function choice(string $name, mixed $value, array $choices): string
    {
        if (!in_array((string)$value, $choices, true)) {
            throw new \InvalidArgumentException($name . ' must be one of: ' . implode(', ', $choices) . '.');
        }
        return (string)$value;
    }

    /** @param array<string, mixed> $args */
    private function existing(array $args): ContentItem
    {
        $type = $this->type($args['type'] ?? null);
        $lang = isset($args['lang']) ? $this->lang($args['lang']) : $this->defaultLang();
        $slug = Slug::plain((string)($args['slug'] ?? ''));
        $item = $slug !== '' ? $this->content->find($type, $slug, $lang, true, false) : null;
        if ($item === null) {
            throw new \InvalidArgumentException('There is no ' . $type . ' "' . $slug . '" in ' . $lang . '. list_entries or search_entries finds it.');
        }
        return $item;
    }

    private function statusOf(?ContentItem $item): string
    {
        $status = strtolower((string)($item?->meta['status'] ?? 'draft'));
        return in_array($status, self::STATUSES, true) ? $status : 'draft';
    }

    /** @return array<string, mixed> */
    private function summary(ContentItem $item): array
    {
        $status = $this->statusOf($item);
        $date = $item->meta['date'] ?? '';
        return [
            'type' => $item->type,
            'slug' => $item->slug,
            'lang' => $item->lang,
            'title' => (string)($item->meta['title'] ?? $item->slug),
            'status' => $status,
            'date' => is_int($date) ? gmdate('Y-m-d', $date) : (string)$date,
            'publish_at' => (string)($item->meta['publish_at'] ?? ''),
            'excerpt' => (string)($item->meta['excerpt'] ?? ''),
            'updated_at' => self::updatedAt($item),
            'address' => $status === 'published' ? ($this->absoluteUrl)((new ContentPaths($this->settings))->publicPath($item->type, $item->slug, $item->lang)) : null,
            'generated_by' => (string)($item->meta['generated_by'] ?? ''),
        ];
    }

    private static function updatedAt(ContentItem $item): string
    {
        clearstatcache(true, $item->filePath);
        return gmdate('Y-m-d\TH:i:s\Z', (int)@filemtime($item->filePath) ?: $item->mtime);
    }

    private function bodyOf(string $path): string
    {
        [, $body] = FrontMatter::split((string)@file_get_contents($path));
        return trim(preg_replace('/\A\R/', '', $body) ?? $body);
    }

    /** @return array<string, mixed> */
    private function frontOf(string $path): array
    {
        [$front] = FrontMatter::split((string)@file_get_contents($path));
        try {
            $parsed = $front !== '' ? Yaml::parse($front) : [];
        } catch (\Throwable) {
            throw new \InvalidArgumentException('The front matter of this entry cannot be read; a person has to fix it in the admin first.');
        }
        return is_array($parsed) ? $parsed : [];
    }

    private function defaultLang(): string
    {
        return (string)($this->settings['languages']['default'] ?? 'en');
    }

    /**
     * The entry's own custom fields (not declared by its type), as the admin's form sends them back, so a save keeps them.
     *
     * @param array<string, mixed> $meta
     * @param string[] $declared
     * @return array{custom_keys: string[], custom_values: string[]}
     */
    private static function customRows(array $meta, array $declared, ContentEditor $editor): array
    {
        $rows = [];
        $stored = is_array($meta['custom_fields'] ?? null) ? $meta['custom_fields'] : [];
        foreach ($stored + $meta as $key => $value) {
            $key = (string)$key;
            if ($key === '' || $editor->isReservedKey($key) || in_array($key, $declared, true) || array_key_exists($key, $rows)) {
                continue;
            }
            $rows[$key] = match (true) {
                is_array($value) => (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => 'null',
                default => (string)$value,
            };
        }
        return ['custom_keys' => array_keys($rows), 'custom_values' => array_values($rows)];
    }

    /** Whether a cleaned field value is what was given (a number as text, surrounding spaces and a bool as text count as the same). */
    private static function same(mixed $clean, mixed $given): bool
    {
        if (is_bool($clean)) {
            return is_bool($given) ? $clean === $given : in_array(strtolower(trim((string)(is_scalar($given) ? $given : 'x'))), $clean ? ['true', '1'] : ['false', '0', ''], true);
        }
        if (is_scalar($clean) && is_scalar($given)) {
            return trim((string)$clean) === trim((string)$given);
        }
        return $clean === $given;
    }
}
