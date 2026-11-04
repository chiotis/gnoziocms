"""Theme > Export and import over HTTP: the tab, export, the preview of each part, importing the ticked parts, undo, and who may."""
import io, json, sqlite3, sys, uuid, zipfile
import urllib.request, urllib.error
sys.path.insert(0, '.')
from client import Client, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:260]))
    if not ok: fails.append(label)

def stored():
    c = sqlite3.connect('app/storage/db/app.sqlite'); v = c.execute("select value from system_meta where key='theme_settings'").fetchone(); c.close()
    return v[0] if v else ''

root = Client(); root.login()
pub = Client()
root.submit('/admin/users-edit', lambda f: any(x[0] == 'username' for x in f['fields']), {'username': 'ed2', 'email': 'ed2@example.test', 'display_name': 'ed2', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed2', 'Sturdy-pass-99')

def theme_form():
    return next(f for f in root.forms('/admin/theme?tab=transfer') if any(x[0] == 'active_tab' for x in f['fields']))
def token():
    return next(x[1] for x in theme_form()['fields'] if x[0] == '_csrf')
def raw(client, path, fields=(), upload=None, csrf=None):
    boundary = uuid.uuid4().hex
    body = b''
    for k, v in list(fields) + ([('_csrf', csrf)] if csrf else []):
        body += ('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v)).encode()
    if upload is not None:
        body += ('--%s\r\nContent-Disposition: form-data; name="theme_file"; filename="t.zip"\r\nContent-Type: application/zip\r\n\r\n' % boundary).encode() + upload + b'\r\n'
    body += ('--%s--\r\n' % boundary).encode()
    req = urllib.request.Request(BASE + path, data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
    try:
        resp = client.opener.open(req)
        return resp.status, resp.headers, resp.read()
    except urllib.error.HTTPError as e:
        return e.code, e.headers, e.read()
def get_raw(client, path):
    try:
        resp = client.opener.open(urllib.request.Request(BASE + path))
        return resp.status, resp.headers, resp.read()
    except urllib.error.HTTPError as e:
        return e.code, e.headers, e.read()
def save(overrides):
    form = theme_form()
    fields = [tuple(x) for x in form['fields'] if x[0] not in overrides and x[0] != 'active_tab']
    fields += list(overrides.items()) + [('active_tab', 'transfer')]
    return root.request('/admin/theme', data=fields)

# ---- the tab
st, _, html = root.get('/admin/theme?tab=transfer')
check('the Theme screen has the Export and import tab', st == 200 and 'data-tab="transfer"' in html and 'data-theme-transfer' in html and 'transfer=export' in html and 'admin-theme-transfer.js' in html, st)
check('the script is served', get_raw(root, '/assets/js/admin-theme-transfer.js')[0] == 200)
check('nothing to undo yet', 'data-tt-undo-row hidden' in html)

# ---- a theme to carry: shadows (Branding), Custom CSS
save({'theme_settings[design][shadows]': 'strong', 'custom_css': '.carried { color: red; }'})
st, hdr, data = get_raw(root, '/admin/theme?transfer=export')
doc = {}
if st == 200:
    doc = json.loads(zipfile.ZipFile(io.BytesIO(data)).read('theme.json'))
check('export is a zip with theme.json', st == 200 and 'zip' in (hdr.get('Content-Type') or '') and doc.get('format') == 'gnoziocms-theme', (st, list(doc)))
check('it carries Branding, Custom CSS and the archives of the lists', doc.get('settings', {}).get('design', {}).get('shadows') == 'strong' and '.carried' in doc.get('custom_css', '') and 'posts' in doc.get('archives', {}).get('types', {}), list(doc.get('archives', {})))
check('only people who may change the theme can download it', [get_raw(ed, '/admin/theme?transfer=export')[0] in (302, 403), get_raw(pub, '/admin/theme?transfer=export')[0] in (302, 403)] == [True, True])
exported = data

# ---- another state, then the preview
save({'theme_settings[design][shadows]': 'none', 'custom_css': ''})
before = stored()
st, _, body = raw(root, '/admin/theme?transfer=preview', upload=exported, csrf=token())
pv = json.loads(body)
parts = {p['key']: p for p in pv.get('parts', [])}
check('the preview has a part for each area, with what would change', st == 200 and any(c['to'] == 'strong' for c in parts.get('branding', {}).get('changes', [])) and parts.get('custom_css', {}).get('changes') and 'archive_layouts' in parts, body[:300])
check('Brand is offered but not ticked', parts.get('section:brand', {}).get('ticked') is False)
check('the preview writes nothing', stored() == before)
st, _, body = raw(root, '/admin/theme?transfer=preview', upload=b'not a theme file', csrf=token())
check('a wrong file is refused with a message', st == 422 and 'theme' in json.loads(body)['error'], (st, body[:150]))

# ---- import only Branding: the CSS stays as it is
st, _, body = raw(root, '/admin/theme?transfer=import', [('parts[]', 'branding')], upload=exported, csrf=token())
check('importing one part', st == 200 and json.loads(body)['ok'] and json.loads(body)['changed'] >= 1, body[:200])
html = root.get('/admin/theme?tab=transfer')[2]
check('Branding came', 'shadows: strong' in stored(), stored()[:200])
check('the Custom CSS box is still empty', '.carried' not in root.get('/admin/theme?tab=custom_css')[2])
check('Undo is offered now', 'data-tt-undo-row hidden' not in html and 'Undo the last import' in html)
st, _, body = raw(root, '/admin/theme?transfer=import', [('parts[]', 'custom_css')], upload=exported, csrf=token())
check('then Custom CSS', st == 200 and '.carried' in root.get('/admin/theme?tab=custom_css')[2], body[:200])
st, _, body = raw(root, '/admin/theme?transfer=import', [], upload=exported, csrf=token())
check('no part ticked is refused', st == 422, (st, body[:150]))

# ---- undo
st, _, body = raw(root, '/admin/theme?transfer=undo', csrf=token())
check('undo puts back the state before the last import', st == 200 and '.carried' not in root.get('/admin/theme?tab=custom_css')[2] and 'shadows: strong' in stored(), body[:150])

# ---- who may
for client, label in ((ed, 'an editor'), (pub, 'someone not signed in')):
    codes = [raw(client, '/admin/theme?transfer=' + a, [('parts[]', 'branding')], upload=exported, csrf=token())[0] for a in ('preview', 'import', 'undo')]
    check(label + ' cannot preview, import or undo', all(c in (302, 403, 419) for c in codes), codes)
st, _, body = raw(root, '/admin/theme?transfer=import', [('parts[]', 'custom_css')], upload=exported)
check('a request without the form token is refused', st in (302, 403, 419), st)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
