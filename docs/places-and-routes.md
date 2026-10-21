# Points of interest, routes and businesses

A ready-made set for sites about places: tourist routes drawn from a GPX, KML or GeoJSON file, the points of interest around them,
and the businesses that serve the visitors. Each is a content type of the default theme (switch it on in **Admin > Content types**),
each has its own page with a map, and all of them can be put on one map: as an archive with the **Map** layout, or anywhere with the
**Map** block.

| Type | Address | What it is |
|------|---------|------------|
| Points of interest (`points`) | `/points/<name>` | A place worth a stop: a sight, a spring, a viewpoint |
| Routes (`routes`) | `/routes/<name>` | A walk, a ride or a drive, from a route file |
| Businesses (`businesses`) | `/businesses/<name>` | A place to stay, eat, buy or do something |

## Fields

Every type keeps a position in the field **`location`** (a field of the kind `location`: `35.2012, 26.2744`, as Google Maps and
OpenStreetMap copy it). It is checked as it is typed, stored one way, and has a **Map** button that opens a map to click the place
on. A value that is not a position is not stored. Field keys never change; a site adds fields or hides one in
`custom/content-types/<type>.yaml`.

- **Points:** `location`, `subtitle`, `area`, `altitude`, `opening_hours`, `admission`, `accessible`, `website`, `phone`.
- **Routes:** `track` (the route file), `location` (the start; optional, the start of the line is used when empty), `subtitle`, `area`, `activity`
  (hiking, trail running, cycling, mountain biking, driving, walking), `difficulty`, `route_type`, `duration`, `season`, `trailhead`,
  and three that replace what the file says: `distance_km`, `ascent_m`, `descent_m`.
- **Businesses:** `location`, `subtitle`, `area`, `address`, `phone`, `email`, `website`, `booking_url` and `booking_label` (a button in the title
  band), `price_range`, `opening_hours`, `services` and `packages` (two texts of several lines), `facebook`, `instagram`.
- **All three** have a **`gallery`** (see *Gallery* below). No field is required.

The kind of business, or of point, or of route, is its **category** (Admin > Taxonomies). The map filters by it and by any other
taxonomy of the site (an activity, a difficulty, a price range, a facet of the points): see [Filters on the map](#filters-on-the-map).

Three field kinds were added for this and can be used by any content type: **`location`**, **`file`** (the address of a file, with a
*Library* button; `kind: track` offers only route files), and **`gallery`** (below). `location` and `file` work in blocks too.

## Gallery

A **gallery** is a field of the kind `gallery`: the pictures of the media library an entry shows, in the order chosen. The three types
here have one under the key `gallery`; any type can have one, under any key (`photos: {type: gallery, label: Photos, max: 30}`, in
`custom/content-types/<type>.yaml` or in **Admin > Content types**). It is stored as a YAML list of addresses:

```yaml
custom_fields:
  gallery:
    - /uploads/media/gorge-1.jpg
    - /uploads/media/gorge-2.jpg
```

A list written at the top of the front matter (`gallery: [...]`, the way an import may write it) is read as the field too, and the
editor moves it under `custom_fields` the next time the entry is saved. Each address is checked as an image field's is (anything else
is dropped), a picture is kept once, and a gallery keeps up to its `max` (100 when it sets none).

- **In the editor** the field is a row of thumbnails: *Add pictures* opens the library and stays open, so several can be picked in one
  go (the ones already in the gallery are marked); each picture moves earlier or later with its buttons, or by dragging it, and is
  removed with ✕. *Edit as text* shows the addresses, one on each line, which is also what the form holds without JavaScript.
- **On the page of one**, the gallery is a **strip** (a Gallery block with the *Scrolling strip* layout; the pictures open larger in its
  viewer). *Theme > Single Layouts* has the choice on the card of every type that has a gallery (`gallery_strip`): *Under the title*
  (the default), *In the body* (at the end of the text), or *Off*. An entry with no pictures shows nothing, and its page loads none
  of the gallery's style sheet or script. The strip is the same on the page of any type (`components/page-body.twig`); a type whose
  template is built with blocks places its own (see below). A gallery is never a line of the information card.
