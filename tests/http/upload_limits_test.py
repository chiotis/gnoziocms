import re, sys, sqlite3, urllib.request, urllib.error, uuid
sys.path.insert(0, '.')
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:220]))
    if not ok: fails.append(label)

def db(): return sqlite3.connect('app/storage/db/app.sqlite')
def token(c): return re.search(r'name="_csrf" value="([0-9a-f]+)"', c.get('/admin')[2]).group(1)
def is_settings(f): return any(x[0] == 'title' for x in f['fields']) and any(x[0] == 'date_format' for x in f['fields'])
def stored(): 
    r = db().execute("select value from system_meta where key='site_settings'").fetchone(); return r[0] if r else ''
PNG = bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360000002000001e221bc330000000049454e44ae426082')

def upload(c, name, data, mime='application/octet-stream'):
    b = uuid.uuid4().hex
    body = b''
    for k, v in (('_csrf', token(c)), ('media_action', 'upload'), ('upload_tags', '')):
        body += f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    body += f'--{b}\r\nContent-Disposition: form-data; name="upload_file[]"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + data + f'\r\n--{b}--\r\n'.encode()
    req = urllib.request.Request(BASE + '/admin/media', data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + b})
    try:
        r = c.opener.open(req); return r.status, r.headers, r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.headers, e.read().decode('utf-8', 'replace')
def outcome(c, name, data, mime='application/octet-stream'):
    """Upload, then read the result the media screen shows."""
    st, hdr, body = upload(c, name, data, mime)
    loc = hdr.get('Location') or ''
    st2, _, page = c.get(loc) if loc else (st, None, body)
    return st, loc, page
def in_library(c, name): return name in c.get('/admin/media')[2]

