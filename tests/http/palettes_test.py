import json, os, re, sys, threading, sqlite3
from http.server import BaseHTTPRequestHandler, HTTPServer
sys.path.insert(0, '.')
from client import Client

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:260]))
    if not ok: fails.append(label)

# A stand-in for Duo on this machine: a few made-up pairs, and it counts the requests.
HITS = []
MODE = {'body': json.dumps({'colors': [
    {'colors': ['#1d4ed8', '#f8fafc'], 'aa_accessible': True},
    {'colors': ['#ec4899', '#fdf2f8'], 'aa_accessible': False},
]})}
class Duo(BaseHTTPRequestHandler):
    def do_GET(self):
        HITS.append(self.path)
        if MODE['body'] is None:
            self.send_response(500); self.end_headers(); return
        self.send_response(200); self.send_header('Content-Type', 'application/json'); self.end_headers()
        self.wfile.write(MODE['body'].encode())
    def log_message(self, *a): pass
srv = HTTPServer(('127.0.0.1', 0), Duo)
threading.Thread(target=srv.serve_forever, daemon=True).start()
DUO = 'http://127.0.0.1:%d/colors.json' % srv.server_address[1]

root = Client(); root.login()
pub = Client()

def post_theme(overrides, tab='branding'):
    form = next(f for f in root.forms('/admin/theme?tab=' + tab) if any(x[0] == 'active_tab' for x in f['fields']))
    fields = [tuple(x) for x in form['fields'] if x[0] not in overrides and x[0] != 'active_tab']
    for k, v in overrides.items():
        if v is not None:
            fields.append((k, v))
    fields.append(('active_tab', tab))
    return root.request('/admin/theme', data=fields)
def d(**v): return {'theme_settings[design][%s]' % k: val for k, val in v.items()}
def style():
    m = re.search(r'<style id="gnozio-branding">(.*?)</style>', pub.get('/about')[2], re.S)
    return m.group(1) if m else ''
def stored():
    c = sqlite3.connect('app/storage/db/app.sqlite'); v = c.execute("select value from system_meta where key='theme_settings'").fetchone(); c.close()
    return v[0] if v else ''
form = next(f for f in root.forms('/admin/theme?tab=branding') if any(x[0] == 'active_tab' for x in f['fields']))
token = next(x[1] for x in form['fields'] if x[0] == '_csrf')

home_before = pub.get('/about')[2]

# ---- the generator
st, hdr, body = root.request('/admin/theme?palette=generate', data=[('_csrf', token), ('accent', '#fb5870'), ('base', '#212c34'), ('base_role', 'background'), ('secondary', '#ff8800')])
gen = json.loads(body) if st == 200 else {}
check('the generator answers with the six roles for light and dark', st == 200 and gen.get('ok') is True and set(gen['light']) == {'accent', 'background', 'surface', 'text', 'muted', 'border'} and gen['dark']['background'] == '#212c34' and gen['passes'] is True and 'secondary' in gen, body[:200])
st, _, body = root.request('/admin/theme?palette=generate', data=[('_csrf', token), ('accent', 'red'), ('base', '#212c34')])
check('colours that are not hex are refused', json.loads(body).get('ok') is False, body[:200])
st, _, body = root.request('/admin/theme?palette=generate', data=[('accent', '#fb5870'), ('base', '#212c34')])
check('a request without the form token is refused', st in (302, 403, 419) and '"light"' not in body, st)
st, _, _ = pub.request('/admin/theme?palette=duo')
check('and so is one from someone who is not signed in', st in (302, 403, 419), st)

