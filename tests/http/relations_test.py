import os, re, sqlite3, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

# Two types that exist only as files in custom/content-types: no code knows about them.
os.makedirs('app/custom/content-types', exist_ok=True)
open('app/custom/content-types/authors.yaml', 'w', encoding='utf-8').write("label: Authors\nsingular: Author\nfields:\n  country: {type: text, label: Country}\n")
open('app/custom/content-types/volumes.yaml', 'w', encoding='utf-8').write('''label: Volumes
singular: Volume
fields:
  author: {type: relation, types: [authors], label: Author}
  others: {type: relation, types: [authors], multiple: true, max: 2, label: Also by}
  editions:
    type: repeater
    label: Editions
    item_label: Edition
    fields:
      year: {type: number, label: Year}
      editor: {type: relation, types: [authors], label: Editor}
''')
os.makedirs('app/content/authors', exist_ok=True)
os.makedirs('app/content/volumes', exist_ok=True)

root = Client(); root.login()
pub = Client()

def save(type_, slug, fields, pick=None):
    st, hdr, _ = root.submit('/admin/edit?type=%s&slug=%s&lang=el' % (type_, slug), pick or has_field('body'), fields)
    m = re.search(r'slug=([^&]+)', hdr.get('Location') or '')
    return st, m.group(1) if m else None

base = {'status': 'published', 'visible': '1', 'body': 'Text.'}
save('authors', '', dict(base, title='Ada Lovelace', slug='ada-lovelace'))
save('authors', '', dict(base, title='Charles Babbage', slug='charles-babbage'))
check('the authors exist', os.path.exists('app/content/authors/ada-lovelace.md') and os.path.exists('app/content/authors/charles-babbage.md'))
ada = open('app/content/authors/ada-lovelace.md', encoding='utf-8').read()
ada_id = (re.search(r'translation_id: (\w+)', ada) or [None, ''])[1]
check('an entry made in the editor has a stable id', len(ada_id) == 16, ada)

# ---- the editor offers the entries
save('volumes', '', dict(base, title='Notes', slug='notes'))
st, _, html = root.get('/admin/edit?type=volumes&slug=notes&lang=el')
check('a relation is a search box with chips, that may point at the types its field names', st == 200 and re.search(r'<div class="rel" data-relation data-types="authors" data-multiple="0" data-max="0" data-url="/admin/relations">', html) is not None and 'data-rel-search' in html, html[:200])
check('a field that takes several is marked, with its limit', re.search(r'data-types="authors" data-multiple="1" data-max="2"', html) is not None and 'Up to 2.' in html)
check('a relation inside a row of a list is in the row template, and keeps its name for the script', re.search(r'name="details\[editions\]\[__INDEX__\]\[editor\]" value="" disabled data-rel-name', html) is not None)
check('the script of the picker is loaded', 'admin-relations.js' in html)
st, hdr, body = root.get('/admin/relations?q=ada&types=authors')
rows = __import__('json').loads(body) if st == 200 else []
check('the search answers with the entries of that type whose title has the text', [r['title'] for r in rows] == ['Ada Lovelace'] and rows[0]['value'] == ada_id and rows[0]['type'] == 'authors' and rows[0]['edit_url'].startswith('/admin/edit?'), body[:300])
check('a type the field does not name is not offered', __import__('json').loads(root.get('/admin/relations?q=notes&types=authors')[2]) == [])
check('with no text it lists the first entries by title', [r['title'] for r in __import__('json').loads(root.get('/admin/relations?types=authors')[2])] == ['Ada Lovelace', 'Charles Babbage'])
check('a visitor gets no answer', 'Ada' not in pub.get('/admin/relations?q=ada&types=authors')[2])

def volume_form(f): return any(x[0] == 'details[author]' for x in f['fields'])
st, _ = save('volumes', 'notes', dict(base, title='Notes', **{'details[author]': ada_id, 'details[others][]': ['authors/charles-babbage'], 'details[editions][0][year]': '1843', 'details[editions][0][editor]': 'authors/charles-babbage', 'details[editions][1][year]': '1850', 'details[editions][1][editor]': ''}), volume_form)
stored = open('app/content/volumes/notes.md', encoding='utf-8').read()
babbage = open('app/content/authors/charles-babbage.md', encoding='utf-8').read()
babbage_id = (re.search(r'translation_id: (\w+)', babbage) or [None, ''])[1]
check('what is stored is the id of the author, not an address', ('author: ' + ada_id) in stored and 'authors/' not in stored.split('---')[1], stored)
check('an address typed in the form became the id of that entry, also in a list and in a row of a list', stored.count(babbage_id) == 2 and len(babbage_id) == 16, stored)
st, _, html = root.get('/admin/edit?type=volumes&slug=notes&lang=el')
check('the editor shows the chosen entries as chips that hold their ids', re.search(r'<li class="rel-chip" data-rel-chip>\s*<input type="hidden" name="details\[author\]" value="%s">\s*<span class="rel-title">Ada Lovelace' % ada_id, html) is not None and re.search(r'name="details\[others\]\[\]" value="%s"' % babbage_id, html) is not None, re.findall(r'<li class="rel-chip.*?</li>', html, re.S)[:1])

