"""
Filing many entries under a term at once: the content list's bulk menu offers "Add to" and "Remove from" each term of the type's
taxonomies; the selected entries (or every entry the filters let through) are added to the term keeping their other terms, or
taken out of it, with the history kept and the public term page following; the same through MCP's file_entries, which checks
every entry first, needs content:publish for published entries, and lets shop:write file products under the shop's taxonomies.
"""
import json, os, re, sqlite3, sys, urllib.error, urllib.parse, urllib.request
sys.path.insert(0, '.')
from client import BASE, Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:500]))
    if not ok: fails.append(label)

def read(path): return open('app/content/' + path, encoding='utf-8').read() if os.path.exists('app/content/' + path) else ''
def write(path, text): open('app/content/' + path, 'w', encoding='utf-8').write(text)
def token(c): return re.search(r'name="_csrf" value="([0-9a-f]+)"', c.get('/admin')[2]).group(1)
def db(query):
    c = sqlite3.connect('app/storage/db/app.sqlite'); rows = c.execute(query).fetchall(); c.close(); return rows
def look_again():
    c = sqlite3.connect('app/storage/db/app.sqlite'); c.execute("update system_meta set value='0' where key='content_index_checked_at'"); c.commit(); c.close()

for n, tags in ((1, '[design]'), (2, '[strategy]'), (3, '[]')):
    write(f'posts/batch-{n}.md', "---\ntitle: 'Batch %d'\nstatus: published\nvisible: true\ndate: '2026-01-0%d'\ntags: %s\n---\n\nText.\n" % (n, n, tags))
write('posts/batch-4.md', "---\ntitle: 'Batch 4'\nstatus: draft\nvisible: true\ndate: '2026-01-04'\n---\n\nText.\n")

root = Client(); root.login()
pub = Client()
look_again()

# ---- the menu
st, _, html = root.get('/admin/content?type=posts&lang=el')
check('the bulk menu offers adding to and removing from each term', st == 200 and 'value="term_add:tags:growth"' in html and 'value="term_remove:tags:growth"' in html and '<optgroup label="Add to Tags">' in html and 'value="term_add:categories:news"' in html, re.findall(r'<optgroup label="[^"]+">', html)[:6])
check('pages have no taxonomies, so none', 'term_add:' not in root.get('/admin/content?type=pages&lang=el')[2])

def bulk(action, slugs, **extra):
    data = [('_csrf', token(root)), ('type', 'posts'), ('lang', 'el'), ('bulk_action', action)] + [('selected[]', s) for s in slugs] + list(extra.items())
    st, hdr, _ = root.request('/admin/content-bulk', data=data)
    return st, urllib.parse.unquote_plus(hdr.get('Location') or '')

# ---- adding
st, msg = bulk('term_add:tags:growth', ['batch-1', 'batch-2', 'batch-3'])
check('three entries are added to a term', st == 302 and '3 items added to Ανάπτυξη' in msg, msg)
check('their other terms stay', re.search(r'tags:\n\s+- design\n\s+- growth', read('posts/batch-1.md')) is not None and re.search(r'tags:\n\s+- strategy\n\s+- growth', read('posts/batch-2.md')) is not None and re.search(r'tags:\n\s+- growth', read('posts/batch-3.md')) is not None, read('posts/batch-1.md'))
check('the rest of the file is as it was', 'title: \'Batch 1\'' in read('posts/batch-1.md') and 'Text.' in read('posts/batch-1.md'))
check('the history keeps the text before', db("select count(*) from content_revisions where slug = 'batch-1'")[0][0] >= 2)
st, _, page = pub.get('/tag/growth')
check('the term\'s page lists them', st == 200 and 'Batch 1' in page and 'Batch 3' in page, st)
st, msg = bulk('term_add:tags:growth', ['batch-1', 'batch-4'])
check('one already in it is told apart', '1 item added' in msg and '1 already were in it' in msg, msg)
check('a draft is filed too', 'growth' in read('posts/batch-4.md'))

# ---- removing
st, msg = bulk('term_remove:tags:growth', ['batch-1', 'batch-3'])
check('entries are taken out of a term', '2 items taken out of Ανάπτυξη' in msg, msg)
check('the others stay; an empty field goes', re.search(r'tags:\n\s+- design\n', read('posts/batch-1.md')) is not None and 'growth' not in read('posts/batch-1.md') and 'tags:' not in read('posts/batch-3.md'), (read('posts/batch-1.md'), read('posts/batch-3.md')))
check('the term\'s page follows', 'Batch 1' not in pub.get('/tag/growth')[2] and 'Batch 2' in pub.get('/tag/growth')[2])

# ---- select all that the filters let through
st, msg = bulk('term_add:categories:news', [], q='Batch', all_matching='1')
check('"select all" on every page files every entry the filters let through', '4 items added' in msg and all('news' in read(f'posts/batch-{n}.md') for n in (1, 2, 3, 4)), msg)

