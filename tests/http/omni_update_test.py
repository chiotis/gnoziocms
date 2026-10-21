"""Updating a site from Omni, end to end: a site connected to an Omni stand-in receives a signed `update` command at
/_omni/command, answers at once, then makes the verified backup, installs a real package served on a local port, and
reports how it went. Every way a command is refused is checked, and so are the site's own switch and its data."""
import hashlib, hmac, http.server, json, os, re, secrets, shutil, sqlite3, subprocess, sys, tempfile, threading, time, urllib.request, urllib.error
sys.path.insert(0, '.')
import client
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

APP = os.path.abspath('app')
def read(path):
    with open(os.path.join(APP, path), 'rb') as f: return f.read().decode('utf-8', 'replace')
def meta(key):
    c = sqlite3.connect(os.path.join(APP, 'storage/db/app.sqlite')); row = c.execute('select value from system_meta where key = ?', (key,)).fetchone(); c.close()
    return json.loads(row[0]) if row else None
def set_meta(key, value):
    c = sqlite3.connect(os.path.join(APP, 'storage/db/app.sqlite')); c.execute('update system_meta set value = ? where key = ?', (json.dumps(value), key)); c.commit(); c.close()

old_version = read('VERSION').strip()
NEW = '99.0.0'
with open(os.path.join(APP, 'content/pages/mine.md'), 'w') as f: f.write('---\ntitle: Mine\nstatus: published\n---\n\nKept.\n')

# ---- a release of this code at a newer version, served on a local port
work = tempfile.mkdtemp()
src = os.path.join(work, 'src')
skip = {'.git', 'node_modules', 'storage', 'custom', 'content', 'tests'}
shutil.copytree(APP, src, ignore=lambda d, names: [n for n in names if n in (skip if os.path.relpath(d, APP) == '.' else ({'uploads'} if os.path.relpath(d, APP) == 'public' else set()))])
with open(os.path.join(src, 'VERSION'), 'w') as f: f.write(NEW + '\n')
subprocess.run(['php', os.path.join(src, 'scripts/build-release.php'), os.path.join(work, 'out')], check=True, capture_output=True)
served = os.path.join(work, 'served'); os.makedirs(served)
class Files(http.server.SimpleHTTPRequestHandler):
    def log_message(self, *a): pass
    def __init__(self, *a, **k): super().__init__(*a, directory=served, **k)
files = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Files)
threading.Thread(target=files.serve_forever, daemon=True).start()
FILES = 'http://127.0.0.1:%d' % files.server_port
zip_name = 'gnoziocms-%s.zip' % NEW
shutil.copy(os.path.join(work, 'out', zip_name), os.path.join(served, zip_name))
manifest = json.load(open(os.path.join(work, 'out', 'release.json')))
manifest['package_url'] = '%s/%s' % (FILES, zip_name)
with open(os.path.join(served, 'release.json'), 'w') as f: json.dump(manifest, f)

# ---- an Omni stand-in: connect, and signed reports
CODE, SITE_ID, SECRET = 'K7QM-4TZP-9WXC-2HRD', 's_0123456789ab', 'Xy9-secret_from_the_omni_standin_0123456789'
reports = []
class Omni(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    def log_message(self, *a): pass
    def _send(self, status, data):
        body = json.dumps(data).encode()
        self.send_response(status); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
    def do_GET(self):
        self._send(200, {'omni': True, 'name': 'Stand-in', 'protocols': [1]}) if self.path == '/api/v1/ping' else self._send(404, {})
    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length') or 0))
        if self.path == '/api/v1/connect':
            return self._send(200, {'site_id': SITE_ID, 'secret': SECRET, 'report_every': 21600})
        if self.path == '/api/v1/report':
            reports.append(json.loads(body)); return self._send(200, {'ok': True, 'report_every': 21600})
        self._send(200, {'ok': True})
omni = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Omni)
threading.Thread(target=omni.serve_forever, daemon=True).start()
OMNI = 'http://127.0.0.1:%d' % omni.server_port

root = Client(); root.login()
root.submit('/admin/settings', has_field('title'), {'update_release_url': FILES + '/release.json'})
root.submit('/admin/omni', lambda f: any(x[0] == 'omni_code' for x in f['fields']), {'omni_url': OMNI, 'omni_code': CODE}, button=('omni_action', 'connect'))
check('the site is connected to the stand-in', (meta('omni') or {}).get('status') == 'connected', meta('omni'))
page = root.get('/admin/omni')[2]
check('Admin > Omni has the switch, on by default', 'name="allow_updates"' in page and re.search(r'name="allow_updates"[^>]*checked', page) is not None)

