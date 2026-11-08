"""
Cardlink over HTTP (docs/shop-plan.md, phase 5), against a fake of its XML API that this test serves on this machine: the Payments
tab's card (the shared secret kept apart, never shown again) and Check connection; the checkout sending the customer to the signed
go page, the installments asked there and the signed form to the bank; a forged answer paying nothing; the bank's answer paying the
order (and its emails); a cancel, then Pay now; a refund from the order's screen.
"""
import base64, hashlib, json, os, re, sqlite3, sys, threading
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

SECRET = 'Shared-Secret-42'
def digest(values):
    return base64.b64encode(hashlib.sha256((values + SECRET).encode()).digest()).decode()

# ---- a fake Cardlink XML API
BANK = {'requests': []}
def xml(inner):
    return ('<?xml version="1.0" encoding="UTF-8"?><VPOS xmlns="http://www.modirum.com/schemas/vposxmlapi41"><Message messageId="M1" timeStamp="t" version="2.1">' + inner + '</Message><Digest>x</Digest></VPOS>').encode()
class Fake(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def do_POST(self):
        n = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(n).decode() if n else ''
        BANK['requests'].append((urlparse(self.path).path, body))
        if '<RefundRequest>' in body:
            out = xml('<RefundResponse><Status>CAPTURED</Status><TxId>91000500</TxId></RefundResponse>')
        else:
            out = xml('<ErrorMessage><ErrorCode>1</ErrorCode><Description>Order not found</Description></ErrorMessage>')
        self.send_response(200); self.send_header('Content-Type', 'application/xml'); self.send_header('Content-Length', str(len(out))); self.end_headers(); self.wfile.write(out)

server = HTTPServer(('127.0.0.1', 0), Fake)
threading.Thread(target=server.serve_forever, daemon=True).start()
FAKE = 'http://127.0.0.1:%d' % server.server_address[1]

root = Client(); root.login()
toggle(root, 'products', True)
write('app/content/products/ring.md', """---
title: Δαχτυλίδι
status: published
date: '2026-03-01'
translation_id: ring00000000000c
custom_fields:
  price: 150
  track_stock: true
---
Ring.
""")
db("insert or replace into shop_stock (product_id, variant_key, on_hand, reserved, updated_at) values ('ring00000000000c', '', 5, 0, 0)")
root.submit('/admin/shop-settings?tab=general', has_field('order_prefix'), {'store_name': 'Aleon', 'order_prefix': 'CL', 'store_email': 'orders@example.test', 'guest_checkout': '1', 'terms_page': ''})
root.submit('/admin/shop-settings?tab=shipping', has_field('tab'), {'zones[0][name]': 'Greece', 'zones[0][countries]': 'GR', 'zones[0][methods][0][kind]': 'flat', 'zones[0][methods][0][label][el]': 'Courier', 'zones[0][methods][0][cost]': '0'})
# The fake's address: a secret no screen can set, honoured only on this machine.
db("insert or replace into system_meta (key, value, updated_at) values ('shop_secrets', ?, datetime('now'))", (json.dumps({'cardlink': {'base': FAKE}}),))

# ---- Shop settings > Payments
st, _, html = root.get('/admin/shop-settings?tab=payments')
check('the Cardlink card: the acquirer, the addresses the bank sends back to', st == 200 and 'name="cardlink[merchant_id]"' in html and 'Worldline (Eurobank)' in html and '/_shop/pay/cardlink/confirm' in html, st)
st, hdr, _ = root.submit('/admin/shop-settings?tab=payments', has_field('tab'), {'cardlink[enabled]': '1', 'cardlink[test]': '1', 'cardlink[acquirer]': 'worldline', 'cardlink[merchant_id]': '0022000230', 'cardlink[installments]': '100 = 3', 'cardlink_secret[shared_secret]': SECRET}, button=('cardlink_action', 'check'))
loc = hdr.get('Location') or ''
check('Save and check connection: Cardlink answers', 'done=ok' in loc and 'Cardlink' in loc and BANK['requests'] and BANK['requests'][-1][0] == '/vpos/xmlpayvpos' and '<StatusRequest>' in BANK['requests'][-1][1], loc)
st, _, html = root.get('/admin/shop-settings?tab=payments')
check('the secret is never shown again, only its end', SECRET not in html and 'ends in t-42' in html)
secrets = json.loads(db("select value from system_meta where key = 'shop_secrets'")[0][0])
check('kept apart from the Shop settings, the fake\'s address kept', SECRET not in db("select value from system_meta where key = 'shop_settings'")[0][0] and secrets['cardlink'] == {'base': FAKE, 'shared_secret': SECRET}, secrets)

# ---- paying with Cardlink
pub = Client()
ORIGIN = {'Origin': BASE, 'Accept': 'application/json'}
pub.request('/_shop/cart', data={'product': 'ring00000000000c', 'quantity': '1'}, headers=ORIGIN)
st, _, html = pub.get('/checkout')
check('the checkout offers a card through the bank', st == 200 and 'name="payment" value="cardlink"' in html and 'σελίδα της τράπεζας' in html, st)
method = re.search(r'name="method" value="([a-z0-9]+)" checked', html).group(1)
form = {'email': 'maria@example.test', 'billing[first_name]': 'Maria', 'billing[last_name]': 'Patera', 'billing[address]': 'Ermou 1', 'billing[city]': 'Athens', 'billing[postcode]': '10563', 'billing[country]': 'GR', 'billing[phone]': '2100000000', 'payment': 'cardlink', 'method': method}
st, hdr, _ = pub.submit('/checkout', has_field('email'), form, button=('place', '1'))
go = local(hdr.get('Location'))
check('placing the order: to the go page', st == 303 and go.startswith('/_shop/pay/cardlink/go?ref='), (st, go))
order = db("select id, number, status from shop_orders order by id desc limit 1")[0]
ref = db("select reference from shop_payments where order_id = ?", (order[0],))[0][0]
check('the order waits for its payment', order[2] == 'pending_payment')
check('a go page for a changed address: nothing there', Client().get(go[:-4] + '0000')[0] == 404)

st, _, html = pub.get(go)
check('the go page asks about installments (€150 allows 3)', st == 200 and 'name="installments" value="3"' in html and 'data-shop-autosubmit' not in html, st)
st, _, html = pub.submit(go, has_field('installments'), {'installments': '3'})
action = re.search(r'<form[^>]*action="([^"]+)"[^>]*data-shop-autosubmit', html)
fields = re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)">', html)
values = dict(fields)
check('then the form to the bank, sent on its own', st == 200 and action is not None and action.group(1) == FAKE + '/vpos/shophandlermpi', st)
check('with the installments and the order\'s amount', values.get('extInstallmentperiod') == '3' and values.get('orderAmount') == '150.00' and values.get('orderid') == ref and values.get('mid') == '0022000230', values)
import html as htmlmod
signed = ''.join(htmlmod.unescape(v) for k, v in fields if k != 'digest')
check('signed with the shared secret over the fields in their order', values.get('digest') == digest(signed), values.get('digest'))

