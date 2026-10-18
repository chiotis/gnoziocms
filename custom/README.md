# custom/ — site-specific customizations

Everything in this folder belongs to this site. GnozioCMS updates replace `src/`, `admin/`, `themes/`, `vendor/`, and `public/assets/`, but never touch `custom/`. Backups include it, and a restore can bring it back ("Site customizations").

Files here mirror the theme's layout (`themes/default/`). The same relative path in `custom/` wins:

```text
custom/
├── assets/
│   ├── css/custom.css      loaded automatically after the theme stylesheet
│   ├── js/custom.js        loaded automatically after the theme script
│   └── ...                 any other file, served at /_custom/<path>
├── lang/
│   └── <lang>.yaml         string overrides, written by Admin > Translations
├── blocks/<type>/          a new block (block.yaml, block.twig, block.css), or a block.css
│                           that adds to a theme block's styles
├── icons/<name>.svg        extra icons for blocks
├── content-types/<type>.yaml  fields and archive settings of a content type (Admin > Content types)
├── page-templates.yaml     the site's own page templates (each needs templates/<name>.twig)
├── presets/<name>.yaml     sections saved from the block editor (or written by hand)
├── templates/              page templates, e.g. templates/single-project.twig
├── components/             header, footer, card, ...
└── layouts/                base.twig
```

Prefer small, additive changes:

- Style changes go in `assets/css/custom.css`. They keep receiving theme fixes.
- To change part of a template, extend the theme's version and override one block:

  ```twig
  {% extends '@theme/templates/single-post.twig' %}
  {% block content %}
    {{ parent() }}
    <p>Extra line under every post.</p>
  {% endblock %}
  ```

- Copying a whole theme file here freezes it: later theme updates to that file will not reach this site.

See `docs/theming.md` for the full theme contract.
