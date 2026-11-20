import io, json, os, re, sqlite3, sys, uuid, zipfile
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
root.submit('/admin/users-edit', lambda f: any(x[0] == 'username' for x in f['fields']), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed1', 'Sturdy-pass-99')

def token():
    form = next(f for f in root.forms('/admin/theme?tab=branding') if any(x[0] == 'active_tab' for x in f['fields']))
    return next(x[1] for x in form['fields'] if x[0] == '_csrf')
def raw(client, path, fields=None, upload=None, csrf=None):
    """POST like a browser (multipart when a file goes with it) and return (status, headers, bytes)."""
    boundary = uuid.uuid4().hex
    body = b''
    for k, v in (fields or {}).items():
        body += ('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v)).encode()
    if csrf:
        body += ('--%s\r\nContent-Disposition: form-data; name="_csrf"\r\n\r\n%s\r\n' % (boundary, csrf)).encode()
    if upload is not None:
        body += ('--%s\r\nContent-Disposition: form-data; name="branding_file"; filename="b.zip"\r\nContent-Type: application/zip\r\n\r\n' % boundary).encode() + upload + b'\r\n'
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
def style(html):
    m = re.search(r'<style id="gnozio-branding">(.*?)</style>', html, re.S)
    return m.group(1) if m else ''
def post_theme(overrides):
    form = next(f for f in root.forms('/admin/theme?tab=branding') if any(x[0] == 'active_tab' for x in f['fields']))
    fields = [tuple(x) for x in form['fields'] if x[0] not in overrides and x[0] != 'active_tab']
    fields += [(k, v) for k, v in overrides.items()] + [('active_tab', 'branding')]
    return root.request('/admin/theme', data=fields)

# ---- the screen
st, _, html = root.get('/admin/theme?tab=branding')
check('the screen has the transfer card, the forms card and the phone card', all(s in html for s in ('data-bp-transfer', 'branding=export', 'data-bt-file', 'data-bt-reset="type"', 'data-bt-reset="forms"', 'name="theme_settings[design][input_ring]"', 'name="theme_settings[design][mobile_gutter]"', 'name="theme_settings[design][h1_step]"', 'name="theme_settings[design][radius_card]"', 'name="theme_settings[design][body_weight]"', 'name="theme_settings[design][radius_input]"', 'admin-branding-transfer.js')))
check('shadows offer none, soft, medium and strong', re.findall(r'name="theme_settings\[design\]\[shadows\]" value="([^"]+)"', html) == ['auto', 'none', 'soft', 'medium', 'strong'], re.findall(r'name="theme_settings\[design\]\[shadows\]" value="([^"]+)"', html))
check('the script is served', get_raw(root, '/assets/js/admin-branding-transfer.js')[0] == 200)
check('nothing undoable yet, so no Undo row is shown', 'data-bt-undo-row hidden' in html)

# ---- the new choices reach the public page
post_theme({'theme_settings[design][h1_step]': '4', 'theme_settings[design][h1_weight]': '800', 'theme_settings[design][input_ring]': 'high', 'theme_settings[design][radius_card]': '24', 'theme_settings[design][shadows]': 'medium', 'theme_settings[design][mobile_gutter]': '14', 'theme_settings[design][light_accent]': '#1d4ed8'})
css = style(pub.get('/about')[2])
check('the page carries them', all(s in css for s in ('h1,:root[data-theme][data-mode] .hero-title', 'font-size:var(--step-4)', 'outline:3px solid var(--color-text)', 'border-radius:1.5rem', '--shadow-m:0 18px 40px -20px', '@media(max-width:640px)')), css[:300])
st, _, body = root.request('/admin/theme?preview=branding', data=[('_csrf', token()), ('theme_settings[design][h2_step]', '3'), ('theme_settings[design][error_style]', 'tinted')])
pcss = json.loads(body)['css']
check('and the live preview follows the unsaved ones', 'font-size:var(--step-3)' in pcss and 'border-left:3px solid var(--color-danger)' in pcss, pcss[:200])
mine = stored()

# ---- export
st, hdr, data = get_raw(root, '/admin/theme?branding=export')
z = zipfile.ZipFile(io.BytesIO(data)) if st == 200 else None
doc = json.loads(z.read('branding.json')) if z else {}
check('export is a zip with branding.json of all design settings (colours as a site palette, not as per-role overrides)', st == 200 and 'zip' in (hdr.get('Content-Type') or '') and doc.get('settings', {}).get('design', {}).get('h1_step') == '4' and doc['settings']['design']['light_accent'] == '' and '"accent":"#1d4ed8"' in doc['settings']['design']['site_palettes'] and doc['settings']['design']['site_palette'] == 'slate-custom', (st, list(doc)))
check('and only people who may change the theme can download it', [get_raw(ed, '/admin/theme?branding=export')[0] in (302, 403), get_raw(pub, '/admin/theme?branding=export')[0] in (302, 403)] == [True, True])
exported = data

# ---- a different site state: reset everything, then import with a preview
for section in ('colour', 'type', 'layout', 'shape', 'forms', 'buttons'):
    st, _, body = raw(root, '/admin/theme?branding=reset', {'section': section}, csrf=token())
    check('reset ' + section + ' answers', st == 200 and json.loads(body)['ok'], body[:150])
check('after the resets the page has no branding style', style(pub.get('/about')[2]) == '')
st, _, body = raw(root, '/admin/theme?branding=undo', csrf=token())
check('undo restores the last reset only', st == 200 and json.loads(body)['restored'], body[:150])
for section in ('colour', 'type', 'layout', 'shape', 'forms', 'buttons'):
    raw(root, '/admin/theme?branding=reset', {'section': section}, csrf=token())

before = stored()
st, _, body = raw(root, '/admin/theme?branding=preview', upload=exported, csrf=token())
pv = json.loads(body)
keys = {c['key']: c for c in pv.get('changes', [])}
check('the preview lists the differences and writes nothing', st == 200 and keys.get('h1_step', {}).get('to') == '4' and keys.get('shadows', {}).get('to') == 'medium' and stored() == before, body[:300])
st, _, body = raw(root, '/admin/theme?branding=preview', upload=b'not a branding file', csrf=token())
check('a wrong file is refused with a message', st == 422 and 'Branding' in json.loads(body)['error'], (st, body[:150]))
st, _, body = raw(root, '/admin/theme?branding=import', upload=exported, csrf=token())
check('the import applies', st == 200 and json.loads(body)['ok'], body[:150])
check('the page has the same branding CSS as before', style(pub.get('/about')[2]) == css, style(pub.get('/about')[2])[:200])
html = root.get('/admin/theme?tab=branding')[2]
check('the screen now offers Undo', 'data-bt-undo-row hidden' not in html and 'Undo the last import' in html)
st, _, body = raw(root, '/admin/theme?branding=undo', csrf=token())
check('one click undoes the import', st == 200 and style(pub.get('/about')[2]) == '', body[:150])

# ---- permissions and the token
etok = None
for client, label in ((ed, 'an editor'), (pub, 'someone not signed in')):
    codes = []
    for action in ('preview', 'import'):
        codes.append(raw(client, '/admin/theme?branding=' + action, upload=exported, csrf=token())[0])
    for action in ('undo', 'reset'):
        codes.append(raw(client, '/admin/theme?branding=' + action, {'section': 'type'}, csrf=token())[0])
    check(label + ' cannot preview, import, undo or reset', all(c in (302, 403, 419) for c in codes), codes)
st, _, body = raw(root, '/admin/theme?branding=import', upload=exported)
check('a request without the form token is refused and changes nothing', st in (302, 403, 419) and style(pub.get('/about')[2]) == '', st)
st, _, body = raw(root, '/admin/theme?branding=reset', {'section': 'bogus'}, csrf=token())
check('an unknown section is refused', st == 422, (st, body[:100]))

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
