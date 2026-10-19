"""The one-file installer, end to end: a fake release (the real package of this code, served with ranges on a local
port), an installer uploaded to an empty folder, and every way it must refuse (no key, a wrong key, an existing site, a
package that is not the published one, a package with a file outside the code paths), then the whole way through:
download in pieces, unpack, the wait for the document root (it does not go on until the folder is really served from
public/), and the finish that removes the installer and ends at the first-administrator screen."""
import signal, hashlib, http.server, json, os, re, shutil, socket, subprocess, sys, tempfile, threading, time, urllib.request, zipfile
sys.path.insert(0, '.')
import client
from client import Client, has_field

fails = []
# The install is a sequence: once a step it depends on fails (the server check on a PHP that is too old, the first download, ...),
# that is recorded once (critical=True) and the checks after it are skipped instead of failing one by one.
flow = client.Flow(fails)
check = flow.check
def err(j): return (j.get('error') or {}).get('key')
def download_all(s, timeout=60):
    """Asks for the pieces until the download says it is done or fails; never for ever."""
    state = {'calls': 0}
    def once():
        state['calls'] += 1
        state['j'] = s.step('download')[1]
        return state['j'].get('done') or not state['j'].get('ok')
    client.wait_until(once, timeout, 0, 'the installer to finish the download')
    return state['calls'], state['j']

root = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(client.__file__)), '..', '..'))
work = tempfile.mkdtemp()
KEY = 'test-key-0123456789'
version = open(os.path.join(root, 'VERSION')).read().strip()

# ---- the package, and a release server that supports ranges (and one that does not)
subprocess.run(['php', os.path.join(root, 'scripts/build-release.php'), os.path.join(work, 'out')], check=True, capture_output=True)
good_zip = os.path.join(work, 'out', 'gnoziocms-%s.zip' % version)
served = os.path.join(work, 'served'); os.makedirs(served)
RANGES = {'on': True}
class Release(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def do_GET(self):
        path = os.path.join(served, os.path.basename(self.path.split('?')[0]))
        if not os.path.isfile(path):
            self.send_response(404); self.end_headers(); return
        data = open(path, 'rb').read()
        m = re.match(r'bytes=(\d+)-(\d+)', self.headers.get('Range', ''))
        if m and RANGES['on']:
            a, b = int(m.group(1)), min(int(m.group(2)), len(data) - 1)
            self.send_response(206); self.send_header('Content-Range', 'bytes %d-%d/%d' % (a, b, len(data)))
            data = data[a:b + 1]
        else:
            self.send_response(200)
        self.send_header('Content-Length', str(len(data))); self.end_headers(); self.wfile.write(data)
        self.server.hits.append(self.headers.get('Range', ''))
rel = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Release); rel.hits = []
threading.Thread(target=rel.serve_forever, daemon=True).start()
rel_base = 'http://127.0.0.1:%d' % rel.server_port

def publish(zip_path, sha=None):
    for n in os.listdir(served): os.remove(os.path.join(served, n))
    shutil.copy(zip_path, os.path.join(served, 'package.zip'))
    data = open(zip_path, 'rb').read()
    manifest = {'version': version, 'min_php': client.min_php(), 'package': 'package.zip', 'package_url': rel_base + '/package.zip',
                'sha256': sha or hashlib.sha256(data).hexdigest(), 'size': len(data), 'requires_backup': True}
    json.dump(manifest, open(os.path.join(served, 'release.json'), 'w'))
    rel.hits.clear()

def free_port():
    s = socket.socket(); s.bind(('127.0.0.1', 0)); p = s.getsockname()[1]; s.close(); return p

servers = []
def stop_all():
    for sv in servers:
        if sv.poll() is None: stop(sv)
def serve(docroot, port):
    env = dict(os.environ, GNOZIO_INSTALLER_RELEASE_URL=rel_base + '/release.json', PHP_CLI_SERVER_WORKERS='4')
    log = open(os.path.join(work, 'server-%d.log' % port), 'a')
    p = subprocess.Popen(['php', '-S', '127.0.0.1:%d' % port, '-t', docroot], cwd=docroot, env=env, stdout=log, stderr=log, start_new_session=True)
    servers.append(p)
    client.wait_until(lambda: client.port_open(port), 10, what='the PHP server on port %d to accept connections' % port)
    return p
def stop(p):
    try: os.killpg(p.pid, signal.SIGTERM)
    except ProcessLookupError: pass
    p.wait()