def command(cmd='update', cid=None, secret=SECRET, site=SITE_ID, ts=None, nonce=None, method='POST'):
    body = json.dumps({'protocol': 1, 'command': cmd, 'command_id': cid or 'c_' + secrets.token_hex(8)}).encode()
    ts = str(ts if ts is not None else int(time.time())); nonce = nonce or secrets.token_hex(16)
    to_sign = '\n'.join(['POST', '/_omni/command', ts, nonce, hashlib.sha256(body).hexdigest()])
    headers = {'Content-Type': 'application/json', 'X-Omni-Site': site, 'X-Omni-Timestamp': ts, 'X-Omni-Nonce': nonce,
               'X-Omni-Signature': 'v1=' + hmac.new(secret.encode(), to_sign.encode(), hashlib.sha256).hexdigest()}
    req = urllib.request.Request(client.BASE + '/_omni/command', data=body if method == 'POST' else None, headers=headers, method=method)
    started = time.time()
    try:
        r = urllib.request.urlopen(req, timeout=30); status, data = r.status, r.read()
    except urllib.error.HTTPError as e:
        status, data = e.code, e.read()
    try: data = json.loads(data or b'{}')
    except ValueError: data = {}
    return status, data, time.time() - started

# ---- refused
st, data, _ = command(method='GET')
check('only POST', st == 405, st)
st, data, _ = command(secret='not-the-secret-of-this-site_0123456789abcd')
check('a command signed with another secret is refused', st == 401 and data.get('error') == 'invalid_signature', (st, data))
st, data, _ = command(site='s_ffffffffffff')
check('a command for another site is refused', st == 401 and data.get('error') == 'unknown_site', (st, data))
st, data, _ = command(ts=int(time.time()) - 400)
check('an old command is refused', st == 401 and data.get('error') == 'stale_timestamp', (st, data))
st, data, _ = command(cmd='delete_everything')
check('a command that is not update is refused', st == 400 and data.get('error') == 'unknown_command', (st, data))
root.submit('/admin/omni', lambda f: any(x[0] == 'omni_action' and x[1] == 'updates' for x in f['fields']), drop=['allow_updates'])
st, data, _ = command()
check('with the switch off, the site refuses', st == 403 and data.get('error') == 'not_allowed', (st, data))
check('and the screen shows it off', re.search(r'name="allow_updates"[^>]*checked', root.get('/admin/omni')[2]) is None)
root.submit('/admin/omni', lambda f: any(x[0] == 'omni_action' and x[1] == 'updates' for x in f['fields']), {'allow_updates': '1'})
state = meta('omni'); state['command'] = {'id': 'c_00000000', 'type': 'update', 'state': 'running', 'accepted_at': time.strftime('%Y-%m-%dT%H:%M:%S+00:00', time.gmtime())}; set_meta('omni', state)
st, data, _ = command()
check('while a command runs, another is refused', st == 409 and data.get('error') == 'busy', (st, data))
state = meta('omni'); state.pop('command'); set_meta('omni', state)
check('nothing was installed by any of these', read('VERSION').strip() == old_version)

# ---- accepted, and run
nonce = secrets.token_hex(16)
st, data, took = command(cid='c_a1b2c3d4e5f6', nonce=nonce)
check('a good command is accepted at once (202), before the work', st == 202 and data == {'ok': True, 'command_id': 'c_a1b2c3d4e5f6', 'state': 'accepted'} and took < 10, (st, data, took))
st, data, _ = command(cid='c_a1b2c3d4e5f6', nonce=nonce)
check('the same request again is a replay', st == 401 and data.get('error') == 'replayed_nonce', (st, data))
deadline = time.time() + 180
done = None
while time.time() < deadline and done is None:
    done = next((r for r in reports if (r.get('command') or {}).get('id') == 'c_a1b2c3d4e5f6' and r['command'].get('state') not in ('accepted', 'running')), None)
    time.sleep(0.5)
cmd = (done or {}).get('command') or {}
check('the site reports how it ended: installed', cmd.get('state') == 'installed', cmd)
check('from the old version to the new one, and the report says the new version', cmd.get('from') == old_version and cmd.get('to') == NEW and (done or {}).get('faros', {}).get('version') == NEW, (cmd, (done or {}).get('faros')))
check('the new code is in place', read('VERSION').strip() == NEW)
pre = meta('pre_update_backup') or {}
check('the verified backup was made first, on the old version', pre.get('verified') is True and pre.get('version') == old_version and os.path.isfile(os.path.join(APP, 'storage/backups', pre.get('filename', '-'))), pre)
check("the site's own content is untouched", 'Kept.' in read('content/pages/mine.md'))
page = root.get('/admin/omni')[2]
check('Admin > Omni shows the last update from Omni', 'data-omni-command' in page and 'Installed' in page, page[:200])
st, _, logs = root.get('/admin/activity-logs')
check('the command is in the activity log', 'omni.command_update' in logs)

# ---- again: nothing newer
st, data, _ = command(cid='c_ffeeddccbbaa')
deadline = time.time() + 60
done = None
while time.time() < deadline and done is None:
    done = next((r for r in reports if (r.get('command') or {}).get('id') == 'c_ffeeddccbbaa' and r['command'].get('state') not in ('accepted', 'running')), None)
    time.sleep(0.3)
check('a second command finds nothing newer and says so', st == 202 and ((done or {}).get('command') or {}).get('state') == 'up_to_date', (st, done and done.get('command')))

shutil.rmtree(work, ignore_errors=True)
print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
