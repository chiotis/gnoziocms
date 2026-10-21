# Rural Greece content types and taxonomies

This document records the public WordPress content model observed on [ruralgreece.gr](https://ruralgreece.gr/) on 21 October 2026. It is intended as a reference for configuring the Rural Greece content model in GnozioCMS.

The public WordPress REST API was queried on 21 October 2026 for three records each from `route`, `business`, and `pois`, three `page` records, and the one public `post` returned. The term endpoints were queried with `per_page=100` for the 16 custom taxonomies plus `category` and `post_tag`; each response contained fewer than 100 terms. The resulting sample records and term snapshot are included below.

The custom-type responses expose ACF field keys and current values, enough to inventory observed fields and JSON value shapes. They do not expose configured ACF field labels, field types, required flags, or conditional rules. The samples may not cover every optional field or content variant.

## Content types

The three domain-specific WordPress content types are `route`, `business`, and `pois`. Each is registered as a non-hierarchical type with an archive. Their public archive paths are `/route/`, `/business/`, and `/pois/` (with the language prefix where applicable).

| WordPress type | WordPress label | Content represented | Suggested Gnozio type |
|---|---|---|---|
| `route` | Διαδρομές | Routes and itineraries | Existing `routes` type |
| `business` | Επιχειρήσεις | Rural tourism businesses | Existing `businesses` type |
| `pois` | Σημεία Ενδιαφέροντος | Points of interest | Existing `points` type |
| `post` | Άρθρα | Standard WordPress articles | Gnozio posts |
| `page` | Σελίδες | Static pages, such as About pages | Gnozio pages |
| `attachment` | Πολυμέσα | Images and other media | Media library, not a content type |

Gnozio already documents generic **Points**, **Routes**, and **Businesses** in [Points of interest, routes and businesses](places-and-routes.md). The WordPress slugs differ from Gnozio's existing type slugs, but the concepts correspond. Reuse those Gnozio types where they fit instead of creating duplicate types.

WordPress also exposes navigation, block, template, font, and Elementor records through its type registry. Examples include `nav_menu_item`, `wp_block`, `wp_template`, `wp_template_part`, `wp_navigation`, `wp_global_styles`, `wp_font_family`, `wp_font_face`, `e-floating-buttons`, `elementor_library`, and `elementor_snippet`. These are CMS or plugin records, not Rural Greece editorial content, and should not be modelled as Gnozio content types.

## Taxonomies

The 16 custom taxonomies below are all registered as hierarchical. The standard WordPress `category` taxonomy is hierarchical; `post_tag` is flat.

| Taxonomy slug | WordPress label | Attached to | Hierarchical |
|---|---|---|---|
| `activity` | Δραστηριότητες | Route, POI | Yes |
| `difficulty` | Επίπεδα Δυσκολίας | Route, POI | Yes |
| `price_range` | Εύρη Τιμής | Business | Yes |
| `museum-theme` | Θέματα Μουσείου | POI | Yes |
| `monument-status` | Καταστάσεις Μνημείου | POI | Yes |
| `period` | Περίοδοι | POI | Yes |
| `location` | Περιοχές | Route, Business, POI | Yes |
| `protection-status` | Προστασίες | POI | Yes |
| `path_markers` | Σημάνσεις | Route, POI | Yes |
| `business-type` | Τύποι Επιχειρήσεων | Business | Yes |
| `kitchen_type` | Τύποι Κουζίνας | Business | Yes |
| `monument-type` | Τύποι Μνημείου | POI | Yes |
| `museum-type` | Τύποι Μουσείου | POI | Yes |
| `ecosystem-type` | Τύποι Οικοσυστήματος | POI | Yes |
| `beach-type` | Τύποι Παραλίας | POI | Yes |
| `poi-type` | Τύποι Σημείων | POI | Yes |
| `category` | Κατηγορίες | Post, Route | Yes |
| `post_tag` | Ετικέτες | Post, Route, Business, POI | No |

`nav_menu` and `wp_pattern_category` also appear in the taxonomy registry, attached to WordPress navigation and block records. They are CMS taxonomies and are not part of the Rural Greece content model.

## Mapping notes for Gnozio

- **Keep shared taxonomies shared.** `location` is attached to routes, businesses, and POIs. `activity`, `difficulty`, and `path_markers` are shared between routes and POIs. In Gnozio, define each once and attach it to the relevant content types.
- **Preserve the POI facets.** `poi-type` sits alongside the more specific `museum-type`, `monument-type`, `ecosystem-type`, and `beach-type` taxonomies, plus the POI-only `museum-theme`, `monument-status`, `period`, and `protection-status`. Keep these as separate facets until the current term lists are reviewed.
- **Use entry relationships for linked content.** Public route pages list businesses under “Products and Services” and POIs under groups such as “Natural Environment,” “Beaches,” and “Culture and History.” In Gnozio, represent these as relations to business and POI entries. The inspected REST records do not expose relation IDs or relation fields, so the importer still needs a separate way to resolve the source links; do not infer source relationships from shared location alone.
- **Support Greek and English.** Both language versions are public. Keep entry content and taxonomy terms translatable in Gnozio rather than treating each language as a separate content type.
- **Preserve the term trees and source IDs.** The term snapshot below includes the current IDs, names, slugs, parent nesting, and usage counts. `business-type` and `category` have parent-child terms in this snapshot; the other taxonomies are currently flat even where WordPress allows hierarchy.

## Sample records and observed fields

These are the first results returned by the public endpoints with `per_page=3`. The lists identify records and assigned terms without reproducing full descriptions or contact details.

### Routes

- [Τρίπολη – Χρυσοβίτσι – Λιμποβίσι – Πιάνα – Σιλίμνα](https://ruralgreece.gr/route/tripoli-chrysovitsi-libovisi-piana-silimna/) — WordPress ID `16257`, slug `tripoli-chrysovitsi-libovisi-piana-silimna`; `activity`: Ποδηλασία, Πολιτισμός, Φυσικό περιβάλλον; `location`: Αρκαδία.
- [Ποδηλατικός Γύρος Λίμνης Τάκα](https://ruralgreece.gr/route/podilatikos-gyros-limnis-taka/) — WordPress ID `16254`, slug `podilatikos-gyros-limnis-taka`; `activity`: Παρατήρηση Πτηνών, Ποδηλασία, Φυσικό περιβάλλον; `location`: Αρκαδία.
- [Σιλίμνα – Χρυσοβίτσι](https://ruralgreece.gr/route/silimna-chrysovitsi/) — WordPress ID `16251`, slug `silimna-chrysovitsi`; `activity`: Πεζοπορία, Πολιτισμός, Φυσικό περιβάλλον; `location`: Αρκαδία.

**ACF keys observed across these samples:**
- `title_en`, `υπότιτλος_el`, `υπότιτλος_eν`, `εισαγωγικό_κείμενο`, `εισαγωγικό_κείμενο_en`, `κεντρικό_κείμενο`
- `κεντρικό_κείμενο_en`, `latitude`, `longitude`, `map_kml`, `banner_image`, `map_img_url`
- `map_img_url_copy`, `photo_gallery`, `youtube_video`, `μήκος_μονοπατιού`, `προτεινόμενη_περίοδος_el`, `προτεινόμενη_περίοδος_en`
- `διάρκεια_el`, `διάρκεια_en`

### Businesses

- [Οικία Πετράκη 1940](https://ruralgreece.gr/business/oikia-petraki-1940/) — WordPress ID `16707`, slug `oikia-petraki-1940`; `business-type`: Διαμερίσματα, Διαμονή; `location`: Π.Ε. Χανίων.
- [Kapetánissa Concept Studios](https://ruralgreece.gr/business/kapetanissa-concept-studios/) — WordPress ID `16510`, slug `kapetanissa-concept-studios`; `business-type`: Διαμερίσματα, Διαμονή; `location`: Π.Ε. Ηρακλείου.
- [Alexander Mountain Resort](https://ruralgreece.gr/business/alexander-mountain-resort/) — WordPress ID `15317`, slug `alexander-mountain-resort`; `business-type`: Διαμονή, Θέρετρο; `location`: Π.Ε. Ρεθύμνου.

**ACF keys observed across these samples:**
- `title_en`, `υπότιτλος_el`, `υπότιτλος_eν`, `εισαγωγικό_κείμενο`, `εισαγωγικό_κείμενο_en`, `κεντρικό_κείμενο`
- `κεντρικό_κείμενο_en`, `latitude`, `longitude`, `map_kml`, `banner_image`, `map_img_url`
- `map_img_url_copy`, `photo_gallery`, `youtube_video`, `τύπος_επιχείρησης`, `open_on`, `address`
- `email`, `telephone`, `website_url`, `booking_url`, `υπηρεσίες_επιχείρησης_el`, `υπηρεσίες_επιχείρησης_en`
- `τουριστικά_πακέτα_el`, `τουριστικά_πακέτα_en`

### Points of interest

- [Χώρα Σαμοθράκης](https://ruralgreece.gr/pois/chora-samothrakis/) — WordPress ID `16509`, slug `chora-samothrakis`; `activity`: Περιήγηση, Πολιτισμός; `poi-type`: Παραδοσιακός Οικισμός.
- [Λαογραφικό Μουσείο Σαμοθράκης](https://ruralgreece.gr/pois/laografiko-mouseio-samothrakis/) — WordPress ID `16508`, slug `laografiko-mouseio-samothrakis`; `activity`: Πολιτισμός; `poi-type`: Μουσείο.
- [Αρχαιολογικό Μουσείο Σαμοθράκης](https://ruralgreece.gr/pois/archaiologiko-mouseio-samothrakis/) — WordPress ID `16507`, slug `archaiologiko-mouseio-samothrakis`; `activity`: Πολιτισμός; `poi-type`: Μουσείο.

**ACF keys observed across these samples:**
- `title_en`, `υπότιτλος_el`, `υπότιτλος_eν`, `εισαγωγικό_κείμενο`, `εισαγωγικό_κείμενο_en`, `κεντρικό_κείμενο`
- `κεντρικό_κείμενο_en`, `latitude`, `longitude`, `map_kml`, `banner_image`, `map_img_url`
- `map_img_url_copy`, `photo_gallery`, `youtube_video`, `address`, `email`, `telephone`
- `website_url`, `ωράριο_λειτουργίας_el`, `ωράριο_λειτουργίας_en`

### Standard posts

- [Κρήτη](https://ruralgreece.gr/crete/) — WordPress ID `7007`, slug `crete`; `category`: Προορισμοί; `post_tag`: Κρήτη.

**ACF keys observed across these samples:**
- `title_en`, `υπότιτλος_el`, `υπότιτλος_eν`, `εισαγωγικό_κείμενο`, `εισαγωγικό_κείμενο_en`, `κεντρικό_κείμενο`
- `κεντρικό_κείμενο_en`, `banner_image`, `map_img_url`, `map_img_url_copy`, `photo_gallery`, `youtube_video`

Only one public post was returned by the endpoint. It includes standard `content` and `excerpt` plus an ACF object, with `category` and `post_tag` assignments.

### Pages

- [Homepage 2026](https://ruralgreece.gr/homepage-2026/) — WordPress ID `9236`, slug `homepage-2026`; no taxonomy terms returned.
- [Quicklinks](https://ruralgreece.gr/quicklinks/) — WordPress ID `7334`, slug `quicklinks`; no taxonomy terms returned.
- [Προτάσεις](https://ruralgreece.gr/gia-ton-episkepti/) — WordPress ID `6842`, slug `gia-ton-episkepti`; no taxonomy terms returned.

**ACF keys observed:** None; the sample responses returned an empty `acf` array.

The three sample pages include standard `content` and `excerpt`, but their `acf` values are empty arrays.

### REST response observations

- For `route`, `business`, and `pois`, the sampled responses contain the rendered title, date, featured-media ID, ACF values, and taxonomy term-ID arrays. The sampled responses do not contain standard `content` or `excerpt` fields; the narrative is in ACF fields such as `κεντρικό_κείμενο` and `κεντρικό_κείμενο_en`.
- The standard `post` and `page` responses include `content` and `excerpt`. The one sampled post also exposes ACF fields; the sampled pages return `acf: []`.
- Top-level taxonomy properties contain arrays of WordPress term IDs. ACF `photo_gallery` values are arrays of media IDs. `banner_image` is an integer when populated in route and business samples, but an empty string in the sampled POIs.
- Sample `latitude` and `longitude` values are numeric strings. `map_kml` and map URL values are strings; ACF configuration types are not exposed by these responses.
- Main copy may be plain text or HTML. Some fields are present but blank in the samples; route length, suggested-period, and duration fields are blank in all three sampled routes.
- Some route facts, including distance and duration, appear in the narrative text while the corresponding structured ACF fields are blank in these samples. The importer should not assume those values can be read from the structured fields.
- The sampled businesses have populated contact fields; booking, opening-schedule, services, and tourist-package fields are blank in the three samples. The sampled POIs have contact and opening-hours keys, but those values are blank.
- The POI response also includes plugin SEO values under `meta` (SEOPress keys). These are technical metadata, not POI content fields.
- No explicit relationship field or linked-entry ID was returned for route-to-business or route-to-POI links. Public pages display related entries, but these REST samples do not show how WordPress stores or derives those links.

### Field inventory from sampled ACF values

The names below are exact ACF keys returned by REST. The English descriptions are explanatory labels, not WordPress field labels.

| Type | Observed fields and interpretation |
|---|---|
| All three custom types | `title_en` (English title); `υπότιτλος_el`, `υπότιτλος_eν` (subtitle); `εισαγωγικό_κείμενο`, `εισαγωγικό_κείμενο_en` (intro); `κεντρικό_κείμενο`, `κεντρικό_κείμενο_en` (main narrative); `latitude`, `longitude`; `map_kml`; `banner_image`; `map_img_url`, `map_img_url_copy`; `photo_gallery`; `youtube_video`. |
| One standard post also returned | `title_en`; `υπότιτλος_el`, `υπότιτλος_eν`; `εισαγωγικό_κείμενο`, `εισαγωγικό_κείμενο_en`; `κεντρικό_κείμενο`, `κεντρικό_κείμενο_en`; `banner_image`; `map_img_url`, `map_img_url_copy`; `photo_gallery`; `youtube_video`. The sampled post did not return `latitude`, `longitude`, or `map_kml`. |
| Route only in these samples | `μήκος_μονοπατιού`; `προτεινόμενη_περίοδος_el`, `προτεινόμενη_περίοδος_en`; `διάρκεια_el`, `διάρκεια_en`. These fields were blank in all three sampled routes. |
| Business fields | `τύπος_επιχείρησης` (array); `open_on`; `address`; `email`; `telephone`; `website_url`; `booking_url`; `υπηρεσίες_επιχείρησης_el`, `υπηρεσίες_επιχείρησης_en`; `τουριστικά_πακέτα_el`, `τουριστικά_πακέτα_en`. |
| POI fields | `ωράριο_λειτουργίας_el`, `ωράριο_λειτουργίας_en`; `address`, `email`, `telephone`, and `website_url` are shared with Business. |
| Page | No ACF keys in the three page samples. |

The sample values do not establish ACF configuration types or validation. Preserve the original key spelling, including `υπότιτλος_eν`, until a source field definition or export confirms whether it is intentional or a typo.

## Taxonomy term inventory snapshot

The public term endpoints returned 268 term records across 18 taxonomies. Each was requested with `per_page=100`; every response contained fewer than 100 records. Names and slugs below are reproduced as returned. Nested bullets show source `parent` relationships.

Each term records its source WordPress ID, slug, and current usage count. Non-empty descriptions are included with HTML stripped and whitespace normalized.

### `category` — Κατηγορίες (WordPress built-in) (26 terms)

- **Blogs** — slug `blogs`, WordPress ID `144`, count `0`.
- **Αναρριχητικές Διαδρομές** — slug `anarrichitikes-diadromes`, WordPress ID `655`, count `1`.
- **Γαστρονομικές Διαδρομές** — slug `gastronomikes`, WordPress ID `404`, count `74`.
  - **Γαστρονομικές διαδρομές Κρήτης** — slug `crete-gastronomic-routes`, WordPress ID `651`, count `20`. Description: Οι γαστρονομικές διαδρομές εκτείνονται σε ολόκληρο το νησί και περιλαμβάνουν πλήθος εμπειριών που συνδέονται με τον πλούσιο κρητικό διατροφικό πολιτισμό. Κάποιες περνούν μέσα από αγροτικές και καλλιεργήσιμες εκτάσεις, άλλες διασχίζουν παραδοσιακούς οικισμούς και άλλες κινούνται στην ενδοχώρα ή στα παράλια. Όλες τους όμως αναδεικνύουν ιδιαίτερα τοπικά προϊόντα και πιάτα, χώρους παραγωγής και εστίασης, παραδοσιακές τεχνικές μαγειρικής, προσφέροντας τη δυνατότητα γνωριμίας με την κρητική γαστρονομία και τη σύνδεσή της με τον πολιτισμό, το φυσικό περιβάλλον και τους ανθρώπους της υπαίθρου.
  - **Γαστρονομικές διαδρομές Μακεδονίας** — slug `gastronomy-routes-macedonia`, WordPress ID `804`, count `2`.
- **Γεωδιαδρομές** — slug `geodiadromes`, WordPress ID `752`, count `0`.
- **Διαδρομές Κρήτης** — slug `crete-routes`, WordPress ID `4`, count `138`.
  - **Γεωδιαδρομές** — slug `georoutes`, WordPress ID `7`, count `27`. Description: Ακολουθώντας τις γεωδιαδρομές των δύο Γεωπάρκων της Κρήτης μπορεί κανείς να απολαύσει τοπία μοναδικού φυσικού κάλους να περιπλανηθεί σε οικισμούς που διατηρούν την αυθεντικότητά τους και να γνωρίσει μικρές επιχειρήσεις της υπαίθρου
    - **Γεωδιαδρομές Σητείας** — slug `sitia-georoutes`, WordPress ID `11`, count `14`. Description: Γνώρισε την ομορφιά του Παγκόσμιου Γεωπάρκου UNESCO της Σητείας μέσα από διαδρομές, που διαπερνούν εντυπωσιακές φυσικές μορφολογίες.
    - **Γεωδιαδρομές Ψηλορείτη** — slug `psiloritis-georoutes`, WordPress ID `10`, count `13`. Description: Οι γεωδιαδρομές στην πλούσια φύση του Παγκόσμιου Γεωπάρκου UNESCO του Ψηλορείτη, οδηγούν τον επισκέπτη στο άγριο τοπίο των κρητικών ορέων. Οι διαδρομές είναι αποτέλεσμα του ερευνητικού προγράμματος JoinOut.
- **Εκδηλώσεις** — slug `events`, WordPress ID `370`, count `0`.
- **Θαλάσσιες Διαδρομές** — slug `thalassies-diadromes`, WordPress ID `654`, count `3`.
- **Μονοπάτι E4** — slug `path-e4`, WordPress ID `9`, count `84`. Description: Το Ε4 στην Κρήτη περιλαμβάνει 91 διαφορετικές πεζοπορικές διαδρομές διαφόρων τύπων κι επιπέδων δυσκολίας. Κάποια τμήματά του είναι αλπικά σε χαρακτήρα, ενώ άλλα ακολουθούν την ακτογραμμή ή διατρέχουν την ενδοχώρα της Κρήτης. Το τμήμα του Ε4 στην Κρήτη ξεκινάει από το Καστέλι Κισσάμου κατεβαίνει προς το Ελαφονήσι, διασχίζει όλη τη Κρήτη (σε δυο τμήματα που ξαναενώνονται) φτάνει ως την Κάτω Ζάκρο, ανεβαίνει προς τον Βορρά καταλήγοντας στην περιοχή της Σητείας.
- **Νέα** — slug `news`, WordPress ID `1`, count `0`.
- **Οδικές Διαδρομές** — slug `routes-by-car`, WordPress ID `441`, count `50`.
  - **Οδικές Διαδρομές Μακεδονίας** — slug `routes-by-car-macedonia`, WordPress ID `802`, count `14`.
- **Οικοτουριστικές Διαδρομές** — slug `ecotourism-routes`, WordPress ID `443`, count `113`.
  - **Οικοτουριστικές Διαδρομές Κρήτης** — slug `ecotourism-routes-crete`, WordPress ID `652`, count `58`. Description: Οι οικοτουριστικές διαδρομές εκτείνονται σε ολόκληρο το νησί και περιλαμβάνουν πλήθος διαδρομών και εμπειριών στη φύση, διαφορετικών τύπων και επιπέδων δυσκολίας. Κάποιες διατρέχουν ορεινά και ημιορεινά τοπία. Άλλες ακολουθούν την ακτογραμμή ή κινούνται στην ενδοχώρα, αναδεικνύοντας οικοσυστήματα ιδιαίτερης περιβαλλοντικής αξίας και προσφέροντας τη δυνατότητα γνωριμίας με την κρητική φύση, με σεβασμό στο περιβάλλον και στον τοπικό τρόπο ζωής.
  - **Οικοτουριστικές Διαδρομές Μακεδονίας** — slug `ecotourism-routes-macedonia`, WordPress ID `800`, count `2`.
- **Οινικές Διαδρομές** — slug `oinikes-diadromes`, WordPress ID `452`, count `83`.
  - **Οινικές Διαδρομές Μακεδονίας** — slug `oinikes-diadromes-macedonia`, WordPress ID `803`, count `1`.
- **Πεζοπορικές Διαδρομές** — slug `pezoporikes-diadromes`, WordPress ID `653`, count `6`.
- **Ποδηλατικές Διαδρομές** — slug `podilatikes-diadromes`, WordPress ID `789`, count `4`.
- **Πολιτιστικές Διαδρομές** — slug `cultural-routes`, WordPress ID `794`, count `26`.
  - **Πολιτιστικές Διαδρομές Μακεδονίας** — slug `cultural-routes-macedonia`, WordPress ID `801`, count `10`.
- **Προορισμοί** — slug `proorismoi`, WordPress ID `779`, count `1`.

### `post_tag` — Ετικέτες (WordPress built-in) (14 terms)

- **Έβρος** — slug `evros`, WordPress ID `363`, count `0`.
- **Ήπειρος** — slug `ipeiros`, WordPress ID `453`, count `1`.
- **Αιγαίο** — slug `aigaio`, WordPress ID `422`, count `69`.
- **Ζαγόρι** — slug `zagori`, WordPress ID `361`, count `0`.
- **Θεσσαλία** — slug `thessalia`, WordPress ID `454`, count `5`.
- **Θεσσαλονίκη** — slug `thessaloniki`, WordPress ID `365`, count `0`.
- **Θράκη** — slug `thraki`, WordPress ID `420`, count `305`.
- **Ιόνιο** — slug `ionio`, WordPress ID `421`, count `13`.
- **Κεντρική Ελλάδα** — slug `kentriki-ellada`, WordPress ID `755`, count `0`.
- **Κεντρική Μακεδονία** — slug `kentriki-makedonia`, WordPress ID `765`, count `0`.
- **Κρήτη** — slug `crete`, WordPress ID `12`, count `946`.
- **Μακεδονία** — slug `macedonia`, WordPress ID `418`, count `170`.
- **Νότιο Αιγαίο** — slug `notio-aigaio`, WordPress ID `582`, count `14`.
- **Πελοπόννησος** — slug `peloponnisos`, WordPress ID `455`, count `193`.

### `activity` — Δραστηριότητες (36 terms)

- **Bird watchin** — slug `bird-watchin`, WordPress ID `767`, count `27`.
- **Canyoning** — slug `canyoning`, WordPress ID `148`, count `36`.
- **Extreme sports** — slug `extreme-sports`, WordPress ID `412`, count `11`.
- **Αθλήματα βουνού** — slug `athlimata-vounou`, WordPress ID `414`, count `76`.
- **Αθλήματα νερού** — slug `athlimata-nerou`, WordPress ID `406`, count `97`.
- **Αθλήματα χιονιού** — slug `athlimata-chioniou`, WordPress ID `419`, count `5`.
- **Αθλητισμός** — slug `athlitismos`, WordPress ID `407`, count `36`.
- **Αναρρίχηση** — slug `climbing`, WordPress ID `147`, count `23`.
- **Αναψυχή** — slug `anapsychi`, WordPress ID `781`, count `7`.
- **Αρχαιολογία** — slug `archaiologia`, WordPress ID `775`, count `11`.
- **Αρχαιολογικοί χώροι** — slug `archaiologikoi-choroi`, WordPress ID `784`, count `10`.
- **Γαστρονομία** — slug `gastronomia`, WordPress ID `405`, count `176`.
- **Γεωλογία** — slug `geologia`, WordPress ID `753`, count `16`.
- **Γεωτουρισμός** — slug `geotourismos`, WordPress ID `413`, count `35`.
- **Θρησκευτικός τουρισμός** — slug `thriskeftikos-tourismos`, WordPress ID `417`, count `55`.
- **Ιαματικός τουρισμός** — slug `iamatikos-tourismos`, WordPress ID `415`, count `10`.
- **Ιππασία** — slug `ippasia`, WordPress ID `408`, count `14`.
- **Ιστορικά μνημεία** — slug `istorika-mnimeia`, WordPress ID `785`, count `10`.
- **Κολύμβηση** — slug `kolymvisi`, WordPress ID `409`, count `129`.
- **Κολύμ��ηση** — slug `kolym%ef%bf%bd%ef%bf%bdisi`, WordPress ID `795`, count `1`.
- **Μουσεία** — slug `mouseia`, WordPress ID `773`, count `9`.
- **Οικοτουρισμός** — slug `oikotourismos`, WordPress ID `754`, count `106`.
- **Ορειβασία** — slug `mountaineering`, WordPress ID `146`, count `138`.
- **Ορεινή ποδηλασία** — slug `mountain-biking`, WordPress ID `150`, count `0`.
- **Ο��κοτουρισμός** — slug `o%ef%bf%bd%ef%bf%bdkotourismos`, WordPress ID `796`, count `1`.
- **Παρατήρηση πουλιών** — slug `bird-watching`, WordPress ID `149`, count `23`.
- **Παρατήρηση Πτηνών** — slug `paratirisi-ptinon`, WordPress ID `310`, count `90`.
- **Πεζοπορία** — slug `hiking`, WordPress ID `145`, count `483`.
- **Περιήγηση** — slug `periigisi`, WordPress ID `776`, count `113`.
- **Ποδήλατο Βουνού** — slug `podilato-vounou`, WordPress ID `311`, count `26`.
- **Ποδηλασία** — slug `podilasia`, WordPress ID `416`, count `71`.
- **Πολιτισμός** — slug `politismos`, WordPress ID `410`, count `775`.
- **Φυσική ιστορία** — slug `fysiki-istoria`, WordPress ID `766`, count `7`.
- **Φυσικό περιβάλλον** — slug `fysiko-perivallon`, WordPress ID `411`, count `430`.
- **Φυσιολατρεία** — slug `fysiolatreia`, WordPress ID `768`, count `90`.
- **Χειμερινά αθλήματα** — slug `cheimerina-athlimata`, WordPress ID `774`, count `1`.

### `difficulty` — Επίπεδα Δυσκολίας (5 terms)

- **Δύσκολο** — slug `difficult`, WordPress ID `247`, count `7`.
- **Εύκολο** — slug `easy`, WordPress ID `244`, count `56`.
- **Μέτριας Δυσκολίας** — slug `medium-difficulty`, WordPress ID `246`, count `7`.
- **Πολύ Δύσκολο** — slug `very-difficult`, WordPress ID `248`, count `9`.
- **Σχετικά Εύκολο** — slug `relatively-easy`, WordPress ID `245`, count `52`.

### `price_range` — Εύρη Τιμής (3 terms)

- **€** — slug `price-range-1`, WordPress ID `372`, count `0`.
- **€€** — slug `price-range-2`, WordPress ID `373`, count `4`.
- **€€€** — slug `price-range-3`, WordPress ID `374`, count `2`.

### `museum-theme` — Θέματα Μουσείου (16 terms)

- **Αρχαιολογικό** — slug `archaeological`, WordPress ID `229`, count `19`.
- **Βιογραφικό** — slug `biography`, WordPress ID `236`, count `10`.
- **Εικαστικό** — slug `eikastiko`, WordPress ID `333`, count `8`.
- **Εικονογραφικό** — slug `pictorial`, WordPress ID `237`, count `3`.
- **Εκκλησιαστικό** — slug `ecclesiastical`, WordPress ID `231`, count `9`.
- **Επιστήμες** — slug `epistimes`, WordPress ID `332`, count `1`.
- **Επιστημών** — slug `sciences`, WordPress ID `239`, count `12`.
- **Ιστορικό** — slug `historical`, WordPress ID `234`, count `28`.
- **Λαϊκής Τέχνης** — slug `folk`, WordPress ID `230`, count `12`.
- **Λαϊκός Πολιτισμός** — slug `laikos-politismos`, WordPress ID `331`, count `35`.
- **Μουσικό** — slug `music`, WordPress ID `238`, count `2`.
- **Ναυτικό** — slug `maritime`, WordPress ID `232`, count `0`.
- **Ναυτιλία** — slug `naftilia`, WordPress ID `335`, count `1`.
- **Πολεμικό** — slug `war`, WordPress ID `235`, count `2`.
- **Φυσικό Περιβάλλον** — slug `fysiko-perivallon`, WordPress ID `334`, count `9`.
- **Φύσης** — slug `nature`, WordPress ID `233`, count `3`.

### `monument-status` — Καταστάσεις Μνημείου (8 terms)

- **Ανοιχτά - Διάσπαρτα Μνημεία** — slug `open-scattered-monuments`, WordPress ID `242`, count `0`.
- **Ανοιχτό - Καλά διατηρημένο** — slug `open-well-preserved`, WordPress ID `241`, count `0`.
- **Ανοιχτός-Διάσπαρτα Μνημεία** — slug `anoichtos-diasparta-mnimeia`, WordPress ID `321`, count `14`.
- **Ανοιχτός-Καλά διατηρημένος** — slug `anoichtos-kala-diatirimenos`, WordPress ID `312`, count `63`.
- **Καλά οργανωμένο / Υπηρεσίες** — slug `well-organized-services`, WordPress ID `240`, count `0`.
- **Κλειστό** — slug `closed`, WordPress ID `243`, count `0`.
- **Κλειστός** — slug `kleistos`, WordPress ID `324`, count `4`.
- **Οργανωμένος Χώρος** — slug `organomenos-choros`, WordPress ID `323`, count `7`.

### `period` — Περίοδοι (21 terms)

- **1η Βυζαντινή περίοδος** — slug `1st-byzantine-period`, WordPress ID `200`, count `0`.
- **2η Βυζαντινή περίοδος** — slug `2nd-byzantine-period`, WordPress ID `202`, count `0`.
- **Α Βυζαντινή Περίοδος** — slug `a-vyzantini-periodos`, WordPress ID `320`, count `13`.
- **Αραβική περίοδος** — slug `arabic-period`, WordPress ID `201`, count `0`.
- **ΑρχαΪκή - Ελληνική περίοδος** — slug `archaic-greek-period`, WordPress ID `196`, count `0`.
- **Αρχαϊκή Περίοδος** — slug `archaiki-periodos`, WordPress ID `318`, count `20`.
- **Β Βυζαντινή Περίοδος** — slug `v-vyzantini-periodos`, WordPress ID `326`, count `17`.
- **Βενετική περίοδος** — slug `venetian-period`, WordPress ID `203`, count `0`.
- **Γεωμετρική - Ελληνική περίοδος** — slug `geometric-greek-period`, WordPress ID `195`, count `0`.
- **Γεωμετρική Περίοδος** — slug `geometriki-periodos`, WordPress ID `317`, count `18`.
- **Ελληνιστική περίοδος** — slug `hellenistic-period`, WordPress ID `198`, count `23`.
- **Ενετική Περίοδος** — slug `enetiki-periodos`, WordPress ID `313`, count `55`.
- **Κλασική - Ελληνική Περίοδος** — slug `classical-greek-period`, WordPress ID `197`, count `0`.
- **Κλασσική Περίοδος** — slug `klassiki-periodos`, WordPress ID `319`, count `21`.
- **Κρητική Πολιτεία** — slug `cretan-state`, WordPress ID `205`, count `6`.
- **Μινωική εποχή** — slug `minoan-era`, WordPress ID `194`, count `33`.
- **Νεολιθική εποχή** — slug `neolithic-era`, WordPress ID `193`, count `3`.
- **Νεότερη Εποχή** — slug `neoteri-epochi`, WordPress ID `315`, count `13`.
- **Οθωμανική περίοδος** — slug `ottoman-period`, WordPress ID `204`, count `29`.
- **Ρωμαϊκή περίοδος** — slug `roman-period`, WordPress ID `199`, count `15`.
- **Σύγχρονη περίοδος** — slug `modern-period`, WordPress ID `206`, count `0`.

### `location` — Περιοχές (20 terms)

- **Έβρος** — slug `evros`, WordPress ID `790`, count `24`.
- **Αρκαδία** — slug `arkadia`, WordPress ID `793`, count `33`.
- **Κιλκίς** — slug `kilkis`, WordPress ID `782`, count `22`.
- **Ξάνθη** — slug `xanthi`, WordPress ID `788`, count `17`.
- **Π.Ε. Έβρου** — slug `p-e-evrou`, WordPress ID `763`, count `0`.
- **Π.Ε. Ανατολικής Αττικής** — slug `p-e-anatolikis-attikis`, WordPress ID `761`, count `0`.
- **Π.Ε. Αρκαδίας** — slug `p-e-arkadias`, WordPress ID `758`, count `0`.
- **Π.Ε. Ηρακλείου** — slug `irakleiou`, WordPress ID `746`, count `60`.
- **Π.Ε. Θεσσαλονίκης** — slug `p-e-thessalonikis`, WordPress ID `764`, count `0`.
- **Π.Ε. Λακωνίας** — slug `p-e-lakonias`, WordPress ID `757`, count `0`.
- **Π.Ε. Λασιθίου** — slug `lasithiou`, WordPress ID `748`, count `60`.
- **Π.Ε. Μαγνησίας** — slug `p-e-magnisias`, WordPress ID `760`, count `0`.
- **Π.Ε. Ξάνθης** — slug `p-e-xanthis`, WordPress ID `759`, count `0`.
- **Π.Ε. Πειραιώς** — slug `p-e-peiraios`, WordPress ID `762`, count `0`.
- **Π.Ε. Ρεθύμνου** — slug `rethymnou`, WordPress ID `747`, count `60`.
- **Π.Ε. Χανίων** — slug `chanion`, WordPress ID `745`, count `54`.
- **Ροδόπη** — slug `rodopi`, WordPress ID `786`, count `3`.
- **Σαμοθράκη** — slug `samothraki`, WordPress ID `792`, count `5`.
- **Σητεία** — slug `siteia`, WordPress ID `750`, count `6`.
- **Ψηλορείτης** — slug `psiloreitis`, WordPress ID `749`, count `13`.

### `protection-status` — Προστασίες (2 terms)

- **Natura 2000** — slug `natura-2000`, WordPress ID `263`, count `0`.
- **Εθνικό Πάρκο** — slug `national-park`, WordPress ID `264`, count `0`.

### `path_markers` — Σημάνσεις (3 terms)

- **E4 (Κίτρινο/Μαύρο)** — slug `e4-kitrino-mavro`, WordPress ID `328`, count `77`.
- **Καμία** — slug `kamia`, WordPress ID `329`, count `21`.
- **Κόκκινο** — slug `kokkino`, WordPress ID `330`, count `3`.

### `business-type` — Τύποι Επιχειρήσεων (48 terms)

- **Γαστρονομία** — slug `gastronomy`, WordPress ID `170`, count `7`. Description: Η κρητική κουζίνα και διατροφή αποτελεί αναπόσπαστο κομμάτι του πολιτιστικού και κοινωνικού ιστού του νησιού. Βασίζεται στον αγροδιατροφικό πλούτο της Κρήτης. Χρησιμοποιεί τα καλύτερα τοπικά υλικά και παραδοσιακές τεχνικές μαγειρέματος. Μην αμελήσετε να γευτείτε τα πιάτα της παραδοσιακής κρητικής γαστρονομίας. Θα διαπιστώσετε πως η κρητική διατροφή είναι ένα πολύτιμο παράδειγμα υγιεινής ζωής και προσφέρει μια ισορροπημένη στάση απέναντι στην τροφή.
  - **Γκουρμέ** — slug `gourmet`, WordPress ID `176`, count `0`.
  - **Εστιατόριο** — slug `restaurant`, WordPress ID `171`, count `4`.
  - **Εστιατόριο - Ξενώνας** — slug `restaurant-guesthouse`, WordPress ID `175`, count `0`.
  - **Μπαρ Εστιατόριο** — slug `bar-restaurant`, WordPress ID `178`, count `0`.
  - **Μπριζολάδικο** — slug `steakhouse`, WordPress ID `177`, count `0`.
  - **Οικογενειακή ταβέρνα** — slug `family-tavern`, WordPress ID `173`, count `0`.
  - **Ταβέρνα** — slug `tavern`, WordPress ID `174`, count `2`.
  - **Ψαροταβέρνα** — slug `fish-tavern`, WordPress ID `172`, count `0`.
- **Διαμονή** — slug `accommodation`, WordPress ID `151`, count `22`. Description: Η φιλοξενία είναι τρόπος ζωής για τους κατοίκους της Κρήτης και αναπόσπαστο κομμάτι της κουλτούρας τους. Εδώ θα βρεις πληθώρα επιλογών για κάθε γούστο, από παραδοσιακούς ξενώνες και αγροτουριστικά καταλύματα μέχρι πολυτελείς επιλογές διαμονής. Όποια κι αν είναι η επιλογή σου, η άνεση και η μοναδική κρητική φιλοξενία θα σε κάνουν να νιώσεις σαν στο σπίτι σου!
  - **Hostel** — slug `hostel`, WordPress ID `157`, count `0`.
  - **Βίλες** — slug `villas`, WordPress ID `155`, count `1`.
  - **Διαμερίσματα** — slug `apartments`, WordPress ID `154`, count `2`.
  - **Ενοικίαση δωματίων** — slug `rooms-rental`, WordPress ID `153`, count `1`.
  - **Θέρετρο** — slug `resort`, WordPress ID `159`, count `3`.
  - **Ξενοδοχείο** — slug `hotel`, WordPress ID `152`, count `4`.
  - **Ξενώνες** — slug `guesthouses`, WordPress ID `156`, count `7`.
  - **Σουίτες** — slug `suites`, WordPress ID `158`, count `4`.
- **Εμπειρία** — slug `experience`, WordPress ID `160`, count `29`. Description: Η Κρήτη είναι ένας τόπος που προσφέρει μοναδικές δραστηριότητες και εμπειρίες στην ύπαιθρο. Πεζοπορίες στα επιβλητικά φαράγγια, κολύμπι στις αμέτρητες παραλίες της, εξερεύνηση αρχαιολογικών χώρων, μνημείων και παραδοσιακών οικισμών και πολλές ακόμη επιλογές περιπέτειας και αναψυχής είναι εδώ για να τις ανακαλύψεις!
  - **Jeep - 4X4 - Μοτοσικλέτα** — slug `jeep-4x4-motorbike`, WordPress ID `166`, count `3`.
  - **Αναψυχή - Αθλητισμός** — slug `recreation-sports`, WordPress ID `165`, count `1`.
  - **Ιστιοπλοΐα** — slug `sailing`, WordPress ID `169`, count `3`.
  - **Οργανωμένες εκδρομές - περιηγήσεις** — slug `guided-trips-tours`, WordPress ID `167`, count `11`.
  - **Πεζοπορία** — slug `hiking`, WordPress ID `161`, count `7`.
  - **Περιπέτεια** — slug `adventure`, WordPress ID `164`, count `5`.
  - **Ποδηλασία** — slug `cycling`, WordPress ID `162`, count `4`.
  - **Σεμινάρια - Μαθήματα** — slug `seminars-courses`, WordPress ID `168`, count `13`.
  - **Τρέξιμο** — slug `treximo`, WordPress ID `393`, count `1`.
  - **Υγρό Στοιχείο** — slug `water-element`, WordPress ID `163`, count `2`.
- **Προϊόντα** — slug `local-products`, WordPress ID `192`, count `14`. Description: Η Κρήτη είναι γνωστή για την ποικιλία των τοπικών της προϊόντων, που περιλαμβάνουν γαστρονομικά, χειροτεχνήματα και πολιτιστικά αγαθά. Ξεχωρίζουν το ελαιόλαδο, τα κρητικά τυριά και τα κρασιά, το μέλι και τα βότανα της υπαίθρου, τα υφαντά, τα κεραμικά, τα ξυλόγλυπτα και τα κρητικά κοσμήματα. Όλα αυτά και πολλά ακόμη προϊόντα αντανακλούν την αυθεντικότητα και την ποιότητα της κρητικής παράδοσης. Ανακάλυψέ τα!
  - **Εργαστήριο/Βιοτεχνία** — slug `workshop-craft`, WordPress ID `345`, count `2`.
  - **Προϊόντα Αγροδιατροφής** — slug `agrifood-products`, WordPress ID `346`, count `8`.
  - **Προϊόντα Οικοτεχνίας** — slug `cottage-industry-products`, WordPress ID `347`, count `3`.
  - **Προϊόντα Χειροτεχνίας** — slug `handicraft-products`, WordPress ID `348`, count `2`.
- **Ψυχαγωγία** — slug `entertainment`, WordPress ID `179`, count `4`.
  - **Club** — slug `club`, WordPress ID `189`, count `0`.
  - **Pub** — slug `pub`, WordPress ID `182`, count `0`.
  - **Εμπορικό κέντρο** — slug `mall`, WordPress ID `188`, count `0`.
  - **Θέατρο** — slug `theater`, WordPress ID `185`, count `0`.
  - **Καφέ** — slug `cafe`, WordPress ID `181`, count `0`.
  - **Καφέ - Εστιατόριο** — slug `cafe-restaurant`, WordPress ID `187`, count `0`.
  - **Μουσική σκηνή** — slug `music-scene-stage`, WordPress ID `183`, count `0`.
  - **Μουσικό καφέ** — slug `music-cafe`, WordPress ID `191`, count `0`.
  - **Μουσικό καφενείο** — slug `mousiko-kafeneio`, WordPress ID `353`, count `1`.
  - **Μπαρ** — slug `bar`, WordPress ID `180`, count `0`.
  - **Μπαρ παραλίας** — slug `beach-bar`, WordPress ID `184`, count `0`.
  - **Χώρος διεξαγωγής** — slug `venue`, WordPress ID `186`, count `0`.
  - **Χώρος τεχνών** — slug `arts-space`, WordPress ID `190`, count `2`.

### `kitchen_type` — Τύποι Κουζίνας (2 terms)

- **Vegan** — slug `vegan`, WordPress ID `442`, count `0`.
- **Παραδοσιακή** — slug `traditional-kitchen`, WordPress ID `371`, count `8`.

### `monument-type` — Τύποι Μνημείου (20 terms)

- **Άλλα** — slug `other`, WordPress ID `222`, count `0`.
- **Άλλο** — slug `allo`, WordPress ID `327`, count `1`.
- **Αρχαία Πόλη** — slug `archaia-poli`, WordPress ID `316`, count `25`.
- **Αρχαία Πόλη/Δήμος** — slug `ancient-city-town`, WordPress ID `211`, count `0`.
- **Αρχιτεκτονική** — slug `architecture`, WordPress ID `215`, count `20`.
- **Εγκαταλελειμμένο Μοναστήρι** — slug `abandoned-monastery`, WordPress ID `216`, count `0`.
- **Εγκαταλελημένο Μοναστήρι** — slug `egkatalelimeno-monastiri`, WordPress ID `314`, count `8`.
- **Εκκλησία** — slug `church`, WordPress ID `217`, count `15`.
- **Ιερό** — slug `sanctuary`, WordPress ID `213`, count `4`.
- **Κάστρο** — slug `castle`, WordPress ID `219`, count `8`.
- **Μεσαιωνικός Οικισμός** — slug `medieval-settlement`, WordPress ID `220`, count `1`.
- **Μινωική βίλα** — slug `minoan-villa`, WordPress ID `208`, count `0`.
- **Μινωική Βίλλα** — slug `minoiki-villa`, WordPress ID `325`, count `5`.
- **Μινωική Οικία** — slug `minoan-house`, WordPress ID `209`, count `1`.
- **Μινωικό Παλάτι** — slug `minoan-palace`, WordPress ID `207`, count `7`.
- **Μινωικός Οικισμός** — slug `minoan-settlement`, WordPress ID `210`, count `7`.
- **Ναός** — slug `temple`, WordPress ID `214`, count `1`.
- **Νεκρόπολη** — slug `necropolis`, WordPress ID `212`, count `3`.
- **Οχυρώσεις** — slug `fortifications`, WordPress ID `218`, count `5`.
- **Φάρος** — slug `lighthouse`, WordPress ID `221`, count `1`.

### `museum-type` — Τύποι Μουσείου (6 terms)

- **Έκθεση** — slug `exhibition`, WordPress ID `225`, count `18`.
- **Αστεροσκοπείο** — slug `observatory`, WordPress ID `227`, count `1`.
- **Ενυδρείο** — slug `aquarium`, WordPress ID `228`, count `2`.
- **Μουσείο** — slug `museum`, WordPress ID `223`, count `56`.
- **Πινακοθήκη** — slug `gallery`, WordPress ID `224`, count `5`.
- **Συλλογή** — slug `collection`, WordPress ID `226`, count `20`.

### `ecosystem-type` — Τύποι Οικοσυστήματος (9 terms)

- **Γεωπάρκο** — slug `geopark`, WordPress ID `260`, count `0`.
- **Γεώτοπος** — slug `geotope`, WordPress ID `262`, count `0`.
- **Δάσος** — slug `forest`, WordPress ID `253`, count `0`.
- **Θαλάσσιο οικοσύστημα** — slug `marine-ecosystem`, WordPress ID `259`, count `0`.
- **Κορυφή** — slug `peak`, WordPress ID `261`, count `0`.
- **Λίμνη** — slug `lake`, WordPress ID `255`, count `0`.
- **Ποταμός** — slug `river`, WordPress ID `256`, count `0`.
- **Υγρότοπος** — slug `wetland`, WordPress ID `254`, count `0`.
- **Φοινικόδασος** — slug `palm-forest`, WordPress ID `258`, count `0`.

### `beach-type` — Τύποι Παραλίας (4 terms)

- **Αμμώδης** — slug `sandy`, WordPress ID `249`, count `139`.
- **Βραχώδης** — slug `rocky`, WordPress ID `252`, count `15`.
- **Βότσαλο** — slug `pebble`, WordPress ID `251`, count `33`.
- **Λεπτό βότσαλο** — slug `thin-pebble`, WordPress ID `250`, count `11`.

### `poi-type` — Τύποι Σημείων (25 terms)

- **Άυλη πολιτιστική κληρονομιά** — slug `ayli-politistiki-klironomia`, WordPress ID `770`, count `23`.
- **Αρχαιολογικό μνημείο** — slug `archaiologiko-mnimeio`, WordPress ID `444`, count `3`.
- **Αρχαιολογικός Χώρος** — slug `archaeological-site`, WordPress ID `19`, count `82`.
- **Βουνό** — slug `vouno`, WordPress ID `450`, count `5`.
- **Θρησκευτικό μνημείο** — slug `thriskeftiko-mnimeio`, WordPress ID `448`, count `61`.
- **Ιαματικές πηγές** — slug `iamatikes-piges`, WordPress ID `451`, count `1`.
- **Ιστορικό μνημείο** — slug `istoriko-mnimeio`, WordPress ID `445`, count `61`.
- **Κόλπος - 'Ορμος** — slug `kolpos-ormos`, WordPress ID `696`, count `1`.
- **Λίμνη** — slug `limni`, WordPress ID `449`, count `1`.
- **Μνημείο** — slug `monument`, WordPress ID `20`, count `49`.
- **Μνημείο της Φύσης** — slug `mnimeio-tis-fysis`, WordPress ID `447`, count `2`.
- **Μοναστήρι** — slug `monastery`, WordPress ID `22`, count `37`.
- **Μουσείο** — slug `museum`, WordPress ID `21`, count `169`.
- **Νησίδα** — slug `nisida`, WordPress ID `697`, count `1`.
- **Οικισμός** — slug `oikismos`, WordPress ID `771`, count `21`.
- **Οικοσύστημα** — slug `ecosystem`, WordPress ID `17`, count `41`.
- **Οροπέδιο** — slug `plateau`, WordPress ID `16`, count `11`.
- **Οροσειρά** — slug `mountain-range`, WordPress ID `18`, count `5`.
- **Παραδοσιακοί οικισμοί** — slug `paradosiakoi-oikismoi`, WordPress ID `777`, count `50`.
- **Παραδοσιακός Οικισμός** — slug `traditional-settlement`, WordPress ID `402`, count `164`.
- **Παραλία** — slug `beach`, WordPress ID `14`, count `219`.
- **Πολιτιστικό περιβάλλον** — slug `politistiko-perivallon`, WordPress ID `772`, count `48`.
- **Σπήλαιο** — slug `spilaio`, WordPress ID `446`, count `11`.
- **Φαράγγι** — slug `gorge`, WordPress ID `15`, count `59`.
- **Φυσικό περιβάλλον** — slug `fysiko-perivallon`, WordPress ID `769`, count `90`.

### Term data quality notes

- In `activity`, term ID `767` is named `Bird watchin` with slug `bird-watchin` in the endpoint response.
- In `activity`, term IDs `795` and `796` contain replacement characters in the returned names and percent-encoded slugs (`kolym%ef%bf%bd%ef%bf%bdisi` and `o%ef%bf%bd%ef%bf%bdkotourismos`). Keep these source records distinct and confirm their intended values before normalizing or merging terms.
- In `monument-type`, IDs `216` (`Εγκαταλελειμμένο Μοναστήρι`) and `314` (`Εγκαταλελημένο Μοναστήρι`) are separate spelling variants. In `category`, `Γεωδιαδρομές` appears as both root ID `752` and child ID `7` under `Διαδρομές Κρήτης`. Preserve source IDs and parent context until these are reviewed.
- This snapshot contains names returned by the default public endpoints. It does not include a separate Greek-English translation map for taxonomy terms.

## Public examples

- [Routes archive](https://ruralgreece.gr/route/)
- [Businesses archive](https://ruralgreece.gr/en/business/)
- [Points of interest archive](https://ruralgreece.gr/en/pois/)
- [Activity taxonomy archive](https://ruralgreece.gr/en/activity/bird-watching/)
- [Business type archive: Accommodation](https://ruralgreece.gr/en/business-type/accommodation/)
- [POI type archive: Ecosystem](https://ruralgreece.gr/en/poi-type/ecosystem/)
- [Route detail showing type, location, businesses, and POIs](https://ruralgreece.gr/route/chania-therisso-omalos-sougia/)
- [POI detail example](https://ruralgreece.gr/pois/paralongoi/)

## Source registries

- Sample records: [routes](https://ruralgreece.gr/wp-json/wp/v2/route?per_page=3), [businesses](https://ruralgreece.gr/wp-json/wp/v2/business?per_page=3), [POIs](https://ruralgreece.gr/wp-json/wp/v2/pois?per_page=3), [posts](https://ruralgreece.gr/wp-json/wp/v2/posts?per_page=3), [pages](https://ruralgreece.gr/wp-json/wp/v2/pages?per_page=3).
- Term endpoint pattern: `/wp-json/wp/v2/<rest_base>?per_page=100`; the REST bases match the taxonomy slugs except `category` → `categories` and `post_tag` → `tags`.
- [WordPress REST API: registered content types](https://ruralgreece.gr/wp-json/wp/v2/types)
- [WordPress REST API: registered taxonomies](https://ruralgreece.gr/wp-json/wp/v2/taxonomies)
