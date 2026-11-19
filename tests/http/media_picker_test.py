import json, re, sys, urllib.request, urllib.error, uuid
sys.path.insert(0, '.')
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:240]))
    if not ok: fails.append(label)

PNG = bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360000002000001e221bc330000000049454e44ae426082')
def token(c): return re.search(r'name="_csrf" value="([0-9a-f]+)"', c.get('/admin')[2]).group(1)
def upload(c, files, tags=''):
    """Several files in one request: files is a list of (name, bytes, mime)."""
    b = uuid.uuid4().hex
    body = b''
    for k, v in (('_csrf', token(c)), ('media_action', 'upload'), ('upload_tags', tags)):
        body += f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    for name, data, mime in files:
        body += f'--{b}\r\nContent-Disposition: form-data; name="upload_file[]"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + data + b'\r\n'
    body += f'--{b}--\r\n'.encode()
    req = urllib.request.Request(BASE + '/admin/media', data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + b})
    try:
        return c.opener.open(req).status
    except urllib.error.HTTPError as e:
        return e.code
def api(c, query=''):
    st, hdr, body = c.get('/admin/media-picker' + ('?' + query if query else ''))
    try: return st, hdr, json.loads(body)
    except Exception: return st, hdr, None

root = Client(); root.login()
for name, role in (('ed1', 'editor'), ('usr1', 'user')):
    root.submit('/admin/users-edit', has_field('username'), {'username': name, 'email': name + '@example.test', 'display_name': name, 'role': role, 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed1', 'Sturdy-pass-99')
usr = Client(); usr.login('usr1', 'Sturdy-pass-99')

# thirty pictures, five of them tagged, and one document
# (PHP takes at most 20 files in one request)
upload(root, [(f'pic-{i:02d}.png', PNG, 'image/png') for i in range(1, 16)])
upload(root, [(f'pic-{i:02d}.png', PNG, 'image/png') for i in range(16, 26)])
upload(root, [(f'holiday-{i}.png', PNG, 'image/png') for i in range(1, 6)], tags='summer, beach')
upload(root, [('notes.txt', b'just text', 'text/plain')])

# ---- shape and pages
st, hdr, j = api(root)
check('the picker answers with JSON', st == 200 and j is not None and 'application/json' in hdr.get('Content-Type', ''), (st, hdr.get('Content-Type')))
check('and says it must not be cached', 'no-store' in (hdr.get('Cache-Control') or ''))
check('it holds only pictures: 30 of them, 24 to a page, 2 pages', j['total'] == 30 and len(j['items']) == 24 and j['pages'] == 2 and j['per_page'] == 24, {k: j[k] for k in ('total', 'pages', 'per_page')})
item = j['items'][0]
check('each has an address, a thumbnail, a name, alt text, and tags', all(k in item for k in ('url', 'thumb', 'name', 'alt', 'tags')) and item['url'].startswith('/uploads/'), item)
check('the document is not offered', all(i['kind'] == 'image' for i in j['items']) and 'notes.txt' not in json.dumps(j))
st, _, j2 = api(root, 'page=2')
check('page 2 has the other six', j2['page'] == 2 and len(j2['items']) == 6, (j2['page'], len(j2['items'])))
check('no picture is on both pages', not ({i['url'] for i in j['items']} & {i['url'] for i in j2['items']}))
st, _, j3 = api(root, 'page=99'); check('a page past the end shows the last one', j3['page'] == 2, j3['page'])
st, _, j4 = api(root, 'page=0'); check('page 0 shows the first', j4['page'] == 1)
st, _, j5 = api(root, 'per_page=1'); check('a tiny page size is raised to 6', j5['per_page'] == 6 and len(j5['items']) == 6, j5['per_page'])
st, _, j6 = api(root, 'per_page=5000'); check('a huge one is held at 48', j6['per_page'] == 48 and len(j6['items']) == 30, j6['per_page'])

# ---- search and tags
st, _, s1 = api(root, 'q=pic-07'); check('a search finds by name', s1['total'] == 1 and s1['items'][0]['name'] == 'pic-07.png', s1['total'])
st, _, s2 = api(root, 'q=holiday'); check('and returns all of them', s2['total'] == 5)
st, _, s3 = api(root, 'q=beach'); check('by tag too', s3['total'] == 5)
st, _, s4 = api(root, 'tag=summer'); check('the tag filter narrows to the tagged', s4['total'] == 5 and all('summer' in i['tags'] for i in s4['items']), s4['total'])
st, _, s5 = api(root, 'tag=summer&q=holiday-3'); check('a tag and a search together', s5['total'] == 1)
st, _, s6 = api(root, 'q=nothing-like-this'); check('no match is an empty page, not an error', st == 200 and s6['total'] == 0 and s6['items'] == [] and s6['pages'] == 1 and s6['page'] == 1)
check('the tags to choose from come with every answer', 'summer' in j['tags'] and 'beach' in j['tags'], j['tags'])
st, _, sx = api(root, 'q=%22%3E%3Cscript%3E'); check('odd characters in a search are harmless', st == 200 and sx['total'] == 0)
st, _, sk = api(root, 'kind=all'); check('kind=all includes the document', sk['total'] == 31 and any(i['name'] == 'notes.txt' for i in sk['items'] + api(root, 'kind=all&page=2')[2]['items']), sk['total'])

# ---- who may use it
st, _, je = api(ed); check('an editor may (it is part of editing)', st == 200 and je and je['total'] == 30, st)
st, hdr, body = usr.get('/admin/media-picker'); check('a person with no rights is refused', st == 403 and 'application/json' not in hdr.get('Content-Type', ''), st)
anon = Client(); st, hdr, _ = anon.get('/admin/media-picker'); check('and someone not signed in is sent to the login', st == 302 and 'login' in (hdr.get('Location') or ''), (st, hdr.get('Location')))

# ---- the pages use it and no longer carry the pictures
html = root.get('/admin/edit?type=pages&slug=about&lang=el')[2]
check('the pages point at the picker and load its script', 'name="media-picker-url"' in html and 'admin-media-picker.js' in html)
check('the editor page no longer lists the pictures itself', 'media-picker-panel' not in html and 'data-media-pick=' not in html and 'pic-07.png' not in html)
m = re.search(r'id="block-editor-data"[^>]*>(.*?)</script>', html, re.S) or re.search(r'<script type="application/json"[^>]*>(\{"definitions".*?)</script>', html, re.S)
check('nor does the block editor data', m is not None and '"media"' not in m.group(1) and 'pic-07' not in m.group(1), (m.group(1)[:80] if m else None))
check('the Media Library button is still there', 'data-media-picker-toggle' in html)
theme = root.get('/admin/theme')[2]
check('the logo setting gets a Library button', 'name="theme_settings[logo]"' in theme.replace('name="theme_settings[brand][logo]"', 'name="theme_settings[logo]"') and 'data-image-field' in theme, re.findall(r'<input[^>]*logo[^>]*>', theme)[:1])
js = urllib.request.urlopen(BASE + '/assets/js/admin-media-picker.js').read().decode()
check('the script is served', 'FarosMediaPicker' in js and 'media-picker-url' in js)

# ---- uploading from the picker's dialog
def pick_upload(c, files, kind='image'):
    b = uuid.uuid4().hex
    body = b''
    for k, v in (('_csrf', token(c)), ('kind', kind)):
        body += f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    for name, data, mime in files:
        body += f'--{b}\r\nContent-Disposition: form-data; name="upload_file[]"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + data + b'\r\n'
    body += f'--{b}--\r\n'.encode()
    req = urllib.request.Request(BASE + '/admin/media-picker', data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + b})
    try:
        r = c.opener.open(req); return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        return e.code, None
check('the picker says who may upload: the administrator and an editor', api(root)[2].get('can_upload') is True and api(ed)[2].get('can_upload') is True)
st, r = pick_upload(ed, [('fresh-one.png', PNG, 'image/png')])
check('an editor uploads a picture from the dialog and gets it back to choose', st == 200 and r and r['ok'] and len(r['items']) == 1 and r['items'][0]['url'].startswith('/uploads/') and r['items'][0]['name'] == 'fresh-one.png', r)
check('and it is in the library', api(root, 'q=fresh-one')[2]['total'] == 1)
st, r = pick_upload(root, [('readme.txt', b'text', 'text/plain')])
check('a file of another kind stays in the library and is named, nothing is chosen', st == 200 and r and not r['ok'] and r['items'] == [] and 'readme.txt' in r['message'] and api(root, 'kind=all&q=readme')[2]['total'] == 1, r)
st, r = pick_upload(root, [])
check('an upload with no file says so', st == 200 and r and not r['ok'] and r['message'] != '', r)
st, r = pick_upload(usr, [('nope.png', PNG, 'image/png')])
check('a person with no rights may not upload', st == 403 and api(root, 'q=nope')[2]['total'] == 0, st)

# ---- the entry's own gallery in the Media tab (a type with no gallery field: pages)
html = root.get('/admin/edit?type=pages&slug=about&lang=el')[2]
check('the Media tab of a page has a Gallery', 'name="entry_gallery"' in html and 'data-gallery-field' in html)
urls = [i['url'] for i in api(root, 'q=pic-0')[2]['items'][:2]]
root.submit('/admin/edit?type=pages&slug=about&lang=el', has_field('entry_gallery'), {'entry_gallery': '\n'.join(urls)})
html = root.get('/admin/edit?type=pages&slug=about&lang=el')[2]
m = re.search(r'<textarea name="entry_gallery"[^>]*>(.*?)</textarea>', html, re.S)
check('saved: the page keeps its gallery, in order', m is not None and m.group(1).split('\n') == urls, m.group(1) if m else None)
check('and it is not offered again as a custom field', 'value="gallery"' not in html)
root.submit('/admin/edit?type=pages&slug=about&lang=el', has_field('entry_gallery'), {'entry_gallery': ''})
m = re.search(r'<textarea name="entry_gallery"[^>]*>(.*?)</textarea>', root.get('/admin/edit?type=pages&slug=about&lang=el')[2], re.S)
check('emptied: the gallery is gone', m is not None and m.group(1).strip() == '', m.group(1) if m else None)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
