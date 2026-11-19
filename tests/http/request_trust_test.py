"""What the site believes about a request (RequestOrigin, Settings > Trusted proxies; audit S1, S6, S12): a password-reset email
asked for with a foreign Host links to the site's own address or to a path, never to that host; forwarded headers count only
from a trusted proxy, for the address MCP limits by and for the address pages are built with; and the shop's card payments and
customer accounts cannot be turned on while the site address is blank. Mail goes to a fake SMTP server so the body can be read."""
import hashlib, json, os, re, socket, sqlite3, sys, threading
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

def write(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    open(path, 'w', encoding='utf-8').write(text)

def db(query, args=()):
    c = sqlite3.connect('app/storage/db/app.sqlite')
    rows = c.execute(query, args).fetchall()
    c.commit()
    c.close()
    return rows

def toggle(client, type_name, on):
    pick = lambda f: ['type', type_name] in [list(x) for x in f['fields']] and ['action', 'toggle'] in [list(x) for x in f['fields']]
    return client.submit('/admin/content-types', pick, {'enabled': '1' if on else '0'})

def is_settings(f):
    return any(x[0] == 'title' for x in f['fields']) and any(x[0] == 'date_format' for x in f['fields'])

# ---- a fake SMTP server that keeps every message
MAILS = []
listener = socket.socket()
listener.bind(('127.0.0.1', 0))
listener.listen(8)
listener.settimeout(None)
SMTP_PORT = listener.getsockname()[1]

def serve(conn):
    conn.settimeout(20)
    f = conn.makefile('rwb')
    def say(line):
        f.write((line + '\r\n').encode()); f.flush()
    try:
        say('220 fake ESMTP')
        data = None
        while True:
            line = f.readline()
            if not line:
                return
            if data is not None:
                if line in (b'.\r\n', b'.\n'):
                    MAILS.append(b''.join(data).decode('utf-8', 'replace'))
                    data = None
                    say('250 queued')
                else:
                    data.append(line)
                continue
            cmd = line.decode('utf-8', 'replace').strip().upper()
            if cmd.startswith('EHLO') or cmd.startswith('HELO'):
                say('250 fake')
            elif cmd == 'DATA':
                data = []
                say('354 go on')
            elif cmd == 'QUIT':
                say('221 bye')
                return
            else:
                say('250 ok')
    except OSError:
        pass
    finally:
        conn.close()

def accept():
    while True:
        try:
            conn, _ = listener.accept()
        except OSError:
            return
        threading.Thread(target=serve, args=(conn,), daemon=True).start()
threading.Thread(target=accept, daemon=True).start()

def plain(message):
    """The message with quoted-printable soft breaks and =3D undone, enough to find a link in it."""
    return message.replace('=\r\n', '').replace('=\n', '').replace('=3D', '=')

root = Client(); root.login()
st, hdr, _ = root.submit('/admin/settings', is_settings, {'base_url': '', 'mail_driver': 'smtp', 'smtp_host': '127.0.0.1', 'smtp_port': str(SMTP_PORT), 'smtp_user': '', 'smtp_pass': '', 'smtp_encryption': '', 'mail_from': 'site@example.test'})
st, _, html = root.get('/admin/settings')
check('Trusted proxies hold this machine until the list is changed', '127.0.0.1\n::1</textarea>' in html, re.findall(r'name="trusted_proxies"[^>]*>[^<]*', html)[:1])
# A site with nothing in front: no forwarded header is believed.
root.submit('/admin/settings', is_settings, {'trusted_proxies': ''})
toggle(root, 'products', True)
root.submit('/admin/shop-settings?tab=general', has_field('order_prefix'), {'store_name': 'Aleon', 'order_prefix': 'AL', 'store_email': 'orders@example.test', 'guest_checkout': '1', 'terms_page': ''})

# ---- the shop refuses to turn on what sends people to the site's addresses, while the address is blank
st, _, html = root.get('/admin/shop-settings?tab=payments')
check('the shop settings warn that the site address is not set', 'The site address is not set' in html, st)
st, hdr, _ = root.submit('/admin/shop-settings?tab=payments', has_field('tab'), {'viva[enabled]': '1', 'viva[merchant_id]': 'aaaa1111-2222-3333-4444-555566667777', 'viva[client_id]': 'client.apps.vivapayments.com'})
st, _, html = root.get(hdr.get('Location') or '/admin/shop-settings?tab=payments')
check('Viva cannot be turned on without a site address, and the screen says why', 'Card payments cannot be turned on yet' in html and 'site address' in html, re.findall(r'Card payments[^<]*', html)[:1])
shop = json.loads(db("select value from system_meta where key = 'shop_settings'")[0][0] or '{}')
check('and it stays off', not shop.get('payments', {}).get('viva', {}).get('enabled'), shop.get('payments'))
st, hdr, _ = root.submit('/admin/shop-settings?tab=accounts', has_field('tab'), {'google': '1', 'google_client_id': '1234-abc.apps.googleusercontent.com'})
st, _, html = root.get(hdr.get('Location') or '/admin/shop-settings?tab=accounts')
check('nor Google sign-in for customers', 'Customer accounts cannot be turned on yet' in html, st)

# ---- a customer, then "forgot password" sent with a foreign Host
shopper = Client()
shopper.submit('/account', lambda f: (f['attrs'].get('action') or '').endswith('/signup'), {'name': 'Eleni', 'email': 'eleni@example.test', 'password': 'a long password'})

def forgot(headers):
    """Asks for a reset link with the given headers (the form's CSRF token comes from a normal visit) and returns the mail."""
    visitor = Client()
    form = next(f for f in visitor.forms('/account/forgot') if (f['attrs'].get('action') or '').endswith('/forgot'))
    fields = [tuple(x) for x in form['fields'] if x[0] != 'email'] + [('email', 'eleni@example.test')]
    before = len(MAILS)
    st, hdr, _ = visitor.request('/account/forgot', data=fields, headers=dict(headers, Origin=BASE))
    for _ in range(100):
        if len(MAILS) > before:
            break
        threading.Event().wait(0.05)
    return plain(MAILS[-1]) if len(MAILS) > before else '', st

mail, st = forgot({'Host': 'evil.example', 'X-Forwarded-Host': 'evil.example', 'X-Forwarded-Proto': 'https'})
check('a reset email is sent', 'reset?token=' in mail, (st, mail[-300:]))
check('with no site address and a foreign Host, its link does not name that host', 'evil.example' not in mail and re.search(r'(?<![\w.:/])/account/reset\?token=', mail) is not None, re.findall(r'\S*reset\?token=\S*', mail)[:1])

root.submit('/admin/settings', is_settings, {'base_url': BASE})
mail, st = forgot({'Host': 'evil.example', 'X-Forwarded-Host': 'evil.example'})
check('with the site address set, the link is the site\'s own, whatever Host says', (BASE + '/account/reset?token=') in mail and 'evil.example' not in mail, re.findall(r'\S*reset\?token=\S*', mail)[:1])

# ---- forwarded headers count only from a trusted proxy
root.submit('/admin/settings', is_settings, {'base_url': ''})
pub = Client()
st, _, html = pub.request('/', headers={'X-Forwarded-Host': 'evil.example', 'X-Forwarded-Proto': 'https'})
check('an untrusted X-Forwarded-Host or -Proto does not change the address pages are built with', 'evil.example' not in html and 'https://127.0.0.1' not in html, re.findall(r'<link rel="canonical"[^>]*>', html)[:1])

def mcp_hits(ip):
    key = hashlib.sha256(('mcp-ip:' + ip).encode()).hexdigest()
    rows = db("select hits from mcp_rate_limits where rate_key = ?", (key,))
    return rows[0][0] if rows else 0

import urllib.request
def mcp_post(forwarded):
    req = urllib.request.Request(BASE + '/mcp', data=b'{}', headers={'Content-Type': 'application/json', 'X-Forwarded-For': forwarded}, method='POST')
    try:
        urllib.request.urlopen(req, timeout=30).read()
    except Exception:
        pass

root.submit('/admin/mcp', has_field('mcp_action'), button=('mcp_action', 'enable'))
before = mcp_hits('127.0.0.1')
for fake in ('198.51.100.1', '198.51.100.2', '198.51.100.3'):
    mcp_post(fake)
check('a forged X-Forwarded-For does not change the address MCP limits by', mcp_hits('127.0.0.1') - before == 3 and mcp_hits('198.51.100.1') == 0, (mcp_hits('127.0.0.1'), before, mcp_hits('198.51.100.1')))

st, hdr, _ = root.submit('/admin/settings', is_settings, {'trusted_proxies': '127.0.0.1\n10.0.0.0/8\nnot an address'})
st, _, html = root.get('/admin/settings')
check('Trusted proxies are kept, and what is not an address is left out', '127.0.0.1\n10.0.0.0/8</textarea>' in html, re.findall(r'name="trusted_proxies"[^>]*>[^<]*', html)[:1])
mcp_post('203.0.113.66, 198.51.100.7, 10.1.2.3')
check('behind a trusted proxy, the visitor is the first address from the right that is not a proxy', mcp_hits('198.51.100.7') == 1 and mcp_hits('203.0.113.66') == 0, (mcp_hits('198.51.100.7'), mcp_hits('203.0.113.66')))
st, _, html = pub.request('/', headers={'X-Forwarded-Host': 'proxied.example', 'X-Forwarded-Proto': 'https'})
check('and its X-Forwarded-Host and -Proto count', 'https://proxied.example' in html, re.findall(r'<link rel="canonical"[^>]*>', html)[:1])
listener.close()
print('\nALL PASSED' if not fails else '\n%d FAILED' % len(fails))
sys.exit(1 if fails else 0)
