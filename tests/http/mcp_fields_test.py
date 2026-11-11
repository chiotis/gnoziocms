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

# A type with every kind of field MCP now writes, as a shop's products have: a price shared by the translations, a picture,
# a gallery, an entry it points at, and rows with a price, a picture and an entry of their own.
os.makedirs('app/custom/content-types', exist_ok=True)
open('app/custom/content-types/makers.yaml', 'w', encoding='utf-8').write("label: Makers\nsingular: Maker\nfields:\n  country: {type: text, label: Country}\n")
open('app/custom/content-types/gadgets.yaml', 'w', encoding='utf-8').write('''label: Gadgets
singular: Gadget
fields:
  price: {type: decimal, label: Price, min: 0, shared: true}
  cover: {type: image, label: Cover}
  photos: {type: gallery, label: Photos, max: 3}
  maker: {type: relation, types: [makers], label: Maker}
  parts:
    type: repeater
    label: Parts
    max: 5
    shared: true
    fields:
      name: {type: text, label: Name}
      price: {type: decimal, label: Price, min: 0}
      picture: {type: image, label: Picture}
      made_by: {type: relation, types: [makers], label: Made by}
''')
os.makedirs('app/content/makers', exist_ok=True)
os.makedirs('app/content/gadgets', exist_ok=True)
os.makedirs('app/public/uploads/media', exist_ok=True)
for name in ('one', 'two', 'three', 'four'):
    open('app/public/uploads/media/%s.jpg' % name, 'wb').write(b'\xff\xd8\xff' + name.encode() * 50)

root = Client(); root.login()
root.submit('/admin/mcp', has_field('mcp_action'), button=('mcp_action', 'enable'))
root.submit('/admin/mcp', has_field('days'), {'name': 'Shop job', 'days': '30', 'scopes[]': ['content:read', 'content:write', 'content:publish']})
token = re.search(r'value="(gat_[A-Za-z0-9_-]+)"', root.get('/admin/mcp')[2]).group(1)

r = call(token, 'create_entry', {'type': 'makers', 'title': 'Acme', 'body': 'Makers of things.', 'status': 'published'})
check('a maker to point at', r['isError'] is False and r['data']['entry']['slug'] == 'acme', r)

# ---- what site_info says
info = call(token, 'site_info')['data']
fields = next(t for t in info['content_types'] if t['type'] == 'gadgets')['fields']
check('site_info says every field is writable and what it takes', all(fields[k].get('writable') for k in ('price', 'cover', 'photos', 'maker', 'parts')) and 'type/slug' in fields['maker'].get('takes', '') and 'add_image_from_url' in fields['cover'].get('takes', ''), fields)
check('the fields of a repeater\'s rows, the types an entry may be, and what is shared', set(fields['parts'].get('fields', {})) == {'name', 'price', 'picture', 'made_by'} and fields['maker'].get('types') == ['makers'] and 'shared' in fields['price'] and 'shared' not in fields['cover'], fields)

# ---- every problem at once
bad = {'price': 'cheap', 'cover': '/uploads/media/missing.jpg', 'photos': ['/uploads/media/one.jpg'] * 4, 'maker': 'makers/nobody',
       'parts': [{'name': 'Bolt', 'colour': 'red'}, {'name': 'Nut', 'price': -2}, 'loose']}
r = call(token, 'create_entry', {'type': 'gadgets', 'title': 'Bad', 'body': 'X', 'status': 'draft', 'fields': bad})
t = r['text']
check('each bad value is named: a price that is not a number, a missing picture, too many photos, an unknown entry',
      r['isError'] and '"price"' in t and 'cheap' in t and '"cover"' in t and 'media library' in t and 'at most 3' in t and 'makers/nobody' in t, t)
check('and inside the rows: an unknown field, a price under its minimum, a row that is not an object',
      'parts[1]' in t and 'colour' in t and 'parts[2].price' in t and 'at least 0' in t and 'parts[3]' in t, t)
