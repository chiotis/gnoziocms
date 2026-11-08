"""
Subscriptions over HTTP (docs/shop-plan.md, phase 7), against a fake Viva Wallet served by this test: the product page's period;
the checkout offering only Viva and asking a guest for an account; a signed-in customer's first payment allowing recurring
charges and starting the subscription; the account listing it; Shop > Subscriptions; the cron script charging a renewal as an
order of its own; the shop pausing and resuming; the customer cancelling.
"""
import json, os, re, sqlite3, subprocess, sys, threading, time
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlparse
sys.path.insert(0, '.')
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

def local(location):
    return re.sub(r'^https?://[^/]+', '', location or '')

# ---- a fake Viva
VIVA = {'orders': {}, 'tx': {}, 'charges': [], 'next': 1234567890123456}
class Fake(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def answer(self, status, data):
        body = json.dumps(data).encode()
        self.send_response(status); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
    def do_POST(self):
        path = urlparse(self.path).path
        n = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(n).decode() if n else ''
        if path == '/connect/token':
            return self.answer(200, {'access_token': 'fake-token', 'expires_in': 3600})
        if path == '/checkout/v2/orders':
            code = VIVA['next']; VIVA['next'] += 1
            VIVA['orders'][str(code)] = json.loads(body)
            return self.answer(200, {'orderCode': code})
        if path.startswith('/api/transactions/'):
            VIVA['charges'].append((path.rsplit('/', 1)[1], json.loads(body)))
            return self.answer(200, {'TransactionId': 'bbbbbbbb-0000-4000-8000-%012d' % len(VIVA['charges']), 'StatusId': 'F', 'ErrorCode': 0, 'Success': True})
        self.answer(404, {})
    def do_GET(self):
        path = urlparse(self.path).path
        if path.startswith('/checkout/v2/transactions/'):
            tx = VIVA['tx'].get(path.rsplit('/', 1)[1])
            return self.answer(200, tx) if tx else self.answer(404, {})
        self.answer(404, {})

server = HTTPServer(('127.0.0.1', 0), Fake)
threading.Thread(target=server.serve_forever, daemon=True).start()
FAKE = 'http://127.0.0.1:%d' % server.server_address[1]

root = Client(); root.login()
toggle(root, 'products', True)
write('app/content/products/club.md', """---
title: Λέσχη ανάγνωσης
status: published
date: '2026-03-01'
translation_id: club00000000000s
custom_fields:
  price: 10
  kind: subscription
  interval: month
  interval_count: 1
  signup_fee: 5
---
A club.
""")
root.submit('/admin/shop-settings?tab=general', has_field('order_prefix'), {'store_name': 'Aleon', 'order_prefix': 'SB', 'store_email': 'orders@example.test', 'guest_checkout': '1', 'terms_page': ''})
root.submit('/admin/shop-settings?tab=payments', has_field('tab'), {'viva[enabled]': '1', 'viva[demo]': '1', 'viva[merchant_id]': 'aaaa1111-2222-3333-4444-555566667777', 'viva[client_id]': 'client.apps.vivapayments.com', 'bank_transfer[enabled]': '1', 'bank_transfer[accounts]': 'GR16'})
db("insert or replace into system_meta (key, value, updated_at) values ('shop_secrets', ?, datetime('now'))", (json.dumps({'viva': {'base': FAKE, 'api_key': 'API-KEY-abcdef', 'client_secret': 'CLIENT-SECRET-xyz'}}),))

# ---- the product and a guest
pub = Client()
st, _, html = pub.get('/products/club')
check('the product page: a price per month, charged again, Add to cart', st == 200 and 'τον μήνα' in html and 'Χρεώνεται ξανά' in html and 'name="product" value="club00000000000s"' in html, st)
ORIGIN = {'Origin': BASE, 'Accept': 'application/json'}
pub.request('/_shop/cart', data={'product': 'club00000000000s', 'quantity': '1'}, headers=ORIGIN)
st, _, html = pub.get('/checkout')
check('the checkout: only Viva, the first payment with its sign-up fee', st == 200 and 'value="viva"' in html and 'value="bank_transfer"' not in html and '€15' in html, st)
form = {'email': 'maria@example.test', 'billing[first_name]': 'Maria', 'billing[last_name]': 'Patera', 'billing[address]': 'Ermou 1', 'billing[city]': 'Athens', 'billing[postcode]': '10563', 'billing[country]': 'GR', 'billing[phone]': '2100000000', 'payment': 'viva'}
st, hdr, html = pub.submit('/checkout', has_field('email'), form, button=('place', '1'))
check('a guest is asked for an account', st == 200 and 'χρειάζεται λογαριασμό' in html and db('select count(*) from shop_orders')[0][0] == 0, st)

# ---- signed in: the first payment
pub.submit('/account', lambda f: (f['attrs'].get('action') or '').endswith('/signup'), {'name': 'Maria', 'email': 'maria@example.test', 'password': 'a long password'})
# The cart kept from before signing in.
st, hdr, _ = pub.submit('/checkout', has_field('billing[first_name]'), form, button=('place', '1'))
go = hdr.get('Location') or ''
check('signed in: off to Viva', st == 303 and go.startswith(FAKE + '/web/checkout?ref='), (st, go))
code = go.rsplit('=', 1)[1]
sent = VIVA['orders'].get(code, {})
check('allowing the card to be charged again, for €15', sent.get('allowRecurring') is True and sent.get('amount') == 1500, sent)
order = db('select id, number from shop_orders order by id desc limit 1')[0]
check('a subscription waits for it', db('select status, price_cents from shop_subscriptions') == [('pending', 1000)])
tx = '7f9b0a2e-0000-4000-8000-000000000001'
VIVA['tx'][tx] = {'statusId': 'F', 'amount': 15.0, 'orderCode': int(code), 'merchantTrns': order[1], 'currencyCode': '978', 'totalInstallments': 0}
st, hdr, _ = pub.get('/_shop/pay/viva/success?t=%s&s=%s' % (tx, code))
check('paid: the order and the subscription start', 'pay=paid' in (hdr.get('Location') or '') and db('select status, token from shop_subscriptions') == [('active', tx)], hdr.get('Location'))
sub_id = db('select id from shop_subscriptions')[0][0]

st, _, html = pub.get('/account')
check('the account lists it, active, with its next date and Cancel', 'Οι συνδρομές σας' in html and 'Λέσχη ανάγνωσης' in html and 'ενεργή' in html and '/account/subscriptions/%d/cancel' % sub_id in html, st)

# ---- the shop's screen
st, _, html = root.get('/admin/shop-subscriptions')
check('Shop > Subscriptions lists it', st == 200 and 'Λέσχη ανάγνωσης' in html and 'Active' in html and 'Maria' in html, st)
st, _, html = root.get('/admin/shop-subscriptions?id=%d' % sub_id)
check('one: its price, next charge, orders and actions', '€10' in html and 'every month' in html and order[1] in html and 'Pause' in html and 'Skip the next period' in html)

# ---- the cron script charges a renewal
db('update shop_subscriptions set next_at = ? where id = ?', (int(time.time()) - 60, sub_id))
run = subprocess.run(['php', '-d', 'disable_functions=set_time_limit,ignore_user_abort,disk_free_space,opcache_reset,curl_exec', 'app/src/cron-backup.php'], capture_output=True, text=True, timeout=120)
check('the cron script renews it', run.returncode == 0 and 'background jobs worked: 1' in run.stdout, (run.returncode, run.stdout, run.stderr[-300:]))
check('charged on the token, for the price', VIVA['charges'] and VIVA['charges'][0][0] == tx and VIVA['charges'][0][1].get('amount') == 1000, VIVA['charges'])
renewal = db("select o.status, o.total_cents from shop_orders o join shop_subscription_orders l on l.order_id = o.id where l.kind = 'renewal'")
check('as a paid order of its own', renewal == [('paid', 1000)], renewal)
check('the subscription a period on', db('select renewed, next_at > ? from shop_subscriptions where id = ?', (int(time.time()) + 20 * 86400, sub_id)) == [(1, 1)])
run = subprocess.run(['php', '-d', 'disable_functions=set_time_limit,ignore_user_abort,disk_free_space,opcache_reset,curl_exec', 'app/src/cron-backup.php'], capture_output=True, text=True, timeout=120)
check('not twice', len(VIVA['charges']) == 1, VIVA['charges'])

# ---- pause, resume, cancel
pick = lambda action: (lambda f: ['action', action] in [list(x) for x in f['fields']])
st, hdr, _ = root.submit('/admin/shop-subscriptions?id=%d' % sub_id, pick('pause'), {})
check('the shop pauses it', 'done=ok' in (hdr.get('Location') or '') and db('select status from shop_subscriptions')[0][0] == 'paused')
st, hdr, _ = root.submit('/admin/shop-subscriptions?id=%d' % sub_id, pick('resume'), {})
check('and resumes it', 'done=ok' in (hdr.get('Location') or '') and db('select status from shop_subscriptions')[0][0] == 'active')
other = Client()
st, _, _ = other.request('/account/subscriptions/%d/cancel' % sub_id, data={})
check('no one else can cancel it', db('select status from shop_subscriptions')[0][0] == 'active')
st, hdr, _ = pub.submit('/account', lambda f: (f['attrs'].get('action') or '').endswith('/cancel'), {})
check('the customer cancels it', db('select status from shop_subscriptions')[0][0] == 'cancelled' and 'subscriptions' in (hdr.get('Location') or ''), hdr.get('Location'))
st, _, html = pub.get('/account')
check('the account says so, without Cancel', 'ακυρώθηκε' in html and '/account/subscriptions/%d/cancel' % sub_id not in html)

server.shutdown()
print('ALL PASSED' if not fails else f'{len(fails)} FAILED')
sys.exit(1 if fails else 0)
