# Places and routes, second phase: plan

Source: `docs/ruralgreece-content-model.md` (the public model of ruralgreece.gr, 21 October 2026). Starting point: `docs/places-and-routes.md` and item 3 of the roadmap's *Site-specific follow-ups*.

## Principles

- **The CMS stays generic.** Rural Greece's own terms, fields and types live in the site (`custom/content-types/`, `content/taxonomies/`), and in an importer profile in `docs/wordpress-profiles/`. What goes into the repository is what any site of places needs: filters from taxonomies, a gallery, an importer that reads custom types, ACF fields and terms.
- **Nothing is required.** No field of the three types is `required`; an import of a half-filled entry is never refused.
- **Related places stay automatic.** The routes' businesses and points are the ones near the line (`geo_nearby`, radius and limits in Single Layouts), as the old site does by coordinates. No relationship has to be imported. Explicit relationships (a relation field from a route to its points) are not part of this phase: the coordinates say everything the old site's links say.
- **One entry, two languages.** The `_en` fields of the old site become the English translation of the same entry (`translation_id`), not second fields.

## Mapping

| Old site | Here |
|---|---|
| `route`, `business`, `pois` | `routes`, `businesses`, `points` |
| `title_en`, `υπότιτλος_*`, `εισαγωγικό_κείμενο*`, `κεντρικό_κείμενο*` | title, excerpt, body (and the subtitle as a field) of the Greek and English entries |
| `latitude`, `longitude` | `location` (`lat, lng`); a pair with only one of them is left empty |
| `map_kml` | `track` (fetched into the library as a route file; one that cannot be read is skipped and reported) |
| `banner_image`, `map_img_url*` | main image; `map_img_url*` are old map pictures and are not imported |
| `photo_gallery` | the entry's gallery (phase 3) |
| `youtube_video` | a Video block in the entry |
| `μήκος_μονοπατιού`, `διάρκεια_*`, `προτεινόμενη_περίοδος_*` | `distance_km`, `duration`, `season`. Empty in the samples, so the importer uses the route file's own length when the field is blank |
| `address`, `email`, `telephone`, `website_url`, `booking_url`, `open_on`, `ωράριο_λειτουργίας_*` | the fields of the same name (`opening_hours`, `phone`, `website`, `booking_url`) |
| `υπηρεσίες_επιχείρησης_*`, `τουριστικά_πακέτα_*` | two text fields of Businesses (`services`, `packages`) |
| `τύπος_επιχείρησης` | the `business-type` terms (already given by the taxonomy) |
| 16 taxonomies | taxonomies of the site, see below |
| `category`, `post_tag` | the CMS's own categories (a tree) and tags |

## Taxonomies

The old site's `activity`, `difficulty` and `price_range` are today fixed lists in the type definitions. For a site with these terms they become taxonomies of the site (**Admin > Taxonomies**), one definition shared by the types it is attached to: `location` (routes, businesses, points), `activity`, `difficulty`, `path_markers` (routes, points), `price_range`, `business-type` and `kitchen_type` (businesses), and the points' `poi-type`, `museum-type`, `museum-theme`, `monument-type`, `monument-status`, `period`, `protection-status`, `ecosystem-type`, `beach-type`.

