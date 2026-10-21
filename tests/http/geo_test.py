import json, math, os, re, sys, urllib.request, urllib.error, uuid
sys.path.insert(0, '.')
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
anon = Client()
def write(path, text):
    os.makedirs(os.path.dirname('app/content/' + path), exist_ok=True)
    open('app/content/' + path, 'w', encoding='utf-8').write(text)
def token(c): return re.search(r'name="_csrf" value="([0-9a-f]+)"', c.get('/admin')[2]).group(1)
def upload(c, name, data, mime='application/octet-stream'):
    b = uuid.uuid4().hex
    body = b''
    for k, v in (('_csrf', token(c)), ('media_action', 'upload'), ('upload_tags', '')):
        body += f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    body += f'--{b}\r\nContent-Disposition: form-data; name="upload_file[]"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + data + f'\r\n--{b}--\r\n'.encode()
    req = urllib.request.Request(BASE + '/admin/media', data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + b})
    try:
        r = c.opener.open(req); return r.status, r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        where = e.headers.get('Location') or ''
        return e.code, (c.get(where)[2] if where else e.read().decode('utf-8', 'replace'))
def geo_data(html): return json.loads(re.search(r'data-geo-data>(.*?)</script>', html, re.S).group(1))
def picker(kind): return json.loads(root.get('/admin/media-picker?kind=' + kind)[2])

# ---- route files in the media library
def gpx(points, name='Loop'):
    pts = ''.join('<trkpt lat="%.6f" lon="%.6f"><ele>%d</ele></trkpt>' % p for p in points)
    return ('<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><wpt lat="35.2" lon="26.31"><name>Spring</name></wpt><trk><name>%s</name><trkseg>%s</trkseg></trk></gpx>' % (name, pts)).encode()
LOOP = [(35.00 + 0.03 * math.sin(a), 26.00 + 0.04 * math.cos(a), 100 + int(60 * math.sin(2 * a)) + 60) for a in [i * 2 * math.pi / 120 for i in range(121)]]
st, _ = upload(root, 'loop.gpx', gpx(LOOP))
tracks = picker('track')['items']
check('a GPX file can be uploaded and is listed as a route file', len(tracks) == 1 and tracks[0]['kind'] == 'track' and tracks[0]['url'].endswith('.gpx'), (st, tracks))
TRACK = tracks[0]['url'] if tracks else ''
check('route files are also in the list of every kind of file, and the other lists do not have them', any(i['kind'] == 'track' for i in picker('all')['items']) and not any(i['kind'] == 'track' for i in picker('image')['items']))
st, text = upload(root, 'doctype.gpx', b'<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/hostname">]><gpx><trk><trkseg><trkpt lat="1" lon="1"/><trkpt lat="2" lon="2"/></trkseg></trk></gpx>')
check('a route file with a DOCTYPE is refused', len(picker('track')['items']) == 1 and 'could not be read' in text, text[-300:])
st, text = upload(root, 'empty.gpx', b'<gpx xmlns="http://www.topografix.com/GPX/1/1"></gpx>')
check('and one with no line or place', len(picker('track')['items']) == 1 and 'could not be read' in text)
st, text = upload(root, 'broken.geojson', b'{"type": "Feature"')
check('and one that is not JSON', len(picker('track')['items']) == 1 and 'could not be read' in text)
st, _ = upload(root, 'walk.geojson', json.dumps({'type': 'Feature', 'properties': {}, 'geometry': {'type': 'LineString', 'coordinates': [[26.0, 35.0, 10], [26.1, 35.1, 20]]}}).encode())
st, _ = upload(root, 'walk.kml', b'<kml xmlns="http://www.opengis.net/kml/2.2"><Placemark><LineString><coordinates>26.0,35.0,10 26.1,35.1,20</coordinates></LineString></Placemark></kml>')
check('GeoJSON and KML are accepted', len(picker('track')['items']) == 3, picker('track')['items'])