def new_site(name, key=KEY):
    folder = os.path.join(work, name); os.makedirs(folder)
    source = open(os.path.join(root, 'installer.php')).read()
    open(os.path.join(folder, 'installer.php'), 'w').write(source.replace("const INSTALL_KEY = '';", "const INSTALL_KEY = '%s';" % key))
    return folder

class Session(Client):
    """A browser: cookies, and the installer's JSON steps."""
    def __init__(self, port):
        super().__init__(); self.port = port
    def at(self, path): return 'http://127.0.0.1:%d%s' % (self.port, path)
    def page(self, path='/installer.php'): return self.request(self.at(path))
    def unlock(self, key=KEY):
        return self.request(self.at('/installer.php'), data={'key': key})
    def step(self, name, data=None, path='/installer.php'):
        st, h, body = self.request(self.at('%s?step=%s' % (path, name)), data=data if data is not None else {}, headers={'X-Gnozio-Installer': '1'})
        try: return st, json.loads(body)
        except ValueError: return st, {'raw': body[:200]}

def finish():
    stop_all()
    rel.shutdown()
    shutil.rmtree(work, ignore_errors=True)
    print('\nALL PASSED' if not fails else '\n%d FAILED' % len(fails))
    sys.exit(1 if fails else 0)

publish(good_zip)
import atexit
atexit.register(stop_all)   # also when the script dies or is stopped by the runner's time limit
signal.signal(signal.SIGTERM, lambda n, f: sys.exit(143))

# ---- 1. no key: the installer refuses and says how to set one
site = new_site('nokey', key='')
p = serve(site, port := free_port()); s = Session(port)
st, h, html = s.page()
check('with no key the page refuses and explains how to set it', st == 403 and 'INSTALL_KEY' in html and '<form' not in html, st)
check('and no step runs', s.step('check')[0] == 403 and s.step('begin')[0] == 403)
stop(p)
site = new_site('shortkey', key='short')
p = serve(site, port := free_port()); s = Session(port)
st, h, html = s.page()
check('a key shorter than 12 characters is refused too', st == 403 and 'INSTALL_KEY' in html, st)
stop(p)

# ---- 2. the key
site = new_site('keyed')
p = serve(site, port := free_port()); s = Session(port)
st, h, html = s.page()
check('the page asks for the key', st == 200 and 'name="key"' in html and 'var GZ=' not in html)
check('with a security header set', 'frame-ancestors' in h.get('Content-Security-Policy', '') and h.get('X-Frame-Options') == 'DENY')
check('steps are refused without it', s.step('check')[0] == 403)
t0 = time.time()
st, h, html = s.unlock('wrong-key-wrong-key')
check('a wrong key is refused and slowed down', st == 200 and 'name="key"' in html and 'var GZ=' not in html and time.time() - t0 >= 1.5, st)
check('and gives no access', s.step('check')[0] == 403)
st, h, html = s.unlock()
check('the right key is accepted', st == 303 and 'gz_installer' in (h.get('Set-Cookie') or '') and 'HttpOnly' in h.get('Set-Cookie', '') and 'SameSite=Strict' in h.get('Set-Cookie', ''), (st, h.get('Set-Cookie')), critical=True)
st, h, html = s.page()
check('and opens the installer', st == 200 and 'var GZ=' in html and 'nonce-' in h.get('Content-Security-Policy', ''))
st_get, _, _ = s.request(s.at('/installer.php?step=check'))
check('a step needs a POST with the installer header', st_get == 403 and s.request(s.at('/installer.php?step=check'), data={})[0] == 403)
st, j = s.step('check')
check('the server check lists every line and the release', st == 200 and j.get('ok') and not j.get('blocking') and j.get('version') == version and {r['id'] for r in j.get('rows', [])} >= {'php', 'ext_zip', 'ext_pdo_sqlite', 'writable', 'disk', 'network', 'release'}, j, critical=True)
check('all of them fine here', all(r['level'] in ('ok', 'warn') for r in j.get('rows', [])), [r for r in j.get('rows', []) if r['level'] == 'fail'])
check('a step out of order is refused', s.step('unpack')[1].get('error', {}).get('key') == 'e_nostate')

