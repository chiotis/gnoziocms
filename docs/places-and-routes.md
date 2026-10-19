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

- **Points:** `location`, `area`, `altitude`, `opening_hours`, `admission`, `accessible`, `website`, `phone`.
- **Routes:** `track` (the route file), `location` (the start; optional, the start of the line is used when empty), `area`, `activity`
  (hiking, trail running, cycling, mountain biking, driving, walking), `difficulty`, `route_type`, `duration`, `season`, `trailhead`,
  and three that replace what the file says: `distance_km`, `ascent_m`, `descent_m`.
- **Businesses:** `location`, `area`, `address`, `phone`, `email`, `website`, `booking_url` and `booking_label` (a button in the title
  band), `price_range`, `opening_hours`, `facebook`, `instagram`.

The kind of business, or of point, or of route, is its **category** (Admin > Taxonomies): that is what the map filters by.

Two field kinds were added for this and can be used by any content type or block: **`location`**, and **`file`** (the address of a
file, with a *Library* button; `kind: track` offers only route files).

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
to 800; no paging); a route is drawn as a line. A bar over the map filters by kind (when the list has several), by category, and by a
search. The filters of the type that are not categories (activity, difficulty, price range) stay as the page's own form. The three
types here use this layout by default.

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

- A **title band** with the main image behind it, the area and the categories (as links), the title, the excerpt, and buttons: *Book
  now* (a business with a booking link) and *Download the route* (a route).
- A **route** has a bar of facts (length, duration, climb, descent, highest point, difficulty, type), a large map with the whole line and
  the places near it, and the profile of the height (a drawing that says its range in its label).
- A **point or a business** has an information card (type, area, address, phone, email, website… each with an icon), a map card that is
  close on the place with directions, and the entries near it.
- **Nearby.** Routes list the points and businesses within a distance of their line; a point or a business lists the routes whose line
  passes near it and the businesses and points around. Nearest first, with the distance. Computed from the positions, so there is
  nothing to keep in step by hand.
- *Theme > Single Layouts* has a card for each type: the map's height, whether to show the information, the profile, the download
  button, what is near, what distance counts as near (`opt.nearby_radius`, in km), and how many of each kind to list
  (`nearby_limit_routes`, `nearby_limit_points`, `nearby_limit_businesses`; 18 when empty), so a crowded route cannot push the points
  out. A site that set one `nearby_limit` in its own type definition keeps it as the number for every kind.
- Search engines get a `TouristAttraction` or `LocalBusiness` with its coordinates, address, phone, email and price range.

## For templates

`geo_dataset(items, {lines, cluster})` and `geo_single(item, {types, radius, limit})` give the data a map draws;
`include 'components/geo-map.twig' with {geo, height, list, filters, cluster, load, label}` draws it. `geo_nearby(item, types, radius,
limit)` gives `[{item, km}]` (`limit` is a number for all, or a map such as `{points: 6, businesses: 4}` for each type; a type missing from the map is not cut); `route_facts(item)` the facts of a route; `elevation_profile(profile, label)` the drawing;
`geo_position(item)` the place of an entry. A type counts as having places when one of its fields is of the kind `location`.

## Not in this version

Entries have one main image; the reference pages' strip of pictures is a Gallery block in the entry's blocks for now. Filters on the map
are by kind, category and search (the other filters reload the page). Route files are not edited in the admin: upload a file, choose it.