# ---- the content
CATS = 'title: Categories\nterms:\n' + ''.join('  - id: %s\n    slug: %s\n    labels:\n      en: %s\n      el: %s\n' % (s, s, e, e) for s, e in (('hiking', 'Hiking'), ('sights', 'Sights'), ('hotels', 'Hotels'), ('food', 'Food')))
write('taxonomies/categories.yaml', CATS)
# A taxonomy of the site's own, for the bar over the map (three terms: offered without being chosen).
write('taxonomies/facility.yaml', 'title: Facility\nterms:\n' + ''.join('  - id: %s\n    slug: %s\n    labels:\n      en: %s\n' % (s, s, e) for s, e in (('pool', 'Pool'), ('parking', 'Parking'), ('garden', 'Garden'))))
def entry(type_, slug, title, fields, extra=''):
    lines = ''.join("  %s: %s\n" % (k, json.dumps(v, ensure_ascii=False)) for k, v in fields.items())
    write('%s/%s.en.md' % (type_, slug), "---\ntitle: '%s'\nstatus: published\nvisible: true\ndate: '2026-01-02'\n%scustom_fields:\n%s---\nBody of %s.\n" % (title, extra, lines, title))
entry('routes', 'loop', 'The loop', {'track': TRACK, 'area': 'Sitia', 'activity': 'hiking', 'difficulty': 'moderate', 'duration': '3 h'}, "excerpt: 'A walk around the bay.'\ncategories: [hiking]\n")
entry('routes', 'no-file', 'A route without a file', {'activity': 'cycling', 'difficulty': 'easy', 'distance_km': 8.5, 'location': '35.5, 26.5'}, 'categories: [hiking]\n')
entry('points', 'spring', 'The spring', {'location': '35.0, 26.041', 'area': 'Sitia', 'opening_hours': 'Always', 'phone': '+30 28430 29623', 'accessible': True}, 'categories: [sights]\n')
entry('points', 'far-away', 'Far away', {'location': '36.9, 27.9'}, 'categories: [sights]\n')
entry('points', 'unplaced', 'Not on a map', {'area': 'Nowhere'})
entry('businesses', 'hotel', 'The hotel', {'location': '35.031, 26.0', 'area': 'Sitia', 'address': 'Main street 1', 'phone': '+30 28430 11111', 'email': 'info@example.test', 'website': 'https://example.test', 'booking_url': 'https://example.test/book', 'price_range': 'moderate'}, 'categories: [hotels]\nfacility: [pool, parking]\n')
entry('businesses', 'tavern', 'The tavern', {'location': '35.2, 26.3'}, 'categories: [food]\nfacility: [parking]\n')
for i in range(15):
    entry('businesses', 'shop-%02d' % i, 'Shop %02d' % i, {'location': '35.%02d, 26.%02d' % (50 + i, 50 + i)}, 'categories: [food]\n')

