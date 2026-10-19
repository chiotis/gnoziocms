"""Backups kept in remote storage can be listed on the Backups screen and fetched back to the server, then restored like any other:
a small S3 stand-in runs inside this test, the site uploads its backups to it (the real S3 code, signed requests and all), and the
screen reads it only when asked, fetches one, discards what is not a good backup, and keeps the whole thing to superadmins."""
import atexit, datetime, glob, os, re, socket, subprocess, sys, threading, time, urllib.parse, urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
sys.path.insert(0, '.')
import client
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)
def loc(hdr): return hdr.get('Location') or ''

# ---- a path-style S3 (bucket "bk"): PUT, GET (one object or the list), DELETE; some objects misbehave on purpose
objects = {}   # key -> {'data': bytes, 'modified': iso, 'mode': ''|'denied'|'short'|'lie'}
requests = []  # (method, 'list' or key)
class S3(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    def log_message(self, *a): pass
    def _split(self):
        u = urllib.parse.urlsplit(self.path)
        parts = urllib.parse.unquote(u.path).lstrip('/').split('/', 1)
        return (parts[1] if len(parts) > 1 else ''), urllib.parse.parse_qs(u.query)
    def _send(self, status, body=b'', ctype='application/xml', declared=None):
        self.send_response(status); self.send_header('Content-Type', ctype)
        self.send_header('Content-Length', str(len(body) if declared is None else declared)); self.end_headers(); self.wfile.write(body)
    def do_PUT(self):
        key, _ = self._split(); n = int(self.headers.get('Content-Length') or 0); data = self.rfile.read(n)
        objects[key] = {'data': data, 'modified': datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.000Z'), 'mode': ''}
        requests.append(('PUT', key)); self._send(200)
    def do_DELETE(self):
        key, _ = self._split(); objects.pop(key, None); requests.append(('DELETE', key)); self._send(204)
    def do_GET(self):
        key, q = self._split()
        if key == '':
            prefix = (q.get('prefix') or [''])[0]; requests.append(('GET', 'list'))
            items = ''.join('<Contents><Key>%s</Key><LastModified>%s</LastModified><Size>%d</Size></Contents>' % (k, o['modified'], len(o['data']) + (1 if o['mode'] == 'lie' else 0)) for k, o in sorted(objects.items()) if k.startswith(prefix))
            return self._send(200, ('<?xml version="1.0"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Name>bk</Name><IsTruncated>false</IsTruncated>%s</ListBucketResult>' % items).encode())
        requests.append(('GET', key)); o = objects.get(key)
        if o is None: return self._send(404, b'<Error><Code>NoSuchKey</Code><Message>The key does not exist</Message></Error>')
        if o['mode'] == 'denied': return self._send(403, b'<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>')
        if o['mode'] == 'short':  # promises more than it sends, then hangs up
            self.send_response(200); self.send_header('Content-Length', str(len(o['data']) + 500)); self.end_headers(); self.wfile.write(o['data'][:20]); self.wfile.flush(); self.close_connection = True; return
        self._send(200, o['data'], 'application/zip')
server = ThreadingHTTPServer(('127.0.0.1', 0), S3)
threading.Thread(target=server.serve_forever, daemon=True).start()
PORT = server.server_address[1]

root = Client(); root.login()
def backups_dir(): return sorted(os.path.basename(p) for p in glob.glob('app/storage/backups/*.zip'))
def flash(path):
    html = root.get(path)[2]
    m = re.search(r'<template data-flash data-type="(\w+)">(.*?)</template>', html, re.S)
    return (m.group(1), m.group(2).strip()) if m else (None, '')

# ---- turn remote storage on
root.submit('/admin/settings', has_field('backup_schedule'), {
    'backup_remote_enabled': '1', 'backup_remote_provider': 'custom', 'backup_remote_endpoint': 'http://127.0.0.1:%d' % PORT, 'backup_remote_bucket': 'bk',
    'backup_remote_access_key': 'AK', 'backup_remote_secret_key': 'SK', 'backup_remote_prefix': 'site', 'backup_remote_keep': '20', 'backup_remote_path_style': '1'})

# The test server runs with curl_exec switched off, as a shared host may (every other test relies on that). Remote storage then says
# so, instead of dying on a call to a function that is not there; the archive stays on the server.
st, hdr, _ = root.submit('/admin/backups', lambda f: ['backup_action', 'create_database'] in f['fields'])
kind, msg = flash(loc(hdr))
check('with curl_exec off, a backup is kept locally and the remote problem is a warning, not a crash', st in (302, 303) and 'backup=warn' in loc(hdr) and 'cURL' in msg and len(backups_dir()) == 1, (st, loc(hdr), msg))
st, _, html = root.get('/admin/backups?remote=1')
check('and the bucket list says why it cannot be read', 'Could not read remote storage' in html and 'cURL' in html, st)
root.submit('/admin/backups', lambda f: ['backup_action', 'delete'] in f['fields'], {'filename': backups_dir()[0]})
check('(that archive is removed again)', backups_dir() == [], backups_dir())

# From here on a second server on the same site, with curl on, so the real S3 code runs against the stand-in.
with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0)); curl_port = sock.getsockname()[1]
curl_server = subprocess.Popen(['php', '-S', '127.0.0.1:%d' % curl_port, '-t', 'public', 'public/index.php'], cwd='app', env=dict(os.environ, PHP_CLI_SERVER_WORKERS='4'), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
atexit.register(curl_server.kill)
client.BASE = 'http://127.0.0.1:%d' % curl_port
client.wait_until(lambda: client.port_open(curl_port), 10, what='the PHP server with curl to accept connections')
root = Client(); root.login()

# ---- the site's own backups go to the bucket and (by default) leave nothing on the server
st, hdr, _ = root.submit('/admin/backups', lambda f: ['backup_action', 'create_full'] in f['fields'])
check('a backup is made and sent to the bucket', st in (302, 303) and 'backup=ok' in loc(hdr) and len([k for k in objects if k.startswith('site/')]) == 1, (st, loc(hdr), list(objects)))
remote_name = next(k for k in objects).split('/', 1)[1]
check('by default no copy stays on the server', backups_dir() == [], backups_dir())

# ---- the screen reads the bucket only when asked
requests.clear()
st, _, html = root.get('/admin/backups')
check('the Backups screen offers the bucket but does not read it', st == 200 and 'id="remote-backups"' in html and 'Show backups in remote storage' in html and 'Fetch to this server' not in html and ('GET', 'list') not in requests, requests)
st, _, html = root.get('/admin/backups?remote=1')
check('asked, it lists what the bucket holds, with a Fetch button', remote_name in html and 'Fetch to this server' in html and requests.count(('GET', 'list')) == 1, (requests, html[:100]))
check('Local backups is still empty', 'No local backups yet.' in html)

# ---- fetching one
st, hdr, _ = root.submit('/admin/backups?remote=1', lambda f: ['backup_action', 'fetch_remote'] in f['fields'] and ['filename', remote_name] in f['fields'])
check('fetching sends the browser back with a success message', st in (302, 303) and 'backup=ok' in loc(hdr), (st, loc(hdr)))
check('the archive is in the backups folder and nothing else is', backups_dir() == [remote_name] and not glob.glob('app/storage/backups/.fetch-*'), (backups_dir(), glob.glob('app/storage/backups/.*')))
kind, msg = flash(loc(hdr))
check('the message says what was fetched and that it is not restored', kind == 'success' and remote_name in msg and 'Fetched' in msg and 'use Restore' in msg, (kind, msg))
st, _, html = root.get('/admin/backups?remote=1')
check('it is now a local backup and the bucket list says it is on this server', f'>{remote_name}<' in html and html.count(remote_name) >= 2 and 'Fetch to this server' not in html and re.search(r'bg-emerald-50[^>]*>Yes<', html) is not None, html.count(remote_name))
check('with a Restore link', f'admin/backups?restore={remote_name}' in html)
st, _, html = root.get('/admin/backups?restore=' + remote_name)
check('and the restore screen opens for it, the archive verified', st == 200 and remote_name in html and 'Archive verified' in html, st)
st, hdr, _ = root.submit('/admin/backups', lambda f: ['backup_action', 'verify'] in f['fields'], {'backup_action': 'fetch_remote', 'filename': remote_name})
kind, msg = flash(loc(hdr)) if st in (302, 303) else (None, '')
check('fetching it again is refused', kind == 'error' and 'already on this server' in msg, (st, kind, msg))

# ---- what goes wrong is reported and leaves nothing behind
good = objects['site/' + remote_name]['data']; now = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.000Z')
def plant(name, data, mode=''): objects['site/' + name] = {'data': data, 'modified': now, 'mode': mode}
plant('x-backup-20260102-120000-bbbbbb.zip', b'not a zip file' * 40)
plant('x-backup-20260103-120000-cccccc.zip', good, 'short')
plant('x-backup-20260104-120000-dddddd.zip', good, 'denied')
plant('x-backup-20260105-120000-eeeeee.zip', good, 'lie')
def fetch(name):
    # any form of the screen carries the token; the action and the name are set, as a hand-made request would
    st, hdr, _ = root.submit('/admin/backups', lambda f: ['backup_action', 'create_database'] in f['fields'], {'backup_action': 'fetch_remote', 'filename': name})
    return flash(loc(hdr)) if st in (302, 303) else (None, 'status %s' % st)
kind, msg = fetch('x-backup-20260102-120000-bbbbbb.zip')
check('a file that is not a backup is discarded with a reason', kind == 'error' and 'not a valid backup' in msg and 'x-backup-20260102-120000-bbbbbb.zip' not in backups_dir(), (kind, msg))
kind, msg = fetch('x-backup-20260103-120000-cccccc.zip')
check('a transfer that is cut short fails and leaves nothing', kind == 'error' and 'x-backup-20260103-120000-cccccc.zip' not in backups_dir(), (kind, msg))
kind, msg = fetch('x-backup-20260104-120000-dddddd.zip')
check('a refused download says what the storage said', kind == 'error' and 'HTTP 403' in msg and 'Access Denied' in msg, (kind, msg))
kind, msg = fetch('x-backup-20260105-120000-eeeeee.zip')
check('a file that is not the size listed is discarded', kind == 'error' and 'incomplete' in msg and 'x-backup-20260105-120000-eeeeee.zip' not in backups_dir(), (kind, msg))
kind, msg = fetch('notes.zip')
check('a name the bucket does not list is not fetched', kind == 'error' and 'not found in remote storage' in msg, (kind, msg))
check('after all that the folder holds only the good one, with no temporary files', backups_dir() == [remote_name] and not glob.glob('app/storage/backups/.fetch-*'), (backups_dir(), glob.glob('app/storage/backups/.*')))
requests.clear(); fetch('notes.zip')
check('and nothing else in the bucket was downloaded', not [r for r in requests if r[0] == 'GET' and r[1] != 'list'], requests)

# ---- superadmins only
for u, r in (('adm2', 'admin'), ('ed2', 'editor')):
    root.submit('/admin/users-edit', has_field('username'), {'username': u, 'email': u + '@example.test', 'display_name': u, 'role': r, 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
    c = Client(); c.login(u, 'Sturdy-pass-99')
    st, _, html = c.get('/admin/backups?remote=1')
    check(f'{r}: the bucket is not listed', 'id="remote-backups"' not in html and 'Fetch to this server' not in html, st)
    plant('y-backup-20260106-120000-%s.zip' % ('a' * 6), good)
    requests.clear()
    try:
        st, _, _ = c.submit('/admin/backups', lambda f: ['backup_action', 'create_database'] in f['fields'], {'backup_action': 'fetch_remote', 'filename': 'y-backup-20260106-120000-aaaaaa.zip'})
    except StopIteration:  # a role that cannot even open the screen has no form to send
        st = 403
    check(f'{r}: fetching is refused', st == 403 and 'y-backup-20260106-120000-aaaaaa.zip' not in backups_dir() and not [x for x in requests if x[0] == 'GET'], (st, requests))

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
