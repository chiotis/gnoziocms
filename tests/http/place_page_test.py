"""The page of one place over HTTP (places and routes, phase 4): the subtitle under the title, the suggested season in the bar of a
route's facts, the services and packages of a business and the opening hours of a point in the information card (empty ones show
nothing, text is escaped and keeps its lines), the terms as chips linking to their pages grouped by taxonomy, the nearby lists under
headings by the top term of a taxonomy with a tree (in the tree's order, the rest under "Other"), the Single Layouts switches for
both, the fallback of a site with no taxonomies, and the height profile ready to be linked to the map (its data and script), with no
profile for a route without heights."""
import json, math, os, re, sys, urllib.request, urllib.error, uuid
sys.path.insert(0, '.')
from client import Client, BASE

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
def upload(c, name, data):
    b = uuid.uuid4().hex
    body = b''
    for k, v in (('_csrf', token(c)), ('media_action', 'upload'), ('upload_tags', '')):
        body += f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    body += f'--{b}\r\nContent-Disposition: form-data; name="upload_file[]"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + data + f'\r\n--{b}--\r\n'.encode()
    req = urllib.request.Request(BASE + '/admin/media', data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + b})
    try:
        c.opener.open(req).read()
    except urllib.error.HTTPError:
        pass
def tracks(): return [i['url'] for i in json.loads(root.get('/admin/media-picker?kind=track')[2])['items']]
def entry(type_, slug, title, fields, extra=''):
    lines = ''.join("  %s: %s\n" % (k, json.dumps(v, ensure_ascii=False)) for k, v in fields.items())
    write('%s/%s.en.md' % (type_, slug), "---\ntitle: '%s'\nstatus: published\nvisible: true\ndate: '2026-01-02'\n%scustom_fields:\n%s---\nBody of %s.\n" % (title, extra, lines, title))
def aside(html): return html.split('class="place-aside"')[1] if 'class="place-aside"' in html else ''
def hero(html): return html.split('class="place-hero')[1].split('</section>')[0] if 'class="place-hero' in html else ''
def card(html, kind): return aside(html).split('data-near-type="%s"' % kind)[1].split('</section>')[0] if 'data-near-type="%s"' % kind in html else ''
def headings(part): return re.findall(r'<h3 class="place-near-group"[^>]*>(?:<a href="[^"]*">)?([^<]+)', part)

# ---- a site with no taxonomies of its own, first: the pages are as they were
entry('businesses', 'inn', 'The inn', {'location': '35.0, 26.0', 'area': 'Sitia', 'services': '', 'opening_hours': ''})
entry('businesses', 'bakery', 'The bakery', {'location': '35.001, 26.001'})
st, _, html = anon.get('/en/businesses/inn')
check('without taxonomies: no chips, no group headings, one list of what is near', st == 200 and 'place-terms' not in html and 'place-near-group' not in html and 'The bakery' in card(html, 'businesses'), st)
check('empty fields show nothing: no services, no opening hours, no subtitle', 'Services' not in aside(html) and 'Opening hours' not in aside(html) and 'place-subtitle' not in html)

# ---- the taxonomies: one with a tree (the groups) and a flat facet
write('taxonomies/kinds.yaml', 'title: Kinds\nhierarchical: true\nterms:\n' + ''.join(
    '  - id: %s\n    slug: %s\n    labels:\n      en: %s\n%s' % (i, i, l, '    parent: %s\n' % p if p else '')
    for i, l, p in (('nature', 'Nature', ''), ('beaches', 'Beaches', ''), ('culture', 'Culture and history', ''),
                    ('gorges', 'Gorges', 'nature'), ('sandy', 'Sandy', 'beaches'), ('museums', 'Museums', 'culture'), ('folk', 'Folk museums', 'museums'))))
write('taxonomies/facets.yaml', 'title: Habitat\nterms:\n  - id: wetland\n    slug: wetland\n    labels:\n      en: Wetland\n')
write('taxonomies/tags.yaml', 'title: Tags\nterms:\n  - id: family\n    slug: family\n    labels:\n      en: Family\n')

entry('points', 'centre', 'The centre', {'location': '35.5, 26.5', 'subtitle': 'Where the gorge opens', 'opening_hours': 'Mon to Fri 9 to 5', 'area': 'Zakros'}, 'kinds: [gorges]\nfacets: [wetland]\ntags: [family]\n')
entry('points', 'near-gorge', 'A gorge nearby', {'location': '35.501, 26.5'}, 'kinds: [gorges]\n')
entry('points', 'near-nature', 'Plain nature', {'location': '35.503, 26.5'}, 'kinds: [nature]\n')
entry('points', 'near-beach', 'A beach', {'location': '35.502, 26.5'}, 'kinds: [sandy]\n')
entry('points', 'near-museum', 'A folk museum', {'location': '35.504, 26.5'}, 'kinds: [folk]\n')
entry('points', 'near-other', 'Something else', {'location': '35.505, 26.5'})