def answer(reference, status, amount='150.00', secret_ok=True):
    data = [('version', '2'), ('mid', '0022000230'), ('orderid', reference), ('status', status), ('orderAmount', amount), ('currency', 'EUR'), ('paymentTotal', amount), ('message', 'OK'), ('riskScore', '0'), ('payMethod', 'visa'), ('txId', '91000100'), ('paymentRef', '123456')]
    data.append(('digest', digest(''.join(v for _, v in data)) if secret_ok else 'forged'))
    return data

bank = Client()  # the bank's post comes from the customer's browser, without the shop's session in it
st, hdr, _ = bank.request('/_shop/pay/cardlink/confirm', data=answer(ref, 'CAPTURED', secret_ok=False))
check('a forged answer pays nothing', db('select status from shop_orders where id = ?', (order[0],))[0][0] == 'pending_payment' and 'pay=pending' in (hdr.get('Location') or ''), hdr.get('Location'))
st, hdr, _ = bank.request('/_shop/pay/cardlink/confirm', data=answer(ref, 'CAPTURED', amount='1.00'))
check('nor a signed one of another amount', db('select status from shop_orders where id = ?', (order[0],))[0][0] == 'pending_payment')
st, hdr, _ = bank.request('/_shop/pay/cardlink/confirm', data=answer(ref, 'CAPTURED'))
back = local(hdr.get('Location'))
check('the bank\'s answer: to the order\'s page, paid', st == 303 and back.startswith('/checkout/order/' + order[1]) and 'pay=paid' in back, (st, back))
check('the order is paid and has taken its stock', db('select status, stock_held from shop_orders where id = ?', (order[0],))[0] == ('paid', 2))
check('the payment keeps the bank\'s transaction', db("select status, transaction_id from shop_payments where reference = ?", (ref,))[0] == ('paid', '91000100'))
mails = db('select recipient, subject from email_logs')
check('now the customer\'s email and the shop\'s notice', any(r == 'maria@example.test' and order[1] in s for r, s in mails) and any(r == 'orders@example.test' and order[1] in s for r, s in mails), mails[-3:])
bank.request('/_shop/pay/cardlink/confirm', data=answer(ref, 'CAPTURED'))
check('the same answer twice: one email', len([1 for (s,) in db('select subject from email_logs where recipient = ?', ('maria@example.test',)) if order[1] in s]) == 1)
st, hdr, _ = pub.get(go)
check('the go page of a paid order leads to the order', st == 303 and local(hdr.get('Location')).startswith('/checkout/order/' + order[1]), (st, hdr.get('Location')))

