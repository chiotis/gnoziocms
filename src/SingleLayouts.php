<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * How the page of one entry looks, for each content type (Admin > Theme > Single Layouts): the page layout (a template
 * the theme offers, such as the one with a sidebar), the style of the title area, whether the header sits over it, and
 * which parts show. The theme declares the choices (`single_layouts` in its manifest); the values live in the theme
 * settings under `single_layouts.<type>`, so a content type the site adds later simply has the defaults until it is saved.
 *
 * A site that saved the older Hero Layouts, Transparent Header and Sidebar settings keeps them: they are the starting
 * point of every type that has no choice of its own yet.
 */
final class SingleLayouts
{
    /** What an entry's `template:` says when it asks for the plain layout although its content type has another. */
    public const PLAIN = 'standard';

    /** The page layout that has a sidebar: a type chooses it with its sidebar setting, not as a page layout of its own. */
    public const SIDEBAR = 'sidebar';

    public function __construct(private Theme $theme, private ?ContentTypes $types = null)
    {
    }

    /** The values of options in the order they were declared. @param array<string, array<string, mixed>> $declared @param array<string, mixed> $values @return array<string, mixed> */
    private static function inOrder(array $declared, array $values): array
    {
        return array_replace(array_fill_keys(array_keys($declared), null), array_intersect_key($values, $declared));
    }

    /**
     * What a content type says about its own page: whether its template draws a sidebar and a header that sits over it
     * (so the card offers them for a page layout that is not the standard one) and the options it declares.
     *
     * @return array{sidebar: bool, header: bool, options: array<string, array<string, mixed>>}
     */
    public function declaredBy(string $type): array
    {
        return ($this->types ??= new ContentTypes($this->theme))->definition($type)['single'];
    }

    /** Whether the theme has single layouts at all. */
    public function declared(): bool
    {
        return $this->fields() !== [];
    }

    /** @return array<string, array<string, mixed>> the choices the theme offers, normalized (without the page layout) */
    public function fields(): array
    {
        $raw = $this->theme->manifest()['single_layouts']['fields'] ?? [];
        return is_array($raw) ? FieldSchema::normalize($raw) : [];
    }

    /** @return array<string, string> the styles of title area the theme offers (the older Hero Layouts options when it has no single layouts) */
    public function titleChoices(): array
    {
        $options = $this->fields()['title']['options'] ?? $this->theme->settingOptions('hero_layouts', 'default');
        return is_array($options) ? $options : [];
    }

    /** @return array<string, string> what an entry can ask of the header: over its title or solid ("the site's choice" is not among them) */
    public function headerChoices(): array
    {
        $options = $this->fields()['header']['options'] ?? $this->theme->settingOptions('transparent_header', 'default');
        return array_diff_key(is_array($options) ? $options : [], ['site' => true]);
    }

    /** @return array<string, string> what an entry can ask of the picture of its title area: to move slower than the page, or to stay (empty when the theme has no such choice) */
    public function parallaxChoices(): array
    {
        return isset($this->fields()['parallax']) ? ['on' => 'On', 'off' => 'Off'] : [];
    }

    /**
     * The page layouts an entry of a type can choose. When the type has a layout of its own (say, the one with a sidebar)
     * "default" follows it, and the plain one is offered as "standard" for the entries that should not.
     *
     * @param array<string, mixed> $themeSettings
     * @return array<string, array{label: string, description: string}>
     */
    public function templatesFor(string $type, array $themeSettings): array
    {
        $templates = $this->templates();
        $chosen = $this->effectiveTemplate($type, $themeSettings);
        if ($chosen === 'default' || !isset($templates[$chosen])) {
            return $templates;
        }
        $out = ['default' => ['label' => 'Like the others (' . $templates[$chosen]['label'] . ')', 'description' => 'What the content type\'s layout is in Theme settings > Single Layouts. ' . $templates[$chosen]['description']]];
        $out[self::PLAIN] = ['label' => 'Standard', 'description' => $templates['default']['description'] ?: 'Title header, then the text and the blocks.'];
        return $out + array_diff_key($templates, ['default' => true]);
    }

    /** @return array<string, array{label: string, description: string}> the page layouts a type can have (templates the theme or the site offers) */
    public function templates(): array
    {
        return $this->theme->pageTemplates();
    }