# ---- a site palette is saved, used, edited and deleted
def encode(palettes): return json.dumps(palettes, separators=(',', ':'))
brand = {'id': 'brand', 'name': 'Brand', 'light': gen['light'], 'dark': gen['dark'], 'ink': gen['ink'], 'secondary': gen['secondary']}
post_theme(d(site_palettes=encode([brand]), site_palette='brand'))
css = style()
check('a saved site palette in use gives the page its colours', '--n-bg:%s;' % gen['light']['background'] in css and '--d-bg:%s;' % gen['dark']['background'] in css and '--accent-l:%s;' % gen['light']['accent'] in css, css[:300])
check('and the secondary colour with its variables', '--secondary-l:%s;' % gen['secondary']['light'] in css and '--color-secondary:var(--secondary-l);' in css, css[:400])
screen = root.get('/admin/theme?tab=branding')[2]
check('the screen carries them back for the editor', 'data-pl-store' in screen and '&quot;id&quot;:&quot;brand&quot;' in screen, screen[:100])
brand['name'] = 'Brand renamed'; brand['light'] = dict(brand['light'], accent='#b91c1c')
second = {'id': 'second', 'name': 'Second', 'light': {'accent': '#0f766e'}}
post_theme(d(site_palettes=encode([brand, second]), site_palette='brand'))
check('editing a role changes the page and several palettes are kept', '--accent-l:#b91c1c;' in style() and 'Brand renamed' in stored() and '"second"' in stored())
post_theme(d(site_palettes=encode([brand, second]), site_palette='second'))
check('choosing another palette uses that one', '--accent-l:#0f766e;' in style())
post_theme(d(site_palettes=encode([brand, second]), site_palette='second', light_accent='#7c3aed'))
check('an old per-role colour sent with the form is folded into the palette in use, and still wins', '--accent-l:#7c3aed;' in style() and '--accent-l:#0f766e;' not in style() and '#7c3aed' in stored().split('site_palettes')[1] and "light_accent: ''" in stored() or 'light_accent: \'\'' in stored(), stored()[:600])
post_theme(d(site_palettes=encode([brand]), site_palette='second', light_accent=''))
check('deleting the palette in use returns the page to the ready-made palette', '--accent-l:#0f766e;' not in style() and '--n-bg:' not in style(), style()[:200])
post_theme(d(site_palettes=encode([brand]), site_palette=''))
check('a palette that is stored but not in use adds nothing', pub.get('/about')[2] == home_before)
post_theme(d(site_palettes='<script>alert(1)</script>', site_palette='x'))
check('junk in the setting adds nothing and cannot reach the page', pub.get('/about')[2] == home_before and '<script>alert' not in pub.get('/about')[2])
post_theme(d(site_palettes='', site_palette='', light_secondary='#b45309'))
check('a secondary colour alone also works', '--color-secondary:var(--secondary-l);' in style() and '--secondary-d:#b45309;' in style())
post_theme(d(site_palettes='', site_palette='', light_secondary=''))
check('and set back it leaves the page as it was', pub.get('/about')[2] == home_before)

# ---- the preview follows a palette that is not saved yet
st, _, body = root.request('/admin/theme?preview=branding', data=[('_csrf', token), ('theme_settings[design][site_palettes]', encode([brand])), ('theme_settings[design][site_palette]', 'brand')])
check('the live preview draws a palette that is not saved', st == 200 and '--accent-l:#b91c1c;' in json.loads(body)['css'] and 'b91c1c' not in stored(), body[:200])

# ---- Duo
post_theme(d(duo_url=DUO, duo_picker='1'))
st, _, body = root.get('/admin/theme?palette=duo')
duo = json.loads(body)
check('the pairs come from the address of the setting, with the credit', duo.get('ok') is True and len(duo['pairs']) == 2 and duo['pairs'][0] == {'colors': ['#1d4ed8', '#f8fafc'], 'aa': True} and duo['credit'] == 'Colour pairings from Duo by Alex Pate' and duo['credit_url'] == 'https://duo.alexpate.com', body[:200])
n = len(HITS)
root.get('/admin/theme?palette=duo')
check('and are cached, so the second look asks nobody', len(HITS) == n and n == 1, HITS)
screen = root.get('/admin/theme?tab=branding')[2]
check('opening the Branding screen does not contact Duo; only the picker does', len(HITS) == 1 and 'duo.alexpate.com' in screen and 'Colour pairings from Duo by Alex Pate' in screen)
check('the public site never contacts Duo', pub.get('/about') and pub.get('/') and len(HITS) == 1 and 'duo' not in pub.get('/about')[2].lower())
if os.path.exists('app/storage/cache/duo-pairings.json'):
    os.remove('app/storage/cache/duo-pairings.json')
MODE['body'] = None
st, _, body = root.get('/admin/theme?palette=duo')
down = json.loads(body)
check('when Duo fails the answer is a quiet note and the screen still opens', st == 200 and down.get('ok') is False and 'not available' in down.get('message', '') and root.get('/admin/theme?tab=branding')[0] == 200, body[:200])
MODE['body'] = '<html>not json</html>'
st, _, body = root.get('/admin/theme?palette=duo')
check('an answer that is not a list of pairs is the same', json.loads(body).get('ok') is False)
MODE['body'] = json.dumps({'colors': [{'colors': ['#1d4ed8', '#f8fafc'], 'aa_accessible': True}]})
post_theme(d(duo_url='http://duo.example.test/colors.json', duo_picker='1'))
st, _, body = root.get('/admin/theme?palette=duo')
check('an address that is not https is not asked', json.loads(body).get('ok') is False)
post_theme(d(duo_url=DUO, duo_picker=None))  # the toggle is left out: off
before = len(HITS)
st, _, body = root.get('/admin/theme?palette=duo')
check('switched off, the picker is not offered and nothing is asked', json.loads(body).get('ok') is False and len(HITS) == before, body[:200])
check('and the screen shows the switch off', re.search(r'<input[^>]*type="checkbox"[^>]*name="theme_settings\[design\]\[duo_picker\]"[^>]*checked', root.get('/admin/theme?tab=branding')[2]) is None)