- **The Gallery block** has a first choice, *Pictures*: *Chosen below* (as before) or *The entry's gallery*, the pictures of the page
  or entry the block is on, so a Gallery block in an entry's own blocks shows its gallery in any layout (grid, masonry, strip). For a
  type with more than one gallery, *Gallery field* names the key; empty means the type's gallery (`gallery`, else the first of the
  kind). With no pictures the block draws nothing.
- **In a template built with blocks**, the Gallery block can take *The entry's gallery* in the same way, or its *Images* can be bound
  to the gallery (the chain button): a gallery is offered as a list with one column, *Image*, so each picture is a row, and the other
  columns (description, caption) can be given a fixed value.
- **For templates**, `content_values(item).gallery` is the checked list of addresses.

## Route files

Upload a `.gpx`, `.kml` or `.geojson` file in **Media** (a new group of allowed files, *Route files*, in Settings > Limits). A file is
kept only if it can be read as a route: it needs a line or a place, up to 15 MB, and no `DOCTYPE`. In a route's *Route details*, press
*Library* beside **Route file**.

From the file the page works out the **length, the total climb and descent, the highest and lowest point, and a profile of the height**
(a change of less than 4 m is receiver noise, not a climb), and draws the line, its two ends (one when the route is a loop) and the
places the file marks. A route without heights has no climb and no profile. Where an editor types `distance_km`, `ascent_m` or
`descent_m`, that wins over the file. The page offers the file as a download.

A file is read once: the result (the line cut down to 1500 points for a page of its own and to 90 for an overview map, the places, the
facts) is kept in `storage/cache/geo`, named after the file's path, time and size, so a replaced file is read again. GPX and KML are
read as a stream, so a long recording does not have to fit in memory.

### What is kept for the maps

Besides the route file's own copy, each route has a **summary** (`*.sum.json` in `storage/cache/geo`, named the same way): its facts, the overview line a map of many routes draws and the short line distances are measured to. Lists of routes, the Map archive and "what is near" read these small summaries and never the 1500-point copies, so showing or comparing a hundred routes does not read a hundred large files. A page of one route still reads its own full copy for the line and the profile.

On top of that, the data a map is made from the whole of the content is kept in `storage/cache/geo-data`: the **markers and lines of a map of entries** (an archive with the Map layout, the Map block, the Map of the list block), the **map of a page of one**, and **what is near** an entry. The caches are disposable and rebuilt on demand: delete the folder and the next visit makes them again, with the same output. A visitor and someone signed in (who also sees drafts) each get their own.

An answer is kept under a token of the content. It changes when an entry is saved, deleted, imported or edited by hand (the content index notices a file changed outside the admin within half a minute), when anything is saved in the admin (settings, taxonomies, a replaced file in Media, a content type), and when a type with a place changes its definition. The route files an answer was made from are also compared, by time and size, on every read, so a route file replaced behind the site's back is not served from an old answer. When the token changes, what the old one kept is deleted. An entry that is not a saved file (the editor's live preview of an unsaved entry) is never kept. The entries listed as "near" are always the content's today; only their addresses and distances are kept.

## The map