# ---- the archive with a map
st, _, html = anon.get('/en/routes')
check('an archive of routes is a map with a list', st == 200 and 'data-geo' in html and 'class="geo-list"' in html and 'The loop' in html, st)
data = json.loads(re.search(r'<script type="application/json" data-geo-data>(.*?)</script>', html, re.S).group(1))
check('the map is given each route that has a place, with its line', [i['id'] for i in data['items']] == ['routes/loop', 'routes/no-file'] or sorted(i['id'] for i in data['items']) == ['routes/loop', 'routes/no-file'], [i['id'] for i in data['items']])
loop = next(i for i in data['items'] if i['id'] == 'routes/loop')
check('a route has its facts (from the file) and its line', loop['facts'] and loop['facts'][0].endswith('km') and len(loop['line']) == 1 and 2 < len(loop['line'][0]) <= 90, (loop['facts'], len(loop['line'])))
nofile = next(i for i in data['items'] if i['id'] == 'routes/no-file')
check('a route with no file is a pin at its start, with the length typed', nofile['line'] == [] and nofile['facts'] == ['8.5 km'] and nofile['lat'] == 35.5, nofile)
check('the data holds the tiles, and is safe to put in a page', data['tiles']['url'].startswith('https://tile.openstreetmap.org/') and '</script>' not in html.split('data-geo-data>')[1].split('</script>')[0])
check('the stylesheet and the script of the map come with the page, and Leaflet is only named, not loaded', re.search(r'_blocks\.css\?b=[^"]*map', html) is not None and re.search(r'_blocks\.js\?b=[^"]*map', html) is not None and 'vendor/leaflet/leaflet.js' in html and '<script src="/_themes/default/vendor/leaflet' not in html, re.findall(r'_blocks\.(?:css|js)\?b=[^&"]*', html))
check('a map is shown when the visitor asks (the site\'s choice until it says otherwise)', 'data-geo-load-mode="click"' in html and 'data-geo-load' in html)
check('the page keeps the filters of the type (activity, difficulty) as a form; a category all of them share is no filter', 'name="filter[activity]"' in html and 'name="filter[difficulty]"' in html and 'filter[categories]' not in html and data['facets'] == [])
check('and the map has its own bar to fill (made by the script)', 'data-geo-filters' in html)
st, _, html = anon.get('/en/businesses')
data = json.loads(re.search(r'data-geo-data>(.*?)</script>', html, re.S).group(1))
check('there is no paging on a map: every business with a place is on it and in the list (per page does not apply)', len(data['items']) == 17 and html.count('data-geo-item=') == 17, (len(data['items']), html.count('data-geo-item=')))
check('the categories are there to filter by, with their names and counts', {c['slug']: c['count'] for c in data['cats']} == {'food': 16, 'hotels': 1} and {c['label'] for c in data['cats']} == {'Food', 'Hotels'}, data['cats'])
check('without pagination links', 'class="pagination"' not in html)
facets = {f['name']: f for f in data['facets']}
check('the bar offers every taxonomy of the type with terms in use, not only the categories', list(facets) == ['categories', 'facility'] and facets['categories']['tree'] and not facets['facility']['tree'], data['facets'])
check('each term with how many entries on the map have it', [(o['id'], o['label'], o['count']) for o in facets['facility']['options']] == [('parking', 'Parking', 2), ('pool', 'Pool', 1)] and {o['id']: o['count'] for o in facets['categories']['options']} == {'food': 16, 'hotels': 1}, data['facets'])
tax = {i['id']: i.get('tax') for i in data['items']}
check('and each entry its terms, so the bar can filter at once', tax['businesses/hotel'] == {'categories': ['hotels'], 'facility': ['pool', 'parking']} and tax['businesses/shop-00'] == {'categories': ['food']}, (tax['businesses/hotel'], tax['businesses/shop-00']))
check('the taxonomies are named as the theme names them', facets['categories']['label'] == 'Category' and facets['facility']['label'] == 'Facility', [f['label'] for f in data['facets']])
check('without JavaScript the page\'s own form has them too, marked as covered by the bar', 'data-geo-form' in html and 'data-geo-covered="facility"' in html and 'name="filter[facility]"' in html and 'data-geo-covered="categories"' in html)
html = anon.get('/en/businesses?filter[facility]=pool')[2]
check('and the form filters the list and the map', sorted(i['id'] for i in geo_data(html)['items']) == ['businesses/hotel'], [i['id'] for i in geo_data(html)['items']])
st, _, html = anon.get('/en/points')
data = json.loads(re.search(r'data-geo-data>(.*?)</script>', html, re.S).group(1))
check('an entry with no place is not on the map', sorted(i['id'] for i in data['items']) == ['points/far-away', 'points/spring'], [i['id'] for i in data['items']])

# ---- the page of a route
st, _, html = anon.get('/en/routes/loop')
check('the page of a route has a title band with its area, its category and the lead', st == 200 and 'class="place-title">The loop' in html and 'Sitia' in html and 'A walk around the bay.' in html and 'taxonomy' not in html and re.search(r'class="place-term" href="[^"]*/hiking">Hiking</a>', html) is not None, st)
stats = dict(re.findall(r'<dt>([^<]+)</dt><dd>([^<]+)</dd>', html))
check('with the facts worked out from its file: length, duration, climb, descent, highest point, difficulty, type', 'Length' in stats and re.fullmatch(r'\d+\.\d km', stats['Length']) and stats['Duration'] == '3 h' and stats['Climb'].startswith('↑') and stats['Descent'].startswith('↓') and stats['Highest point'].endswith(' m') and stats['Difficulty'] == 'Moderate' and stats['Type'] == 'Loop', stats)
check('a button downloads the route file', re.search(r'class="btn btn-secondary place-download" href="[^"]*\.gpx" download>[^<]*<svg[^>]*>.*?</svg>Download the route \(GPX\)', html, re.S) is not None)
check('the map of the route has the whole line and the places the file marks', 'data-geo' in html and '"route":{"lines":[[[' in html and '"waypoints":[{"lat":35.2,"lng":26.31,"name":"Spring"' in html.replace(' ', ''), html[html.find('"route"'):html.find('"route"') + 150])
check('the profile of the height is a drawing that says what it shows', '<svg class="route-profile"' in html and re.search(r'aria-label="Height profile: \d+–\d+ m, \d+\.\d km"', html) is not None)
check('the points and the businesses near the line are listed, the far ones are not', 'The spring' in html.split('place-aside')[1] and 'The hotel' in html.split('place-aside')[1] and 'Far away' not in html.split('place-aside')[1])
check('the fields already in the facts are not repeated in the card', 'About the route' in html and '<strong>Difficulty:</strong>' not in html and '<strong>Duration:</strong>' not in html and '<strong>Area:</strong>' in html)
st, _, html = anon.get('/en/routes/no-file')
check('a route with no file shows what was typed, and no map line or profile', st == 200 and '8.5 km' in html and 'route-profile' not in html and 'place-download' not in html and '"route"' not in html, st)

