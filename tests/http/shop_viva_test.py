"""
Viva Wallet over HTTP (docs/shop-plan.md, phase 4), against a fake of Viva's API that this test serves on this machine: the keys
on Shop settings > Payments (kept apart, never shown again) and Check connection; the checkout sending the customer to Viva's page;
coming back paid (asked of the API first), the order paid and its emails written; a forged return that pays nothing; the webhook's
key and its events, taken once; coming back without paying, then Pay now; a refund in part and in full from the order's screen.
"""
import json, os, re, sqlite3, sys, threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlparse, parse_qs
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
VIVA = {'orders': {}, 'tx': {}, 'refunds': [], 'next': 1234567890123456, 'calls': []}
class Fake(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def answer(self, status, data):
        body = json.dumps(data).encode()
        self.send_response(status); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
    def body(self):
        n = int(self.headers.get('Content-Length') or 0)
        return self.rfile.read(n).decode() if n else ''
    def do_POST(self):
        path = urlparse(self.path).path
        VIVA['calls'].append(('POST', path))
        body = self.body()
        if path == '/connect/token':
            ok = 'Basic' in (self.headers.get('Authorization') or '') and 'grant_type=client_credentials' in body
            return self.answer(200, {'access_token': 'fake-token', 'expires_in': 3600}) if ok else self.answer(400, {'error': 'invalid_client'})
        if path == '/checkout/v2/orders':
            if self.headers.get('Authorization') != 'Bearer fake-token':
                return self.answer(401, {})
            code = VIVA['next']; VIVA['next'] += 1
            VIVA['orders'][str(code)] = json.loads(body)
            return self.answer(200, {'orderCode': code})
        self.answer(404, {})
    def do_GET(self):
        path = urlparse(self.path).path
        VIVA['calls'].append(('GET', path))
        if path.startswith('/checkout/v2/transactions/'):
            tx = VIVA['tx'].get(path.rsplit('/', 1)[1])
            return self.answer(200, tx) if tx else self.answer(404, {'message': 'not found'})
        if path == '/api/messages/config/token':
            return self.answer(200, {'Key': 'FAKE-WEBHOOK-KEY'})
        self.answer(404, {})
    def do_DELETE(self):
        url = urlparse(self.path)
        VIVA['calls'].append(('DELETE', url.path))
        VIVA['refunds'].append((url.path.rsplit('/', 1)[1], parse_qs(url.query).get('amount', [''])[0]))
        self.answer(200, {'TransactionId': 'refund-%d' % len(VIVA['refunds']), 'ErrorCode': 0, 'Success': True})

server = HTTPServer(('127.0.0.1', 0), Fake)
threading.Thread(target=server.serve_forever, daemon=True).start()
FAKE = 'http://127.0.0.1:%d' % server.server_address[1]

def pay(code, status='F', amount=None, number=None, tx=None):
    order = VIVA['orders'][str(code)]
    tx = tx or '7f9b0a2e-0000-4000-8000-%012d' % (len(VIVA['tx']) + 1)
    VIVA['tx'][tx] = {'statusId': status, 'amount': (amount if amount is not None else order['amount']) / 100, 'orderCode': int(code), 'merchantTrns': number or order['merchantTrns'], 'currencyCode': '978', 'totalInstallments': 0, 'email': order['customer']['email']}
    return tx

root = Client(); root.login()
toggle(root, 'products', True)
write('app/content/products/ring.md', """---
title: Δαχτυλίδι
status: published
date: '2026-03-01'
translation_id: ring00000000000v
custom_fields:
  price: 150
  track_stock: true
---
Ring.
""")
db("insert or replace into shop_stock (product_id, variant_key, on_hand, reserved, updated_at) values ('ring00000000000v', '', 5, 0, 0)")
root.submit('/admin/shop-settings?tab=general', has_field('order_prefix'), {'store_name': 'Aleon', 'order_prefix': 'AL', 'store_email': 'orders@example.test', 'guest_checkout': '1', 'terms_page': ''})
root.submit('/admin/shop-settings?tab=shipping', has_field('tab'), {'zones[0][name]': 'Greece', 'zones[0][countries]': 'GR', 'zones[0][methods][0][kind]': 'flat', 'zones[0][methods][0][label][el]': 'Courier', 'zones[0][methods][0][cost]': '0'})
# The fake's address: a secret no screen can set, honoured only on this machine.
db("insert or replace into system_meta (key, value, updated_at) values ('shop_secrets', ?, datetime('now'))", (json.dumps({'viva': {'base': FAKE}}),))

# ---- Shop settings > Payments
st, _, html = root.get('/admin/shop-settings?tab=payments')
check('the Viva Wallet card, with the addresses to give Viva', st == 200 and 'name="viva[merchant_id]"' in html and '/_shop/pay/viva/success' in html and '/_shop/pay/viva/webhook' in html, st)
st, hdr, _ = root.submit('/admin/shop-settings?tab=payments', has_field('tab'), {'viva[enabled]': '1', 'viva[demo]': '1', 'viva[merchant_id]': 'aaaa1111-2222-3333-4444-555566667777', 'viva[client_id]': 'client.apps.vivapayments.com', 'viva[source_code]': '1234', 'viva[installments]': '100 = 3', 'viva_secret[api_key]': 'API-KEY-abcdef', 'viva_secret[client_secret]': 'CLIENT-SECRET-xyz'}, button=('viva_action', 'check'))
loc = hdr.get('Location') or ''
check('Save and check connection: the keys work', 'done=ok' in loc and 'answers' in loc, loc)
st, _, html = root.get('/admin/shop-settings?tab=payments')
check('the keys are never shown again, only their ends', 'API-KEY-abcdef' not in html and 'CLIENT-SECRET-xyz' not in html and 'ends in cdef' in html and 'ends in -xyz' in html)
stored = json.loads(db("select value from system_meta where key = 'shop_settings'")[0][0])
secrets = json.loads(db("select value from system_meta where key = 'shop_secrets'")[0][0])
check('kept apart from the Shop settings, the fake\'s address kept', 'API-KEY' not in json.dumps(stored) and secrets['viva']['api_key'] == 'API-KEY-abcdef' and secrets['viva']['base'] == FAKE)
root.submit('/admin/shop-settings?tab=payments', has_field('tab'), {'viva_secret[api_key]': ''})
check('saving with the key left blank keeps it', json.loads(db("select value from system_meta where key = 'shop_secrets'")[0][0])['viva']['api_key'] == 'API-KEY-abcdef')

# ---- paying with Viva
pub = Client()
ORIGIN = {'Origin': BASE, 'Accept': 'application/json'}
pub.request('/_shop/cart', data={'product': 'ring00000000000v', 'quantity': '1'}, headers=ORIGIN)
st, _, html = pub.get('/checkout')
check('the checkout offers cards through Viva first', st == 200 and re.search(r'name="payment" value="viva" checked', html) is not None and 'Apple Pay' in html, st)
method = re.search(r'name="method" value="([a-z0-9]+)" checked', html).group(1)
form = {'email': 'maria@example.test', 'billing[first_name]': 'Maria', 'billing[last_name]': 'Patera', 'billing[address]': 'Ermou 1', 'billing[city]': 'Athens', 'billing[postcode]': '10563', 'billing[country]': 'GR', 'billing[phone]': '2100000000', 'payment': 'viva', 'method': method}
st, hdr, _ = pub.submit('/checkout', has_field('email'), form, button=('place', '1'))
go = hdr.get('Location') or ''
check('placing the order sends the customer to Viva\'s page', st == 303 and go.startswith(FAKE + '/web/checkout?ref='), (st, go))
code = go.rsplit('=', 1)[1]
sent = VIVA['orders'].get(code, {})
order = db("select id, number, status, total_cents, stock_held from shop_orders order by id desc limit 1")[0]
check('the payment order Viva got: amount, number, customer, source, installments', sent.get('amount') == 15000 and sent.get('merchantTrns') == order[1] and sent.get('customer', {}).get('email') == 'maria@example.test' and sent.get('sourceCode') == '1234' and sent.get('maxInstallments') == 3, sent)
check('the order waits, its stock held', order[2] == 'pending_payment' and order[4] == 1 and db("select reserved from shop_stock where product_id = 'ring00000000000v'")[0][0] == 1, order)
check('no email yet: the order is not paid', not any(order[1] in s for (s,) in db('select subject from email_logs')))

# a forged return: a transaction Viva does not know
st, hdr, _ = pub.get('/_shop/pay/viva/success?t=7f9b0a2e-0000-4000-8000-999999999999&s=' + code)
check('a made-up transaction pays nothing', db('select status from shop_orders where id = ?', (order[0],))[0][0] == 'pending_payment' and 'pay=failed' in (hdr.get('Location') or ''), hdr.get('Location'))
# a payment of another amount
cheap = pay(code, amount=100)
pub.get('/_shop/pay/viva/success?t=%s&s=%s' % (cheap, code))
check('nor one of another amount', db('select status from shop_orders where id = ?', (order[0],))[0][0] == 'pending_payment')

tx = pay(code)
st, hdr, _ = pub.get('/_shop/pay/viva/success?t=%s&s=%s&lang=el-GR&eventId=0&eci=1' % (tx, code))
back = local(hdr.get('Location'))
check('back from Viva: to the order\'s page, paid', st == 303 and back.startswith('/checkout/order/' + order[1]) and 'pay=paid' in back, (st, back))
row = db('select status, stock_held from shop_orders where id = ?', (order[0],))[0]
check('the order is paid and has taken its stock', row == ('paid', 2) and db("select on_hand, reserved from shop_stock where product_id = 'ring00000000000v'")[0] == (4, 0), row)
check('the payment keeps its transaction', db("select status, transaction_id from shop_payments where reference = ?", (code,))[0] == ('paid', tx))
mails = db('select recipient, subject from email_logs')
check('now the customer\'s email and the shop\'s notice', any(r == 'maria@example.test' and order[1] in s for r, s in mails) and any(r == 'orders@example.test' and order[1] in s for r, s in mails), mails[-3:])
st, _, page = pub.get(back)
check('the page says paid', 'Η πληρωμή ολοκληρώθηκε' in page and 'Πληρώθηκε' in page)

# ---- the webhook
st, _, body = Client().get('/_shop/pay/viva/webhook')
check('the webhook answers Viva with its key', st == 200 and json.loads(body) == {'Key': 'FAKE-WEBHOOK-KEY'}, body)
event = {'EventTypeId': 1796, 'EventData': {'TransactionId': tx, 'OrderCode': int(code), 'StatusId': 'F'}, 'MessageId': 'm-1'}
import urllib.request
def post_event(e):
    req = urllib.request.Request(BASE + '/_shop/pay/viva/webhook', data=json.dumps(e).encode(), headers={'Content-Type': 'application/json'}, method='POST')
    try:
        r = urllib.request.urlopen(req, timeout=20); return r.status
    except urllib.error.HTTPError as err:
        return err.code
check('the same payment by the webhook: answered, nothing changes twice', post_event(event) == 200 and len([1 for (s,) in db('select subject from email_logs where recipient = ?', ('maria@example.test',)) if order[1] in s]) == 1)
check('the event is written', db("select kind, outcome from shop_gateway_events where event_key = ?", ('1796:' + tx,)) == [('webhook 1796', 'already')])

# ---- a second order, paid by the webhook alone
pub.request('/_shop/cart', data={'product': 'ring00000000000v', 'quantity': '1'}, headers=ORIGIN)
st, hdr, _ = pub.submit('/checkout', has_field('email'), form, button=('place', '1'))
code2 = (hdr.get('Location') or '').rsplit('=', 1)[1]
second = db("select id, number from shop_orders order by id desc limit 1")[0]
tx2 = pay(code2)
check('the webhook alone pays an order (the customer closed the page)', post_event({'EventTypeId': 1796, 'EventData': {'TransactionId': tx2}}) == 200 and db('select status from shop_orders where id = ?', (second[0],))[0][0] == 'paid')
check('an event for a transaction Viva does not know changes nothing', post_event({'EventTypeId': 1796, 'EventData': {'TransactionId': '7f9b0a2e-0000-4000-8000-000000000000'}}) == 503)

# ---- coming back without paying, then Pay now
pub.request('/_shop/cart', data={'product': 'ring00000000000v', 'quantity': '1'}, headers=ORIGIN)
st, hdr, _ = pub.submit('/checkout', has_field('email'), form, button=('place', '1'))
code3 = (hdr.get('Location') or '').rsplit('=', 1)[1]
third = db("select id, number from shop_orders order by id desc limit 1")[0]
st, hdr, _ = pub.get('/_shop/pay/viva/failure?s=' + code3)
page_url = local(hdr.get('Location'))
check('back without paying: the order\'s page says so', 'pay=failed' in page_url and db('select status from shop_orders where id = ?', (third[0],))[0][0] == 'pending_payment', page_url)
st, _, page = pub.get(page_url)
check('with Pay now', 'Η πληρωμή δεν ολοκληρώθηκε' in page and 'Πληρωμή τώρα' in page and '/checkout/order/' + third[1] + '/pay' in page)
st, hdr, _ = pub.submit(page_url, lambda f: (f['attrs'].get('action') or '').endswith('/pay'), {})
check('Pay now: off to Viva again with a new payment order', st == 303 and (hdr.get('Location') or '').startswith(FAKE + '/web/checkout?ref=') and not (hdr.get('Location') or '').endswith(code3), hdr.get('Location'))
check('the failed one is written as failed', db("select status from shop_payments where reference = ?", (code3,))[0][0] == 'failed')
st, _, _ = Client().get('/_shop/pay/viva/failure?s=0000000000000000')
check('a failure for a payment the shop never made: nothing there', st == 404, st)

# ---- refunds
st, _, html = root.get('/admin/shop-orders?id=%d' % order[0])
check('the order\'s screen lists the payment, and can refund it', 'Viva Wallet · €150' in html and tx in html and 'Refund through Viva' in html)
st, hdr, _ = root.submit('/admin/shop-orders?id=%d' % order[0], lambda f: ['action', 'refund'] in [list(x) for x in f['fields']], {'amount': '50'})
check('a part refunded', 'done=ok' in (hdr.get('Location') or '') and VIVA['refunds'][-1] == (tx, '5000') and db('select status from shop_orders where id = ?', (order[0],))[0][0] == 'paid', (hdr.get('Location'), VIVA['refunds']))
st, hdr, _ = root.submit('/admin/shop-orders?id=%d' % order[0], lambda f: ['action', 'refund'] in [list(x) for x in f['fields']], {'amount': '200'})
check('not more than what is left', 'done=fail' in (hdr.get('Location') or '') and len(VIVA['refunds']) == 1)
st, hdr, _ = root.submit('/admin/shop-orders?id=%d' % order[0], lambda f: ['action', 'refund'] in [list(x) for x in f['fields']], {})
check('the rest: the order is refunded', 'done=ok' in (hdr.get('Location') or '') and VIVA['refunds'][-1] == (tx, '10000') and db('select status from shop_orders where id = ?', (order[0],))[0][0] == 'refunded', (hdr.get('Location'), VIVA['refunds']))
check('and the payment shows it', db('select refunded_cents from shop_payments where reference = ?', (code,))[0][0] == 15000)

# ---- turned off
root.submit('/admin/shop-settings?tab=payments', has_field('tab'), {}, drop=['viva[enabled]'])
pub.request('/_shop/cart', data={'product': 'ring00000000000v', 'quantity': '1'}, headers=ORIGIN)
st, _, html = pub.get('/checkout')
check('Viva off: not offered at the checkout', 'value="viva"' not in html)
st, _, _ = Client().get('/_shop/pay/viva/webhook')
check('and its webhook is closed', st == 404, st)

server.shutdown()
print('ALL PASSED' if not fails else f'{len(fails)} FAILED')
sys.exit(1 if fails else 0)