The map is [Leaflet](https://leafletjs.com) (with Leaflet.markercluster), shipped inside the theme (`assets/vendor/leaflet/`); nothing
is loaded from anyone else except the **map tiles**. A page that has no map loads none of it.

- **When it loads.** *Settings > APIs > Maps > Load a map*: *when the visitor asks for it* (the default: no request is made to the tile
  server until then) or *as soon as it comes into view*. A Map block can choose for itself.
- **Which tiles.** OpenStreetMap's own, meant for light use. A busy site puts the address of a provider (MapTiler, Thunderforest, Stadia…)
  or of its own tile server in *Tile address* (https, with `{z}`, `{x}` and `{y}`) and the credit the provider asks for.
- **Without JavaScript** every page still has the list of places, as links.
- **Accessible.** The list is an equal way in; markers can be reached with the keyboard; the wheel scrolls the page until the map has
  been clicked; the map stays still for visitors who ask for less motion; a map is a labelled region.

## Archive with a map

*Admin > Theme > Archive Layouts* offers the layout **Map** for every type and every category or tag. An archive with that layout is
one map with a list beside it (right, left, below, or no list), with the height you choose; or, as wide as the screen, with the list *over* the map on one side (see below). A popup's text is the entry's excerpt, or, without one, the start of its first paragraph (headings and code blocks are skipped). It shows every entry that has a place (up
to 800; no paging); a route is drawn as a line. A bar over the map filters by kind (when the list has several), by the taxonomies of
the list and by a search (see below). The page's own form keeps the filters of its *Filters* row and the select fields of the type
(activity, difficulty, price range) as before. The three types here use this layout by default.

## Pages of terms

Every taxonomy has a page for each term (`/activity/hiking`, `/poi-type/ecosystem`, `/business-type/stay/hotel` in a tree), set on
its card in *Theme > Archive Layouts* like any list: the layout (the **Map** too), the title area and its picture, which types it
lists (*Lists*), the filters, and the title and subtitle. The term's description is the subtitle unless the card writes one.

- **A taxonomy of places opens as a map.** When the pages of a taxonomy list only types with a place (its *Lists* names them, and
  each has a field of the kind `location`), the page of a term is the Map layout until the site chooses another one; its card says
  *Map by default*. Choosing the Map there stores nothing; choosing another layout is stored and kept. Categories and tags (they list
  every type) and any taxonomy that lists a type without a place stay as they were, and so does a site without types with a place.
  The importer makes the taxonomies of a `rest_types` profile list the types the profile files under them, so `activity`,
  `location`, `poi-type` and the rest open as maps.
- **Sub-terms as chips.** In a taxonomy with a tree, a row of chips under the title links to the sub-terms: *All* (the term itself,
  marked) and each sub-term with how many entries it has (with *List entries of the sub-terms too*, those below it count). A sub-term
  with no entries in the language of the page is left out. A term without sub-terms shows its parent's, itself marked and *All*
  leading up to the parent. The numbers come from the content index; while it cannot answer, every sub-term is a chip without a
  number. *Chips under the title* in the card's *Sub-terms* row switches the row off (`archive.sub_term_chips: false`). A flat
  taxonomy has no chips.
- **The bar of a term's page.** The bar over the map (and the page's own form) does not offer the page's own taxonomy: every entry
  on the page has the term, and in a tree its sub-terms are the chips, so the same choice is not offered twice. The card's *Map
  filters* row does not list it either. The other taxonomies are offered as on any list (what the card chose, or else what each
  listed type chose).