# ---- the page of a point and of a business
st, _, html = anon.get('/en/points/spring')
check('a point is a tourist attraction', '"@type":"TouristAttraction"' in html and '"latitude":35' in html)
check('the page of a point has its information with an icon for each line', st == 200 and 'The spring' in html and 'Opening hours:</strong> Always' in html and re.search(r'href="tel:\+302843029623">\+30 28430 29623', html) is not None and 'Wheelchair accessible' in html and html.count('place-fact-icon') >= 3, st)
check('its map is close on it, with the places around', '"current":"points/spring"' in html and 'place-map-card' in html and 'openstreetmap.org/directions' in html)
check('and the routes whose line passes by are listed, nearest first', 'Routes and trails nearby' in html and 'The loop' in html.split('Routes and trails nearby')[1])
st, _, html = anon.get('/en/businesses/hotel')
ld = json.loads(re.search(r'<script type="application/ld\+json">(.*?)</script>', html, re.S).group(1))['@graph']
place = next((n for n in ld if n.get('@type') == 'LocalBusiness'), None)
check('a business is a place to search engines too: its position, address, phone, email and price', place is not None and place['geo'] == {'@type': 'GeoCoordinates', 'latitude': 35.031, 'longitude': 26.0} and place['address']['streetAddress'] == 'Main street 1' and place['telephone'] == '+30 28430 11111' and place['email'] == 'info@example.test' and place['priceRange'] == '€€', place)
check('the page of a business has its information as the reference page does: type, area, address, phone, email, website, price', st == 200 and 'Business information' in html and 'Type:</strong> Hotels' in html and 'Address:</strong> Main street 1' in html and 'href="mailto:info@example.test"' in html and 'example.test</a>' in html and '€€' in html, st)
check('with a booking button in the title band', re.search(r'class="btn btn-primary" href="https://example.test/book"', html) is not None and 'Book now' in html)
check('and the route that passes by', 'The loop' in html.split('place-aside')[1])
check('a business page is not found under another type\'s address', anon.get('/en/points/hotel')[0] == 404)

# ---- the Map block, with content
def page(slug, *blocks):
    write('pages/%s.en.md' % slug, "---\ntitle: '%s'\nstatus: published\nvisible: true\nblocks: %s\n---\n" % (slug, json.dumps(list(blocks))))
    return anon.get('/en/' + slug)
