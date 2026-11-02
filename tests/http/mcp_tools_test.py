import json, os, re, sqlite3, sys, urllib.error, urllib.request
sys.path.insert(0, '.')
from client import BASE, Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:400]))
    if not ok: fails.append(label)

def call(token, name, arguments=None):
    body = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': name, 'arguments': arguments or {}}}).encode()
    req = urllib.request.Request(BASE + '/mcp', data=body, method='POST', headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token})
    try:
        out = json.loads(urllib.request.urlopen(req, timeout=60).read().decode())
    except urllib.error.HTTPError as e:
        return {'isError': True, 'text': 'HTTP %d' % e.code}
    result = out.get('result', {})
    return {'isError': result.get('isError'), 'text': result.get('content', [{}])[0].get('text', ''), 'data': result.get('structuredContent', {})}

def file(slug, lang='en'):
    path = f'app/content/posts/{slug}.{lang}.md' if lang != 'el' else f'app/content/posts/{slug}.md'
    return open(path, encoding='utf-8').read() if os.path.exists(path) else ''

# A content type with fields of its own, as a site adds in custom/.
os.makedirs('app/custom/content-types', exist_ok=True)
open('app/custom/content-types/posts.yaml', 'w').write("fields:\n  subtitle:\n    type: text\n    label: Subtitle\n  level:\n    type: select\n    label: Level\n    options: { easy: Easy, hard: Hard }\n")

root = Client(); root.login()
root.submit('/admin/mcp', has_field('mcp_action'), button=('mcp_action', 'enable'))
def make_token(name, scopes):
    root.submit('/admin/mcp', has_field('days'), {'name': name, 'days': '30', 'scopes[]': scopes})
    return re.search(r'value="(gat_[A-Za-z0-9_-]+)"', root.get('/admin/mcp')[2]).group(1)
full = make_token('Newsroom job', ['content:read', 'content:write', 'content:publish'])
drafts = make_token('Drafts only', ['content:read', 'content:write'])

# ---- rules of the connection: a byline
st, _, html = root.get('/admin/mcp')
conn = re.findall(r'data-mcp-connection="(\d+)"', html)
newsroom = [c for c in conn if re.search(r'data-mcp-connection="' + c + r'".{0,600}Newsroom job', html, re.S)][0]
root.submit('/admin/mcp', lambda f: ('connection', newsroom) in [tuple(x) for x in f['fields']] and ('mcp_action', 'policy') in [tuple(x) for x in f['fields']], {'byline': 'The Newsroom', 'daily_limit': '3'})
check('the rules of a connection are saved', 'by The Newsroom' in root.get('/admin/mcp')[2])

# ---- what is offered
info = call(full, 'site_info')['data']
check('site_info tells the rules', info.get('connection', {}).get('byline') == 'The Newsroom' and info['connection'].get('publications_left') == 3, info.get('connection'))
check('and the type\'s own fields', 'subtitle' in next(t for t in info['content_types'] if t['type'] == 'posts')['fields'])

# ---- strict
r = call(full, 'create_entry', {'type': 'posts', 'lang': 'en', 'title': 'X', 'body': 'Y'})
check('status is required', r['isError'] and 'status is required' in r['text'], r)
r = call(full, 'create_entry', {'type': 'posts', 'title': 'X', 'body': 'Y', 'status': 'draft', 'colour': 'red'})
check('an unknown argument is named', r['isError'] and 'colour' in r['text'], r)
r = call(full, 'create_entry', {'type': 'gadgets', 'title': 'X', 'body': 'Y', 'status': 'draft'})
check('an unknown type is named, with the ones there are', r['isError'] and 'gadgets' in r['text'] and 'posts' in r['text'], r)
r = call(full, 'create_entry', {'type': 'posts', 'lang': 'fr', 'title': 'X', 'body': 'Y', 'status': 'draft'})
check('so is an unknown language', r['isError'] and '"fr"' in r['text'], r)
r = call(full, 'create_entry', {'type': 'posts', 'lang': 'en', 'title': 'X', 'body': 'Y', 'status': 'draft', 'terms': {'tags': ['strategy', 'nonsense']}, 'fields': {'level': 'medium', 'mood': 'x'}})
check('every problem at once: an unknown term, a value a field does not take, an unknown field', r['isError'] and 'nonsense' in r['text'] and 'level' in r['text'] and 'easy, hard' in r['text'] and 'mood' in r['text'], r)
r = call(full, 'create_entry', {'type': 'posts', 'lang': 'en', 'title': 'X', 'body': 'Y', 'status': 'scheduled'})
check('scheduled needs a time', r['isError'] and 'publish_at' in r['text'], r)
r = call(full, 'validate_entry', {'type': 'posts', 'lang': 'en', 'title': 'Checked only', 'body': 'Y', 'status': 'draft'})
check('validate_entry saves nothing', r['isError'] is False and r['data'].get('ok') and not os.path.exists('app/content/posts/checked-only.en.md'), r)