# ---- 3. an existing site
other = new_site('existing')
os.makedirs(os.path.join(other, 'src')); open(os.path.join(other, 'VERSION'), 'w').write('0.0.1\n')
p2 = serve(other, port2 := free_port()); s2 = Session(port2)
st, h, html = s2.page()
check('a folder that holds a site is refused', st == 409 and 'already installed' in html and 'name="key"' not in html, st)
s2.jar.set_cookie(next(iter(s.jar)))  # a signed-in browser from the other folder (same key, same host)
for c in s.jar: s2.jar.set_cookie(c)
check('and its steps too', s2.step('begin')[0] == 409 and s2.step('check')[0] == 409)
check('nothing was written there', sorted(os.listdir(other)) == ['VERSION', 'installer.php', 'src'])
stop(p2)
db_site = new_site('withdb'); os.makedirs(os.path.join(db_site, 'storage/db')); open(os.path.join(db_site, 'storage/db/app.sqlite'), 'w').write('')
p2 = serve(db_site, port2 := free_port()); s2 = Session(port2)
check('so is one that has a database', s2.page()[0] == 409)
stop(p2)

# ---- 4. a package that is not the one published
if not flow.active:   # everything from here walks the install step by step and needs the checks above
    print('skip the install itself: download, verify, unpack, document root and the first administrator  (skipped: %s failed)' % flow.blocked)
    flow.skipped.append('the install itself')
    finish()
publish(good_zip, sha='0' * 64)
st, j = s.step('begin')
check('the install begins', j.get('ok') is True, j, critical=True)
for _ in range(10):
    st, j = s.step('download')
    if j.get('done') or not j.get('ok'): break
check('the download finishes in pieces', j.get('done') is True and len([h for h in rel.hits if h]) >= 2 and all(h.startswith('bytes=') for h in rel.hits if h), (j, rel.hits))
st, j = s.step('verify')
check('a package whose checksum is not the published one is refused', j.get('ok') is False and err(j) == 'e_sha' and j['error']['args'].get('restart') == 'download', j)
check('and thrown away, with nothing unpacked', not os.path.exists(os.path.join(site, 'storage/install/package.zip')) and not os.path.exists(os.path.join(site, 'src')))
publish(good_zip)
for _ in range(10):
    st, j = s.step('download')
    if j.get('done') or not j.get('ok'): break
st, jv = s.step('verify')
check('the retry reads the release again, downloads again and verifies', j.get('done') is True and jv.get('ok') is True, (j, jv))

# ---- 5. a package with a file outside the code paths (the manifest is honest about it: the checksum matches)
def evil_zip(name, entry, content=b'x', symlink=False):
    path = os.path.join(work, name + '.zip')
    shutil.copy(good_zip, path)
    with zipfile.ZipFile(path, 'a') as z:
        info = zipfile.ZipInfo(entry)
        if symlink: info.external_attr = (0o120777 << 16)
        z.writestr(info, content)
    return path
ok_refusals = {}
for name, entry, link in [('climb', '../evil.txt', False), ('content', 'content/pages/evil.md', False), ('uploads', 'public/uploads/shell.php', False), ('link', 'src/link', True), ('self', 'public/installer.php', False)]:
    stop(p); shutil.rmtree(site); site = new_site('keyed'); p = serve(site, port); s.jar.clear()
    publish(evil_zip(name, entry, symlink=link))
    s.unlock(); s.step('begin')
    download_all(s)
    jv = s.step('verify')[1]
    ju = s.step('unpack')[1]
    key = err(ju)
    ok_refusals[name] = (jv.get('ok'), key, os.path.exists(os.path.join(site, 'src')), os.path.exists(os.path.join(work, 'evil.txt')) or os.path.exists(os.path.join(site, 'content')))
check('a path that climbs out of the folder is refused whole', ok_refusals['climb'] == (True, 'e_zip_unsafe', False, False), ok_refusals['climb'])
check('so is a file of the site itself (content, uploads)', ok_refusals['content'] == (True, 'e_zip_unsafe', False, False) and ok_refusals['uploads'][1] == 'e_zip_unsafe', ok_refusals)
check('a link is refused', ok_refusals['link'][1] == 'e_zip_link' and not ok_refusals['link'][2], ok_refusals['link'])
check('and a package cannot bring an installer of its own', ok_refusals['self'][1] == 'e_zip_unsafe', ok_refusals['self'])