def geo(html): return [json.loads(m) for m in re.findall(r'data-geo-data>(.*?)</script>', html, re.S)]
st, _, html = page('everything', {'type': 'map', 'source': 'all', 'heading': 'Around'})
d = geo(html)[0]
check('the Map block can show everything with a place: three kinds, with their counts', st == 200 and {t['id']: t['count'] for t in d['types']} == {'businesses': 17, 'points': 2, 'routes': 2}, d['types'])
check('and has a bar of filters, a list, and the block\'s assets', 'data-geo-filters' in html and 'geo-list' in html and re.search(r'_blocks\.js\?b=[^"]*map', html) is not None)
st, _, html = page('only-routes', {'type': 'map', 'source': 'routes', 'list': 'none', 'filters': False, 'height': 'tall'})
d = geo(html)[0]
check('one kind, with no list and no filters, as tall as asked', [t['id'] for t in d['types']] == ['routes'] and 'geo-list' not in html and 'data-geo-filters' not in html and 'geo-h-tall' in html and 'has-list-none' in html)
st, _, html = page('hotels-only', {'type': 'map', 'source': 'businesses', 'term': 'hotels'})
check('a category narrows it', [i['id'] for i in geo(html)[0]['items']] == ['businesses/hotel'], [i['id'] for i in geo(html)[0]['items']])
st, _, html = page('few', {'type': 'map', 'source': 'businesses', 'limit': 3})
check('and the number of entries can be limited', len(geo(html)[0]['items']) == 3)
st, _, html = page('none', {'type': 'map', 'source': 'businesses', 'term': 'no-such-category'})
check('a map with nothing to show leaves no section', 'block-map' not in html and st == 200)
st, _, html = page('one', {'type': 'map', 'lat': 35.2, 'lng': 26.27, 'heading': 'One place', 'address': 'Palaikastro'})
check('a map of one place typed in is as it was: a frame that asks, no Leaflet', 'data-map-src="https://www.openstreetmap.org/export/embed.html' in html and 'data-geo' not in html)
st, _, html = page('typed-site', {'type': 'map', 'source': 'routes', 'load': 'click'}, {'type': 'map', 'source': 'routes', 'load': 'auto'})
check('a block can choose when its map loads', re.findall(r'data-geo-load-mode="(\w+)"', html) == ['click', 'auto'], re.findall(r'data-geo-load-mode="(\w+)"', html))
st, _, edit = root.get('/admin/edit?type=pages&slug=about&lang=en')
defs = {d['type']: d for d in json.loads(re.search(r'id="block-editor-data"[^>]*>(.*?)</script>', edit, re.S).group(1))['definitions']}
mp = defs['map']
source = next(f for f in mp['fields'] if f['key'] == 'source')
check('the editor offers the Map block what to show: one place, or everything, or each kind', [o[0] for o in source['options']] == ['manual', 'all', 'businesses', 'points', 'routes'], source['options'])
check('and shows the fields that matter for each choice', next(f for f in mp['fields'] if f['key'] == 'lat')['when'] == {'source': ['manual']} and next(f for f in mp['fields'] if f['key'] == 'filters')['when'] == {'source': ['!manual']})

# ---- the list over the map
check('the editor knows which layouts fit which map: details beside one place, a list over many', mp['variant_when'] == {'split': {'source': ['manual']}, 'overlay': {'source': ['!manual']}} and [v[0] for v in next(f for f in mp['common'] if f['key'] == 'variant')['options']] == ['contained', 'full', 'split', 'overlay'], mp.get('variant_when'))
st, _, html = page('over', {'type': 'map', 'source': 'all', 'variant': 'overlay', 'list': 'right', 'height': 'tall'})
side = html.split('class="geo-side"')[1].split('</div>')[0] if 'class="geo-side"' in html else ''
check('a map with the list over it is as wide as the screen, with the list floating on the side chosen', 'is-overlay over-right' in html and 'map-geo-full' in html and '"overlay":"right"' in html and 'has-list-' not in html, re.findall(r'geo-layout [^"]*', html))
check('and the filters are in the list, not above the map', 'data-geo-filters' in html.split('class="geo-side"')[1].split('<ul class="geo-list"')[0], side[:200])
st, _, html = page('over-left', {'type': 'map', 'source': 'all', 'variant': 'overlay', 'list': 'left'})
check('on the left too', 'is-overlay over-left' in html and '"overlay":"left"' in html)
check('the editor is told that "below" is not a choice over a map (a layout-dependent choice), and the other choices stay', next(f for f in mp['fields'] if f['key'] == 'list').get('options_when') == {'below': {'variant': ['!overlay']}} and [o[0] for o in next(f for f in mp['fields'] if f['key'] == 'list')['options']] == ['right', 'left', 'below', 'none'])
st, _, html = page('over-below', {'type': 'map', 'source': 'all', 'variant': 'overlay', 'list': 'below'})
check('"below" makes no sense over a map, so the list is on the right', 'is-overlay over-right' in html)
st, _, html = page('over-none', {'type': 'map', 'source': 'all', 'variant': 'overlay', 'list': 'none'})
check('with no list there is nothing to float over the map', 'is-overlay' not in html and 'geo-side' not in html and '"overlay":""' in html)
st, _, html = page('split-many', {'type': 'map', 'source': 'routes', 'variant': 'split'})
check('details beside the map is the plain layout for a map of many places', 'block--contained' in html and 'block--split' not in html)
st, _, html = page('over-one', {'type': 'map', 'lat': 35.2, 'lng': 26.27, 'variant': 'overlay'})
check('and a list over the map is the plain layout for one place', 'block--contained' in html and 'data-geo ' not in html)

