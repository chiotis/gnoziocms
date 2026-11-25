# Public search

`/search?q=…` (or `/en/search?q=…`) searches the current language. The header's existing form and search link use this page. It works with ordinary GET requests and without JavaScript. There are no suggestions while typing.

## Matching and ordering

- Case, Greek accents (including polytonic text), combining accents and final sigma do not change a match. Common Latin accented letters are normalized too, without requiring PHP intl.
- Each word must occur somewhere in an entry; words may occur in different fields and in any order. Punctuation separates words. Words of three or more characters also match as the start or the middle of a longer word; words of one or two characters («ο», «της», "a", "of") match only whole words and add only a point per field. Beside longer words they are optional and only raise an entry's position; a query made only of short words needs all of them. A whole word scores higher than a word start, and a word start higher than the middle of a word. There is no special query syntax.
- **Greek endings.** A Greek word of four or more letters also finds its other forms: «πόλη» finds «πόλεις», «πόλης» and «πόλεων», «βιβλίο» finds «βιβλία». A light stemmer takes one usual ending off the word (the list is `SearchText::ENDINGS`; the stem keeps at least three letters), and a text word matches when it is that stem alone or the stem with one usual ending. It is whole words, not word starts, so «πόλη» does not find «πόλεμος», «πολιτεία» or «πολύ». A match in another form ranks below the word as typed in the same field, and the whole word is highlighted. Latin words, words under four letters and words with no usual ending are matched as before.
- **Typos.** A word of five or more letters that is nowhere on the site, neither as typed (also inside a longer word) nor in another Greek form, also finds the site's words that are one letter away from it (two for a word of nine or more letters): a letter changed, added or taken out, or two letters side by side swapped. «ξενοδοχιο» finds «Ξενοδοχείο», «μουσίεο» finds «Μουσείο», "restaurnt" finds "restaurant". At most eight such words count for each misspelt word, closest first. They match only as whole words, rank below the word as typed and its other forms, and are highlighted whole; every other word of the query must still match. Above the results the page says which words it included («Μαζί με παρόμοιες λέξεις: Ξενοδοχείο»), as the text writes them. A word that is on the site is never corrected, so a search for "garden" does not also bring "warden". Words of under five letters, words with digits (codes, ISBNs, phone numbers) and short words are not corrected. The close words come from the search index (below): without it there is no correction. A word that only a draft or hidden entry has does not count as being on the site for a visitor, and the entries found through a close word are still only those the searcher may see.
- Exact titles rank first, followed by phrases in titles. Title matches carry more weight than taxonomy terms, custom fields, excerpts and body text. Repeating a word many times does not improve its score. Equal scores sort by normalized title, then type and slug for stable pagination.
- Empty and punctuation-only queries show no results. The query is limited to 200 characters and to its first 10 distinct words (the exact-title and phrase boosts use those words too); malformed array parameters are ignored.

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

### Related entries

