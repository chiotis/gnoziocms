<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * Ready-made sections and page layouts for the block editor.
 *
 * Presets are YAML files: themes/<theme>/presets/*.yaml ship with the theme; custom/presets/*.yaml
 * belong to the site (saved from the editor, kept across updates). A preset has
 *   kind: section | page
 *   label / description: text, or a map per language (el: …, en: …)
 *   template: optional page template a page layout suggests (e.g. landing)
 *   blocks: a list of blocks, or a map per language of lists
 * Blocks are checked with BlockRegistry::sanitizeForStorage() before they reach the editor.
 */
final class PresetLibrary
{
    /**
     * @param \Closure(string): string $slugify
     */
    public function __construct(
        private Theme $theme,
        private BlockRegistry $registry,
        private \Closure $slugify
    ) {
    }

    /**
     * Presets for an editor working in $lang, theme presets first.
     *
     * @return array<int, array{id: string, kind: string, label: string, description: string, origin: string, template: string, blocks: array<int, array<string, mixed>>}>
     */
    public function forEditor(string $lang, string $defaultLang): array
    {
        $presets = [];
        foreach (['theme' => $this->theme->path() . '/presets', 'custom' => $this->customDir()] as $origin => $dir) {
            $files = glob($dir . '/*.yaml') ?: [];
            sort($files);
            foreach ($files as $file) {
                $preset = $this->load($file, $origin, $lang, $defaultLang);
                if ($preset !== null) {
                    $presets[] = $preset;
                }
            }
        }
        return $presets;
    }

    /**
     * Saves blocks as a site section in custom/presets.
     *
     * @param array<int, mixed> $blocks
     * @return array{ok: bool, message?: string, id?: string}
     */
    public function saveCustom(string $name, string $description, string $lang, array $blocks): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') {
            return ['ok' => false, 'message' => 'Give the section a name.'];
        }
        $blocks = $this->registry->sanitizeForStorage($blocks);
        if ($blocks === []) {
            return ['ok' => false, 'message' => 'Select at least one block.'];
        }
        $dir = $this->customDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'message' => 'Could not create custom/presets. Check folder permissions.'];
        }
        $base = ($this->slugify)($name);
        if ($base === '') {
            $base = 'section';
        }
        $slug = $base;
        for ($i = 2; is_file($dir . '/' . $slug . '.yaml'); $i++) {
            $slug = $base . '-' . $i;
        }
        $payload = [
            'kind' => 'section',
            'label' => mb_substr($name, 0, 80),
            'description' => mb_substr(trim($description), 0, 200),
            'blocks' => [$lang !== '' ? $lang : 'default' => $blocks],
        ];
        $yaml = "# Saved from the block editor. Kept across updates.\n" . Yaml::dump($payload, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        if (@file_put_contents($dir . '/' . $slug . '.yaml', $yaml) === false) {
            return ['ok' => false, 'message' => 'Could not write the section file.'];
        }
        return ['ok' => true, 'id' => 'custom:' . $slug];
    }

    public function deleteCustom(string $id): bool
    {
        if (!preg_match('/^custom:([a-z0-9-]+)$/', $id, $m)) {
            return false;
        }
        $file = $this->customDir() . '/' . $m[1] . '.yaml';
        return is_file($file) && @unlink($file);
    }

    private function customDir(): string
    {
        return $this->theme->customPath() . '/presets';
    }

    /** @return array{id: string, kind: string, label: string, description: string, origin: string, template: string, blocks: array<int, array<string, mixed>>}|null */
    private function load(string $file, string $origin, string $lang, string $defaultLang): ?array
    {
        $name = basename($file, '.yaml');
        if (!preg_match('/^[a-z0-9-]+$/', $name)) {
            return null;
        }
        try {
            $raw = Yaml::parseFile($file);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($raw)) {
            return null;
        }
        $pick = static function (mixed $value) use ($lang, $defaultLang): mixed {
            if (!is_array($value) || array_is_list($value)) {
                return $value;
            }
            return $value[$lang] ?? $value[$defaultLang] ?? $value['en'] ?? $value['default'] ?? reset($value);
        };
        $blocks = $pick($raw['blocks'] ?? []);
        $blocks = is_array($blocks) ? $this->registry->sanitizeForStorage(array_values($blocks)) : [];
        if ($blocks === []) {
            return null;
        }
        $templates = $this->theme->pageTemplates();
        $template = (string)($raw['template'] ?? '');
        return [
            'id' => $origin . ':' . $name,
            'kind' => ($raw['kind'] ?? 'section') === 'page' ? 'page' : 'section',
            'label' => (string)($pick($raw['label'] ?? $name) ?: $name),
            'description' => (string)$pick($raw['description'] ?? ''),
            'origin' => $origin,
            'template' => isset($templates[$template]) ? $template : '',
            'blocks' => $blocks,
        ];
    }
}
