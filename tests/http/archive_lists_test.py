import json, os, re, sqlite3, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
pub = Client()

def stored():
    c = sqlite3.connect('app/storage/db/app.sqlite')
    row = c.execute("select value from system_meta where key = 'theme_settings'").fetchone()
    c.close()
    return row[0] if row else ''

def write(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    open(path, 'w', encoding='utf-8').write(text)

def editor_data(html):
    return json.loads(re.search(r'<script type="application/json" id="block-editor-data">(.*?)</script>', html, re.S).group(1))

def save_cards(overrides):
    form = next(f for f in root.forms('/admin/theme?tab=archive_layouts') if any(x[0] == 'active_tab' for x in f['fields']))
    fields = [tuple(x) for x in form['fields'] if x[0] not in overrides and x[0] != 'active_tab']
    fields += [(k, v) for k, v in overrides.items()] + [('active_tab', 'archive_layouts')]
    return root.request('/admin/theme', data=fields)

def save_template(key, part, blocks, card_type=''):
    q = key.replace(':', '%3A')
    path = '/admin/theme?archive_template=' + q + '&part=' + part + ('&card_type=' + card_type if card_type else '')
    form = next(f for f in root.forms(path) if any(x[0] == 'blocks_json' for x in f['fields']))
    fields = [tuple(x) for x in form['fields'] if x[0] not in ('blocks_json', 'blocks_editor', 'use', 'part', 'card_type')]
    fields += [('part', part), ('card_type', card_type), ('blocks_editor', '1'), ('blocks_json', json.dumps(blocks)), ('use', '1')]
    return root.request('/admin/theme?archive_template=' + q, data=fields)

def cards(html):
    return len(re.findall(r'class="entry-card ', html))

# ---- types that exist only as files of data: one with a relation and a place, one with a place
write('app/custom/content-types/authors.yaml', "label: Authors\nfields: {}\n")
write('app/custom/content-types/walks.yaml', """label: { en: Walks }
singular: { en: Walk }
fields:
  guide: { type: relation, types: [authors], label: Guide, filterable: true }
  level: { type: select, label: Level, filterable: true, options: { easy: Easy, hard: Hard } }
  location: { type: location, label: Start }
archive:
  per_page: 4
  order: date_desc
  taxonomies: [categories]
""")
write('app/custom/content-types/cafes.yaml', """label: { en: Cafes }
singular: { en: Cafe }
fields:
  location: { type: location, label: Place }
""")
AUTH = {'ada': ('Ada', 'aaaaaaaa00000001', 'published'), 'bob': ('Bob', 'bbbbbbbb00000002', 'published'), 'cy': ('Cy', 'cccccccc00000003', 'draft')}
for slug, (title, ident, status) in AUTH.items():
    write('app/content/authors/%s.md' % slug, "---\ntitle: %s\nstatus: %s\ntranslation_id: %s\n---\n\nAbout %s\n" % (title, status, ident, title))
guides = ['aaaaaaaa00000001', 'bbbbbbbb00000002', 'cccccccc00000003', None]
for i in range(11):
    g = guides[i % 4]
    fields = ('  guide: %s\n' % g if g else '') + '  level: %s\n' % ('hard' if i % 3 == 0 else 'easy') + ('  location: "35.%02d, 26.%02d"\n' % (10 + i, 10 + i) if i != 5 else '')
    write('app/content/walks/w%02d.md' % i, "---\ntitle: Walk %02d\nstatus: published\ndate: '2026-04-%02d'\ncategories: [news]\ncustom_fields:\n%s---\n\nBody %d\n" % (i, i + 1, fields, i))
for i in range(3):
    write('app/content/cafes/c%d.md' % i, "---\ntitle: Cafe %d\nstatus: published\ndate: '2026-05-%02d'\ncategories: [news]\ncustom_fields:\n  location: \"36.%02d, 27.%02d\"\n---\n\nCafe\n" % (i, i + 1, i, i))

# ---- a relation as a filter, in the theme's own list
page = pub.get('/walks')[2]
check('a relation field with filterable: true is a filter naming the entries it points at (a draft guide is not offered)',
      'name="filter[guide]"' in page and '>Ada (' in page and '>Bob (' in page and 'Cy' not in page.split('filter[guide]')[1].split('</select>')[0], page[page.find('filter[guide]') - 100:page.find('filter[guide]') + 400])
ada = pub.get('/walks?filter[guide]=aaaaaaaa00000001')[2]
check('choosing a guide lists their walks, says so, and the page is not indexed (a plain GET address)', ada.count('latest-item') == 3 and 'noindex' in ada and re.search(r'>3 (result|results|αποτελέσματα)<', ada) is not None, ada.count('latest-item'))
check('a guide nobody may see is ignored', pub.get('/walks?filter[guide]=cccccccc00000003')[2].count('latest-item') == 4)

# ---- the type editor keeps "Filter" on a relation
st, _, html = root.get('/admin/content-types?type=walks')
check('the editor of a type offers the Filter chip on a relation too', 'data-only-kind="select relation"' in html and re.search(r'name="fields\[\d+\]\[filterable\]"', html) is not None, st)
form = next(f for f in root.forms('/admin/content-types?type=walks') if any(x[0] == 'fields[0][key]' for x in f['fields']))
root.request(form['attrs'].get('action') or '/admin/content-types?type=walks', data=[tuple(x) for x in form['fields']])
yaml_after = open('app/custom/content-types/walks.yaml', encoding='utf-8').read()
check('and saving the type with it ticked keeps it', re.search(r'guide:(?:(?!level:).)*filterable: true', yaml_after, re.S) is not None, yaml_after)

# ---- built: the same filters, a sort for the visitor, a map
save_cards({'archive_template[walks]': 'built', 'archive_template[taxonomy:categories]': 'built'})
built = pub.get('/walks')[2]
check('the Filters block offers the relation as well', 'block-filter-bar' in built and 'name="filter[guide]"' in built and 'name="filter[level]"' in built, built.count('filter[guide]'))
ada = pub.get('/walks?filter[guide]=aaaaaaaa00000001')[2]
check('and a guide\'s walks are listed, the page is not indexed', cards(ada) == 3 and 'noindex' in ada, cards(ada))
ada_both = pub.get('/walks?filter[guide]=bbbbbbbb00000002&filter[level]=easy')[2]
check('filters combine', 0 < cards(ada_both) <= 3 and 'noindex' in ada_both, cards(ada_both))

blocks = [{'type': 'archive-title'}, {'type': 'filter-bar'},
          {'type': 'entry-list', 'variant': 'grid', 'per_page': 4, 'order': 'date_desc', 'visitor_sort': True,
           'sort_choices': [{'order': 'title_asc'}, {'order': 'title_desc'}, {'order': 'nope'}]}]
save_template('walks', 'page', blocks)
sorted_page = pub.get('/walks')[2]
check('a list that lets visitors choose shows the choice, with the orders the template allows and not an order the list does not know', 'class="archive-sort"' in sorted_page and 'value="title_asc"' in sorted_page and 'value="title_desc"' in sorted_page and 'value="nope"' not in sorted_page and 'value="date_asc"' not in sorted_page, sorted_page.count('archive-sort'))
check('with no choice made the list is in its own order and is indexed', 'noindex' not in sorted_page and sorted_page.find('Walk 10') < sorted_page.find('Walk 09'))
desc = pub.get('/walks?sort=title_desc')[2]
asc = pub.get('/walks?sort=title_asc')[2]
check('the order a visitor chose is used', asc.find('Walk 00') < asc.find('Walk 01') and desc.find('Walk 10') < desc.find('Walk 09') and 'value="title_desc" selected' in desc, [asc.find('Walk 00'), desc.find('Walk 10')])
check('a page in another order is not indexed, and the page links keep the choice', 'noindex' in desc and 'sort=title_desc&amp;page=2' in desc, re.findall(r'href="(\?[^"]*)"', desc)[:4])
check('page two in that order carries on from page one', 'Walk 06' in pub.get('/walks?sort=title_desc&page=2')[2] and cards(pub.get('/walks?sort=title_desc&page=3')[2]) == 3)
check('an order that is not offered, or nonsense, is ignored', pub.get('/walks?sort=date_asc')[2].find('Walk 10') < pub.get('/walks?sort=date_asc')[2].find('Walk 09') and 'noindex' not in pub.get('/walks?sort[]=x')[2])
both = pub.get('/walks?filter[level]=hard&sort=title_asc')[2]
check('a choice of order keeps the filters (hidden fields of the form)', 'name="filter[level]" value="hard"' in both and cards(both) == 4 and both.find('Walk 00') < both.find('Walk 03'), cards(both))
editor = editor_data(root.get('/admin/theme?archive_template=walks')[2])
lst = next(d for d in editor['definitions'] if d['type'] == 'entry-list')
check('the editor of the list has the choice, and the orders of the type to name', any(f['key'] == 'visitor_sort' for f in lst['fields']) and any(f['key'] == 'sort_choices' for f in lst['fields']))

# a map of the list
blocks = [{'type': 'archive-title'}, {'type': 'filter-bar'}, {'type': 'entry-map', 'height': 'medium'},
          {'type': 'entry-list', 'variant': 'grid', 'per_page': 4, 'order': 'date_desc'}]
save_template('walks', 'page', blocks)
mp = pub.get('/walks')[2]
check('the Map block draws the map of the list, with the stylesheet and script of the map', 'data-geo' in mp and 'geo-h-medium' in mp and re.search(r'_blocks\.css\?b=[^"]*map', mp) is not None and re.search(r'_blocks\.js\?b=[^"]*map', mp) is not None)
data = json.loads(re.search(r'<script type="application/json" data-geo-data>(.*?)</script>', mp, re.S).group(1))
check('it holds every walk that has a place, not only those of the page (one walk has none)', len(data['items']) == 10 and cards(mp) == 4, [len(data['items']), cards(mp)])
hard = json.loads(re.search(r'<script type="application/json" data-geo-data>(.*?)</script>', pub.get('/walks?filter[level]=hard')[2], re.S).group(1))
check('and only those the filters leave', len(hard['items']) == 4 and all(i['title'] in ('Walk 00', 'Walk 03', 'Walk 06', 'Walk 09') for i in hard['items']), [i['title'] for i in hard['items']])
save_cards({'archive_template[authors]': 'built'})
save_template('authors', 'page', [{'type': 'entry-map'}, {'type': 'entry-list'}])
people = pub.get('/authors')[2]
check('a list with nothing to put on a map has none, and leaves no empty section', 'data-geo' not in people and 'block-entry-map' not in people and cards(people) == 2, [cards(people), 'data-geo' in people])
check('the theme\'s own map archive is as it was', 'block-entry-map' not in pub.get('/cafes')[2])

# ---- a card for each type, on the page of a term
save_template('taxonomy:categories', 'card', [{'type': 'text', 'heading': {'bind': 'title'}, 'eyebrow': 'GENERIC CARD'}])
save_template('taxonomy:categories', 'card', [{'type': 'text', 'heading': {'bind': 'title'}, 'eyebrow': 'CAFE CARD'}], 'cafes')
cat = pub.get('/category/news')[2]
check('on a page of several types a type with a card of its own draws it, the others the card for the rest', cat.count('CAFE CARD') == 3 and cat.count('GENERIC CARD') == cards(cat) - 3 and cards(cat) > 7, [cat.count('CAFE CARD'), cat.count('GENERIC CARD'), cards(cat)])
st, _, html = root.get('/admin/theme?archive_template=taxonomy%3Acategories&part=card')
check('the card screen offers a card for each content type the page can list, and says which have one', 'Card for' in html and 'card_type=cafes' in html and 'card_type=walks' in html and 'name="card_type"' in html and re.search(r'Cafes ●', html) is not None)
st, _, html = root.get('/admin/theme?archive_template=taxonomy%3Acategories&part=card&card_type=walks')
data = editor_data(html)
check('the card of one type is edited with the fields of that type', data['bindings']['type'] == 'walks' and 'custom_fields.guide.title' in [s['path'] for s in data['bindings']['sources']], data['bindings']['type'])
check('a list of one type has no card for each type', 'Card for' not in root.get('/admin/theme?archive_template=walks&part=card')[2])
# the preview marks the cards of the type being edited only
form = next(f for f in root.forms('/admin/theme?archive_template=taxonomy%3Acategories&part=card&card_type=cafes') if any(x[0] == 'blocks_json' for x in f['fields']))
fields = [tuple(x) for x in form['fields'] if x[0] not in ('blocks_json', 'blocks_editor')] + [('blocks_editor', '1'), ('blocks_json', json.dumps([{'type': 'text', 'eyebrow': 'UNSAVED CAFE'}]))]
st, _, body = root.request('/admin/theme?archive_template=taxonomy%3Acategories&preview=1', data=fields)
pv = json.loads(body)['html']
check('the preview of the card of a type draws it unsaved, and marks only the cards of that type', pv.count('UNSAVED CAFE') == 3 and pv.count('data-bk-block="0"') == 3 and 'UNSAVED' not in pub.get('/category/news')[2], [pv.count('UNSAVED CAFE'), pv.count('data-bk-block')])
# start again for a type, then the card of a type is removed by saving it empty
form = next(f for f in root.forms('/admin/theme?archive_template=taxonomy%3Acategories&part=card&card_type=cafes') if any(x[0] == 'blocks_json' for x in f['fields']))
root.request('/admin/theme?archive_template=taxonomy%3Acategories', data=[tuple(x) for x in form['fields'] if x[0] not in ('blocks_json', 'blocks_editor')] + [('start_again', '1')])
again = pub.get('/category/news')[2]
check('start again puts back the starting card of that type (the card of its own list)', 'CAFE CARD' not in again and cards(again) == cards(cat) and again.count('GENERIC CARD') == cards(cat) - 3)
save_template('taxonomy:categories', 'card', [], 'cafes')
check('and a card saved empty is the card for the others again', pub.get('/category/news')[2].count('GENERIC CARD') == cards(cat))

print()
if fails:
    print('FAILED:', fails); sys.exit(1)
print('ALL PASSED')