root = Client(); root.login()
root.submit('/admin/users-edit', has_field('username'), {'username': 'adm1', 'email': 'adm1@example.test', 'display_name': 'adm1', 'role': 'admin', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
adm = Client(); adm.login('adm1', 'Sturdy-pass-99')

# ---- the settings
st, _, html = root.get('/admin/settings?tab=limits')
check('the Limits tab has a File uploads section', st == 200 and 'File uploads' in html and 'name="upload_limit_mb"' in html, st)
check('with the size, default 20 MB', re.search(r'name="upload_limit_mb" value="20"', html) is not None)
check('a tick box for every kind of file, all ticked by default', all(('value="%s" checked' % k) in html for k in ('images', 'svg', 'documents', 'archives', 'audio', 'video')), re.findall(r'name="upload_types\[\]"[^>]*', html))
check('it says what the server itself allows', 'This server takes at most' in html and 'upload_max_filesize' in html)
check('and that dangerous kinds are never accepted', 'programs, scripts, and web pages are never accepted' in html)
st, _, html = adm.get('/admin/settings')
check('an admin does not see them', 'upload_limit_mb' not in html and 'upload_types[]' not in html)
adm.submit('/admin/settings', is_settings, {'upload_limit_mb': '1'})
check('and cannot change them by sending the fields', 'upload_mb' not in stored())

# ---- images only, at most 1 MB
root.submit('/admin/settings', is_settings, {'upload_limit_mb': '1', 'upload_types[]': ['images']})
data = stored()
check('the size and the kinds are stored', 'upload_mb: 1' in data and re.search(r'upload_types:\s*\n\s*- images', data) is not None, data[-300:])
st, _, html = root.get('/admin/settings?tab=limits')
check('the form shows them back', 'name="upload_limit_mb" value="1"' in html and 'value="images" checked' in html and 'value="documents" checked' not in html)
st, _, html = root.get('/admin/media')
check('the media screen tells the limit and the kinds', 'max 1 MB per file' in html and 'Allowed: images' in html, re.findall(r'max \d+ MB[^<]*', html))
accept = re.search(r'accept="([^"]*)"', html)
check('the file field offers only those kinds', accept is not None and '.png' in accept.group(1) and '.pdf' not in accept.group(1) and '.svg' not in accept.group(1), accept and accept.group(1))

st, loc, page = outcome(root, 'small.png', PNG, 'image/png')
check('a small image is accepted', 'small.png' in root.get('/admin/media')[2], (st, loc))
st, loc, page = outcome(root, 'paper.pdf', b'%PDF-1.4\n%test\n', 'application/pdf')
check('a document is refused, with the reason', 'not allowed on this site' in page and not in_library(root, 'paper.pdf'), re.findall(r'not allowed[^<]*', page))
big = PNG + b'\0' * (1024 * 1024 + 4096)
st, loc, page = outcome(root, 'big.png', big, 'image/png')
check('a file over the size is refused, with the reason', 'exceeds the maximum allowed size' in page and not in_library(root, 'big.png'), re.findall(r'exceeds[^<]*', page))
st, loc, page = outcome(root, 'drawing.svg', b'<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>', 'image/svg+xml')
check('SVG is its own choice, so it is refused when not ticked', 'not allowed on this site' in page and not in_library(root, 'drawing.svg'))
st, _, html = root.get('/admin/activity-logs')
check('the change is in the activity log', 'limits.upload' in html or 'Upload limits changed' in html)

# ---- more kinds
root.submit('/admin/settings', is_settings, {'upload_limit_mb': '1', 'upload_types[]': ['images', 'svg', 'documents']})
st, loc, page = outcome(root, 'paper.pdf', b'%PDF-1.4\n%test\n', 'application/pdf')
check('ticking documents lets a PDF in', in_library(root, 'paper.pdf'), re.findall(r'not allowed[^<]*', page))
st, loc, page = outcome(root, 'drawing.svg', b'<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>', 'image/svg+xml')
check('and a clean SVG when that is ticked', in_library(root, 'drawing.svg'))
import glob
svgs = lambda: set(glob.glob('app/public/uploads/**/*.svg', recursive=True))
before = svgs()
st, loc, page = outcome(root, 'evil.svg', b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml')
kept = [open(f, 'rb').read() for f in sorted(svgs() - before)]
check('an SVG with a script is kept without it (written again with only what a drawing needs)', in_library(root, 'evil.svg') and kept != [] and all(b'script' not in k and b'alert' not in k for k in kept), kept)
st, loc, page = outcome(root, 'entity.svg', b'<!DOCTYPE svg [<!ENTITY x "javascript:alert(1)">]><svg xmlns="http://www.w3.org/2000/svg"><a href="&x;"/></svg>', 'image/svg+xml')
check('an SVG with a DOCTYPE is refused', not in_library(root, 'entity.svg') and 'DOCTYPE' in page, re.findall(r'SVG[^<]*', page))
st, loc, page = outcome(root, 'clip.mp4', b'\0\0\0\x18ftypmp42\0\0\0\0mp42isom', 'video/mp4')
check('video was not ticked, so it is refused', not in_library(root, 'clip.mp4'))
st, loc, page = outcome(root, 'run.php', b'<?php echo 1;', 'application/x-php')
check('a script is never accepted, whatever is ticked', not in_library(root, 'run.php'))

# ---- guards
root.submit('/admin/settings', is_settings, {'upload_types_present': '1'}, drop=['upload_types[]'])
check('unticking everything changes nothing (the library would be shut)', re.search(r'upload_types:\s*\n\s*- images\s*\n\s*- svg\s*\n\s*- documents', stored()) is not None, stored()[-300:])
root.submit('/admin/settings', is_settings, {'upload_types[]': ['images', 'exe', 'documents']})
check('a kind that does not exist is ignored', 'exe' not in stored() and 'documents' in stored())
root.submit('/admin/settings', is_settings, {'upload_limit_mb': '0'})
st, _, html = root.get('/admin/media')
check('0 means the server decides', re.search(r'max \d+ MB per file|no size limit', html) is not None and 'upload_mb: 0' in stored(), re.findall(r'max \d+ MB[^<]*', html))

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