# ---- the public page
st, _, html = pub.get('/volumes/notes')
check('the page of the volume names its author with a link', st == 200 and re.search(r'<a href="/authors/ada-lovelace">Ada Lovelace</a>', html) is not None, html[html.find('type-fields') - 50:html.find('type-fields') + 900] if 'type-fields' in html else st)
check('and the others, and the editor in the row', html.count('/authors/charles-babbage') >= 2)

# ---- a new address
st, _ = save('authors', 'ada-lovelace', dict(base, title='Ada Lovelace', slug='ada-king', original_slug='ada-lovelace', original_lang='el'), lambda f: any(x[0] == 'details[country]' for x in f['fields']))
check('the author was moved to a new address', os.path.exists('app/content/authors/ada-king.md') and not os.path.exists('app/content/authors/ada-lovelace.md'))
st, _, html = pub.get('/volumes/notes')
check('the volume still points at her: the link follows the new address', '<a href="/authors/ada-king">Ada Lovelace</a>' in html and '/authors/ada-lovelace"' not in html, st)
check('and the file of the volume was not touched', open('app/content/volumes/notes.md', encoding='utf-8').read() == stored)

# ---- search finds the volume by the title of its author
st, _, html = pub.get('/search?q=lovelace')
check('search finds a volume by the title of its author (its Author field)', st == 200 and 'href="/volumes/notes"' in html and 'href="/authors/ada-king"' in html, st)

# ---- unpublished
text = open('app/content/authors/ada-king.md', encoding='utf-8').read()
open('app/content/authors/ada-king.md', 'w', encoding='utf-8').write(text.replace('status: published', 'status: draft'))
def look_again():
    # A file edited outside the admin is seen within half a minute; here the clock is moved instead of waiting.
    c = sqlite3.connect('app/storage/db/app.sqlite'); c.execute("update system_meta set value='0' where key='content_index_checked_at'"); c.commit(); c.close()
import time
os.utime('app/content/authors/ada-king.md', (time.time() + 10, time.time() + 10))
look_again()
st, _, html = pub.get('/volumes/notes')
check('an author who is a draft is not shown to visitors', st == 200 and 'Ada Lovelace' not in html and 'Charles Babbage' in html, st)
check('search no longer finds the volume by a draft author\'s title for visitors, but still does for someone signed in', 'href="/volumes/notes"' not in pub.get('/search?q=lovelace')[2] and 'href="/volumes/notes"' in root.get('/search?q=lovelace')[2])
st, _, html = root.get('/admin/edit?type=volumes&slug=notes&lang=el')
check('but the editor still holds the reference, its chip marked as a draft', re.search(r'<li class="rel-chip is-hidden" data-rel-chip>\s*<input type="hidden" name="details\[author\]" value="%s">.*?rel-state">draft' % ada_id, html, re.S) is not None, re.findall(r'<li class="rel-chip.*?</li>', html, re.S)[:1])
st, _, html = root.get('/admin/edit?type=authors&slug=ada-king&lang=el')
check('the author\'s own page lists who points at it, and says it is not shown while a draft', 'data-tab="references"' in html and 'Notes' in html.split('data-panel="references"')[1] and 'is not published' in html and 'author, ' in html or 'author' in html.split('data-panel="references"')[1], html[html.find('data-panel="references"'):][:400])
st, _, html = root.get('/admin/relations?q=ada&types=authors')
check('a draft is offered, marked hidden', '"hidden":true' in html and '"status":"draft"' in html, html[:200])

# ---- an id made only of digits is still an id (it must not turn into a number anywhere)
open('app/content/authors/digits.md', 'w', encoding='utf-8').write("---\ntitle: Digits\nstatus: published\ntranslation_id: '4444444444444444'\n---\n\nx\n")
open('app/content/volumes/digit-vol.md', 'w', encoding='utf-8').write("---\ntitle: Digit volume\nstatus: published\ncustom_fields:\n  author: '4444444444444444'\n---\n\nx\n")
look_again()
st, _, html = root.get('/admin/edit?type=volumes&slug=digit-vol&lang=el')
check('an id of digits only is a chip with its title in the editor', re.search(r'name="details\[author\]" value="4444444444444444">\s*<span class="rel-title">Digits', html) is not None, re.findall(r'<li class="rel-chip.*?</li>', html, re.S)[:1])
check('and the page links it', '<a href="/authors/digits">Digits</a>' in pub.get('/volumes/digit-vol')[2])

