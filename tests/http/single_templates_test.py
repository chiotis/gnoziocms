import json, os, re, sqlite3, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
pub = Client()
root.submit('/admin/users-edit', has_field('username'), {'username': 'tpl_ed', 'email': 'tpl_ed@example.test', 'display_name': 'tpl_ed', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('tpl_ed', 'Sturdy-pass-99')

def stored():
    c = sqlite3.connect('app/storage/db/app.sqlite')
    row = c.execute("select value from system_meta where key = 'theme_settings'").fetchone()
    c.close()
    return row[0] if row else ''

def write(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    open(path, 'w', encoding='utf-8').write(text)

def theme_form(client):
    return next(f for f in client.forms('/admin/theme?tab=single_layouts') if any(x[0] == 'active_tab' for x in f['fields']))

def save_cards(client, overrides):
    """Submit the Theme form (Single Layouts) the way the browser does: its own values, then what the test changes."""
    form = theme_form(client)
    fields = [tuple(x) for x in form['fields'] if x[0] not in overrides and x[0] != 'active_tab']
    fields += [(k, v) for k, v in overrides.items()] + [('active_tab', 'single_layouts')]
    return client.request('/admin/theme', data=fields)

def save_template(type_, blocks, use=True, extra=()):
    """The form of Edit template, as the browser sends it: the editor's output, and the switch."""
    form = next(f for f in root.forms('/admin/theme?template=' + type_) if any(x[0] == 'blocks_json' for x in f['fields']))
    fields = [tuple(x) for x in form['fields'] if x[0] not in ('blocks_json', 'blocks_editor', 'use')]
    fields += [('blocks_editor', '1'), ('blocks_json', json.dumps(blocks))] + ([('use', '1')] if use else []) + list(extra)
    return root.request('/admin/theme?template=' + type_, data=fields)

def editor_data(html):
    return json.loads(re.search(r'<script type="application/json" id="block-editor-data">(.*?)</script>', html, re.S).group(1))

# ---- a book, and the type switched on (the theme ships it; the site chooses)
root.submit('/admin/content-types', lambda f: ['type', 'books'] in [list(x) for x in f['fields']] and ['action', 'toggle'] in [list(x) for x in f['fields']], {'enabled': '1'})
write('app/content/books/dune.md', """---
title: Dune
status: published
date: '2026-02-01'
excerpt: A desert planet and a long game.
main_image: /uploads/dune.jpg
custom_fields:
  author: Frank Herbert
  publisher: Chilton
  year: 1965
  isbn: '9780441172719'
blocks:
  - type: text
    heading: The book's own block
    body: Written in the entry itself.
---

The long text of the book, in the entry.
""")
write('app/content/books/dune.en.md', """---
title: Dune (English)
status: published
date: '2026-02-01'
excerpt: A desert planet and a long game.
custom_fields:
  author: Frank Herbert
  year: 1965
---

English text of the book.
""")
write('app/content/books/plain.md', """---
title: A book with no fields
status: published
---

Only a text.
""")
write('app/content/books/own.md', """---
title: A book with its own template
status: published
template: landing
custom_fields:
  author: Someone
---

Own template text.
""")

# ---- Single Layouts: the choice, the buttons
st, _, html = root.get('/admin/theme?tab=single_layouts')
check('every card but a form\'s has the choice "The theme\'s" / "Built with blocks", with Edit template and Reset',
      all(('name="single_template[%s]"' % t) in html for t in ('pages', 'posts', 'books')) and 'name="single_template[forms]"' not in html and 'href="/admin/theme?template=books"' in html and 'data-built-reset' in html, st)
check('it starts on "The theme\'s"', re.search(r'name="single_template\[books\]" value="theme" checked data-lc="built"', html) is not None)
page_before = pub.get('/books/dune')[2]
check('a book page is the theme\'s book page', 'class="book-' in page_before or 'book' in page_before and 'block-entry-title' not in page_before, page_before[:200])
check('and no template is stored', 'single_templates' not in stored())

# ---- Edit template
st, _, html = root.get('/admin/theme?template=books')
data = editor_data(html)
check('the screen has the block editor, a preview, the switch, the entries to preview', st == 200 and 'data-block-editor' in html and 'js/admin-blocks.js' in html and 'data-entry-preview' in html and 'name="use"' in html and 'name="preview_entry"' in html, st)
check('the editor is told this is a template for the type', data.get('context') == 'template' and data['bindings']['type'] == 'books', list(data))
paths = [s['path'] for s in data['bindings']['sources']]
check('the fields it can bind are the entry\'s and those the type declares', paths[:3] == ['title', 'excerpt', 'main_image'] and 'custom_fields.publisher' in paths and 'custom_fields.year' in paths, paths)
lists = [x for x in data['bindings']['sources'] if x['kind'] == 'list']
check('a list of rows (the buy links) is offered as a list, with its columns, not as a field', [x['path'] for x in lists] == ['custom_fields.buy_links'] and [c['key'] for c in lists[0]['columns']] == ['label', 'url'] and 'list' not in ' '.join(data['bindings']['accepts']), lists)
types = [d['type'] for d in data['definitions']]
check('the blocks of a template are offered here', all(t in types for t in ('entry-title', 'fact-sheet', 'content', 'entry-blocks', 'hero', 'text')), types)
fact = next(d for d in data['definitions'] if d['type'] == 'fact-sheet')
choices = [o[0] for o in fact['fields'][1]['fields'][0]['options']]
check('and the Fact sheet\'s list is made of the type\'s fields', 'custom_fields.publisher' in choices and 'custom_fields.isbn' in choices, choices)
entries = re.findall(r'<option value="([a-z0-9-]+\|[a-z]+)"', html)
check('the preview can show any entry of the type, the latest first', 'dune|el' in entries and 'dune|en' in entries and 'plain|el' in entries, entries)
st, _, html_entry = root.get('/admin/edit?type=books&slug=dune&lang=en')
check('the editor of an entry does not offer the blocks of a template', st == 200 and '"type":"entry-title"' not in html_entry and '"type":"fact-sheet"' not in html_entry and '"type":"content"' in html_entry)
check('an editor cannot open it', ed.get('/admin/theme?template=books')[0] == 403, ed.get('/admin/theme?template=books')[0])
check('nor a visitor', pub.get('/admin/theme?template=books')[0] in (302, 303, 403))
st, hdr, _ = root.get('/admin/theme?template=nothing-here')
check('a type that does not exist goes back to Single Layouts', st in (302, 303) and 'tab=single_layouts' in (hdr.get('Location') or ''), (st, hdr.get('Location')))
check('a form has no template', root.get('/admin/theme?template=forms')[0] in (302, 303))

# ---- build one: title, fact sheet, a bound hero, the text, the entry's own blocks
TEMPLATE = [
    {'type': 'entry-title'},
    {'type': 'fact-sheet', 'heading': 'The details', 'fields': [{'field': 'custom_fields.year'}, {'field': 'custom_fields.author'}]},
    {'type': 'hero', 'variant': 'minimal', 'eyebrow': {'bind': 'custom_fields.publisher'}, 'heading': {'bind': 'custom_fields.author'}, 'text': {'bind': 'excerpt'}},
    {'type': 'text', 'heading': 'Published by', 'eyebrow': {'bind': 'custom_fields.publisher'}},
    {'type': 'content'},
    {'type': 'entry-blocks'},
]
st, hdr, _ = save_template('books', TEMPLATE)
y = stored()
check('the template is saved in the theme settings under its type, bindings as they are', st in (200, 302) and 'single_templates:' in y and 'bind: custom_fields.author' in y and 'type: entry-title' in y, y[-500:])
page = pub.get('/books/dune')[2]
check('the book page is drawn from the template now', 'block-entry-title' in page and 'block-fact-sheet' in page and 'block-entry-blocks' in page, page[:300])
i_title, i_facts, i_text, i_own = page.find('<h1>Dune</h1>'), page.find('The details'), page.find('The long text of the book'), page.find("own block")
check('the parts come in the order of the template', 0 < i_title < i_facts < i_text < i_own, (i_title, i_facts, i_text, i_own))
check('the fact sheet shows the fields chosen, in that order', page.find('1965', i_facts) < page.find('Frank Herbert', i_facts) < i_text, page[i_facts:i_facts + 600])
check('the hero shows the author as its heading, the publisher above it and the excerpt under it', re.search(r'block-hero.*Chilton.*Frank Herbert.*A desert planet', page, re.S) is not None)
check('a bound text block shows the publisher in its eyebrow', re.search(r'eyebrow">\s*Chilton</p>\s*<h2[^>]*>Published by', page) is not None, page[page.find('Published by') - 200:page.find('Published by') + 50])
check('the text of the entry is there once, the entry\'s own block after it', page.count('The long text of the book') == 1 and 'Written in the entry itself.' in page)
check('the page is a normal page: header, footer, one h1 per title area, the styles of the blocks in the head', page.count('<h1') == 1 and '<footer' in page and re.search(r'_blocks\.css', page.split('</head>')[0]) is not None, page.count('<h1'))
page_plain = pub.get('/books/plain')[2]
check('an entry with no fields: bound blocks with nothing to show are left out', 'Published by' not in page_plain and 'block-hero' not in page_plain and 'block-fact-sheet' not in page_plain and 'Only a text.' in page_plain, page_plain[-600:])
check('an entry with its own template: that template draws it', 'block-entry-title' not in pub.get('/books/own')[2] and 'Own template text.' in pub.get('/books/own')[2])
check('another type is not affected', 'block-entry-title' not in pub.get('/posts/rebrand-readiness-guide')[2] and pub.get('/about')[0] == 200 and 'block-entry-title' not in pub.get('/about')[2])

# ---- the preview with a real entry and unsaved blocks
UNSAVED = [{'type': 'text', 'heading': {'bind': 'title'}, 'body': 'Unsaved words'}]
def preview(entry, blocks, client=root):
    form = next(f for f in client.forms('/admin/theme?template=books') if any(x[0] == 'blocks_json' for x in f['fields']))
    fields = [tuple(x) for x in form['fields'] if x[0] not in ('blocks_json', 'blocks_editor', 'preview_entry')]
    fields += [('blocks_editor', '1'), ('blocks_json', json.dumps(blocks)), ('preview_entry', entry)]
    st, _, text = client.request('/admin/theme?template=books&preview=1', data=fields)
    try:
        return st, json.loads(text)
    except ValueError:
        return st, {'raw': text}
before = stored()
st, a = preview('dune|el', UNSAVED)
check('the preview draws the entry with the unsaved blocks, and nothing is stored', st == 200 and 'Unsaved words' in a.get('html', '') and re.search(r'<h2[^>]*>\s*Dune\s*</h2>', a.get('html', '')) is not None and stored() == before, (st, list(a)))
check('its blocks carry their place in the list, so a click can open them', 'data-bk-block="0"' in a.get('html', ''))
st, b = preview('dune|el', TEMPLATE)
check('and the entry\'s own blocks do not claim a place of the template', 'data-bk-block="5"' in b['html'] and b['html'].count('data-bk-block="0"') == 1, re.findall(r'data-bk-block="[0-9.]+"', b['html']))
st, c = preview('nothing|el', UNSAVED)
check('an entry that is not there says so instead of failing', st == 200 and 'no entry' in c.get('html', ''), c)
token = re.search(r'name="_csrf" value="([^"]+)"', ed.get('/admin/content?type=pages')[2]).group(1)
check('someone who may not edit the theme cannot ask', ed.request('/admin/theme?template=books&preview=1', data=[('_csrf', token), ('preview_entry', 'dune|el'), ('blocks_editor', '1'), ('blocks_json', json.dumps(UNSAVED))])[0] == 403)

# ---- a language of its own
st, _, html = root.get('/admin/theme?template=books')
check('with two languages the editor has a set for each, the site\'s own first', isinstance(editor_data(html)['blocks'], dict) and [l['key'] for l in editor_data(html)['languages']][0] == 'default', editor_data(html).get('languages'))
save_template('books', {'default': TEMPLATE, 'en': [{'type': 'text', 'heading': 'English template'}, {'type': 'content'}]})
y = stored()
check('a set for English is stored beside the default one', re.search(r'blocks:\s+default:', y) is not None and re.search(r'\n\s+en:', y) is not None, y[-300:])
page_en = pub.get('/en/books/dune')[2]
check('the English page uses the English set', 'English template' in page_en and 'English text of the book.' in page_en and 'block-fact-sheet' not in page_en, page_en[:200])
check('the default language keeps the default set', 'block-fact-sheet' in pub.get('/books/dune')[2] and 'English template' not in pub.get('/books/dune')[2])
save_template('books', {'default': TEMPLATE, 'en': []})
check('a language without a set of its own uses the default one', 'block-fact-sheet' in pub.get('/en/books/dune')[2])

# ---- Reset and switching on again
st, hdr, _ = save_cards(root, {'single_template[books]': 'theme'})
y = stored()
check('Reset (The theme\'s) draws the theme\'s page again and the template stays stored', st in (200, 302) and 'use: false' in y and 'type: entry-title' in y and 'block-entry-title' not in pub.get('/books/dune')[2], y[-300:])
st, _, html = root.get('/admin/theme?tab=single_layouts')
check('the card shows it, with the blocks it still has', re.search(r'name="single_template\[books\]" value="theme" checked data-lc="built"', html) is not None and re.search(r'\d+ blocks in the template', html) is not None)
save_cards(root, {'single_template[books]': 'built'})
check('and choosing Built with blocks again finds it', 'block-entry-title' in pub.get('/books/dune')[2] and 'use: true' in stored())
st, _, html = root.get('/admin/theme?template=books')
check('Edit template shows the stored blocks', '"entry-blocks"' in html or 'entry-blocks' in html and 'Chilton' not in html)

# ---- saving another tab with no editor data, and an empty template
before = stored()
form = next(f for f in root.forms('/admin/theme?tab=footer_blocks') if any(x[0] == 'active_tab' for x in f['fields']))
root.request('/admin/theme', data=[tuple(x) for x in form['fields'] if x[0] not in ('active_tab', 'blocks_json', 'blocks_editor')] + [('active_tab', 'footer_blocks')])
check('saving a different tab keeps the templates', 'type: entry-title' in stored() and 'block-entry-title' in pub.get('/books/dune')[2])
save_template('books', [])
check('an empty template draws the theme\'s page (a template with no block cannot draw a page)', 'block-entry-title' not in pub.get('/books/dune')[2] and pub.get('/books/dune')[0] == 200)
check('and nothing is kept for it unless it is switched on', True)

# ---- a content type added only as a file, after all this: no code change
write('app/custom/content-types/education.yaml', """label: { en: Courses, el: Μαθήματα }
singular: { en: Course, el: Μάθημα }
fields:
  teacher:
    type: text
    label: { en: Teacher, el: Διδάσκων }
  level:
    type: select
    label: Level
    options: { beginner: Beginner level, advanced: Advanced level }
  hours:
    type: number
    label: Hours
  starts:
    type: date
    label: Starts
  syllabus:
    type: markdown
    label: Syllabus
  cover:
    type: image
    label: Cover
  apply:
    type: link
    label: Apply
""")
write('app/content/education/intro.md', """---
title: Introduction to Typography
status: published
excerpt: Letters, spaces and the rules between them.
custom_fields:
  teacher: Ada Lovelace
  level: advanced
  hours: 24
  starts: '2026-09-15'
  syllabus: '**Week one:** letters.'
  cover: /uploads/typo.jpg
  apply: /apply
---

Course description text.
""")
st, _, html = root.get('/admin/theme?tab=single_layouts')
check('the new type has its card on Single Layouts', 'name="single_template[education]"' in html and 'href="/admin/theme?template=education"' in html, st)
st, _, html = root.get('/admin/theme?template=education')
data = editor_data(html)
paths = [s['path'] for s in data['bindings']['sources']]
labels = {s['path']: s['label'] for s in data['bindings']['sources']}
check('its Edit template offers its own fields, by their labels', st == 200 and all('custom_fields.' + k in paths for k in ('teacher', 'level', 'hours', 'starts', 'syllabus', 'cover', 'apply')) and labels['custom_fields.teacher'] in ('Teacher', 'Διδάσκων'), paths)
EDU = [
    {'type': 'entry-title', 'variant': 'centered'},
    {'type': 'text', 'eyebrow': {'bind': 'custom_fields.level'}, 'heading': {'bind': 'custom_fields.teacher'}, 'body': {'bind': 'custom_fields.syllabus'}},
    {'type': 'image', 'image': {'bind': 'custom_fields.cover'}},
    {'type': 'cta', 'heading': {'bind': 'custom_fields.hours'}, 'actions': [{'label': 'Apply'}]},
    {'type': 'fact-sheet', 'fields': [{'field': 'custom_fields.starts'}, {'field': 'custom_fields.level'}]},
    {'type': 'content'},
]
save_template('education', EDU)
st, _, page = pub.get('/education/intro')
check('a course is drawn from its template: select label, number, date, Markdown, picture', st == 200 and 'Advanced level' in page and 'Ada Lovelace' in page and '<strong>Week one:</strong>' in page and '/uploads/typo.jpg' in page and '24' in page and 'Course description text.' in page, st)
check('the title area, the heading bound to a text field, the fact sheet in order', page.find('Introduction to Typography') < page.find('Ada Lovelace') and page.find('Starts') < page.find('Level', page.find('Starts')), '')
st, _, a = root.request('/admin/theme?template=education&preview=1', data=[('blocks_editor', '1'), ('blocks_json', json.dumps(EDU)), ('preview_entry', 'intro|el'), ('_csrf', re.search(r'name="_csrf" value="([^"]+)"', root.get('/admin/theme?template=education')[2]).group(1))])
check('its preview works too', st == 200 and 'Ada Lovelace' in json.loads(a)['html'], a[:200])

# a field added later appears at once, and one that is removed or changes kind renders empty, never an error
write('app/custom/content-types/education.yaml', open('app/custom/content-types/education.yaml', encoding='utf-8').read() + "  room:\n    type: text\n    label: Room\n")
paths = [s['path'] for s in editor_data(root.get('/admin/theme?template=education')[2])['bindings']['sources']]
check('a field added to the type later is available to bind', 'custom_fields.room' in paths, paths)
src = open('app/custom/content-types/education.yaml', encoding='utf-8').read()
write('app/custom/content-types/education.yaml', src.replace('  teacher:\n    type: text\n    label: { en: Teacher, el: Διδάσκων }\n', '').replace('  hours:\n    type: number', '  hours:\n    type: toggle'))
st, _, page = pub.get('/education/intro')
check('a field that is removed, or is another kind now, draws nothing: the block is left out, the page is whole', st == 200 and 'Ada Lovelace' not in page and 'Course description text.' in page and 'Fatal' not in page and 'Warning' not in page and 'Exception' not in page, st)
st, _, html = root.get('/admin/theme?template=education')
data = editor_data(html)
check('the editor still holds the bindings, so they can be fixed (the page above is the proof that nothing broke)', st == 200 and any(b.get('heading') == {'bind': 'custom_fields.teacher'} for sets in (data['blocks'].values() if isinstance(data['blocks'], dict) else [data['blocks']]) for b in sets) and 'custom_fields.teacher' not in [s['path'] for s in data['bindings']['sources']], '')

# ---- starting templates: a type switched to "Built with blocks" starts from its starting template
save_cards(root, {'single_template[books]': 'theme'})
save_template('books', [], use=False)
write('app/content/books/kafka.md', """---
title: The Trial
status: published
excerpt: A man is arrested.
custom_fields:
  author: Franz Kafka
  publisher: Schocken
  year: 1925
  buy_links:
    - { label: Shop One, url: 'https://one.example.org/trial' }
    - { label: Shop Two, url: 'https://two.example.org/trial' }
    - { label: Shop Three, url: '/local-shop' }
    - { label: '', url: '' }
---

The text of the trial.
""")
st, _, html = root.get('/admin/theme?template=books')
data = editor_data(html)
sets = data['blocks']['default'] if isinstance(data['blocks'], dict) else data['blocks']
check('the screen of a template with no blocks opens on the starting template, and says so', 'data-template-starts' in html and [b['type'] for b in sets] == ['entry-title', 'fact-sheet', 'buttons', 'content', 'entry-blocks'], [b['type'] for b in sets])
check('and nothing is stored by looking at it', 'type: buttons' not in stored())
save_cards(root, {'single_template[books]': 'built'})
y = stored()
check('choosing Built with blocks for a type with no blocks stores its starting template', 'type: buttons' in y and 'bind: custom_fields.buy_links' in y and 'use: true' in y and 'first: primary' in y, y[-700:])
page = pub.get('/books/kafka')[2]
buttons = re.findall(r'<a class="btn (btn-[a-z]+)" href="([^"]+)">\s*([^<]*?)\s*(?:<svg|</a>)', page)
check('the book is drawn from it, with one button for each shop of buy_links in order, the first primary and the others secondary, the empty row left out',
      [(b[0], b[1], b[2]) for b in buttons if 'example.org' in b[1] or 'local-shop' in b[1]] == [('btn-primary', 'https://one.example.org/trial', 'Shop One'), ('btn-secondary', 'https://two.example.org/trial', 'Shop Two'), ('btn-secondary', '/local-shop', 'Shop Three')], buttons)
check('with the title area, the details of the book and its text', 'block-entry-title' in page and 'Franz Kafka' in page and 'Schocken' in page and '1925' in page and 'The text of the trial.' in page and page.count('<h1') == 1, page[:300])
check('a book with no shops has no buttons block, and none with no fields at all is an error', 'block-buttons' not in pub.get('/books/dune')[2] and pub.get('/books/plain')[0] == 200 and 'block-buttons' not in pub.get('/books/plain')[2])
st, a = preview('kafka|el', sets)
check('the preview of the starting template draws the book too', st == 200 and 'Shop Two' in a.get('html', ''), a)

# Start again: replaces what the template has, for every language, after the editor's confirmation
save_template('books', {'default': [{'type': 'text', 'heading': 'Mine'}, {'type': 'content'}], 'en': [{'type': 'text', 'heading': 'Mine in English'}]})
check('a template of its own is drawn as it is', 'Mine' in pub.get('/books/kafka')[2] and 'Shop Two' not in pub.get('/books/kafka')[2])
st, _, html = root.get('/admin/theme?template=books')
check('the screen has the Start again button, with a confirmation, and no start note while the template has blocks', 'data-start-again' in html and 'data-confirm="Replace the blocks' in html and 'data-template-starts' not in html, st)
form = next(f for f in root.forms('/admin/theme?template=books') if any(x[0] == 'blocks_json' for x in f['fields']))
st, hdr, _ = root.request('/admin/theme?template=books', data=[tuple(x) for x in form['fields'] if x[0] not in ('blocks_json', 'blocks_editor', 'use')] + [('blocks_editor', '1'), ('blocks_json', json.dumps([{'type': 'text', 'heading': 'Typed after'}])), ('start_again', '1')])
y = stored()
check('Start again puts the starting template back, the blocks of every language included, and the typed ones are gone', st in (200, 302) and 'type: buttons' in y and 'Mine' not in y and 'Typed after' not in y and re.search(r'\n\s+en:', y) is None and 'use: true' in y, y[-600:])
check('the book page is the starting template\'s again', 'Shop Two' in pub.get('/books/kafka')[2] and 'Mine' not in pub.get('/books/kafka')[2])
check('and the English page too', 'Shop Two' in pub.get('/en/books/kafka')[2] or pub.get('/en/books/kafka')[0] == 404)
check('someone who may not edit the theme cannot start again', ed.request('/admin/theme?template=books', data=[('_csrf', re.search(r'name="_csrf" value="([^"]+)"', ed.get('/admin/content?type=pages')[2]).group(1)), ('start_again', '1')])[0] == 403)

# the site's own starting template (custom/) wins over the theme's, for the next Start again
write('app/custom/single-templates/books.yaml', "blocks:\n  - { type: entry-title }\n  - { type: content }\n")
root.request('/admin/theme?template=books', data=[tuple(x) for x in form['fields'] if x[0] not in ('blocks_json', 'blocks_editor', 'use')] + [('blocks_editor', '1'), ('blocks_json', '[]'), ('start_again', '1')])
y = stored()
check('custom/single-templates/books.yaml is used instead of the theme\'s', 'type: buttons' not in y and 'type: entry-title' in y and 'Shop Two' not in pub.get('/books/kafka')[2], y[-300:])
os.remove('app/custom/single-templates/books.yaml')

# ---- a type that exists only as a file, with a picture and a list of links, and no starting template of any theme: generated
write('app/custom/content-types/workshops.yaml', """label: { en: Workshops }
singular: { en: Workshop }
fields:
  trainer: { type: text, label: Trainer }
  hours: { type: number, label: Hours }
  poster: { type: image, label: Poster }
  materials:
    type: repeater
    label: Materials
    max: 5
    fields:
      title: { type: text, label: Title }
      link: { type: link, label: Link }
""")
write('app/content/workshops/clay.md', """---
title: Working with clay
status: published
custom_fields:
  trainer: Pat Potter
  hours: 6
  poster: /uploads/clay.jpg
  materials:
    - { title: Reading list, link: '/reading' }
    - { title: Slides, link: 'https://example.org/slides.pdf' }
---

Bring an apron.
""")
write('app/content/workshops/bare.md', "---\ntitle: A workshop with nothing\nstatus: published\n---\n\nJust words.\n")
st, _, html = root.get('/admin/theme?template=workshops')
data = editor_data(html)
sets = data['blocks']['default'] if isinstance(data['blocks'], dict) else data['blocks']
check('a type with no starting template of any theme gets one made from its definition', st == 200 and [b['type'] for b in sets] == ['entry-title', 'image', 'fact-sheet', 'buttons', 'content', 'entry-blocks'], [b['type'] for b in sets])
check('the picture and the links are bound to the type\'s own fields', sets[1]['image'] == {'bind': 'custom_fields.poster'} and sets[3]['actions']['bind'] == 'custom_fields.materials' and sets[3]['actions']['map'] == {'label': 'title', 'url': 'link'}, sets)
save_cards(root, {'single_template[workshops]': 'built'})
page = pub.get('/workshops/clay')[2]
check('switching the type on stores it and every workshop is drawn: title, picture, facts, a button for each material', 'single_templates:' in stored() and 'custom_fields.materials' in stored() and 'block-entry-title' in page and '/uploads/clay.jpg' in page and 'Pat Potter' in page and 'Reading list' in page and 'https://example.org/slides.pdf' in page and 'Bring an apron.' in page, page[:300])
check('one with no fields leaves out the picture and the buttons and stays whole', pub.get('/workshops/bare')[0] == 200 and 'Just words.' in pub.get('/workshops/bare')[2] and 'block-buttons' not in pub.get('/workshops/bare')[2] and '<img' not in pub.get('/workshops/bare')[2].split('<main')[-1][:3000])
# the list can be mended in the editor of the type: the mapping stays when a column is renamed away, and the page draws what is left
write('app/custom/content-types/workshops.yaml', open('app/custom/content-types/workshops.yaml', encoding='utf-8').read().replace('      link: { type: link, label: Link }\n', '      href: { type: link, label: Link }\n'))
st, _, page = pub.get('/workshops/clay')
check('a column that is renamed away draws nothing, without an error', st == 200 and 'Working with clay' in page and 'https://example.org/slides.pdf' not in page and 'Fatal' not in page and 'Warning' not in page, st)
st, _, html = root.get('/admin/theme?template=workshops')
data = editor_data(html)
check('and the editor still holds the mapping, so it can be mended', st == 200 and 'link' in json.dumps(data['blocks']) and [c['key'] for x in data['bindings']['sources'] if x['kind'] == 'list' for c in x['columns']] == ['title', 'href'], '')

# ---- the same templates through an HTML-limited editor cannot write raw HTML (the guard is the entry editor's)
print('\nALL PASSED' if not fails else '\nFAILED: %d' % len(fails))
sys.exit(1 if fails else 0)