# ---- 6. the happy way, with the demo, to the wait for the document root
stop(p); shutil.rmtree(site); site = new_site('keyed'); p = serve(site, port); s.jar.clear()
publish(good_zip)
RANGES['on'] = False
s.unlock(); s.step('begin', {'demo': '1'})
calls, j = download_all(s)
check('a server without ranges sends the package in one go', j.get('done') is True and calls == 1, (calls, j))
RANGES['on'] = True
check('and it verifies', s.step('verify')[1].get('ok') is True)
st, j = s.step('unpack')
check('the package unpacks', j.get('ok') is True and j.get('files', 0) > 500, j, critical=True)
exists = lambda rel_path: os.path.exists(os.path.join(site, rel_path))
check('with the code of the site in place', all(exists(f) for f in ['VERSION', 'src/App.php', 'public/index.php', 'vendor/autoload.php', 'themes/default/theme.yaml', 'public/uploads/.htaccess']))
check('and no archive left behind', not exists('storage/install/package.zip'))
st, j = s.step('prepare')
docroot = j.get('docroot')
check('the site is prepared and the document root named', j.get('ok') is True and docroot == os.path.realpath(site) + '/public', j, critical=True)
check('the folders of the site exist', all(os.path.isdir(os.path.join(site, d)) for d in ['content', 'custom', 'storage/db', 'storage/backups', 'public/uploads']))
check('the demo site is copied', exists('content/pages/index.md') and not exists('content/users'))
check('the installer is copied into public/ with its key', exists('public/installer.php') and open(os.path.join(site, 'public/installer.php')).read() == open(os.path.join(site, 'installer.php')).read())
probe = [n for n in os.listdir(os.path.join(site, 'public')) if n.startswith('gnozio-installer-')]
check('with a probe file beside it, and the state outside public/', len(probe) == 1 and exists('storage/install/state.json'))
check('no database yet', not exists('storage/db/app.sqlite'))
st, j = s.step('status')
check('the status says the installer is waiting for the document root', j.get('phase') == 'waiting' and j.get('docroot') == docroot and 'prepare' in j.get('done', []), j)

# ---- 7. it waits until public/ is really the document root
st, j = s.step('docroot')
check('while the old folder is served the answer is "not yet"', j.get('ok') is True and j.get('changed') is False and j.get('servedFromPublic') is False, j)
st, j = s.step('docroot', path='/public/installer.php')
check('the copy in public/ reached by its folder name is not the document root either', j.get('changed') is False and j.get('servedFromPublic') is True, j)
st, j = s.step('finish')
check('the finish refuses to go on', j.get('ok') is False and err(j) == 'e_docroot_wait', j)
check('and nothing was deleted', exists('installer.php') and exists('public/installer.php') and exists('storage/install/state.json'))
st, h, html = s.page()
check('a reload still shows the installer', st == 200 and 'var GZ=' in html)

stop(p)
p = serve(os.path.join(site, 'public'), port)   # the owner changes the document root
st, h, html = s.page('/installer.php')
check('the same address now serves the copy in public/', st == 200 and 'var GZ=' in html)
st, j = s.step('status')
check('which resumes at the wait', j.get('phase') == 'waiting' and j.get('servedFromPublic') is True, j)
st, j = s.step('docroot')
check('and sees that public/ is the document root', j.get('ok') is True and j.get('changed') is True, j)
check('and the state and the old folder\'s files are out of reach of the web', s.request(s.at('/storage/install/state.json'))[0] == 404 and s.request(s.at('/src/App.php'))[0] == 404)
st, j = s.step('finish')
check('the finish removes both installers, the probe and the state', j.get('ok') is True and j.get('redirect') == '/admin' and j.get('left') == [] and not exists('installer.php') and not exists('public/installer.php') and not exists('storage/install') and not [n for n in os.listdir(os.path.join(site, 'public')) if n.startswith('gnozio-installer')], j)
st, h, html = s.page('/installer.php')
check('the address of the installer is gone', st == 404 or 'var GZ=' not in html, st)

# ---- 8. and it ends at the first-administrator screen
client.BASE = 'http://127.0.0.1:%d' % port
c = Client()
st, h, html = c.get('/admin')
check('/admin sends the visitor to sign in', st in (301, 302) and 'login' in (h.get('Location') or ''), (st, h.get('Location')))
st, h, html = c.get('/admin/login')
check('where a site with no accounts asks for the first administrator', st == 200 and 'Welcome' in html and has_field('password_confirm')(c.forms(html=html)[0]), st)
st, h, html = c.submit('/admin/login', has_field('password_confirm'), {'username': 'owner', 'email': 'owner@example.test', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
check('who can be made', st in (301, 302) and c.get('/admin')[0] == 200)
check('the home page shows the demo', c.get('/')[0] == 200)
log = open(os.path.join(work, 'server-%d.log' % port)).read()
check('with no PHP error in the log', not re.search(r'Fatal|Warning:|Deprecated|Notice:', log), log[-300:])

finish()