    /**
     * What one content type's page does now.
     *
     * @param array<string, mixed> $themeSettings the resolved theme settings
     * @return array<string, mixed> `template` and one value for each declared field
     */
    public function forType(string $type, array $themeSettings): array
    {
        $fields = $this->fields();
        $values = FieldSchema::defaults($fields);
        foreach ($this->legacy($themeSettings, $type) as $key => $value) {
            if (isset($fields[$key])) {
                $values[$key] = $value;
            }
        }
        $stored = is_array($themeSettings['single_layouts'][$type] ?? null) ? $themeSettings['single_layouts'][$type] : [];
        foreach ($fields as $key => $field) {
            if (array_key_exists($key, $stored)) {
                $values[$key] = $stored[$key];
            }
            $values[$key] = FieldSchema::clean($field, $values[$key]);
        }
        // The options the content type declares for its page (a book: where the cover goes, what it shows).
        $declared = $this->declaredBy($type)['options'];
        $held = is_array($stored['options'] ?? null) ? $stored['options'] : [];
        if ($declared !== []) {
            $values['options'] = self::inOrder($declared, FieldSchema::resolve($declared, array_intersect_key($held, $declared)));
        }
        $template = (string)($stored['template'] ?? 'default');
        $values['template'] = isset($this->templates()[$template]) ? $template : 'default';
        // The sidebar layout is the sidebar choice now: a type that was given the template has its sidebar on the right.
        if ($values['template'] === self::SIDEBAR) {
            $values['template'] = 'default';
            if (isset($fields['sidebar']) && !array_key_exists('sidebar', $stored)) {
                $values['sidebar'] = 'right';
            }
        }
        return $values;
    }

    /**
     * The page layout a type's entries get when they choose none: its layout, or the sidebar one when the type has a sidebar
     * (a landing page has none), or "default" for the theme's normal hierarchy.
     *
     * @param array<string, mixed> $themeSettings
     */
    public function effectiveTemplate(string $type, array $themeSettings): string
    {
        $values = $this->forType($type, $themeSettings);
        // A type whose own template draws the sidebar keeps that template.
        if ($values['template'] === 'default' && ($values['sidebar'] ?? 'none') !== 'none' && isset($this->templates()[self::SIDEBAR]) && !$this->declaredBy($type)['sidebar']) {
            return self::SIDEBAR;
        }
        return (string)$values['template'];
    }

    /**
     * The values to store after the Single Layouts form was submitted: what every type does now, with the changes of the
     * types the form had a card for. A type that was not submitted is left as it is.
     *
     * @param array<string, mixed> $input the submitted `single_layouts[<type>][...]`
     * @param string[] $types the content types that exist
     * @param array<string, mixed> $themeSettings the resolved theme settings now
     * @return array<string, array<string, mixed>>
     */
    public function fromInput(array $input, array $types, array $themeSettings): array
    {
        $fields = $this->fields();
        $templates = $this->templates();
        $out = [];
        foreach ($types as $type) {
            $values = $this->forType($type, $themeSettings);
            $card = $input[$type] ?? null;
            if (is_array($card)) {
                foreach ($fields as $key => $field) {
                    if ($field['type'] === 'toggle') {
                        $values[$key] = FieldSchema::isTruthy($card[$key] ?? false);
                    } elseif (is_string($card[$key] ?? null) && isset($field['options'][$card[$key]])) {
                        $values[$key] = $card[$key];
                    }
                }
                $declared = $this->declaredBy($type)['options'];
                if ($declared !== []) {
                    $values['options'] = self::inOrder($declared, FieldSchema::fromInput($declared, is_array($card['options'] ?? null) ? $card['options'] : [], $values['options'] ?? []));
                }
                $template = $card['template'] ?? '';
                if (is_string($template) && isset($templates[$template]) && $template !== self::SIDEBAR) {
                    $values['template'] = $template;
                }
            }
            $out[$type] = $values;
        }
        return $out;
    }

    /**
     * What the Hero Layouts, Transparent Header and Sidebar settings said for a type, in the terms of single layouts.
     *
     * @param array<string, mixed> $themeSettings
     * @return array<string, mixed>
     */
    private function legacy(array $themeSettings, string $type): array
    {
        $values = [];
        $layouts = is_array($themeSettings['hero_layouts'] ?? null) ? $themeSettings['hero_layouts'] : [];
        $title = $layouts[$type] ?? $layouts['default'] ?? null;
        if (is_string($title)) {
            $values['title'] = $title;
        }
        $transparent = is_array($themeSettings['transparent_header'] ?? null) ? $themeSettings['transparent_header'] : [];
        $header = $transparent[$type] ?? $transparent['default'] ?? null;
        if (is_string($header)) {
            $values['header'] = $header;
        }
        $sidebar = is_array($themeSettings['sidebar'] ?? null) ? $themeSettings['sidebar'] : [];
        foreach (['toc', 'related'] as $key) {
            if (isset($sidebar[$key])) {
                $values[$key] = $sidebar[$key];
            }
        }
        return $values;
    }
}
