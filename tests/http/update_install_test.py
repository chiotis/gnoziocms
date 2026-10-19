"""Installing an update from the admin, end to end: a real package built from this code, a release served on a local
port, the install button, the check of the new version, the roll back, an automatic undo when the new version does not
start, a package that is not the one published, and the maintenance page. Through all of it the site's own pages, media,
custom files and settings are never touched."""
import hashlib, http.server, json, os, re, shutil, subprocess, sys, tempfile, threading, urllib.parse
sys.path.insert(0, '.')
import client
from client import Client, has_field

fails = []
# The first install (backup, install) is what the roll back and the checks after it depend on: when it fails that is recorded once
# (critical=True) and the checks that follow are skipped until the next, independent part (flow.clear()).
flow = client.Flow(fails)
check = flow.check

APP = os.path.abspath('app')
def read(path, default=''):
    try:
        with open(os.path.join(APP, path), 'rb') as f: return f.read().decode('utf-8', 'replace')
    except FileNotFoundError:
        return default
def md5(path):
    p = os.path.join(APP, path)
    return hashlib.md5(open(p, 'rb').read()).hexdigest() if os.path.isfile(p) else None
def exists(path): return os.path.exists(os.path.join(APP, path))

old_version = read('VERSION').strip()
NEW, BROKEN, BROKEN_ENTRY = '99.0.0', '99.1.0', '99.2.0'

# ---- the site's own data, which must survive everything
os.makedirs(os.path.join(APP, 'content/pages'), exist_ok=True)
os.makedirs(os.path.join(APP, 'public/uploads/media'), exist_ok=True)
os.makedirs(os.path.join(APP, 'custom/assets/css'), exist_ok=True)
with open(os.path.join(APP, 'content/pages/survives.md'), 'w') as f: f.write('---\ntitle: Survives\nstatus: published\n---\n\nThis page is mine.\n')
with open(os.path.join(APP, 'public/uploads/media/mine.txt'), 'w') as f: f.write('my upload')
with open(os.path.join(APP, 'custom/assets/css/custom.css'), 'w') as f: f.write('body{outline:0}')
mine = ['content/pages/survives.md', 'public/uploads/media/mine.txt', 'custom/assets/css/custom.css']
mine_before = [md5(p) for p in mine]

# ---- the packages: this code, with a newer version and something to see
work = tempfile.mkdtemp()
def make_release(version, outdir, change):
    src = os.path.join(work, 'src-' + version)
    skip = {'.git', 'node_modules', 'storage', 'custom', 'content', 'tests'}
    def ignore(directory, names):
        rel = os.path.relpath(directory, APP)
        return [n for n in names if n in (skip if rel == '.' else ({'uploads'} if rel == 'public' else set()))]
    shutil.copytree(APP, src, ignore=ignore)
    with open(os.path.join(src, 'VERSION'), 'w') as f: f.write(version + '\n')
    change(src)
    subprocess.run(['php', os.path.join(src, 'scripts/build-release.php'), outdir], check=True, capture_output=True)

def good_change(src):
    with open(os.path.join(src, 'themes/default/marker.txt'), 'w') as f: f.write('new theme file')
    with open(os.path.join(src, 'src/UpdateTestMarker.php'), 'w') as f: f.write('<?php // new code\n')
def broken_change(src):
    # A new version that answers every request with an error.
    with open(os.path.join(src, 'public/index.php'), 'w') as f: f.write('<?php http_response_code(500); echo "broken";\n')
def broken_entry_change(src):
    # Login and home work; the sampled public content page alone fails.
    path = os.path.join(src, 'public/index.php')
    with open(path) as f: code = f.read()
    code = code.replace('declare(strict_types=1);', 'declare(strict_types=1);\nif (parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) === "/about") { http_response_code(500); echo "broken entry"; return; }')
    with open(path, 'w') as f: f.write(code)

served = os.path.join(work, 'served'); os.makedirs(served)
make_release(NEW, os.path.join(work, 'out-good'), good_change)
make_release(BROKEN, os.path.join(work, 'out-broken'), broken_change)
make_release(BROKEN_ENTRY, os.path.join(work, 'out-broken-entry'), broken_entry_change)

class Quiet(http.server.SimpleHTTPRequestHandler):
    def log_message(self, *a): pass
    def __init__(self, *a, **k): super().__init__(*a, directory=served, **k)
server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Quiet)
threading.Thread(target=server.serve_forever, daemon=True).start()
base = 'http://127.0.0.1:%d' % server.server_port