st, _, html = anon.get('/en/points/centre')
check('the subtitle is under the title, in the title band', st == 200 and re.search(r'class="place-title">The centre</h1>\s*<p class="place-subtitle">Where the gorge opens</p>', hero(html)) is not None, hero(html)[:600])
check('and is not a line of the information card', 'Subtitle' not in aside(html))
check('the opening hours of a point are in the information card', re.search(r'<strong>Opening hours:</strong> Mon to Fri 9 to 5', aside(html)) is not None)
terms = aside(html).split('place-terms')[1].split('</section>')[0] if 'place-terms' in aside(html) else ''
groups = re.findall(r'data-terms="([\w-]+)">\s*<dt>([^<]+)</dt>', terms)
check('the terms are chips grouped by taxonomy: its own taxonomies, then the tags', [g[0] for g in groups] == ['facets', 'kinds', 'tags'] and [g[1] for g in groups] == ['Habitat', 'Kinds', 'Tags'], groups)
chips = dict((label, href) for href, label in re.findall(r'<a class="place-chip" href="([^"]+)">([^<]+)</a>', terms))
check('each chip links to the page of its term (a child with its parent in the address)', chips.get('Gorges', '').endswith('/en/kinds/nature/gorges') and chips.get('Wetland', '').endswith('/en/facets/wetland') and chips.get('Family', '').endswith('/en/tag/family'), chips)
st2, _, term_page = anon.get(chips.get('Gorges', '/x').replace(BASE, ''))
check('and the page is there', st2 == 200 and 'The centre' in term_page, st2)

near = card(html, 'points')
check('the nearby points are under the top terms of the tree, in the tree\'s order, the rest last under "Other"', headings(near) == ['Nature', 'Beaches', 'Culture and history', 'Other'], headings(near))
parts = dict(zip(headings(near), re.split(r'<h3 class="place-near-group"', near)[1:]))
check('a point filed under a child goes under its top term, nearest first; one filed under the top term too', re.findall(r'place-near-title">([^<]+)', parts.get('Nature', '')) == ['A gorge nearby', 'Plain nature'], parts.get('Nature', '')[:400])
check('a grandchild goes under the top term as well', re.findall(r'place-near-title">([^<]+)', parts.get('Culture and history', '')) == ['A folk museum'])
check('one filed nowhere is under "Other", with no link', re.findall(r'place-near-title">([^<]+)', parts.get('Other', '')) == ['Something else'] and '<a href' not in parts.get('Other', '').split('</h3>')[0])
check('a group heading links to the page of its term', re.search(r'<h3 class="place-near-group" data-group="beaches"><a href="[^"]*/en/kinds/beaches">Beaches</a></h3>', near) is not None, near[:500])
check('the card still says what kind of entries it lists', '<h2 class="place-card-title">Places nearby</h2>' in near)

# ---- the Single Layouts switches
def theme_form(client):
    return next(f for f in client.forms('/admin/theme?tab=single_layouts') if any(x[0] == 'active_tab' for x in f['fields']))
def save_points(values):
    names = ['single_layouts[points][options][%s]' % k for k in values]
    fields = [tuple(x) for x in theme_form(root)['fields'] if x[0] not in names + ['active_tab']]
    sent = [('single_layouts[points][options][%s]' % k, v) for k, v in values.items() if v]
    return root.request('/admin/theme', data=fields + sent + [('active_tab', 'single_layouts')])
st, _, admin = root.get('/admin/theme?tab=single_layouts')
points_card = admin.split('aria-labelledby="single-points"')[1].split('</article>')[0] if 'aria-labelledby="single-points"' in admin else ''
check('the card of points has the two switches, on until changed', 'name="single_layouts[points][options][show_terms]"' in points_card and 'name="single_layouts[points][options][nearby_groups]"' in points_card and re.search(r'name="single_layouts\[points\]\[options\]\[nearby_groups\]"[^>]*checked', points_card) is not None, points_card[points_card.find('show_terms') - 200:points_card.find('show_terms') + 600])
save_points({'show_terms': '', 'nearby_groups': ''})
html = anon.get('/en/points/centre')[2]
check('switched off: no chips, and one list of the nearest places', 'place-terms' not in html and 'place-near-group' not in html and len(re.findall(r'place-near-title', card(html, 'points'))) == 5, re.findall(r'place-near-title">([^<]+)', card(html, 'points')))
save_points({'show_terms': '1', 'nearby_groups': '1'})
check('and on again', 'place-near-group' in anon.get('/en/points/centre')[2])

