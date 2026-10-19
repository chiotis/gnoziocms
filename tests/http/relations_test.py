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
sel = re.search(r'<select name="details\[author\]"[^>]*data-relation-field>(.*?)</select>', html, re.S)
check('a relation is a list of the entries it may point at, by title', st == 200 and sel is not None and 'Ada Lovelace' in sel.group(1) and 'Charles Babbage' in sel.group(1), sel.group(0)[:300] if sel else html[:200])
check('a field that takes several is a multiple list, with its limit said', re.search(r'<select name="details\[others\]\[\]"[^>]*multiple', html) is not None and 'up to 2' in html)
check('a relation inside a row of a list is in the row template', re.search(r'name="details\[editions\]\[__INDEX__\]\[editor\]"', html) is not None)

def volume_form(f): return any(x[0] == 'details[author]' for x in f['fields'])
st, _ = save('volumes', 'notes', dict(base, title='Notes', **{'details[author]': ada_id, 'details[others][]': ['authors/charles-babbage'], 'details[editions][0][year]': '1843', 'details[editions][0][editor]': 'authors/charles-babbage', 'details[editions][1][year]': '1850', 'details[editions][1][editor]': ''}), volume_form)
stored = open('app/content/volumes/notes.md', encoding='utf-8').read()
babbage = open('app/content/authors/charles-babbage.md', encoding='utf-8').read()
babbage_id = (re.search(r'translation_id: (\w+)', babbage) or [None, ''])[1]
check('what is stored is the id of the author, not an address', ('author: ' + ada_id) in stored and 'authors/' not in stored.split('---')[1], stored)
check('an address typed in the form became the id of that entry, also in a list and in a row of a list', stored.count(babbage_id) == 2 and len(babbage_id) == 16, stored)
st, _, html = root.get('/admin/edit?type=volumes&slug=notes&lang=el')
check('the editor shows the chosen entries selected', re.search(r'<option value="%s" selected>Ada Lovelace' % ada_id, html) is not None and re.search(r'<option value="%s" selected>Charles Babbage' % babbage_id, html) is not None)

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
st, _, html = root.get('/admin/edit?type=volumes&slug=notes&lang=el')
check('but the editor still holds the reference, marked as a draft', re.search(r'<option value="%s" selected>Ada King|<option value="%s" selected>Ada Lovelace[^<]*draft' % (ada_id, ada_id), html) is not None, re.findall(r'<option value="%s"[^>]*>[^<]*' % ada_id, html))

# ---- an id made only of digits is still an id (it must not turn into a number anywhere)
open('app/content/authors/digits.md', 'w', encoding='utf-8').write("---\ntitle: Digits\nstatus: published\ntranslation_id: '4444444444444444'\n---\n\nx\n")
open('app/content/volumes/digit-vol.md', 'w', encoding='utf-8').write("---\ntitle: Digit volume\nstatus: published\ncustom_fields:\n  author: '4444444444444444'\n---\n\nx\n")
look_again()
st, _, html = root.get('/admin/edit?type=volumes&slug=digit-vol&lang=el')
check('an id of digits only is chosen by its title in the editor', re.search(r'<option value="4444444444444444" selected>Digits', html) is not None, re.findall(r'<option value="4444444444444444"[^>]*>[^<]*', html))
check('and the page links it', '<a href="/authors/digits">Digits</a>' in pub.get('/volumes/digit-vol')[2])

# ---- the type editor
st, _, html = root.get('/admin/content-types?type=volumes')
check('the type editor shows a relation as read-only, with what it points at', st == 200 and 'Points at: authors' in html and 'Points at: authors (several)' in html, st)

# ---- CSV
st, _, csv = root.get('/admin/export?type=volumes')
check('an export writes the address of each entry', st == 200 and 'authors/charles-babbage' in csv and ada_id not in csv, csv[:300])

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