# ---- a cancel, then Pay now
pub.request('/_shop/cart', data={'product': 'ring00000000000c', 'quantity': '1'}, headers=ORIGIN)
st, hdr, _ = pub.submit('/checkout', has_field('email'), form, button=('place', '1'))
second = db("select id, number from shop_orders order by id desc limit 1")[0]
ref2 = db("select reference from shop_payments where order_id = ?", (second[0],))[0][0]
st, hdr, _ = bank.request('/_shop/pay/cardlink/cancel', data=answer(ref2, 'CANCELED'))
page_url = local(hdr.get('Location'))
check('cancelled at the bank: the order\'s page says so, the order waits', 'pay=failed' in page_url and db('select status from shop_orders where id = ?', (second[0],))[0][0] == 'pending_payment', page_url)
st, hdr, _ = pub.submit(page_url, lambda f: (f['attrs'].get('action') or '').endswith('/pay'), {})
again = local(hdr.get('Location'))
check('Pay now: to a new go page', st == 303 and again.startswith('/_shop/pay/cardlink/go?ref=') and ref2 not in again, again)
st, _, _ = Client().request('/_shop/pay/cardlink/confirm', data=answer('NOSUCHORDER1', 'CAPTURED'))
check('an answer for a payment the shop never made: nothing there', st == 404, st)

# ---- refunds
st, _, html = root.get('/admin/shop-orders?id=%d' % order[0])
check('the order\'s screen lists the payment, and can refund it', 'Cardlink · €150' in html and 'Refund through Cardlink' in html)
st, hdr, _ = root.submit('/admin/shop-orders?id=%d' % order[0], lambda f: ['action', 'refund'] in [list(x) for x in f['fields']], {'amount': '50'})
sent = BANK['requests'][-1][1]
check('a part refunded with a RefundRequest', 'done=ok' in (hdr.get('Location') or '') and '<OrderId>' + ref + '</OrderId><OrderAmount>50.00</OrderAmount>' in sent and db('select refunded_cents from shop_payments where reference = ?', (ref,))[0][0] == 5000, (hdr.get('Location'), sent[:200]))

# ---- turned off
root.submit('/admin/shop-settings?tab=payments', has_field('tab'), {}, drop=['cardlink[enabled]'])
pub.request('/_shop/cart', data={'product': 'ring00000000000c', 'quantity': '1'}, headers=ORIGIN)
st, _, html = pub.get('/checkout')
check('Cardlink off: not offered at the checkout', 'value="cardlink"' not in html)

server.shutdown()
print('ALL PASSED' if not fails else f'{len(fails)} FAILED')
sys.exit(1 if fails else 0)