# ---- a business: services and packages
entry('businesses', 'farm', 'The farm', {'location': '35.6, 26.6', 'subtitle': 'Cheese since 1950', 'services': 'Tastings\nA shop <b>on site</b>', 'packages': 'A day on the farm', 'opening_hours': 'Every day'})
st, _, html = anon.get('/en/businesses/farm')
info = aside(html).split('place-info')[1].split('</section>')[0] if 'place-info' in aside(html) else ''
check('the services of a business are in the information card, one line each, escaped', re.search(r'<strong>Services</strong><span class="place-fact-text">Tastings<br />\s*A shop &lt;b&gt;on site&lt;/b&gt;</span>', info) is not None, info[:900])
check('and its packages, and its opening hours', '<strong>Packages</strong><span class="place-fact-text">A day on the farm</span>' in info and '<strong>Opening hours:</strong> Every day' in info)
check('the subtitle of a business is in its title band', '<p class="place-subtitle">Cheese since 1950</p>' in hero(html))
st, _, edit = root.get('/admin/edit?type=businesses&slug=farm&lang=en')
check('the editor of a business has the two text fields and the subtitle', st == 200 and 'name="details[services]"' in edit and 'name="details[packages]"' in edit and 'name="details[subtitle]"' in edit, st)
check('no group headings for businesses filed in no tree', 'place-near-group' not in card(anon.get('/en/businesses/inn')[2], 'businesses'))

# ---- a route: the season in its facts, and the profile ready for the map
def gpx(points, ele=True):
    pts = ''.join(('<trkpt lat="%.6f" lon="%.6f"><ele>%d</ele></trkpt>' % p) if ele else ('<trkpt lat="%.6f" lon="%.6f"/>' % p[:2]) for p in points)
    return ('<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg>%s</trkseg></trk></gpx>' % pts).encode()
LINE = [(35.7 + 0.001 * i, 26.7 + 0.001 * i, 100 + int(80 * math.sin(i / 10))) for i in range(80)]
def added(name, data):
    before = set(tracks())
    upload(root, name, data)
    return next(iter(set(tracks()) - before), '')
high = added('high.gpx', gpx(LINE))
flat = added('flat.gpx', gpx(LINE, ele=False))
files = tracks()
check('two route files, one with heights and one without', high != '' and flat != '', files)
entry('routes', 'climb', 'The climb', {'track': high, 'season': 'April to June', 'subtitle': 'Up the hill'})
entry('routes', 'level', 'The level walk', {'track': flat})
st, _, html = anon.get('/en/routes/climb')
stats = dict(re.findall(r'<dt>([^<]+)</dt><dd>([^<]+)</dd>', html))
check('the suggested season is in the bar of the facts, and not repeated in the card', st == 200 and stats.get('Best season') == 'April to June' and 'April to June' not in aside(html), stats)
check('the subtitle of a route is in its title band', '<p class="place-subtitle">Up the hill</p>' in hero(html))
fig = html.split('data-route-profile ')[1].split('</figure>')[0] if 'data-route-profile ' in html else ''
data = re.search(r'<script type="application/json" data-route-profile-data>(.*?)</script>', fig, re.S)
profile = json.loads(data.group(1)) if data else []
check('the profile carries its samples for the script, [km, m] from the start to the end', len(profile) > 2 and profile[0][0] == 0 and all(isinstance(p[1], int) for p in profile) and profile[-1][0] > profile[0][0], profile[:3])
check('the profile is a track the script can follow, with the drawing inside', 'data-route-profile-track' in fig and '<svg class="route-profile"' in fig and 'data-label-hint=' in html)
check('and the script that links it to the map comes with it', re.search(r'<script src="[^"]*/js/route-profile\.js[^"]*" defer></script>', html) is not None)
st, _, html = anon.get('/en/routes/level')
check('a route without heights has no profile and no script for it', st == 200 and 'data-route-profile' not in html and 'route-profile.js' not in html and 'route-profile' not in html, st)

print('\nALL PASSED' if not fails else '\n%d FAILED' % len(fails))
sys.exit(1 if fails else 0)
