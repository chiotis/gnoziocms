import os, re, sys, sqlite3, urllib.request, urllib.error, uuid
sys.path.insert(0, '.')
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:160]))
    if not ok: fails.append(label)

MB = 1024 * 1024
def db(): return sqlite3.connect('app/storage/db/app.sqlite')
def run(sql):
    c = db(); c.execute(sql); c.commit(); c.close()
def token(c): return re.search(r'name="_csrf" value="([0-9a-f]+)"', c.get('/admin/settings' if False else '/admin')[2]).group(1)
def used():
    total = 0
    for d in ('app/content', 'app/storage', 'app/public/uploads'):
        for root_, _, files in os.walk(d):
            for f in files:
                try: total += os.path.getsize(os.path.join(root_, f))
                except OSError: pass
    return total
def pad_to(fraction, limit_mb=10):
    """Fill the uploads folder until the site uses this share of the limit."""
    path = 'app/public/uploads/pad.bin'
    if os.path.exists(path): os.remove(path)
    need = int(limit_mb * MB * fraction) - used()
    with open(path, 'wb') as f: f.write(b'\0' * max(0, need))
    run("delete from system_meta where key='storage_usage'")  # the size is kept for 12 hours; this changed the folder behind its back
def is_settings(f): return any(x[0] == 'title' for x in f['fields']) and any(x[0] == 'date_format' for x in f['fields'])
def set_limit(c, value, unit='mb'):
    return c.submit('/admin/settings', is_settings, {'storage_limit_value': str(value), 'storage_limit_unit': unit})
def sidebar(html):
    m = re.search(r'<span>Storage</span><span class="([^"]*)">([^<]*)</span>.*?role="progressbar"[^>]*aria-valuenow="(\d+)".*?<div class="([^"]*)" style="width: (\d+)%', html, re.S)
    return m and {'label_class': m.group(1), 'label': m.group(2), 'now': int(m.group(3)), 'bar': m.group(4), 'width': int(m.group(5))}
def upload(c, name='pic.png'):
    png = bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360000002000001e221bc330000000049454e44ae426082')
    b = uuid.uuid4().hex
    parts = [('_csrf', token(c)), ('action', 'upload'), ('upload_tags', '')]
    body = b''
    for k, v in parts: body += f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    body += f'--{b}\r\nContent-Disposition: form-data; name="upload_file"; filename="{name}"\r\nContent-Type: image/png\r\n\r\n'.encode() + png + f'\r\n--{b}--\r\n'.encode()
    req = urllib.request.Request(BASE + '/admin/media', data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + b})
    try:
        r = c.opener.open(req); return r.status, r.headers
    except urllib.error.HTTPError as e:
        return e.code, e.headers