# ---- the maps' settings
st, _, html = root.get('/admin/settings?tab=apis')
check('Settings > APIs has the maps: when to load, the tiles and the credit', st == 200 and 'name="maps_load"' in html and 'name="maps_tiles_url"' in html and 'name="maps_attribution"' in html and 'OpenStreetMap' in html)
def settings_form(f): return any(x[0] == 'maps_load' for x in f['fields'])
root.submit('/admin/settings?tab=apis', settings_form, {'maps_load': 'auto', 'maps_tiles_url': 'http://insecure.example.com/{z}/{x}/{y}.png', 'maps_attribution': 'x'})
html = anon.get('/en/routes')[2]
check('tiles that are not https are not used, and "as soon as it comes into view" is what was chosen', 'data-geo-load-mode="auto"' in html and 'data-geo-load>' not in html and json.loads(re.search(r'data-geo-data>(.*?)</script>', html, re.S).group(1))['tiles']['url'].startswith('https://tile.openstreetmap.org'))
root.submit('/admin/settings?tab=apis', settings_form, {'maps_load': 'click', 'maps_tiles_url': 'https://tiles.example.com/{z}/{x}/{y}.png?key=1', 'maps_attribution': '© Example <a href="https://example.com">Example</a><script>x</script>'})
html = anon.get('/en/routes')[2]
tiles = json.loads(re.search(r'data-geo-data>(.*?)</script>', html, re.S).group(1))['tiles']
check('the site\'s own tiles and credit are what the map is given, the credit with links and text only', tiles['url'] == 'https://tiles.example.com/{z}/{x}/{y}.png?key=1' and '<script' not in tiles['attribution'] and '<a href="https://example.com" rel="noopener">Example</a>' in tiles['attribution'], tiles)
root.submit('/admin/settings?tab=apis', settings_form, {'maps_tiles_url': ''})
check('and empty goes back to OpenStreetMap', json.loads(re.search(r'data-geo-data>(.*?)</script>', anon.get('/en/routes')[2], re.S).group(1))['tiles']['url'].startswith('https://tile.openstreetmap.org'))

# ---- the editor
st, _, html = root.get('/admin/edit?type=routes&slug=loop&lang=en')
check('a route has a field that picks its file from the route files, and a position with a map button', st == 200 and re.search(r'name="details\[track\]"[^>]*data-image-field data-media-kind="track"', html) is not None and re.search(r'name="details\[location\]"[^>]*data-location-field', html) is not None, st)
check('the admin loads the script of the location field and knows where Leaflet is', 'admin-geo.js' in html and 'name="geo-leaflet"' in html)
def edit_form(f): return any(x[0] == 'title' for x in f['fields']) and any(x[0] == 'details[location]' for x in f['fields'])
root.submit('/admin/edit?type=points&slug=spring&lang=en', edit_form, {'details[location]': ' 35.20120 ,26.31 '})
check('a position is stored one way', "location: '35.2012, 26.31'" in open('app/content/points/spring.en.md', encoding='utf-8').read(), open('app/content/points/spring.en.md', encoding='utf-8').read()[:400])
root.submit('/admin/edit?type=points&slug=spring&lang=en', edit_form, {'details[location]': 'somewhere nice'})
saved = open('app/content/points/spring.en.md', encoding='utf-8').read()
check('and something that is not a position is not stored', 'somewhere nice' not in saved and 'location' not in saved.split('custom_fields')[1].split('---')[0], saved[:400])

# ---- the layout card in Theme > Archive Layouts
st, _, html = root.get('/admin/theme?tab=archive_layouts')
check('the archive layout cards offer the Map layout, and its height and list', st == 200 and 'value="map"' in html and 'data-map-only' in html and 'name="archive_types[routes][map_height]"' in html and 'name="archive_types[routes][map_list]"' in html, st)
def theme_form(f): return any(x[0] == 'active_tab' for x in f['fields'])
fields = [tuple(x) for x in next(f for f in root.forms('/admin/theme?tab=archive_layouts') if theme_form(f))['fields'] if x[0] not in ('archive_types[routes][map_height]', 'archive_types[routes][map_list]', 'active_tab')]
root.request('/admin/theme', data=fields + [('archive_types[routes][map_height]', 'large'), ('archive_types[routes][map_list]', 'over_left'), ('active_tab', 'archive_layouts')])
html = anon.get('/en/routes')[2]
check('an archive can have the list over the map: the map as wide as the screen, outside the page\'s margins', 'class="archive-map-full"' in html and 'is-overlay over-left' in html and html.index('archive-map-full') > html.index('archive-body') and 'class="site-wrap section-stack single-body archive-body"' in html and 'geo-layout' not in html.split('archive-map-full')[0], re.findall(r'geo-layout [^"]*|archive-map-full', html))
root.request('/admin/theme', data=fields + [('archive_types[routes][map_height]', 'tall'), ('archive_types[routes][map_list]', 'left'), ('active_tab', 'archive_layouts')])
html = anon.get('/en/routes')[2]
check('what is chosen is what the archive does', 'geo-h-tall' in html and 'has-list-left' in html, re.findall(r'geo-h-\w+|has-list-\w+', html))
root.request('/admin/theme', data=[tuple(x) for x in fields] + [('archive_types[routes][layout]', 'cards'), ('active_tab', 'archive_layouts')])
html = anon.get('/en/routes')[2]
check('and with another layout the routes are a list of cards again, with no map', 'data-geo ' not in html and 'The loop' in html)