check('nothing is written', os.listdir('app/content/gadgets') == [], os.listdir('app/content/gadgets'))
r = call(token, 'create_entry', {'type': 'gadgets', 'title': 'Bad', 'body': 'X', 'status': 'draft', 'fields': {'price': -1}})
check('a price under the minimum is refused, not changed', r['isError'] and 'at least 0' in r['text'], r)

# ---- a check that saves nothing still knows the entries pointed at
good = {'price': '12.50', 'cover': '/uploads/media/one.jpg', 'photos': ['/uploads/media/two.jpg', BASE + '/uploads/media/three.jpg'], 'maker': 'makers/acme',
        'parts': [{'name': 'Bolt', 'price': 0.4, 'picture': '/uploads/media/four.jpg', 'made_by': 'makers/acme'}, {'name': 'Nut'}]}
r = call(token, 'validate_entry', {'type': 'gadgets', 'title': 'Widget', 'body': 'A widget.', 'status': 'published', 'fields': good})
check('validate_entry takes them all', r['isError'] is False and r['data'].get('ok'), r)

# ---- written
r = call(token, 'create_entry', {'type': 'gadgets', 'title': 'Widget', 'body': 'A widget.', 'status': 'published', 'fields': good})
check('an entry with every kind of field is made', r['isError'] is False, r)
slug = r['data'].get('entry', {}).get('slug', '')
lang = r['data'].get('entry', {}).get('lang', '')
files = os.listdir('app/content/gadgets')
text = read('app/content/gadgets/' + files[0]) if files else ''
maker_id = re.search(r'translation_id:\s*[\'"]?([A-Za-z0-9_-]+)', read('app/content/makers/' + os.listdir('app/content/makers')[0]))
maker_id = maker_id.group(1) if maker_id else '?'
check('the price is a number, the pictures are paths of the library (a full address too), the maker is kept by its id',
      re.search(r'price: 12\.5\b', text) and 'cover: /uploads/media/one.jpg' in text and '- /uploads/media/three.jpg' in text and BASE not in text and ('maker: ' + maker_id) in text and 'makers/acme' not in text, text)
check('the rows are kept with their own price, picture and maker; what a row leaves out is empty', 'name: Bolt' in text and re.search(r'price: 0\.4\b', text) and 'picture: /uploads/media/four.jpg' in text and ('made_by: ' + maker_id) in text and 'name: Nut' in text, text)

got = call(token, 'get_entry', {'type': 'gadgets', 'slug': slug})['data']
f = got.get('fields', {})
check('get_entry gives the entries pointed at as addresses, as they are written', f.get('maker') == 'makers/acme' and f.get('parts', [{}])[0].get('made_by') == 'makers/acme' and f.get('price') == 12.5, f)

# ---- one value for every translation
other = [l for l in info['site']['languages'] if l != lang][0]
r = call(token, 'create_entry', {'type': 'gadgets', 'lang': other, 'title': 'Widget (' + other + ')', 'body': 'Translated.', 'status': 'draft', 'translation_of': {'slug': slug, 'lang': lang}})
check('a translation is made', r['isError'] is False, r)
r = call(token, 'update_entry', {'type': 'gadgets', 'slug': slug, 'lang': other, 'fields': {'price': 15, 'parts': [{'name': 'Spring', 'price': '1.20'}]}})
check('a shared field is changed in one language', r['isError'] is False, r)
originals = [read('app/content/gadgets/' + n) for n in os.listdir('app/content/gadgets')]
check('and is the same in the other: price and rows', len(originals) == 2 and all(re.search(r'price: 15\b', x) and 'name: Spring' in x and 'name: Bolt' not in x for x in originals), originals)
check('while a field of its own stays in its language', sum('cover: /uploads/media/one.jpg' in x for x in originals) == 1, originals)

# ---- clearing
r = call(token, 'update_entry', {'type': 'gadgets', 'slug': slug, 'fields': {'parts': [], 'cover': '', 'photos': []}})
text = read('app/content/gadgets/' + [n for n in os.listdir('app/content/gadgets') if read('app/content/gadgets/' + n).find('A widget.') >= 0][0])
check('an empty list or "" clears a field', r['isError'] is False and 'name: Spring' not in text and '/uploads/media/one.jpg' not in text and '/uploads/media/two.jpg' not in text, text)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