def publish(outdir, version, tamper=False):
    """Makes this release the latest one: the package and its manifest, pointing at the local server."""
    for n in os.listdir(served): os.remove(os.path.join(served, n))
    zip_name = 'gnoziocms-%s.zip' % version
    shutil.copy(os.path.join(outdir, zip_name), os.path.join(served, zip_name))
    manifest = json.load(open(os.path.join(outdir, 'release.json')))
    manifest['package_url'] = '%s/%s' % (base, zip_name)
    if tamper: manifest['sha256'] = '0' * 64
    with open(os.path.join(served, 'release.json'), 'w') as f: json.dump(manifest, f)
    return manifest

root = Client(); root.login()
check('the release manifest address can be set on the settings screen',
      root.submit('/admin/settings', has_field('title'), {'update_release_url': base + '/release.json'})[0] in (302, 303))
st, _, html = root.get('/admin/settings?tab=updates')
check('and it is shown again', base + '/release.json' in html)

def updates_page(): return root.get('/admin/updates')[2]
def install_form(c, action='install'):
    return lambda f: any(x[0] == 'updates_action' and x[1] == action for x in f['fields'])
def post_action(action):
    st, h, html = root.submit('/admin/updates', install_form(root, action), {})
    return st, (h.get('Location') or '')
def post_raw(action):
    """An action sent without the button (the screen shows none): the token of another form on the page is used."""
    form = next(f for f in root.forms('/admin/updates') if any(x[0] == 'updates_action' for x in f['fields']))
    csrf = next(x[1] for x in form['fields'] if x[0] == '_csrf')
    st, h, html = root.request('/admin/updates', data=[('_csrf', csrf), ('updates_action', action)])
    return st, (h.get('Location') or '')
def has_rollback_form():
    return any(install_form(root, 'rollback')(f) for f in root.forms(html=updates_page()))
def untouched(label):
    check(label + ': the site\'s pages, uploads and custom files are as they were', [md5(p) for p in mine] == mine_before, [md5(p) for p in mine])

# ---- nothing is published yet
publish(os.path.join(work, 'out-good'), NEW)
os.remove(os.path.join(served, 'release.json'))
root.submit('/admin/updates', lambda f: any(x[0] == 'updates_action' and x[1] == 'check' for x in f['fields']), {})
html = updates_page()
check('no release published: nothing to install, and it says so', 'No release package was found' in html and 'Install ' + NEW not in html)

# ---- a release, but no backup yet
publish(os.path.join(work, 'out-good'), NEW)
root.submit('/admin/updates', lambda f: any(x[0] == 'updates_action' and x[1] == 'check' for x in f['fields']), {})
html = updates_page()
check('a published release shows its version and what it will do', 'Update available: ' + NEW in html and 'Only the code is replaced' in html)
check('without a verified backup the install button is off and says what is needed', 'Create the verified backup first' in html and not any(install_form(root)(f) for f in root.forms(html=html)))
check('and an install forced past the screen is refused, nothing changes', (lambda r: ('install=failed' in r[1] and read('VERSION').strip() == old_version))(post_raw('install')))

# ---- the backup, then the install
st, loc = post_action('pre_update_backup')
check('the backup before the update is made', 'pre_backup=ok' in loc, loc, critical=True)
flash = root.get(loc)[2]
check('and the message about it is a success, not an error', re.search(r'<template data-flash data-type="success">Verified pre-update backup created', flash) is not None, re.findall(r'<template data-flash[^>]*>[^<]{0,60}', flash))
html = updates_page()
check('then the install button is there', any(install_form(root)(f) for f in root.forms(html=html)) and ('Install ' + NEW) in html)
sessions_before = md5('storage/db/app.sqlite')
st, loc = post_action('install')
check('installing goes back to the Updates screen with a success', 'install=installed' in loc, loc, critical=True)
check('the result names the login, homepage, published entry and sitemap checked', all(path in urllib.parse.unquote(loc) for path in ['/admin/login', '/about', '/sitemap.xml']), loc)
check('the code is the new version', read('VERSION').strip() == NEW and read('themes/default/marker.txt') == 'new theme file' and exists('src/UpdateTestMarker.php'))
check('the site is open: no maintenance flag, and the pages answer', not exists('storage/maintenance.flag') and Client().get('/survives')[0] == 200 and Client().get('/admin/login')[0] == 200)
untouched('after the install')
check('the page of the site still has its text', 'This page is mine.' in Client().get('/survives')[2])
check('the signed-in session still works on the new code, and shows the new version', NEW in updates_page())
st, _, html = root.get('/admin/settings')
check('the settings are still there', 'name="title"' in html and base + '/release.json' in root.get('/admin/settings?tab=updates')[2])
check('the old code was kept to go back to', read('storage/updates/%s/previous/VERSION' % NEW).strip() == old_version and exists('storage/updates/%s/database/app.sqlite' % NEW))
check('the screen says what happened last, and offers to put the old version back', has_rollback_form())
check('the activity log has it', any(w in root.get('/admin/activity-logs')[2] for w in ['updates.install_installed']))