# ---- deleting what is pointed at
open('app/content/authors/ada-king.md', 'w', encoding='utf-8').write(text)
os.utime('app/content/authors/ada-king.md', (time.time() + 20, time.time() + 20))
look_again()
st, _, html = root.get('/admin/delete?type=authors&slug=ada-king&lang=el')
check('the page that asks before deleting says who points at the entry', st == 200 and 'point' in html and 'will lead nowhere' in html and 'Notes' in html, re.sub(r'\s+', ' ', re.sub(r'<svg.*?</svg>|<script.*?</script>|<style.*?</style>', '', html, flags=re.S))[:1800])
os.remove('app/content/authors/digits.md')
look_again()
st, _, html = root.get('/admin/broken-references')
check('a reference to an entry that is gone is listed, with where to fix it', st == 200 and 'Digit volume' in html and '<code>author</code>' in html and 'admin/edit?type=volumes&amp;slug=digit-vol' in html, html[html.find('Entry'):][:300])
dash = root.get('/admin')[2]
check('and the dashboard says so', 'at an entry that is gone' in dash)
check('a visitor cannot read the list', 'Digit volume' not in pub.get('/admin/broken-references')[2])

# ---- the type editor
st, _, html = root.get('/admin/content-types?type=volumes')
check('the type editor shows a relation as read-only, with what it points at', st == 200 and 'Points at: authors' in html and 'Points at: authors (several)' in html, st)

# ---- CSV
st, _, csv = root.get('/admin/export?type=volumes')
check('an export writes the address of each entry', st == 200 and 'authors/charles-babbage' in csv and ada_id not in csv, csv[:300])

# ---- templates built with blocks: relations as binding sources (4C)
import json
def editor_data(html):
    return json.loads(re.search(r'<script type="application/json" id="block-editor-data">(.*?)</script>', html, re.S).group(1))
def save_template(type_, blocks):
    form = next(f for f in root.forms('/admin/theme?template=' + type_) if any(x[0] == 'blocks_json' for x in f['fields']))
    fields = [tuple(x) for x in form['fields'] if x[0] not in ('blocks_json', 'blocks_editor', 'use')]
    fields += [('blocks_editor', '1'), ('blocks_json', json.dumps(blocks)), ('use', '1')]
    return root.request('/admin/theme?template=' + type_, data=fields)
sources = {s['path']: s for s in editor_data(root.get('/admin/theme?template=volumes')[2])['bindings']['sources']}
check('the template editor of a type with a relation offers the fields of the entry it points at, by path', all(('custom_fields.author.' + k) in sources for k in ('title', 'excerpt', 'main_image', 'url', 'custom_fields.country')) and sources['custom_fields.author.title']['kind'] == 'text', list(sources))
check('and a relation to several as a list with columns', sources['custom_fields.others']['kind'] == 'list' and 'field_country' in [c['key'] for c in sources['custom_fields.others']['columns']], sources.get('custom_fields.others'))
back = {s['path']: s for s in editor_data(root.get('/admin/theme?template=authors')[2])['bindings']['sources']}
check('the type that is pointed at offers what points at it', 'referenced_by.volumes.author' in back and back['referenced_by.volumes.author']['kind'] == 'list' and 'title' in [c['key'] for c in back['referenced_by.volumes.author']['columns']], list(back))
save_template('volumes', [
    {'type': 'entry-title'},
    {'type': 'cta', 'heading': {'bind': 'custom_fields.author.title'}, 'text': {'bind': 'custom_fields.author.custom_fields.country'}, 'actions': [{'label': 'More'}]},
    {'type': 'content'},
])
save_template('authors', [
    {'type': 'entry-title'},
    {'type': 'cards', 'source': 'manual', 'heading': 'Volumes', 'items': {'bind': 'referenced_by.volumes.author', 'map': {'title': 'title', 'url': 'url'}}},
    {'type': 'fact-sheet'},
    {'type': 'content'},
])
look_again()
st, _, page = pub.get('/volumes/notes')
check('a volume is drawn from its template: the name of its author and a field of the author', st == 200 and 'Ada Lovelace' in page, st)
st, _, page = pub.get('/authors/ada-king')
check('an author lists, in a cards block, the volumes that point at it, each linked', st == 200 and re.search(r'href="/volumes/notes"[^>]*>|<a[^>]*href="/volumes/notes"', page) is not None and 'Notes' in page, st)
pv = root.request('/admin/theme?template=volumes&preview=1', data=[('blocks_editor', '1'), ('blocks_json', json.dumps([{'type': 'cta', 'heading': {'bind': 'custom_fields.author.title'}, 'actions': [{'label': 'x'}]}])), ('preview_entry', 'notes|el'), ('_csrf', re.search(r'name="_csrf" value="([^"]+)"', root.get('/admin/theme?template=volumes')[2]).group(1))])
check('the live preview of the template works with relations too', pv[0] == 200 and 'Ada Lovelace' in json.loads(pv[2])['html'], pv[2][:200])

# ---- the fact sheet of an author lists its volumes when the relation says reverse_label
volumes_yaml = open('app/custom/content-types/volumes.yaml', encoding='utf-8').read()
open('app/custom/content-types/volumes.yaml', 'w', encoding='utf-8').write(volumes_yaml.replace("author: {type: relation, types: [authors], label: Author}", "author: {type: relation, types: [authors], label: Author, reverse_label: Volumes by this author}"))
look_again()
st, _, page = pub.get('/authors/ada-king')
check('a relation with a reverse_label adds a line to the fact sheet of the entry it points at, with links', st == 200 and 'Volumes by this author' in page and '<a href="/volumes/notes">Notes</a>' in page, st)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
