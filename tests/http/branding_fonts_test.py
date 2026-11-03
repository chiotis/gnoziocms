import json, os, re, struct, sys, urllib.request, urllib.error, uuid, zlib
sys.path.insert(0, '.')
from client import Client, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:260]))
    if not ok: fails.append(label)

def make_font(ranges, family, style='Regular', weight=400):
    """A tiny but valid TrueType font: a character map (format 4), names, OS/2, head, hhea and hmtx."""
    glyph, segs, nxt = {}, [], 1
    for a, b in ranges:
        segs.append((a, b, (nxt - a) & 0xFFFF)); nxt += b - a + 1
    segs.append((0xFFFF, 0xFFFF, 1))
    n = len(segs)
    sub = struct.pack('>HHHHHHH', 4, 16 + 8 * n, 0, n * 2, 0, 0, 0)
    sub += b''.join(struct.pack('>H', s[1]) for s in segs) + b'\0\0' + b''.join(struct.pack('>H', s[0]) for s in segs)
    sub += b''.join(struct.pack('>H', s[2]) for s in segs) + b'\0\0' * n
    cmap = struct.pack('>HHHHI', 0, 1, 3, 1, 12) + sub
    head = b'\0' * 18 + struct.pack('>H', 1000) + b'\0' * 24 + struct.pack('>H', 0) + b'\0' * 8
    hhea = struct.pack('>IhhH', 0x10000, 800, -200, 0) + b'\0' * 24 + struct.pack('>H', nxt)
    hmtx = struct.pack('>Hh', 556, 0) * nxt
    os2 = struct.pack('>HhH', 4, 556, weight) + b'\0' * 56 + struct.pack('>H', 0) + b'\0' * 14
    strings = [(1, family), (2, style)]
    name, store = struct.pack('>HHH', 0, len(strings), 6 + 12 * len(strings)), b''
    for i, t in strings:
        u = t.encode('utf-16-be'); name += struct.pack('>HHHHHH', 3, 1, 0x409, i, len(u), len(store)); store += u
    tables = {'OS/2': os2, 'cmap': cmap, 'head': head, 'hhea': hhea, 'hmtx': hmtx, 'name': name + store}
    d, body, off = b'', b'', 12 + 16 * len(tables)
    for tag in sorted(tables):
        data = tables[tag]; d += tag.encode() + struct.pack('>III', 0, off + len(body), len(data)); body += data + b'\0' * (-len(data) % 4)
    return b'\0\1\0\0' + struct.pack('>HHHH', len(tables), 0, 0, 0) + d + body

LATIN = [(0x20, 0x7E), (0xA0, 0xFF)]
GREEK = LATIN + [(0x391, 0x3A1), (0x3A3, 0x3A9), (0x3B1, 0x3C9), (0x20AC, 0x20AC), (0x2022, 0x2022), (0x2192, 0x2192), (0xA9, 0xA9)]

