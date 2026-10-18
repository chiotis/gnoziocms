# Public search

`/search?q=…` (or `/en/search?q=…`) searches the current language. The header's existing form and search link use this page. It works with ordinary GET requests and without JavaScript. There are no suggestions while typing.

## Matching and ordering

- Case, Greek accents (including polytonic text), combining accents and final sigma do not change a match. Common Latin accented letters are normalized too, without requiring PHP intl.
- Each word must occur somewhere in an entry; words may occur in different fields and in any order. Punctuation separates words. Words of three or more characters also match as the start or the middle of a longer word; words of one or two characters («ο», «της», "a", "of") match only whole words and add only a point per field. Beside longer words they are optional and only raise an entry's position; a query made only of short words needs all of them. A whole word scores higher than a word start, and a word start higher than the middle of a word. There is no stemming, typo correction or special query syntax.
- Exact titles rank first, followed by phrases in titles. Title matches carry more weight than taxonomy terms, custom fields, excerpts and body text. Repeating a word many times does not improve its score. Equal scores sort by normalized title, then type and slug for stable pagination.
- Empty and punctuation-only queries show no results. The query is limited to 200 characters; malformed array parameters are ignored.

## What is searched

`SearchDocument` extracts plain text from the title, excerpt, categories/tags, rendered Markdown body, visible block text and declared public custom fields. It understands repeaters and the blocks in a container's columns, respects hidden blocks, conditional fields and repeater limits, and uses select labels instead of stored option keys for content-type fields.

Only declared text, textarea and Markdown block fields participate. Design settings, URLs, images, icons, technical selects, unknown block types and unknown fields are excluded. Dynamic blocks do not run their providers during search: a page with a Latest content or Map block does not inherit all the text of the entries it lists. Those entries can appear as results of their own. Header/footer text is not repeated in every entry's search document.

Public custom text, textarea, Markdown, select, number, decimal and date fields participate. A field with `hidden: true` is always excluded. A field with `show: false` is excluded unless it is shown on cards (`card: true`) or explicitly marked `searchable: true` for a custom template. `searchable: false` excludes a field regardless of its display flags. These are schema options, not a new admin settings screen; `searchable: true` does not enable technical field types or override `hidden`.

```yaml
fields:
  internal_note:
    type: text
    show: false
    searchable: false
  credit:
    type: text
    show: false
    searchable: true # A public credit rendered by the site's custom template.
```

Visitors see published, visible entries of enabled content types in the requested language. A missing translation is not substituted with another language. As before, someone signed in can also find drafts/hidden entries; their responses are private and not cached by the public page cache. Form definitions are excluded for everyone. The admin search is a separate service and has not changed.

## Results page and custom themes

The bundled `templates/search.twig` keeps the title, subtitle, image and layout set in **Theme → Archive Layouts → Search**. Below them it shows the count, type filters, title and relevant excerpt for each match, and pagination. The excerpt comes from a matching field rather than always taking the beginning of the page. Highlighted words keep the author's spelling.

- `type=posts` selects one enabled content type. Counts beside type filters cover matches across all types; changing a filter starts at page 1. An unknown type falls back to all types.
- `page=2` selects a page of ten results. Out-of-range pages are clamped to the last page. Links preserve the query, selected type and language. Search pages remain `noindex`.
- The backend emits `title_parts` and `snippet_parts` as lists of `{text, match}`. Templates escape every text part normally and wrap matching parts in `<mark>`; no trusted HTML is passed through search results.

Existing overrides in `custom/templates/search.twig` still receive `query` and the **complete ranked, filtered** `results` list of `ContentItem` objects. They do not silently lose results after ten matches. To adopt the new presentation, use:

| Variable | Contents |
| --- | --- |
| `search.hits` | Current page: `{item, type_label, title_parts, snippet_parts}` |
| `search.total`, `search.page`, `search.pages`, `search.per_page` | Selected type's count and pagination |
| `search.type` | Selected type, or empty for all |
| `search.types` | `{value, label, count, url, active}` filter links |
| `search.pagination` | `previous`, `next` URLs and `links: [{page, url, current, gap_before}]` (`gap_before` is true where pages are skipped before that link; render an ellipsis there) |

## Cache and extension points

`ContentRepository` remains the source of visibility, enabled types and parsed content. `SearchDocument` turns each entry into fields of searchable text; `ContentSearch` handles matching, relevance and pagination; `SearchText` handles normalization and display parts. The result template has no search logic. Future repeaters or relationships can extend extraction without replacing the matching or presentation code.

The extracted-text cache under `storage/cache/search` avoids converting block Markdown on every search. It is disposable and may be deleted. Entry data, relevant field definitions, language and application version determine whether an extracted document can be reused. A missing, corrupt or unwritable cache falls back to extraction. Visibility and result sets are never stored in this cache.

This is still a scan over the site's entries, not a full-text database index. It adds no external search service or database migration. An index can be introduced later if measured site sizes justify it, while keeping the same result contract.

## Checks

```sh
php tests/unit/search-document.php
php tests/unit/search.php
tests/run.sh search
```

These cover extraction, Greek normalization, ranking, excerpts/highlights, visibility and language boundaries, current content after changes, custom-template compatibility, type filters, pagination, malformed query parameters and safe HTML output.