- Only `business-type` and `category` have a tree; the rest are flat.
- The defaults of the three types keep their select lists, so a site that does not use taxonomies changes in nothing. A site that wants the taxonomy hides the select (`hidden: true`) and files its entries in the taxonomy; the field key never changes.
- **Merging the duplicates** (decided here, applied by the importer's term map and written to `redirects.txt` for the old addresses):

| Merge | Into |
|---|---|
| `activity`: `Bird watchin` (767), `Παρατήρηση πουλιών` (149), `Παρατήρηση Πτηνών` (310) | `Παρατήρηση Πτηνών` |
| `activity`: `Κολύμ?ηση` (795) | `Κολύμβηση` (409) |
| `activity`: `Ο?κοτουρισμός` (796) | `Οικοτουρισμός` (754) |
| `activity`: `Ποδήλατο Βουνού` (311), `Ορεινή ποδηλασία` (150) | `Ποδήλατο Βουνού` |
| `monument-type`: 216 and 314; `Άλλα` (222) and `Άλλο` (327); `Μινωική Βίλλα` (325) and `Μινωική βίλα` (208); `Αρχαία Πόλη/Δήμος` (211) and `Αρχαία Πόλη` (316) | the term with the use (count above 0) |
| `monument-status`, `period`: pairs where one has count 0 and the other is its spelling variant (`Ανοιχτό - Καλά διατηρημένο` and `Ανοιχτός-Καλά διατηρημένος`, `1η` and `Α Βυζαντινή Περίοδος`, and so on) | the term with the use |
| `museum-theme`: `Επιστήμες` (332), `Επιστημών` (239); `Ναυτικό` (232), `Ναυτιλία` (335) | `Επιστημών`, `Ναυτιλία` |
| `poi-type`: `Παραδοσιακοί οικισμοί` (777) | `Παραδοσιακός Οικισμός` (402) |
| `location`: `Π.Ε. Χανίων` and the like beside a bare name (`Αρκαδία` 793, `Π.Ε. Αρκαδίας` 758) | the term with the use; the rest stay as they are |
| `category`: root `Γεωδιαδρομές` (752) | the child under `Διαδρομές Κρήτης` (7) |

  Rules for the rest: a term with count 0 and no twin is not imported; two terms that differ only in case, accent or `ς`/`σ` are one; each merge is a line of the profile (`term_map`), so the result is the same on every run and can be corrected by hand. The importer prints every merge it makes.

## Phases

### 1. Importer, custom types and ACF (generic)
- Profile keys for a **REST custom type** (`route`, `business`, `pois`): the endpoint, the Gnozio type, the ACF key → field map (with the exact spelling, including `υπότιτλος_eν`), the taxonomy → taxonomy map, the media fields (`banner_image`, `photo_gallery`, `map_kml`), `term_map` and `skip_empty_terms`.
- Terms come over with their parents, descriptions and source IDs (`imported_from`), in both languages when the old site has them.
- `_el`/`_en` pairs and `title_en` produce the Greek and English entries linked by `translation_id`.
- A coordinate pair becomes `location`; a `map_kml` is fetched into the library and checked as a route file.
- A report at the end: what was merged, what was skipped (no coordinates, a route file that could not be read), what has no English text.
- `docs/wordpress-profiles/ruralgreece.yaml` is the first profile; the dry run shows everything before `--apply`.

### 2. Filters from taxonomies in the map's bar
- The bar over the map (and the Map block, and the Map of the list block) offers any taxonomy attached to the type, not only categories: activity, difficulty, price range, location, and each facet of the points. Each option shows how many entries it has in the current list (answered by the content index, which already keeps `content_terms`).
- Which filters are shown is chosen in the layout card (default: those with 2 to 40 terms); a tree of terms is offered as a group.
- Several chosen terms of one taxonomy mean "or", of different taxonomies "and". The page without JavaScript keeps its own form.

### 3. Gallery and strip
- An entry's **gallery** (a field of images, in the type's definition) and a strip of it under the title; the Gallery block can take it as a source. The importer fills it from `photo_gallery`.
- Single Layouts gets a card option for the strip (off, under the title, in the body).

### 4. The page of one
- A **height profile linked to the map**: moving over the profile marks the place on the map and the other way round.
- The fields found on the old site appear in the fact sheet and information card: subtitle, suggested season, services and packages for a business, opening hours for a point.
- Terms are shown as chips with links to their pages, grouped by taxonomy (for points: the type and its facet).
- The nearby lists get headings by type and, for points, by the type's group (nature, beaches, culture and history), taken from the taxonomy, so the old site's grouping comes out without a stored link.

### 5. Pages of terms
- Pages of the taxonomies (`/activity/hiking`, `/poi-type/ecosystem`) use the Map layout with the type chosen, a sub-terms chip where there is a tree, the term's description and its title area. Default archive settings for taxonomies added to a place type.
- The old addresses redirect (`redirects.txt`, then `--redirects=apply`), including those of merged terms.

### 6. Documentation and tests
- `docs/places-and-routes.md` (taxonomies for places, gallery, filters), `docs/wordpress-import.md` (REST custom types), `docs/wordpress-profiles/ruralgreece.yaml`, custom single templates, the CHANGELOG and the skill.
- Tests: profile parsing and the term map (merges stable between runs), the coordinate and bilingual mapping, the filter counts, the gallery field, the fallback when a type has no taxonomies.

## Order and size

1 → 2 → 3 → 4 → 5 → 6 (docs and tests written with each phase). Phases 1 and 2 carry the work; 3 and 4 are small and independent. Each phase is a merge of its own (a third-digit version bump each time, as always).

## Left to check on the real site

- The first dry run on a copy: the type of each ACF value is guessed from the samples (three entries per type), so look through the report for fields that are empty or odd.
- Whether the rest of the 268 terms hide more spelling variants than the ones listed; the profile's `term_map` takes more lines at any time.
- The English terms: the source gives only default names, so English names of terms are added by hand or by the `/en/` endpoint when the site has it.