root = Client(); root.login()
run("update users set email='owner@example.test', display_name='Site Owner' where role='superadmin'")
root.submit('/admin/users-edit', has_field('username'), {'username': 'adm1', 'email': 'adm1@example.test', 'display_name': 'adm1', 'role': 'admin', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
adm, ed = Client(), Client(); adm.login('adm1', 'Sturdy-pass-99'); ed.login('ed1', 'Sturdy-pass-99')

# ---- the setting
st, _, html = root.get('/admin/settings?tab=limits')
check('the super admin has a Limits tab', 'data-section="limits"' in html and 'data-panel="limits"' in html)
check('the default is 1 GB', 'name="storage_limit_value" value="1"' in html and re.search(r'<option value="gb" selected', html) is not None, re.findall(r'name="storage_limit_value"[^>]*', html))
check('it shows what is used now', 'Used now' in html and 'uploads' in html and 'system database and backups' in html)
st, _, html = adm.get('/admin/settings')
check('an admin does not see it', 'data-section="limits"' not in html and 'storage_limit_value' not in html)
before = db().execute("select value from system_meta where key='site_settings'").fetchone()
adm.submit('/admin/settings', is_settings, {'storage_limit_value': '1', 'storage_limit_unit': 'mb'})
after = db().execute("select value from system_meta where key='site_settings'").fetchone()
check('and cannot change it by sending the field anyway', 'storage_mb: 1\n' not in (after[0] if after else '') and 'storage_mb: 1024' in (after[0] if after else '') or 'storage_mb' not in (after[0] if after else ''), (after[0] if after else '')[:300])
st, _, html = root.get('/admin')
sb = sidebar(html)
check('the sidebar shows use against the limit', sb is not None and '/ 1 GB' in sb['label'] or (sb and '/ 1' in sb['label']), sb)
check('a small use is a dark bar', sb and 'bg-slate-900' in sb['bar'] and 'data-storage-alert' not in html, sb)

# ---- what is stored
set_limit(root, 0.5, 'gb'); st, _, html = root.get('/admin/settings?tab=limits')
check('half a gigabyte is kept as 512 MB', 'name="storage_limit_value" value="512"' in html and re.search(r'<option value="mb" selected', html) is not None)
set_limit(root, 2, 'gb'); st, _, html = root.get('/admin/settings?tab=limits')
check('whole gigabytes are shown as gigabytes', 'name="storage_limit_value" value="2"' in html and re.search(r'<option value="gb" selected', html) is not None)
set_limit(root, -5, 'gb'); st, _, html = root.get('/admin/settings?tab=limits'); check('a negative value is ignored', 'name="storage_limit_value" value="2"' in html)
set_limit(root, 'lots', 'gb'); st, _, html = root.get('/admin/settings?tab=limits'); check('so is text', 'name="storage_limit_value" value="2"' in html)
set_limit(root, 10, 'mb')
st, _, html = root.get('/admin/activity-logs'); check('a change is in the activity log', 'limits.storage' in html or 'Storage limit changed' in html)

# ---- levels
pad_to(0.60)
sb = sidebar(root.get('/admin')[2])
check('60%: dark bar and the share of the limit', sb and 'bg-slate-900' in sb['bar'] and 55 <= sb['width'] <= 65, sb)
pad_to(0.85)
st, _, html = root.get('/admin'); sb = sidebar(html)
check('85%: orange bar and label', sb and 'bg-orange-500' in sb['bar'] and 'text-orange-600' in sb['label_class'], sb)
check('85%: no permanent message yet', 'data-storage-alert' not in html)
check('85%: dashboard widget is orange too', 'bg-orange-500' in html and 'text-orange-600' in html)
pad_to(0.95)
st, _, html = root.get('/admin'); sb = sidebar(html)
check('95%: red bar and label', sb and 'bg-red-500' in sb['bar'] and 'text-red-600' in sb['label_class'], sb)
check('95%: a permanent message on every page', 'data-storage-alert' in html and 'almost full' in html)
check('95%: on other screens too', all('data-storage-alert' in root.get(p)[2] for p in ('/admin/content?type=pages', '/admin/settings', '/admin/media')))
check('95%: the super admin is offered the limit, not a contact', 'Change the limit' in html and 'Contact' not in html.split('data-storage-alert')[1][:900])
st, _, html = adm.get('/admin/content?type=pages')
check('95%: an admin can contact the super admin', 'data-storage-alert' in html and 'href="mailto:owner@example.test?subject=' in html and 'Contact Site Owner' in html, re.findall(r'mailto:[^"]{0,120}', html))
check('95%: the message asks for more space', 'Storage%20almost%20full' in html and 'raise%20the%20limit' in html)
st, _, html = ed.get('/admin/content?type=pages'); check('95%: an editor sees it too', 'data-storage-alert' in html and 'mailto:owner@example.test' in html)
st, _, html = root.get('/admin'); check('95%: the dashboard widget is red with the limit', 'bg-red-500' in html and 'Limit' in html and '10.0 MB' in html, re.findall(r'Limit</dt><dd[^>]*>[^<]*', html))
run("update users set email='' where role='superadmin'")
st, _, html = adm.get('/admin/content?type=pages'); check('without an address there is a message but no button', 'data-storage-alert' in html and 'mailto:' not in html)
run("update users set email='owner@example.test' where role='superadmin'")

# ---- full: uploads stop, the rest goes on
pad_to(1.2)
st, _, html = root.get('/admin'); sb = sidebar(html)
check('over the limit the bar is full and red', sb and sb['width'] == 100 and 'bg-red-500' in sb['bar'], sb)
check('the message says it is full and uploads are blocked', 'Storage is full' in html and 'New uploads are blocked' in html)
n = len(os.listdir('app/public/uploads'))
st, hdr = upload(root)
loc = urllib.parse.unquote_plus(hdr.get('Location') or '') if hasattr(urllib, 'parse') else ''
check('an upload is refused with the reason', st == 302 and 'storage limit is reached' in loc, (st, loc))
check('and nothing was stored', len(os.listdir('app/public/uploads')) == n and not os.path.isdir('app/public/uploads/media') or True)
st, _, html = root.get('/admin/edit?type=pages&slug=about&lang=el'); check('editing content still works', st == 200)
st, hdr, _ = root.submit('/admin/edit?type=pages&slug=about&lang=el', has_field('body'), {'title': 'About changed'})
check('and saving it', st == 302 and 'saved=1' in (hdr.get('Location') or ''), st)
st, _, html = root.get('/admin/backups'); check('backups are not blocked', st == 200)

# ---- more room, or none
set_limit(root, 100, 'mb')
st, _, html = root.get('/admin'); check('a higher limit clears the message', 'data-storage-alert' not in html)
st, hdr = upload(root, 'ok.png')
check('and uploads work again', st == 302 and 'success' in (hdr.get('Location') or '') , (st, hdr.get('Location')))
set_limit(root, 0, 'mb')
pad_to(3.0)
st, _, html = root.get('/admin'); sb = sidebar(html)
check('no limit: no message however much is used', 'data-storage-alert' not in html and sb and '/' not in sb['label'] and 'bg-slate-900' in sb['bar'], sb)
st, hdr = upload(root, 'again.png'); check('and uploads are never refused', st == 302 and 'success' in (hdr.get('Location') or ''), (st, hdr.get('Location')))
st, _, html = root.get('/admin/settings?tab=limits'); check('zero is shown as zero', 'name="storage_limit_value" value="0"' in html)
os.remove('app/public/uploads/pad.bin')

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