# ---- create
args = {'type': 'posts', 'lang': 'en', 'title': 'Robots write news', 'body': 'First paragraph.\n\n<script>alert(1)</script>', 'excerpt': 'A test.', 'status': 'published', 'terms': {'tags': ['strategy']}, 'fields': {'subtitle': 'From the wire', 'level': 'easy'}, 'source_key': 'https://wire.example/story-1', 'seo': {'title': 'Robots SEO'}}
r = call(full, 'create_entry', args)
entry = r.get('data', {}).get('entry', {})
check('a published entry is made, with its address', r['isError'] is False and entry.get('status') == 'published' and entry.get('address', '').endswith('robots-write-news'), r)
text = file('robots-write-news')
check('the file has the fields, terms, byline and who wrote it', all(s in text for s in ['subtitle: \'From the wire\'', 'level: easy', '- strategy', "author: 'The Newsroom'", "generated_by: 'Newsroom job'", 'title: \'Robots SEO\'']), text)
check('HTML is shown as text, and the job is told', '<script>' not in text and any('HTML' in w for w in r['data'].get('warnings', [])), text)
check('the public page is there', Client().get(re.sub(r'^https?://[^/]+', '', entry.get('address', '/x')))[0] == 200)
r = call(full, 'create_entry', args)
check('the same source_key returns the entry made before', r['data'].get('existing') is True and r['data']['entry']['slug'] == 'robots-write-news' and not os.path.exists('app/content/posts/robots-write-news-2.en.md'), r)
r = call(full, 'find_by_source', {'source_key': 'https://wire.example/story-1'})
check('find_by_source finds it', r['data'].get('found') is True, r)
check('and nothing for another source', call(full, 'find_by_source', {'source_key': 'https://wire.example/other'})['data'].get('found') is False)
c = sqlite3.connect('app/storage/db/app.sqlite')
note = c.execute("SELECT title FROM notifications WHERE title LIKE 'Published by Newsroom job%'").fetchone()
c.close()
check('a publication leaves a notification', note is not None, note)
check('the content list marks it', '>MCP<' in root.get('/admin/content?type=posts&lang=en')[2])

r = call(full, 'create_entry', {'type': 'posts', 'lang': 'el', 'title': 'Ρομπότ γράφουν ειδήσεις', 'body': 'Κείμενο.', 'status': 'scheduled', 'publish_at': '2099-01-02T09:00:00+02:00', 'translation_of': {'slug': 'robots-write-news', 'lang': 'en'}})
check('a scheduled translation shares the address', r['isError'] is False and r['data']['entry']['slug'] == 'robots-write-news' and r['data']['entry']['status'] == 'scheduled', r)
check('and its id', re.search(r"translation_id: '?([a-f0-9]+)", file('robots-write-news', 'el')).group(1) == re.search(r"translation_id: '?([a-f0-9]+)", file('robots-write-news')).group(1))