def multipart(client, path, fields, files, headers=None):
    boundary = '----faros' + uuid.uuid4().hex
    body = b''
    for k, v in fields:
        body += ('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v)).encode()
    for k, name, data in files:
        body += ('--%s\r\nContent-Disposition: form-data; name="%s"; filename="%s"\r\nContent-Type: application/octet-stream\r\n\r\n' % (boundary, k, name)).encode() + data + b'\r\n'
    body += ('--%s--\r\n' % boundary).encode()
    h = {'Content-Type': 'multipart/form-data; boundary=' + boundary}
    h.update(headers or {})
    req = urllib.request.Request(BASE + path, data=body, headers=h)
    try:
        resp = client.opener.open(req); st, hdrs, content = resp.status, resp.headers, resp.read()
    except urllib.error.HTTPError as e:
        st, hdrs, content = e.code, e.headers, e.read()
    return st, hdrs, content.decode('utf-8', 'replace')

root = Client(); root.login()
pub = Client()
root.submit('/admin/users-edit', lambda f: any(x[0] == 'username' for x in f['fields']), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed1', 'Sturdy-pass-99')
ENDPOINT = '/admin/theme?preview=fonts'

def theme_form(client=root):
    return next(f for f in client.forms('/admin/theme?tab=branding') if any(x[0] == 'active_tab' for x in f['fields']))
def csrf():
    return next(v for k, v in theme_form()['fields'] if k == '_csrf')
def upload(client, name, data, family='', token=None, field='font'):
    return multipart(client, ENDPOINT, [('action', 'upload'), ('family', family), ('_csrf', token if token is not None else csrf())], [(field, name, data)])
def post_theme(overrides):
    form = theme_form()
    fields = [tuple(x) for x in form['fields'] if not any(x[0] == k or x[0].startswith(k + '[') for k in overrides)]
    for k, v in overrides.items():
        for item in (v if isinstance(v, list) else [v]):
            fields.append((k, item))
    return root.request('/admin/theme', data=fields)
def style(html):
    m = re.search(r'<style id="faros-branding">(.*?)</style>', html, re.S)
    return m.group(1) if m else ''

# ---- the screen
st, _, html = root.get('/admin/theme?tab=branding')
check('Branding has the fonts block, its script and an empty list to start with', st == 200 and 'data-bf ' in html and 'admin-branding-fonts.js' in html and 'name="theme_settings[design][fonts]" value=""' in html, st)
check('it offers the family for headings and for text, and upload for four kinds of file', 'name="theme_settings[design][heading_family]"' in html and 'name="theme_settings[design][body_family]"' in html and '.woff2,.woff,.ttf,.otf' in html)
check('the single font file field of earlier versions is still posted, and shown only when it is set', 'name="theme_settings[design][font_file]"' in html and 'data-bp-custom-font' not in html)
check('Your own font is the name of the choice', 'Your own font</option>' in html or 'Your own font<' in html)
js = root.get('/assets/js/admin-branding-fonts.js')
check('the script is served', js[0] == 200 and 'data-bf' in js[2], js[0])
check('the page is unchanged for visitors', 'faros-branding' not in pub.get('/about')[2])

# ---- uploading: who may, and what is accepted
token = csrf()
st, _, body = upload(root, 'Acme-Regular.ttf', make_font(GREEK, 'Acme Sans', 'Regular', 400), 'Acme Sans')
answer = json.loads(body) if st == 200 else {}
entry = answer.get('entry', {})
check('an administrator can upload a font; the answer says what was read', st == 200 and answer.get('ok') is True and entry.get('family') == 'Acme Sans' and entry.get('weight') == '400' and entry.get('cover') == 'latin,greek,symbols', body[:300])
check('it is stored under custom/assets/fonts/<family>/ and nothing is added to the settings yet', re.match(r'^fonts/acme-sans/acme-regular-[0-9a-f]{8}\.ttf$', entry.get('file', '')) is not None and os.path.isfile('app/custom/assets/' + entry.get('file', '/')) and 'acme-regular' not in root.get('/admin/theme?tab=branding')[2], entry)
st, hdr, _ = pub.get('/_custom/' + entry['file'] + '?v=' + entry['v'])
# Either as a copy in public/ that the web server serves itself, or by index.php with the long cache of a versioned file and no sniffing.
check('the file is served as a font', st == 200 and (hdr.get('Content-Type') or '').startswith('font/ttf') and (os.path.isfile('app/public/_custom/' + entry['file']) or ('immutable' in (hdr.get('Cache-Control') or '') and hdr.get('X-Content-Type-Options') == 'nosniff')), (st, dict(hdr)))
bold = json.loads(upload(root, 'Acme-Bold.ttf', make_font(GREEK, 'Acme Sans', 'Bold', 700), 'Acme Sans')[2])
check('a second file joins the family with the weight of its name', bold.get('ok') is True and bold['entry']['weight'] == '700' and bold['entry']['family'] == 'Acme Sans', bold)
latin = json.loads(upload(root, 'Latina-Regular.ttf', make_font(LATIN, 'Latina', 'Regular', 400))[2])
check('a Latin-only font is read as Latin only', latin['entry']['cover'] == 'latin' and latin['entry']['family'] == 'Latina', latin)
bad = [upload(root, 'x.woff2', b'not a font ' * 20), upload(root, 'x.php', make_font(LATIN, 'X')), upload(root, 'x.ttf', make_font(LATIN, 'X'), field='other')]
check('something that is not a font, a file of another type and a missing file are refused', all(json.loads(b)['ok'] is False for _, _, b in bad), [b[:80] for _, _, b in bad])
def own_token(client):
    m = re.search(r'name="_csrf" value="([^"]+)"', client.get('/admin')[2])
    return m.group(1) if m else ''
ed_token = own_token(ed)
st_ed = upload(ed, 'Ed.ttf', make_font(LATIN, 'Ed Font'), token=ed_token)[0]
check('an editor may not upload, even with a valid form token, and the file is not stored', ed_token != '' and st_ed == 403 and not os.path.isdir('app/custom/assets/fonts/ed-font'), (st_ed, ed_token))
st_anon = upload(Client(), 'Anon.ttf', make_font(LATIN, 'Anon'), token=token)[0]
check('nobody signed out may upload', st_anon in (302, 401, 403, 419) and not os.path.isdir('app/custom/assets/fonts/anon'), st_anon)
check('a request without a valid form token is refused', upload(root, 'Nt.ttf', make_font(LATIN, 'No Token'), token='wrong')[0] == 419 and not os.path.isdir('app/custom/assets/fonts/no-token'))

# ---- saving the families and using them on a page
def fonts_fields(entries):
    out = {}
    for i, e in enumerate(entries):
        for k in ('family', 'file', 'weight', 'style', 'range', 'cover', 'adjust', 'v'):
            out['theme_settings[design][fonts][%d][%s]' % (i, k)] = e.get(k, '')
    return out
saved = [dict(entry, style='normal'), dict(bold['entry'], style='normal'), dict(latin['entry'], style='normal')]
overrides = fonts_fields(saved)
overrides.update({'theme_settings[design][heading_font]': 'custom', 'theme_settings[design][body_font]': 'custom',
                  'theme_settings[design][heading_family]': 'Latina', 'theme_settings[design][body_family]': 'Acme Sans'})
st, _, _ = post_theme(overrides)
check('the form saves', st in (200, 302), st)
html = pub.get('/about')[2]
css = style(html)
check('a public page uses two custom families: the face of every file, and both variables', css.count('@font-face{') >= 3 and 'font-family:"Acme Sans";font-style:normal;font-weight:400' in css and 'font-family:"Acme Sans";font-style:normal;font-weight:700' in css and 'font-family:"Latina";' in css
      and '--font-body:"Acme Sans"' in css and '--font-heading:"Latina"' in css and 'font-display:swap' in css, css[:600])
check('with the address and version of each file', '/_custom/' + entry['file'] + '?v=' + entry['v'] in css)
check('a TTF is not preloaded (only WOFF2 is)', not any('/_custom/' in tag for tag in re.findall(r'<link rel="preload"[^>]*>', html)), re.findall(r'<link rel="preload"[^>]*>', html))
check('the screen lists the files with their chips after saving', root.get('/admin/theme?tab=branding')[2].count('name="theme_settings[design][fonts][2][file]"') == 1)

# ---- the earlier single font file keeps working, and can become a family
post_theme({'theme_settings[design][heading_font]': 'custom', 'theme_settings[design][heading_family]': '', 'theme_settings[design][font_file]': 'fonts/old.woff2', 'theme_settings[design][body_font]': 'pairing', **{k: '' for k in []}, 'theme_settings[design][fonts]': ''})
css = style(pub.get('/about')[2])
check('without families the old file gives the old @font-face', '@font-face{font-family:"Brand Font";font-style:normal;font-weight:100 900;font-display:swap;src:url("/_custom/fonts/old.woff2") format("woff2")}' in css and '--font-heading:"Brand Font"' in css, css[:300])
check('and the screen shows the file as an earlier setting', 'data-bp-custom-font' in root.get('/admin/theme?tab=branding')[2])
os.makedirs('app/custom/assets/fonts', exist_ok=True)
open('app/custom/assets/fonts/old.ttf', 'wb').write(make_font(GREEK, 'Old Face', 'Regular', 400))
st, _, body = multipart(root, ENDPOINT, [('action', 'convert'), ('path', 'fonts/old.ttf'), ('family', 'My font'), ('_csrf', csrf())], [])
conv = json.loads(body)
check('it can be read as a family: all weights, as it was drawn before', st == 200 and conv.get('ok') is True and conv['entry']['weight'] == '100 900' and conv['entry']['family'] == 'My font', body[:200])
check('a file that is not there, or a path out of the folder, is refused', all(json.loads(multipart(root, ENDPOINT, [('action', 'convert'), ('path', p), ('family', 'x'), ('_csrf', csrf())], [])[2])['ok'] is False for p in ('fonts/none.woff2', '../../storage/db/app.sqlite')))

# ---- a WOFF2 is preloaded, once, for the weight the theme uses
woff2 = json.loads(upload(root, 'Web-Regular.woff2', open('app/themes/default/assets/fonts/inter/inter-latin.woff2', 'rb').read(), 'Web Face')[2])
check('a WOFF2 is accepted whether or not this server can open its Brotli stream', woff2.get('ok') is True and woff2['entry']['weight'] == '400' and woff2['entry']['file'].endswith('.woff2'), woff2)
post_theme({**fonts_fields([dict(woff2['entry'], style='normal')]), 'theme_settings[design][body_font]': 'custom', 'theme_settings[design][body_family]': 'Web Face', 'theme_settings[design][heading_font]': 'pairing', 'theme_settings[design][font_file]': ''})
tags = [t for t in re.findall(r'<link rel="preload"[^>]*>', pub.get('/about')[2]) if '/_custom/' in t]
check('and preloaded as a font, cross-origin, with its version', len(tags) == 1 and 'as="font"' in tags[0] and 'type="font/woff2"' in tags[0] and 'crossorigin' in tags[0] and '?v=' + woff2['entry']['v'] in tags[0], tags)

# ---- clean up the setting
post_theme({'theme_settings[design][heading_font]': 'pairing', 'theme_settings[design][font_file]': '', 'theme_settings[design][fonts]': ''})
check('removing the choices returns the page to what it was', style(pub.get('/about')[2]) == '' and 'Brand Font' not in pub.get('/about')[2])

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