- **Old addresses.** The term pages of a site's own taxonomy are pages of the site for the Redirects screen too (a redirect from one
  is reported as hidden by a page). The importer's redirects of terms, merged ones and the English ones included, are in
  [Moving a WordPress site over](wordpress-import.md#old-addresses).

## Filters on the map

The bar over a map of entries (the Map layout of an archive, the Map block, the Map of the list block) has a menu for each taxonomy
the list offers. A menu lists the terms the entries on the map have, each with how many of them have it; a taxonomy with a tree
(categories, or a site's own with parents) lists each term under its parent, a parent with its children as a group, and a parent counts
every entry filed under it or under a term below it, once. The menus work at once, without loading the page, and before the map itself
is loaded: the list beside the map follows them, and the markers when the map is shown.

- **Several terms of one taxonomy** mean *any of them* (walking or cycling); **terms of different taxonomies** mean *all of them*
  (walking, and easy). The kind of entry and the search are chosen with them.
- **The numbers follow the choice.** Each term says how many entries it would leave with what is chosen in the other menus; a term with
  none left is shown faded and cannot be ticked. *Clear* empties one menu, *Clear filters* all of them.
- **Which taxonomies.** *Theme > Archive Layouts*, the card of the list, row *Map filters* (shown with the Map layout): one chip for
  each taxonomy, with its number of terms. Until something else is ticked, a list offers the **automatic** ones: categories, as always,
  and every taxonomy that applies to its types (all of them, or those its own pages list) with **2 to 40 terms**; a taxonomy no entry on
  the map uses, or whose entries all share one term, is left out of the bar. Ticking exactly the automatic ones stores nothing (in
  `archive.map_filters` of the type's file), so the list keeps following the taxonomies as they change; ticking none offers none. The
  Map block and the Map of the list block offer what the card of each of their types chose; a page of a term (a category with the Map
  layout) what its taxonomy's card chose, or else what each type it lists chose.
- **Without JavaScript** the archive's own form also has a menu for each taxonomy of the bar (`?filter[<taxonomy>]=<term>`, one term
  each, the different ones together; a parent there is the term itself, not its children), answered by the content index like the
  other filters. With JavaScript those fields are put away
  once the bar is drawn (unless one holds a choice, so it can be undone), and the form with them when nothing else is left in it.
- **A type with no taxonomies** (or entries filed under none) has no menus: the bar has the kinds and the search, as before.
- **Names.** A taxonomy's menu is called by the theme's text `filter.<name>` (`filter.categories` is *Category*), or else by its title in
  Admin > Taxonomies; a term by its name in the language of the page.

The numbers are those of the entries on the map (an entry without a place is not counted, as it is not shown), worked out with the
map's data and kept with it in the cache of the maps; changing the card or a taxonomy changes the token the cache is kept under. The
counts of the page's own form are the archive's, from the content index.

## The Map block

The existing **Map** block has a new first choice, *Show*: *One place, typed below* (as before) or the entries of a content type, or
*Everything with a place*. Then: an optional category, the number of entries, whether to show filters and search, the list (right,
left, below, none), whether markers that are close are grouped, the height, and when the map loads (*as the site says*, on a click, or
when in view). The block draws nothing for a visitor when there is nothing to show.

**Layouts.** *Inside the page width* and *Full width* work for any map. *Details beside the map* (the text and address of one place beside its map) is offered only for a map of one place, and **Full width, list over the map** only for a map of content: the map runs edge to edge, and the list with its filters floats over it on the side chosen in *List* (right or left). *Below* is not offered for this layout in the editor, and a saved *below* is read as right. The map is centred on the part the list leaves free, its zoom buttons and credit move to the other side, and a popup opens clear of the list. On a narrow screen the list goes under the map. An archive can do the same: *Theme > Archive Layouts > Map > List > Over the map*. The editor changes the layout by itself when the choice of *Show* makes the old one meaningless (`variant_when` in `block.yaml`). The field `when` of the block editor can now say
"anything but": `when: {source: '!manual'}`.

## The page of one

`templates/single-point.twig`, `single-route.twig` and `single-business.twig` are three lines each; the page itself is
`components/place-single.twig`, so a site can change one file or override one template in `custom/`.

- A **title band** with the main image behind it, the area and the categories (as links), the title, the **subtitle** (the field
  `subtitle`, when it is filled), the excerpt, and buttons: *Book now* (a business with a booking link) and *Download the route* (a route).
- A **route** has a bar of facts (length, duration, climb, descent, highest point, difficulty, the **best season** when `season` is
  filled, type), a large map with the whole line and the places near it, and the profile of the height (a drawing that says its range
  in its label).
- **The profile and the map point at each other.** Moving the pointer or a finger along the profile shows a line and a dot on it with the
  distance and the height (`2,4 km · 812 m`), and marks the same place on the map's line; the pointer on the map near the line marks
  the profile. With the keyboard the profile is a slider: Tab to it, then the arrows (a hundredth of the way), Page Up and Page Down (a
  tenth), Home and End; Escape or leaving it clears the mark. A finger that moves up or down still scrolls the page. The profile works
  before the map is shown and joins it once it is (the map says so with an event, `geo:ready`). The script is
  `assets/js/route-profile.js`, loaded only by a page that has a profile; a route without heights has none, and without the script the
  profile is the drawing it was.
- A **point or a business** has an information card (type, area, address, phone, email, website, opening hours, and for a business
  its services and packages, each text with its own lines… each with an icon), a map card that is close on the place with directions,
  and the entries near it. A field that is empty is not shown.
- **Terms as chips.** The terms of the entry in the taxonomies a site added (Admin > Taxonomies), and its tags, are a card of chips, a
  row for each taxonomy with its name above, each chip a link to the term's page (for a point filed under a type and a facet: one row
  for the type, one for the facet). The categories stay in the title band. An entry in no such taxonomy shows no card.
- **Nearby.** Routes list the points and businesses within a distance of their line; a point or a business lists the routes whose line
  passes near it and the businesses and points around. Nearest first, with the distance. Computed from the positions, so there is
  nothing to keep in step by hand.
- **Nearby under headings.** Each kind has its own card (routes, businesses, places), and inside it the entries are under the **top
  term of a taxonomy with a tree** they are filed in: a point filed under *Gorges*, a child of *Nature*, is under *Nature*. Nothing is
  stored for it; the group is read from the taxonomy, so moving a term moves its entries. The taxonomy is the one with a tree (at least
  one term with a parent) that most of the listed entries are filed in, a site's own over the categories on a tie. Groups follow the
  order of the tree, each heading links to its term's page and lists up to six, nearest first; entries filed in none of its terms come
  last under *Other*. When no entry is filed in such a taxonomy (a site with flat categories, or none) the card is one list of six, as
  before.
- *Theme > Single Layouts* has a card for each type: the map's height, whether to show the information, the profile, the download
  button, where the strip of the gallery goes, the chips of terms (`show_terms`), whether what is near is grouped (`nearby_groups`), what is near, what distance counts as near (`opt.nearby_radius`, in km), and how many of each kind to list
  (`nearby_limit_routes`, `nearby_limit_points`, `nearby_limit_businesses`; 18 when empty), so a crowded route cannot push the points
  out. A site that set one `nearby_limit` in its own type definition keeps it as the number for every kind.
- Search engines get a `TouristAttraction` or `LocalBusiness` with its coordinates, address, phone, email and price range.

## For templates

`geo_dataset(items, {lines, cluster, filters})` (`filters`: the taxonomies of the bar, as a list of names; null or left out: as the card
of each type chose; each item then has `tax`, its terms of them, and the data has `facets`, each with its `options` and their `count`) and `geo_single(item, {types, radius, limit})` give the data a map draws;
`include 'components/geo-map.twig' with {geo, height, list, filters, cluster, load, label}` draws it. `geo_nearby(item, types, radius,
limit)` gives `[{item, km}]` (`limit` is a number for all, or a map such as `{points: 6, businesses: 4}` for each type; a type missing from the map is not cut); `route_facts(item)` the facts of a route; `elevation_profile(profile, label)` the drawing;
`geo_position(item)` the place of an entry; `nearby_groups(found, prefix)` the entries of `geo_nearby` of one type under the top terms of
a tree (`[{id, taxonomy, title, url, entries}]`, none when they are filed in no tree); `item_terms(item, prefix)` the terms of an entry
in the taxonomies a site added, by taxonomy. A type counts as having places when one of its fields is of the kind `location`.

## Not in this version

The select fields of a type (activity, difficulty, price range as fields rather than taxonomies) filter through the page's form,
which reloads the page; the form takes one term of each taxonomy (the bar takes several). Route files are not edited in the admin:
upload a file, choose it. A picture of a gallery has no caption of its own (the library's description is its text).