# ---- read
r = call(full, 'get_entry', {'type': 'posts', 'slug': 'robots-write-news', 'lang': 'en'})
got = r['data']
check('get_entry gives the text, terms, fields and translations', got.get('body', '').startswith('First paragraph.') and got.get('terms') == {'tags': ['strategy']} and got.get('fields', {}).get('level') == 'easy' and any(t['lang'] == 'el' for t in got.get('translations', [])), got)
check('list_entries lists it', any(e['slug'] == 'robots-write-news' for e in call(full, 'list_entries', {'type': 'posts', 'lang': 'en'})['data']['entries']))
check('with a status filter', all(e['status'] == 'scheduled' for e in call(full, 'list_entries', {'type': 'posts', 'status': 'scheduled'})['data']['entries']))
check('search_entries finds it', any(e['slug'] == 'robots-write-news' for e in call(full, 'search_entries', {'query': 'robots'})['data']['entries']))
terms = call(full, 'list_terms', {'taxonomy': 'tags'})['data']
check('list_terms gives ids and labels', any(t['id'] == 'strategy' and t['labels'].get('en') == 'Strategy' for t in terms.get('terms', [])), terms)

# ---- update
r = call(full, 'update_entry', {'type': 'posts', 'slug': 'robots-write-news', 'lang': 'en', 'expected_updated_at': '2000-01-01T00:00:00Z', 'title': 'Stale'})
check('a change made meanwhile is not overwritten', r['isError'] and 'changed since' in r['text'], r)
with open('app/content/posts/robots-write-news.en.md') as f: raw = f.read()
open('app/content/posts/robots-write-news.en.md', 'w').write(raw.replace('custom_fields:', 'mood: calm\ncustom_fields:\n  extra_note: kept', 1))
r = call(full, 'update_entry', {'type': 'posts', 'slug': 'robots-write-news', 'lang': 'en', 'expected_updated_at': call(full, 'get_entry', {'type': 'posts', 'slug': 'robots-write-news', 'lang': 'en'})['data']['updated_at'], 'excerpt': 'Changed excerpt.'})
text = file('robots-write-news')
check('an update changes what is given and keeps the rest', r['isError'] is False and "excerpt: 'Changed excerpt.'" in text and 'level: easy' in text and '- strategy' in text and 'First paragraph.' in text and "author: 'The Newsroom'" in text, (r, text))
check('the entry\'s own fields stay too', 'extra_note: kept' in text and 'mood: calm' in text, text)

# ---- a connection that writes drafts only
r = call(drafts, 'create_entry', {'type': 'posts', 'lang': 'en', 'title': 'Draft job', 'body': 'x', 'status': 'published'})
check('publishing needs content:publish', r['isError'] and 'content:publish' in r['text'], r)
r = call(drafts, 'create_entry', {'type': 'posts', 'lang': 'en', 'title': 'Draft job', 'body': 'x', 'status': 'draft'})
check('a draft it may write', r['isError'] is False and 'status: draft' in file('draft-job'), r)
r = call(drafts, 'update_entry', {'type': 'posts', 'slug': 'robots-write-news', 'lang': 'en', 'title': 'Hijack'})
check('a published entry it may not change', r['isError'] and 'content:publish' in r['text'], r)

# ---- the daily limit (3: two used above)
r = call(full, 'create_entry', {'type': 'posts', 'lang': 'en', 'title': 'Third', 'body': 'x', 'status': 'published'})
check('the third publication in 24 h is allowed', r['isError'] is False, r)
r = call(full, 'create_entry', {'type': 'posts', 'lang': 'en', 'title': 'Fourth', 'body': 'x', 'status': 'published'})
check('the fourth is refused, with what to do', r['isError'] and 'limit of 3' in r['text'], r)
check('a draft still goes in', call(full, 'create_entry', {'type': 'posts', 'lang': 'en', 'title': 'Fourth', 'body': 'x', 'status': 'draft'})['isError'] is False)

# ---- rules: types
root.submit('/admin/mcp', lambda f: ('connection', newsroom) in [tuple(x) for x in f['fields']] and ('mcp_action', 'policy') in [tuple(x) for x in f['fields']], {'types[]': ['pages'], 'daily_limit': '3', 'byline': 'The Newsroom'})
r = call(full, 'create_entry', {'type': 'posts', 'lang': 'en', 'title': 'Not allowed', 'body': 'x', 'status': 'draft'})
check('a type the rules leave out is refused', r['isError'] and 'may not write posts' in r['text'], r)

print('ALL PASSED' if not fails else f'{len(fails)} FAILED')
sys.exit(1 if fails else 0)