# ---- one place for colours: the screen, Customise, Fix, the migration on load, and who may do it
root.submit('/admin/users-edit', lambda f: any(x[0] == 'username' for x in f['fields']), {'username': 'ed2', 'email': 'ed2@example.test', 'display_name': 'ed2', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed2', 'Sturdy-pass-99')
screen = root.get('/admin/theme?tab=branding')[2]
check('the Colour card has the palette row with + Custom', 'data-pl-chips' in screen and '+ Custom' in screen)
check('and no per-role override fields under it', 'name="theme_settings[design][light_accent]"' not in screen and 'name="theme_settings[design][dark_secondary]"' not in screen and 'Reset colour overrides' not in screen)
check('the Duo settings are in the "+ Custom" flow, not a section of their own', 'Colour pairings settings' not in screen and 'pl-set-body' in screen)
check('the ready-made palettes are still plain radio buttons of the form', screen.count('name="theme_settings[appearance][palette]"') == 7, screen.count('name="theme_settings[appearance][palette]"'))
post_theme({})
st, _, body = root.request('/admin/theme?palette=copy', data=[('_csrf', token), ('builtin', 'teal'), ('taken[]', 'teal-custom')])
cp = json.loads(body)
check('Customise: a copy of Teal with a new id, its source, and the colours of Teal', st == 200 and cp['ok'] and cp['palette']['id'] == 'teal-custom-2' and cp['palette']['name'] == 'Teal (custom)' and cp['palette']['source'] == 'teal' and cp['palette']['light']['accent'] == '#0f766e', body[:200])
st, _, body = root.request('/admin/theme?palette=copy', data=[('_csrf', token), ('builtin', 'nope')])
check('only a ready-made palette can be copied', json.loads(body).get('ok') is False)
st, _, body = root.request('/admin/theme?palette=fix', data=[('_csrf', token), ('colour', '#34d5c3'), ('against', '#ffffff'), ('min', '4.5')])
fx = json.loads(body)
check('Fix answers with the nearest shade that reads at AA', st == 200 and fx['ok'] and fx['ratio_before'] == 1.83 and fx['ratio_after'] >= 4.5 and fx['after'] != fx['before'], body[:200])
edtoken = ''
for f in ed.forms('/admin/profile') + ed.forms('/admin'):
    edtoken = edtoken or next((x[1] for x in f['fields'] if x[0] == '_csrf'), '')
for what, data in (('copy', [('builtin', 'teal')]), ('fix', [('colour', '#34d5c3'), ('against', '#fff')])):
    st, _, body = ed.request('/admin/theme?palette=' + what, data=data + [('_csrf', edtoken)])
    check('%s is refused to an editor, who may not change the theme (token %s)' % (what, 'sent' if edtoken else 'missing'), edtoken != '' and st in (302, 403) and '"palette"' not in body and '"after"' not in body, st)
    st, _, body = pub.request('/admin/theme?palette=' + what, data=data)
    check('and to someone who is not signed in (%s)' % what, st in (302, 403, 419) and '"after"' not in body, st)
st, _, body = root.request('/admin/theme?palette=fix', data=[('colour', '#34d5c3'), ('against', '#fff')])
check('a request without the form token is refused (fix)', st in (302, 403, 419) and '"after"' not in body, st)

# an old site: overrides under a ready-made palette are folded on load, the page does not change, and it is stored once
def put_settings(text):
    c = sqlite3.connect('app/storage/db/app.sqlite'); c.execute("insert or replace into system_meta(key, value, updated_at) values('theme_settings', ?, '2026-10-16T00:00:00+00:00')", (text,)); c.commit(); c.close()
put_settings("appearance:\n  palette: teal\ndesign:\n  light_accent: '#0e7490'\n  dark_accent: '#67e8f9'\n  dark_background: '#0d1a24'\n  light_secondary: '#b45309'\n")
screen = root.get('/admin/theme?tab=branding')[2]
check('on load the old overrides are a site palette "Teal (custom)" in use, with Teal as its source', 'Teal (custom)' in screen and '&quot;source&quot;:&quot;teal&quot;' in screen and 'value="teal-custom"' in screen, screen[:100])
saved = stored()
check('and they are stored that way, without the overrides', 'teal-custom' in saved and "light_accent: ''" in saved and "dark_background: ''" in saved and '#0e7490' in saved, saved[:300])
css = style()
check('the page has the colours the overrides gave it', '--accent-l:#0e7490;' in css and '--accent-d:#67e8f9;' in css and '--d-bg:#0d1a24;' in css and '--secondary-l:#b45309;' in css and 'data-theme="teal"' in pub.get('/about')[2], css[:300])
root.get('/admin/theme?tab=branding')
check('loading again changes nothing', stored() == saved)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