# ---- the filters of the bar on the card
def card(html, type_): return html.split('id="archive-type-%s"' % type_)[1].split('</article>')[0]
c = card(root.get('/admin/theme?tab=archive_layouts')[2], 'businesses')
check('the card offers the taxonomies for the bar, the automatic ones ticked (tags too: the bar leaves out what no entry uses), with their number of terms', 'name="archive_types[businesses][map_filters][]" value="facility" checked' in c and 'value="categories" checked' in c and 'value="tags" checked' in c and 'name="archive_types[businesses][map_filters_auto]" value="categories,facility,tags"' in c and 'Facility <span class="text-[10px] font-normal text-slate-500">3</span>' in c, re.findall(r'map_filters_auto]" value="[^"]*"|value="\w+" checked', c))
def save_map_filters(*names):
    form = [tuple(x) for x in next(f for f in root.forms('/admin/theme?tab=archive_layouts') if theme_form(f))['fields'] if x[0] not in ('archive_types[businesses][map_filters][]', 'active_tab')]
    root.request('/admin/theme', data=form + [('archive_types[businesses][map_filters][]', n) for n in names] + [('active_tab', 'archive_layouts')])
save_map_filters('facility')
html = anon.get('/en/businesses')[2]
check('what is ticked is what the bar offers', [f['name'] for f in geo_data(html)['facets']] == ['facility'] and 'map_filters' in open('app/custom/content-types/businesses.yaml', encoding='utf-8').read(), [f['name'] for f in geo_data(html)['facets']])
check('and the form of the page follows it: the categories (a filter of the page, Filters on the card) stay in it, no longer covered by the bar', 'data-geo-covered="facility"' in html and 'name="filter[categories]"' in html and 'data-geo-covered="categories"' not in html)
save_map_filters('categories', 'facility', 'tags')
check('ticking exactly the automatic ones again stores nothing', 'map_filters' not in (open('app/custom/content-types/businesses.yaml', encoding='utf-8').read() if os.path.exists('app/custom/content-types/businesses.yaml') else ''))
check('and the bar has them again', [f['name'] for f in geo_data(anon.get('/en/businesses')[2])['facets']] == ['categories', 'facility'])

# ---- the data of the maps is kept, and made again whenever what it is made from changes
import glob, sqlite3, time
def ids(path, c=anon): return sorted(i['id'] for i in geo(c.get(path)[2])[0]['items'])
def aside(path, c=anon):
    html = c.get(path)[2]
    return html.split('place-aside')[1] if 'place-aside' in html else ''
def look_again():
    # A file edited outside the admin is seen within half a minute; the clock is moved instead of waiting.
    db = sqlite3.connect('app/storage/db/app.sqlite'); db.execute("update system_meta set value='0' where key='content_index_checked_at'"); db.commit(); db.close()
ids('/en/points'); aside('/en/businesses/hotel'); anon.get('/en/routes/loop')
kept = glob.glob('app/storage/cache/geo-data/*/*.ser')
check('what the maps work out is kept (the data of a map, and what is near what)', any('/data-' in f for f in kept) and any('/near-' in f for f in kept) and any('/single-' in f for f in kept), kept[:3])
before = anon.get('/en/businesses/hotel')[2]
check('and a second visit gives the same page', anon.get('/en/businesses/hotel')[2] == before)
def new_point(slug, title, location, status='published'):
    return root.submit('/admin/edit?type=points&slug=&lang=en', has_field('body'), {'title': title, 'slug': slug, 'status': status, 'visible': '1', 'body': 'Text.', 'details[location]': location})