# ---- rolling back
st, loc = post_action('rollback')
check('putting the old version back says so', 'install=rolled_back' in loc, loc)
check('the old code is back and the new is gone', read('VERSION').strip() == old_version and not exists('themes/default/marker.txt') and not exists('src/UpdateTestMarker.php'))
untouched('after the roll back')
# The release notes on this page may mention the button, so what is asked is whether the form is there.
has_rollback = lambda: any(install_form(root, 'rollback')(f) for f in root.forms(html=updates_page()))
check('the site works, and nothing is left to roll back to', Client().get('/survives')[0] == 200 and not has_rollback())

# ---- a new version that does not start is taken out again
flow.clear()
publish(os.path.join(work, 'out-broken'), BROKEN)
root.submit('/admin/updates', lambda f: any(x[0] == 'updates_action' and x[1] == 'check' for x in f['fields']), {})
post_action('pre_update_backup')
st, loc = post_action('install')
check('a version that answers every request with an error is undone on its own', 'install=rolled_back' in loc, loc)
check('the old code is running again, the site is open and the pages are served', read('VERSION').strip() == old_version and not exists('storage/maintenance.flag') and Client().get('/survives')[0] == 200 and Client().get('/admin/login')[0] == 200)
untouched('after the undone update')
check('and nothing of the failed version is kept', not exists('storage/updates/' + BROKEN))
html = updates_page()
check('the screen says it was undone, and why', 'did not pass the check' in html or 'was put back' in html)

# ---- a content regression is caught even though the homepage and login still work
publish(os.path.join(work, 'out-broken-entry'), BROKEN_ENTRY)
root.submit('/admin/updates', lambda f: any(x[0] == 'updates_action' and x[1] == 'check' for x in f['fields']), {})
post_action('pre_update_backup')
st, loc = post_action('install')
check('a broken internal page triggers rollback and identifies the page', 'install=rolled_back' in loc and '/about' in urllib.parse.unquote(loc), loc)
check('the previous version serves the entry again', read('VERSION').strip() == old_version and Client().get('/about')[0] == 200)
untouched('after the internal-page regression')

# ---- a package that is not the one that was published
publish(os.path.join(work, 'out-good'), NEW, tamper=True)
root.submit('/admin/updates', lambda f: any(x[0] == 'updates_action' and x[1] == 'check' for x in f['fields']), {})
post_action('pre_update_backup')
st, loc = post_action('install')
check('a package whose checksum is not the published one is refused', 'install=failed' in loc and read('VERSION').strip() == old_version, loc)
untouched('after the refused package')
check('and nothing is left behind', not exists('storage/updates/' + NEW) and not exists('storage/maintenance.flag'))

# ---- the maintenance page
flag = os.path.join(APP, 'storage/maintenance.flag')
import time
with open(flag, 'w') as f: json.dump({'since': int(time.time()), 'version': '1', 'token': 'letmein'}, f)
pub = Client()
st, h, html = pub.get('/')
check('while an update runs visitors get the maintenance page, with a request to retry', st == 503 and h.get('Retry-After') == '60' and 'Back in a moment' in html, (st, h.get('Retry-After')))
check('the pages are not cached by anyone', 'no-store' in (h.get('Cache-Control') or ''))
check('the admin is still open', pub.get('/admin/login')[0] == 200)
check('and the request that carries the token of the update gets in', pub.request('/survives', headers={'X-Faros-Update': 'letmein'})[0] == 200)
nonce = 'a' * 32
st, h, _ = pub.request('/survives?_faros_check=' + nonce, headers={'X-Faros-Update': 'letmein'})
import hmac
proof = hmac.new(b'letmein', (old_version + '|' + nonce).encode(), hashlib.sha256).hexdigest()
check('the probe proves which version answered and is never cached', st == 200 and h.get('X-Faros-Update-Check') == proof and 'no-store' in h.get('Cache-Control', ''), dict(h))
check('a normal admin request cannot get a version proof', pub.get('/admin/login?_faros_check=' + nonce)[1].get('X-Faros-Update-Check') is None)
check('a wrong token does not', pub.request('/survives', headers={'X-Faros-Update': 'nope'})[0] == 503)
with open(flag, 'w') as f: json.dump({'since': int(time.time()) - 3600, 'version': '1', 'token': 'old'}, f)
check('a flag an hour old is ignored, so a failed update cannot close the site for ever', pub.get('/survives')[0] == 200)
os.remove(flag)
check('without the flag the site is open', pub.get('/survives')[0] == 200)

server.shutdown()
shutil.rmtree(work, ignore_errors=True)
print()
print('ALL PASSED' if not fails else 'FAILED: %d' % len(fails))
sys.exit(1 if fails else 0)