# ---- refused
st, msg = bulk('term_add:tags:nothing', ['batch-1'])
check('an unknown term is refused', 'There is no "nothing" in tags' in msg, msg)
st, msg = bulk('term_add:shelves:x', ['batch-1'])
check('a taxonomy the type is not filed under is refused', 'not filed under "shelves"' in msg, msg)

# ---- MCP
root.submit('/admin/mcp', has_field('mcp_action'), button=('mcp_action', 'enable'))
def make_token(name, scopes):
    root.submit('/admin/mcp', has_field('days'), {'name': name, 'days': '30', 'scopes[]': scopes})
    return re.search(r'value="(gat_[A-Za-z0-9_-]+)"', root.get('/admin/mcp')[2]).group(1)
def call(tok, name, arguments):
    body = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': name, 'arguments': arguments}}).encode()
    req = urllib.request.Request(BASE + '/mcp', data=body, method='POST', headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + tok})
    result = json.loads(urllib.request.urlopen(req, timeout=60).read().decode()).get('result', {})
    return {'isError': result.get('isError'), 'text': result.get('content', [{}])[0].get('text', ''), 'data': result.get('structuredContent', {})}
full = make_token('Editor job', ['content:read', 'content:write', 'content:publish'])
drafts = make_token('Drafts job', ['content:read', 'content:write'])

r = call(full, 'file_entries', {'type': 'posts', 'entries': ['batch-1', 'batch-2', 'nope', 'nada'], 'taxonomy': 'tags', 'term': 'growth'})
check('MCP: every entry is checked first; nothing changes while one is wrong', r['isError'] and 'nope, nada' in r['text'] and 'growth' not in read('posts/batch-1.md'), r)
r = call(full, 'file_entries', {'type': 'posts', 'entries': ['batch-1', 'batch-2', 'batch-3'], 'taxonomy': 'tags', 'term': 'growth'})
check('MCP: entries are filed in one call, those already in it named', r['isError'] is False and r['data']['changed'] == ['batch-1', 'batch-3'] and r['data']['already_filed'] == ['batch-2'] and 'growth' in read('posts/batch-3.md'), r)
r = call(full, 'file_entries', {'type': 'posts', 'entries': ['batch-1'], 'taxonomy': 'tags', 'term': 'growth', 'action': 'remove'})
check('MCP: and taken out', r['isError'] is False and r['data']['changed'] == ['batch-1'] and 'growth' not in read('posts/batch-1.md'), r)
r = call(drafts, 'file_entries', {'type': 'posts', 'entries': ['batch-1', 'batch-4'], 'taxonomy': 'tags', 'term': 'growth'})
check('MCP: published entries need content:publish', r['isError'] and '1 of them are published' in r['text'] and 'growth' not in read('posts/batch-1.md'), r)
r = call(drafts, 'file_entries', {'type': 'posts', 'entries': ['batch-4'], 'taxonomy': 'tags', 'term': 'strategy'})
check('MCP: a draft only needs content:write', r['isError'] is False and r['data']['changed'] == ['batch-4'], r)
r = call(full, 'file_entries', {'type': 'pages', 'entries': ['about'], 'taxonomy': 'tags', 'term': 'growth'})
check('MCP: pages are not filed', r['isError'] and 'not filed under' in r['text'], r)

# ---- products under the shop's taxonomies with shop:write
pick = lambda f: ['type', 'products'] in [list(x) for x in f['fields']] and ['action', 'toggle'] in [list(x) for x in f['fields']]
root.submit('/admin/content-types', pick, {'enabled': '1'})
root.submit('/admin/taxonomies?taxonomy=collections', has_field('taxonomy_title'), {'term_id[]': [''], 'term_slug[]': ['balos'], 'term_label[el][]': ['Μπάλος'], 'term_label[en][]': ['Balos']})
os.makedirs('app/content/products', exist_ok=True)
for n in (1, 2):
    write(f'products/ring-{n}.md', "---\ntitle: 'Ring %d'\nstatus: published\ntranslation_id: ring00000000000%d\ncustom_fields:\n  price: 10\n---\n\nText.\n" % (n, n))
look_again()
shop = make_token('Shop job', ['shop:read', 'shop:write'])
r = call(shop, 'file_entries', {'type': 'products', 'entries': ['ring-1', 'ring-2'], 'taxonomy': 'collections', 'term': 'balos'})
check('MCP: shop:write files published products under a collection', r['isError'] is False and r['data']['changed'] == ['ring-1', 'ring-2'] and 'balos' in read('products/ring-1.md'), r)
r = call(shop, 'file_entries', {'type': 'products', 'entries': ['ring-1'], 'taxonomy': 'tags', 'term': 'growth'})
check('MCP: but not under other taxonomies', r['isError'] and 'needs content:write' in r['text'], r)
r = call(shop, 'file_entries', {'type': 'posts', 'entries': ['batch-4'], 'taxonomy': 'tags', 'term': 'design'})
check('MCP: nor other types', r['isError'] and 'needs content:write' in r['text'], r)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