new_point('fresh-pin', 'Fresh pin', '35.032, 26.001')
check('an entry saved in the admin is on the map of its type at once', 'points/fresh-pin' in ids('/en/points'), ids('/en/points'))
check('and in what is near it, on the page of a point that was cached', 'Fresh pin' in aside('/en/businesses/hotel') and 'Fresh pin' in aside('/en/routes/loop'))
check('and the Map block that shows everything', 'points/fresh-pin' in ids(('/en/everything')), ids('/en/everything'))
def retitle(f): return any(x[0] == 'title' for x in f['fields']) and any(x[0] == 'details[location]' for x in f['fields'])
root.submit('/admin/edit?type=points&slug=fresh-pin&lang=en', retitle, {'title': 'Renamed pin'})
check('a change of the title shows in the same places', 'Renamed pin' in aside('/en/businesses/hotel') and 'Fresh pin' not in aside('/en/businesses/hotel') and 'Renamed pin' in anon.get('/en/points')[2])
root.submit('/admin/edit?type=points&slug=fresh-pin&lang=en', retitle, {'details[location]': '36.2, 27.2'})
check('and a new position moves it: no longer near, and on the map where it is now', 'Renamed pin' not in aside('/en/businesses/hotel') and [i['lat'] for i in geo(anon.get('/en/points')[2])[0]['items'] if i['id'] == 'points/fresh-pin'] == [36.2])
time.sleep(1.1)  # a file of the same size saved in the same second is not told apart from the one before (the parsed copy goes by time and size)
root.submit('/admin/edit?type=points&slug=fresh-pin&lang=en', retitle, {'details[location]': '35.032, 26.001', 'status': 'draft'})
check('a draft is not on the maps of visitors', 'points/fresh-pin' not in ids('/en/points') and 'Renamed pin' not in aside('/en/businesses/hotel'))
check('but is for someone signed in, whose maps are kept apart', 'points/fresh-pin' in ids('/en/points', root) and 'Renamed pin' in aside('/en/businesses/hotel', root))
check('and the visitor is still not shown it afterwards', 'points/fresh-pin' not in ids('/en/points') and 'Renamed pin' not in aside('/en/businesses/hotel'))
# edited outside the admin (a copied folder, git): seen within half a minute
text = open('app/content/points/fresh-pin.en.md', encoding='utf-8').read()
open('app/content/points/fresh-pin.en.md', 'w', encoding='utf-8').write(text.replace('status: draft', 'status: published'))
os.utime('app/content/points/fresh-pin.en.md', (time.time() + 5, time.time() + 5))
look_again()
check('a file edited outside the admin is on the maps once the index looks at the files', 'points/fresh-pin' in ids('/en/points') and 'Renamed pin' in aside('/en/businesses/hotel'))
os.remove('app/content/points/fresh-pin.en.md')
look_again()
check('and one deleted outside the admin is gone from them', 'points/fresh-pin' not in ids('/en/points') and 'Renamed pin' not in aside('/en/businesses/hotel'))
# a route file replaced
loop_page = anon.get('/en/routes/loop')[2]
length = re.search(r'<dt>Length</dt><dd>([^<]+)</dd>', loop_page).group(1)
page('routes-map', {'type': 'map', 'source': 'routes'})
file = 'app' + '/public' + TRACK
bigger = [(35.00 + 0.06 * math.sin(a), 26.00 + 0.08 * math.cos(a), 100 + int(60 * math.sin(2 * a)) + 60) for a in [i * 2 * math.pi / 120 for i in range(121)]]
open(file, 'wb').write(gpx(bigger))
os.utime(file, (time.time() + 60, time.time() + 60))
loop_page = anon.get('/en/routes/loop')[2]
check('a route file replaced is read again: the page of the route shows the new length', re.search(r'<dt>Length</dt><dd>([^<]+)</dd>', loop_page).group(1) != length, length)
after = [x['facts'][0] for x in geo(page('routes-map', {'type': 'map', 'source': 'routes'})[2])[0]['items'] if x['id'] == 'routes/loop']
check('and so does a map of routes', after != [] and after[0] == re.search(r'<dt>Length</dt><dd>([^<]+)</dd>', loop_page).group(1), (after, length))

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
