"""A new site from the package alone: unzip, copy the demo, make the first administrator, and every public and admin
page works. This catches anything the code needs at run time that the package leaves out."""
import os, re, shutil, socket, subprocess, sys, tempfile, time, zipfile
sys.path.insert(0, '.')
import client
from client import Client, has_field

fails = []
# Each step needs the one before it: a failure is recorded once (critical=True) and the checks after it are skipped.
flow = client.Flow(fails)
check = flow.check

root = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(client.__file__)), '..', '..'))
work = tempfile.mkdtemp()
subprocess.run(['php', os.path.join(root, 'scripts/build-release.php'), os.path.join(work, 'out')], check=True, capture_output=True)
site = os.path.join(work, 'site')
zip_path = [os.path.join(work, 'out', n) for n in os.listdir(os.path.join(work, 'out')) if n.endswith('.zip')][0]
zipfile.ZipFile(zip_path).extractall(site)
check('the package has no content, no database and no account', not os.path.exists(os.path.join(site, 'content')) and not os.path.exists(os.path.join(site, 'storage')))
out = subprocess.run(['php', 'scripts/use-starter.php'], cwd=site, capture_output=True, text=True).stdout
check('the demo is copied in', 'Copied' in out and os.path.isfile(os.path.join(site, 'content/pages/index.md')), out[-200:], critical=True)
check('and brings no account of its own', not os.path.exists(os.path.join(site, 'content/users')))

s = socket.socket(); s.bind(('127.0.0.1', 0)); port = s.getsockname()[1]; s.close()
log = open(os.path.join(work, 'server.log'), 'w')
server = subprocess.Popen(['php', '-S', '127.0.0.1:%d' % port, '-t', 'public', 'public/index.php'], cwd=site, stdout=log, stderr=log)
try:
    client.BASE = 'http://127.0.0.1:%d' % port
    client.wait_until(lambda: client.port_open(port), 10, what='the PHP server to accept connections')
    c = Client()
    st, h, html = c.get('/admin/login')
    check('the first visit asks for the first administrator', st == 200 and 'Welcome' in html, st, critical=True)
    st, h, html = c.submit('/admin/login', has_field('password_confirm'), {'username': 'owner', 'email': 'owner@example.test', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
    check('who is made and signed in', st in (301, 302) and c.get('/admin')[0] == 200, st, critical=True)
    pages = ['/', '/about', '/posts', '/projects', '/contact', '/sitemap.xml', '/robots.txt', '/search?q=a', '/admin', '/admin/content?type=pages', '/admin/content?type=posts',
             '/admin/edit?type=pages&slug=about', '/admin/media', '/admin/settings', '/admin/theme', '/admin/updates', '/admin/backups', '/admin/users', '/admin/roles', '/admin/taxonomies',
             '/admin/menus', '/admin/redirects', '/admin/system', '/admin/translations', '/admin/content-types', '/admin/forms', '/admin/revisions', '/admin/activity-logs']
    bad = []
    for p in pages:
        st, h, html = c.get(p)
        if st != 200 or re.search(r'(?:Fatal error|Parse error|Uncaught|Warning: |Notice: |Deprecated: )', html):
            bad.append((p, st))
    check('every public and admin page works', bad == [], bad)
    check('the theme and its files are served from the package', c.get('/_themes/default/css/site.css')[0] == 200 and c.get('/assets/css/admin.build.css')[0] == 200)
    check('uploads are protected from running code', os.path.isfile(os.path.join(site, 'public/uploads/.htaccess')))
    check('the server log has no PHP errors', not re.search(r'Fatal|Warning|Deprecated|Notice', open(os.path.join(work, 'server.log')).read()))
finally:
    server.terminate()
    server.wait()
    shutil.rmtree(work, ignore_errors=True)

print()
print('ALL PASSED' if not fails else 'FAILED: %d' % len(fails))
sys.exit(1 if fails else 0)
