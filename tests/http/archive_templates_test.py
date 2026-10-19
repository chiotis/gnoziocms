import json, os, re, sqlite3, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
pub = Client()
root.submit('/admin/users-edit', has_field('username'), {'username': 'arc_ed', 'email': 'arc_ed@example.test', 'display_name': 'arc_ed', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('arc_ed', 'Sturdy-pass-99')

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

def blocks_of(data):
    """The site's own set of blocks the editor starts from (a map of sets when the site has several languages)."""
    b = data['blocks']
    return b['default'] if isinstance(b, dict) else b

def theme_form(client):
    return next(f for f in client.forms('/admin/theme?tab=archive_layouts') if any(x[0] == 'active_tab' for x in f['fields']))

def save_cards(client, overrides):
    form = theme_form(client)
    fields = [tuple(x) for x in form['fields'] if x[0] not in overrides and x[0] != 'active_tab']
    fields += [(k, v) for k, v in overrides.items()] + [('active_tab', 'archive_layouts')]
    return client.request('/admin/theme', data=fields)

def save_template(key, part, blocks, use=True, extra=()):
    path = '/admin/theme?archive_template=' + key.replace(':', '%3A') + '&part=' + part
    form = next(f for f in root.forms(path) if any(x[0] == 'blocks_json' for x in f['fields']))
    fields = [tuple(x) for x in form['fields'] if x[0] not in ('blocks_json', 'blocks_editor', 'use', 'part')]
    fields += [('part', part), ('blocks_editor', '1'), ('blocks_json', json.dumps(blocks))] + ([('use', '1')] if use else []) + list(extra)
    return root.request('/admin/theme?archive_template=' + key.replace(':', '%3A'), data=fields)

# ---- a type that exists only as a file of data, with entries (no code knows it)
write('app/custom/content-types/recipes.yaml', """label: { en: Recipes, el: Συνταγές }
singular: { en: Recipe }
fields:
  course: { type: select, label: Course, filterable: true, options: { starter: Starter, main: Main, dessert: Dessert } }
  minutes: { type: number, label: Minutes }
  chef: { type: text, label: Chef }
archive:
  layout: cards
  columns: '3'
  per_page: 7
  order: date_desc
  taxonomies: [categories]
""")
courses = ['starter', 'main', 'dessert']
for i in range(23):
    cats = ['news'] if i % 2 == 0 else []
    chef = '<b>Chef %02d</b>' % i if i == 5 else 'Chef %02d' % i
    write('app/content/recipes/r%02d.md' % i, "---\ntitle: Recipe %02d\nstatus: published\ndate: '2026-03-%02d'\nexcerpt: Excerpt %02d\ncategories: %s\ncustom_fields:\n  course: %s\n  minutes: %d\n  chef: '%s'\n---\n\nBody %d\n" % (i, i + 1, i, json.dumps(cats), courses[i % 3], (i * 7) % 50 + 1, chef, i))
write('app/content/recipes/r00.en.md', "---\ntitle: Recipe English\nstatus: published\ncustom_fields:\n  course: main\n---\n\nEN\n")
write('app/content/recipes/draft.md', "---\ntitle: A draft recipe\nstatus: draft\ncustom_fields:\n  course: main\n---\n\nHidden\n")

# ---- Archive Layouts: the choice
st, _, html = root.get('/admin/theme?tab=archive_layouts')
check('every list but a form\'s and a page\'s has the choice "The theme\'s" / "Built with blocks", with Edit template and Reset (a type of data included, and each taxonomy)',
      all(('name="archive_template[%s]"' % k) in html for k in ('posts', 'recipes', 'taxonomy:categories', 'taxonomy:tags')) and 'name="archive_template[forms]"' not in html and 'name="archive_template[pages]"' not in html and 'href="/admin/theme?archive_template=recipes"' in html and 'data-built-reset' in html, st)
check('it starts on "The theme\'s"', re.search(r'name="archive_template\[recipes\]" value="theme" checked data-lc="built"', html) is not None)
before = pub.get('/recipes')[2]
check('the archive is the theme\'s', 'block-entry-list' not in before and 'archive-body' in before and before.count('class="latest-item') == 7, before[:200])
check('and no template is stored', 'archive_templates' not in stored())

# ---- Edit template: the page
st, _, html = root.get('/admin/theme?archive_template=recipes')
data = editor_data(html)
types = [d['type'] for d in data['definitions']]
check('the screen has the editor, the preview and the switch; the page part starts from the starting template',
      st == 200 and 'data-block-editor' in html and 'data-entry-preview' in html and 'name="use"' in html and 'data-template-starts' in html and data['context'] == 'template', st)
check('the blocks of a list are offered here, not those of the page of an entry', all(t in types for t in ('archive-title', 'filter-bar', 'result-count', 'entry-list', 'text', 'hero')) and 'fact-sheet' not in types and 'entry-title' not in types, types)
check('the page has no fields to bind (the card has)', 'bindings' not in data)
check('the starting page: title, filters, count, the list with the type\'s own settings', [b['type'] for b in blocks_of(data)] == ['archive-title', 'filter-bar', 'result-count', 'entry-list'] and blocks_of(data)[3]['per_page'] == 7 and blocks_of(data)[3].get('columns', '3') == '3', data['blocks'])
lst = next(d for d in data['definitions'] if d['type'] == 'entry-list')
orders = [o[0] for o in next(f for f in lst['fields'] if f['key'] == 'order')['options']]
check('the list offers the orders of the type, sorting by its fields included', 'title_asc' in orders and 'field:minutes:asc' in orders and 'field:chef:desc' in orders and 'field:course:asc' not in orders, orders)
st, _, html = root.get('/admin/theme?archive_template=recipes&part=card')
card = editor_data(html)
ctypes = [d['type'] for d in card['definitions']]
check('the card has the fields of the type to bind and offers neither the list nor the blocks of an entry', card['bindings']['type'] == 'recipes' and 'custom_fields.minutes' in [s['path'] for s in card['bindings']['sources']] and 'entry-list' not in ctypes and 'archive-title' not in ctypes and 'entry-title' not in ctypes and 'text' in ctypes, ctypes)
check('an editor cannot open it', ed.get('/admin/theme?archive_template=recipes')[0] == 403)
check('a list that is not there, and a form, go back to the cards', [root.get('/admin/theme?archive_template=forms')[0], root.get('/admin/theme?archive_template=nope')[0]] == [302, 302])

# ---- switching a list to its template from the card gives it the starting template
save_cards(root, {'archive_template[recipes]': 'built'})
s = stored()
check('choosing "Built with blocks" stores the starting page and card, switched on', 'archive_templates' in s and 'recipes' in s and 'entry-list' in s and 'archive-title' in s and 'use: true' in s, s[-400:])
page1 = pub.get('/recipes')[2]
cards = page1.count('class="entry-card ')
check('the archive is drawn from the template: the blocks, the list with the cards of the template', 'block-archive-title' in page1 and 'block-filter-bar' in page1 and 'block-entry-list' in page1 and cards == 7 and 'latest-item' not in page1, [cards, 'block-entry-list' in page1])
check('with the list\'s own title, the filters of the type, and page links', '<h1>Recipes' in page1 and 'filter[course]' in page1 and 'aria-current="page"' in page1 and 'rel="next"' in page1)
check('each card links to its entry (one link, the title is its text)', re.search(r'class="entry-card-link" href="/recipes/r\d\d"><span class="visually-hidden">Recipe \d\d</span>', page1) is not None, page1[page1.find('entry-card-link') - 80:page1.find('entry-card-link') + 150])
check('a draft is not listed, and the order is newest first', 'A draft recipe' not in page1 and page1.find('Recipe 22') < page1.find('Recipe 21'))
page2 = pub.get('/recipes?page=2')[2]
check('the second page has its own entries, a canonical address of its own, and is indexed', 'Recipe 15' in page2 and 'Recipe 22' not in page2 and 'canonical" href="http://127.0.0.1' in page2 and '/recipes?page=2' in page2 and 'noindex' not in page2)
filtered = pub.get('/recipes?filter[course]=main')[2]
check('a filter narrows the list, says so, and the page is not indexed', filtered.count('class="entry-card ') == 7 and 'noindex' in filtered and 'archive-count' in filtered and re.search(r'>8 (results|αποτελέσματα)<', filtered) is not None, [filtered.count('class="entry-card '), re.search(r'>8 (results|αποτελέσματα)<', filtered) is not None])
check('filters can be combined, and a value nobody has is ignored', 'Recipe' in pub.get('/recipes?filter[course]=main&filter[categories]=news')[2] and pub.get('/recipes?filter[course]=nope')[2].count('class="entry-card ') == 7)
check('the other language lists its own entries', pub.get('/en/recipes')[2].count('class="entry-card ') == 1)

# ---- the template is edited: size, order, columns of the list, and a card made of blocks
blocks = [{'type': 'archive-title', 'variant': 'centered'}, {'type': 'result-count', 'always': True},
          {'type': 'entry-list', 'variant': 'grid', 'columns': '2', 'per_page': 4, 'order': 'field:minutes:asc'},
          {'type': 'text', 'heading': 'Below the list', 'body': 'A block around the list.'}]
cardblocks = [{'type': 'text', 'eyebrow': {'bind': 'custom_fields.chef'}, 'heading': {'bind': 'custom_fields.minutes'}}]
st, _, _ = save_template('recipes', 'page', blocks)
st2, _, _ = save_template('recipes', 'card', cardblocks)
check('saving the page and then the card redirects back with a message', st in (302, 303) and st2 in (302, 303), [st, st2])
page = pub.get('/recipes')[2]
check('the list block\'s size is the size of a page, and the result count is shown', page.count('class="entry-card ') == 4 and 'cols-2' in page and re.search(r'>23 (results|αποτελέσματα)<', page) is not None, [page.count('class="entry-card '), re.search(r'>23 (results|αποτελέσματα)<', page) is not None])
nums = [int(m) for m in re.findall(r'<h2 class="section-title" id="[^"]+">(\d+)</h2>', page)]
check('the order is the list block\'s (by minutes, smallest first) and the card shows the fields it is bound to', nums == sorted(nums) and len(nums) == 4 and 'Chef ' in page, nums)
check('a block around the list is drawn', 'Below the list' in page and page.find('Below the list') > page.find('block-entry-list'))
everything = ''.join(pub.get('/recipes?page=%d' % n)[2] for n in range(1, 7))
check('a bound text is escaped like any other', 'Chef 05' in everything and '<b>Chef 05' not in everything, everything.count('Chef 05'))
ids = re.findall(r' id="(card-[^"]+)"', pub.get('/recipes')[2])
check('the cards of a page have ids of their own (no two the same)', len(ids) == len(set(ids)) and len(ids) >= 4, ids[:6])
check('pages count from the new size', pub.get('/recipes?page=6')[2].count('class="entry-card ') == 3 and pub.get('/recipes?page=99')[2].count('class="entry-card ') == 3)

# ---- the live preview
fields = [tuple(x) for x in next(f for f in root.forms('/admin/theme?archive_template=recipes&part=card') if any(x[0] == 'blocks_json' for x in f['fields']))['fields'] if x[0] not in ('blocks_json', 'blocks_editor')]
unsaved = [{'type': 'text', 'heading': {'bind': 'title'}, 'eyebrow': 'UNSAVED CARD'}]
st, _, body = root.request('/admin/theme?archive_template=recipes&preview=1', data=fields + [('blocks_editor', '1'), ('blocks_json', json.dumps(unsaved))])
pv = json.loads(body)['html'] if st == 200 else ''
check('the card preview draws the real list with the unsaved card, its blocks marked for the editor', st == 200 and 'UNSAVED CARD' in pv and 'data-bk-block="0"' in pv and 'UNSAVED CARD' not in pub.get('/recipes')[2], st)
fields_p = [tuple(x) for x in next(f for f in root.forms('/admin/theme?archive_template=recipes') if any(x[0] == 'blocks_json' for x in f['fields']))['fields'] if x[0] not in ('blocks_json', 'blocks_editor')]
st, _, body = root.request('/admin/theme?archive_template=recipes&preview=1', data=fields_p + [('blocks_editor', '1'), ('blocks_json', json.dumps([{'type': 'text', 'heading': 'UNSAVED PAGE'}, {'type': 'entry-list'}]))])
pv = json.loads(body)['html']
check('the page preview draws the unsaved page; only the page\'s blocks are marked, the cards\' are not', 'UNSAVED PAGE' in pv and 'data-bk-block="0"' in pv and pv.count('data-bk-block="0"') == 1 and 'data-bk-block="1"' in pv, pv.count('data-bk-block'))
check('nothing was stored by previewing', 'UNSAVED' not in stored())

# ---- a category page, and the page of a term built with blocks
save_cards(root, {'archive_template[taxonomy:categories]': 'built'})
cat = pub.get('/category/news')[2]
check('a term page is drawn from its template: its title, the entries filed under it, the cards', 'block-entry-list' in cat and '<h1>' in cat and 'Recipe' in cat and cat.count('class="entry-card ') > 0 and 'entry-card-link' in cat, cat.count('entry-card'))
check('and a card on a page of several types shows what every entry has', 'Recipe 00' in pub.get('/category/news?page=1')[2] or 'Recipe 22' in pub.get('/category/news')[2])
st, _, html = root.get('/admin/theme?archive_template=taxonomy%3Acategories&part=card')
tc = editor_data(html)
check('the card of a term page offers the fields every entry has', [s['path'] for s in tc['bindings']['sources']][:3] == ['title', 'excerpt', 'main_image'] and 'custom_fields.minutes' not in [s['path'] for s in tc['bindings']['sources']], [s['path'] for s in tc['bindings']['sources']])
check('and a term can be previewed', 'name="preview_term"' in html and 'value="news"' in html)

# ---- Reset: the theme's again, the blocks stay
save_cards(root, {'archive_template[recipes]': 'theme', 'archive_template[taxonomy:categories]': 'theme'})
after = pub.get('/recipes')[2]
check('Reset draws the list with the theme\'s template again', 'block-entry-list' not in after and after.count('class="latest-item') == 7, after.count('class="latest-item'))
check('and the blocks are still stored, so choosing the template brings them back', 'Below the list' in stored() and 'taxonomy:categories' in stored())
save_cards(root, {'archive_template[recipes]': 'built'})
check('built again: the saved page returns, not the starting one', 'Below the list' in pub.get('/recipes')[2])

# ---- start again from the default (the part shown only)
form = next(f for f in root.forms('/admin/theme?archive_template=recipes&part=page') if any(x[0] == 'blocks_json' for x in f['fields']))
fields = [tuple(x) for x in form['fields'] if x[0] not in ('blocks_json', 'blocks_editor')] + [('start_again', '1')]
root.request('/admin/theme?archive_template=recipes', data=fields)
again = pub.get('/recipes')[2]
check('"Start again" puts the starting page back and keeps the card', 'Below the list' not in again and again.count('class="entry-card ') == 7 and 'Chef' in again, again.count('class="entry-card '))

# ---- the theme's own types come with a template of their own
write('app/content/posts/hello-arc.md', "---\ntitle: Hello archives\nstatus: published\ndate: '2026-05-01'\nexcerpt: A post.\n---\n\nText\n")
save_cards(root, {'archive_template[posts]': 'built'})
check('a type of the theme starts from the page and the generated card, and lists its entries', pub.get('/posts')[2].count('class="entry-card ') >= 1 and 'Hello archives' in pub.get('/posts')[2])
root.get('/admin/theme?archive_template=posts&part=card')
st, _, html = root.get('/admin/theme?archive_template=posts&part=card')
check('its card starts from the main image and the title', [b['type'] for b in blocks_of(editor_data(html))] == ['image', 'text'])

print()
if fails:
    print('FAILED:', fails); sys.exit(1)
print('ALL PASSED')
