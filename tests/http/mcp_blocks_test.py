"""
Blocks through MCP: list_blocks names the theme's blocks and gives one block's fields; get_entry gives an entry's blocks; create_entry,
update_entry and validate_entry take the whole list, checked as strictly as fields (an unknown block, field or option, a video link
that is not one); a content block places the text; [] removes every block; the page draws a YouTube video in its Video block.
"""
import json, os, re, sys, urllib.error, urllib.request
sys.path.insert(0, '.')
from client import BASE, Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:500]))
    if not ok: fails.append(label)

def call(token, name, arguments=None):
    body = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': name, 'arguments': arguments or {}}}).encode()
    req = urllib.request.Request(BASE + '/mcp', data=body, method='POST', headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token})
    try:
        out = json.loads(urllib.request.urlopen(req, timeout=60).read().decode())
    except urllib.error.HTTPError as e:
        return {'isError': True, 'text': 'HTTP %d' % e.code, 'data': {}}
    result = out.get('result', {})
    return {'isError': result.get('isError'), 'text': result.get('content', [{}])[0].get('text', ''), 'data': result.get('structuredContent', {})}

def read(path):
    return open(path, encoding='utf-8').read() if os.path.exists(path) else ''

root = Client(); root.login()
root.submit('/admin/mcp', has_field('mcp_action'), button=('mcp_action', 'enable'))
root.submit('/admin/mcp', has_field('days'), {'name': 'Blocks job', 'days': '30', 'scopes[]': ['content:read', 'content:write', 'content:publish']})
token = re.search(r'value="(gat_[A-Za-z0-9_-]+)"', root.get('/admin/mcp')[2]).group(1)

# ---- list_blocks
r = call(token, 'list_blocks')
types = {b['type'] for b in r['data'].get('blocks', [])}
check('list_blocks names the blocks, the container too', r['isError'] is False and {'video', 'content', 'audio', 'gallery', 'container'} <= types, r['text'][:200])
r = call(token, 'list_blocks', {'type': 'video'})
f = r['data'].get('fields', {})
check('with a type: its fields, a list\'s rows, and the shared ones (layout)', 'videos' in f and 'url' in f['videos'].get('fields', {}) and 'variant' in r['data'].get('shared_fields', {}), r['text'][:300])
r = call(token, 'list_blocks', {'type': 'nope'})
check('an unknown block is named', r['isError'] and 'nope' in r['text'], r['text'])

# ---- a post with its text and a video under it
YT = 'https://www.youtube.com/watch?v=RRG6cpaby8Y'
r = call(token, 'create_entry', {'type': 'posts', 'title': 'On the news', 'body': 'He spoke about the reform.', 'status': 'published',
                                 'blocks': [{'type': 'content'}, {'type': 'video', 'play': 'inline', 'videos': [{'url': YT, 'title': 'On the news'}]}]})
check('create_entry takes blocks', r['isError'] is False, r['text'])
slug = r['data'].get('entry', {}).get('slug', '')
text = read('app/content/posts/%s.md' % slug) or ''.join(read('app/content/posts/' + n) for n in os.listdir('app/content/posts') if slug in n)
check('they are kept in the file, as the editor keeps them', 'type: video' in text and 'RRG6cpaby8Y' in text and 'type: content' in text, text[:600])
e = call(token, 'get_entry', {'type': 'posts', 'slug': slug})['data']
check('get_entry gives them back', [b['type'] for b in e.get('blocks', [])] == ['content', 'video'] and e['blocks'][1]['videos'][0]['url'] == YT, e.get('blocks'))
page = root.get('/' + slug)[2] if root.get('/' + slug)[0] == 200 else root.get('/posts/' + slug)[2]
check('the page draws the text and then the video', 'He spoke about the reform.' in page and 'RRG6cpaby8Y' in page and page.find('He spoke about') < page.find('RRG6cpaby8Y'), page[:200])

# ---- every problem named, nothing saved
before = e['updated_at']
bad = [{'type': 'nope'}, {'type': 'video', 'colour': 'red'}, {'type': 'video', 'play': 'sideways'},
       {'type': 'video', 'videos': [{'url': 'https://example.com/clip'}]}, 'loose']
r = call(token, 'update_entry', {'type': 'posts', 'slug': slug, 'blocks': bad})
t = r['text']
check('an unknown block, field, option and video link are each named', r['isError'] and 'blocks[0]' in t and '"nope"' in t and 'colour' in t and 'sideways' in t and 'example.com/clip' in t and 'blocks[4]' in t, t)
check('and nothing changed', call(token, 'get_entry', {'type': 'posts', 'slug': slug})['data']['updated_at'] == before)
r = call(token, 'validate_entry', {'type': 'posts', 'title': 'X', 'body': 'Y', 'status': 'draft', 'blocks': [{'type': 'video', 'videos': [{'url': 'ftp://x'}]}]})
check('validate_entry checks them too', r['isError'] and 'ftp://x' in r['text'], r['text'])
r = call(token, 'update_entry', {'type': 'posts', 'slug': slug, 'blocks': {'type': 'video'}})
check('blocks must be a list', r['isError'] and 'list' in r['text'], r['text'])

# ---- changing the text keeps the blocks; [] removes them
r = call(token, 'update_entry', {'type': 'posts', 'slug': slug, 'body': 'He spoke about the reform, again.'})
e = call(token, 'get_entry', {'type': 'posts', 'slug': slug})['data']
check('changing the text keeps the blocks', r['isError'] is False and len(e['blocks']) == 2 and 'again' in e['body'], e.get('blocks'))
r = call(token, 'update_entry', {'type': 'posts', 'slug': slug, 'blocks': [{'type': 'video', 'videos': [{'url': 'https://youtu.be/RRG6cpaby8Y'}]}]})
e = call(token, 'get_entry', {'type': 'posts', 'slug': slug})['data']
check('a new list replaces the old one', r['isError'] is False and [b['type'] for b in e['blocks']] == ['video'], e.get('blocks'))
r = call(token, 'update_entry', {'type': 'posts', 'slug': slug, 'blocks': []})
e = call(token, 'get_entry', {'type': 'posts', 'slug': slug})['data']
check('[] removes every block', r['isError'] is False and e['blocks'] == [] and 'again' in e['body'], e.get('blocks'))

# ---- Markdown in a block is checked as the text is
r = call(token, 'update_entry', {'type': 'posts', 'slug': slug, 'blocks': [{'type': 'text', 'body': 'Hi <script>alert(1)</script>'}]})
e = call(token, 'get_entry', {'type': 'posts', 'slug': slug})['data']
check('HTML in a block\'s Markdown is shown as text', r['isError'] is False and '<script>' not in json.dumps(e['blocks']).replace('&lt;script', ''), e.get('blocks'))

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