An entry is also found by the **titles of the entries it points at**: a book by the name of its author, a route by the points on it, when those are `relation` fields (also inside a repeater's rows, and for any content type, including one that exists only as a YAML file). The titles are matched like a custom field's text, in their own weighted field (the same weight as custom fields, below taxonomy terms and above the excerpt), so a book whose title contains the author's name still ranks above one that only has the author. The snippet may show the related title, highlighted.

- The title is the target's title in the page's language, else the site's own language, exactly as the relation is shown on the page. A target that is a draft or hidden gives its title to someone signed in only; a visitor never finds an entry by a title they cannot see.
- The same rules as other fields apply to the relation field itself: `hidden: true` and `searchable: false` keep its targets out, a field with `show: false` needs `card: true` or `searchable: true`, and a conditional field counts only while its condition holds.
- A field that points at nothing, or at an entry that no longer exists, adds no words.

Visitors see published, visible entries of enabled content types in the requested language. A missing translation is not substituted with another language. As before, someone signed in can also find drafts/hidden entries; their responses are private and not cached by the public page cache. Form definitions are excluded for everyone. The admin search is a separate service and has not changed.

## Results page and custom themes

The bundled `templates/search.twig` keeps the title, subtitle, image and layout set in **Theme → Archive Layouts → Search**. Below them it shows the count, type filters, title and relevant excerpt for each match, and pagination. The excerpt comes from a matching field rather than always taking the beginning of the page. Highlighted words keep the author's spelling.

- `type=posts` selects one enabled content type. Counts beside type filters cover matches across all types; changing a filter starts at page 1. An unknown type falls back to all types.
- **Places.** When the results include places (entries of a type with a position: the types with a field of the kind `location`: points of interest, routes, businesses, and any type of the site's own that has one), the page offers two more filters above the results: **Area**, from the `area` field of the results (one entry for each area however it is written: «Ζάκρος» and « ζάκρος » are one), most results first, and **Category**, the categories of the results in the order of the category tree, where a parent category also counts and finds the entries filed under the categories below it. Each choice shows how many results it keeps. They are `area=Ζάκρος` and `category=beaches` in the address, combine with each other and with the type, and the counts of each filter (and of the types) follow the other filters. A filter is offered only when choosing something in it would leave out some of the results, or when something is chosen (so it can be cleared). A category filter keeps the entries of every type filed under the category, posts too. An unknown area or category gives no results and stays shown so it can be cleared; a malformed value is ignored. It is a form with two lists and a button, with no JavaScript.
- `page=2` selects a page of ten results. Out-of-range pages are clamped to the last page. Links preserve the query, the selected type, area and category, and the language. Whether search pages are `noindex` follows **SEO → Search appearance** (kept out of search engines by default).
- The backend emits `title_parts` and `snippet_parts` as lists of `{text, match}`. Templates escape every text part normally and wrap matching parts in `<mark>`; no trusted HTML is passed through search results.

Existing overrides in `custom/templates/search.twig` still receive `query` and the **complete ranked, filtered** `results` list of `ContentItem` objects. They do not silently lose results after ten matches. To adopt the new presentation, use:

| Variable | Contents |
| --- | --- |
| `search.hits` | Current page: `{item, type_label, title_parts, snippet_parts}` |
| `search.total`, `search.page`, `search.pages`, `search.per_page` | Selected type's count and pagination |
| `search.type` | Selected type, or empty for all |
| `search.types` | `{value, label, count, url, active}` filter links |
| `search.similar` | For each misspelt word typed, the close words the results were found by, as the text writes them (empty when none) |
| `search.area`, `search.category` | The chosen area (as given) and category id, or empty |
| `search.areas`, `search.categories` | The filters for places: `{value, label, count, active, url}` (a category also has `depth`, 0 at the top); `url` selects the option, or clears it when it is the active one. Empty when not offered |
| `search.clear_area`, `search.clear_category` | The address of the same search without that filter |
| `search.pagination` | `previous`, `next` URLs and `links: [{page, url, current, gap_before}]` (`gap_before` is true where pages are skipped before that link; render an ellipsis there) |

## Cache and extension points

`ContentRepository` remains the source of visibility, enabled types and parsed content. `SearchDocument` turns each entry into fields of searchable text; `ContentSearch` handles matching, relevance and pagination; `SearchText` handles normalization and display parts. The result template has no search logic. Repeaters (also a repeater inside a repeater's rows) are read by `SearchDocument`; relationships extend extraction the same way without replacing the matching or presentation code: `SearchDocument` records the ids a relation field holds, and `ContentSearch` turns them into titles with `Relations`. `SearchStore` is the index below; `SearchDocument` keeps its documents there and asks it which entries can match.

### The search index

Search keeps an index in one SQLite file, `storage/cache/search/index.sqlite`. It holds each entry's extracted document, the normalized text it is matched by, and the words of that text (for typos), for each content type and language. It is disposable: it may be deleted at any time (the next search builds it again), it is not part of backups, and it is never the source of what a visitor may see.

- **Which entries are read.** A search still asks `ContentRepository` for the entries the searcher may see (published, visible, enabled type, the page's language; drafts too for someone signed in), so visibility is decided exactly as before. It then asks SQLite which of them contain, for every word that must match, the word, its stem or one of its close words, and reads and scores only those. Entries with a relation field are always read, because the related titles they are found by are not in their own text. The results are the same as reading every entry; `tests/unit/search-typos.php` compares the two.
- **Freshness.** Each entry is kept with a fingerprint of its content (front matter and rendered body). When an entry changes, the next search sees a different fingerprint and extracts it again; nothing needs to tell the index. The definitions it was extracted with (the release, the default language, block and field definitions) are part of each type's schema: when they change, that type's documents are extracted again. Only a signed-in search, which sees every entry, forgets the entries that no longer exist (and rebuilds that type's words); public searches leave drafts kept by it alone. Visibility and result sets are never stored.
- **Failures.** Without SQLite, with a folder that cannot be written, or with a locked or corrupt file, search works as before by extracting every entry, only slower and without typo tolerance.
- **Size and speed.** Measured on generated sites of entries of about 600 words: 1,000 entries take about 0.15 s a search (before: 0.2 s), 3,000 entries 0.3 s (before: 0.8 s), 10,000 entries 0.7 s and 420 MB of memory (before: 3 s and 530 MB). The earlier cache, one file of up to 16 MB for each type and language, was full at about 1,000 such entries, after which every search extracted the rest again. The first search after a release extracts every entry once (about 3 s for 3,000 entries). The index takes about as much disk as the content's parsed cache (19 MB for 1,000 entries, 190 MB for 10,000). A misspelt word costs one more scan of the index (about 0.1 s for 3,000 entries). What remains is reading the entries from `ContentRepository` to decide visibility, which is most of the time and memory of a search on a very large site.

Related titles are not in the index. It holds, for each entry, only the *ids* its relation fields point at (they come from the entry's own data, so the fingerprint covers them). The titles are looked up for each search from the content index, one query for a whole content type, and only for the targets the searcher may see. A target that is renamed, published, unpublished or deleted therefore changes the results of the next search with no invalidation at all, and no title of a draft can be left behind in a file that a visitor's search reads. Without the content index, the lookup finds nothing and the search works as before.

This adds no external search service and no database migration: the index is a cache that builds itself.

## Checks

```sh
php tests/unit/search-document.php
php tests/unit/search.php
php tests/unit/search-typos.php
tests/run.sh search
```

These cover extraction (relation ids included), Greek normalization, ranking, excerpts/highlights, visibility and language boundaries, related titles (language, drafts, `searchable: false`, rows of a repeater, a renamed or unpublished target), current content after changes, custom-template compatibility, type filters, pagination, malformed query parameters and safe HTML output; `search-typos.php` covers typo tolerance, the index (the same results as a full read, edits, deletions, failures) and the filters for places.
