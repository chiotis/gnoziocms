"""
Digital products over HTTP (docs/shop-plan.md, phase 6): Shop > Files (uploading a product's file, kept out of public/); the
product page; a cart of downloads only at the checkout (no shipping step, no cash on delivery); the order paid on its screen and
the paid email with the link; the order's page and the account listing the file; the link sending the file as an attachment and
counting it to its limit; a refund turning it off; a forged link; removing the file.
"""
import json, os, re, sqlite3, sys, urllib.request, urllib.error, uuid
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

def multipart(client, path, fields, files):
    boundary = '----gnozio' + uuid.uuid4().hex
    body = b''
    for k, v in fields:
        body += ('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v)).encode()
    for k, name, data in files:
        body += ('--%s\r\nContent-Disposition: form-data; name="%s"; filename="%s"\r\nContent-Type: application/octet-stream\r\n\r\n' % (boundary, k, name)).encode() + data + b'\r\n'
    body += ('--%s--\r\n' % boundary).encode()
    req = urllib.request.Request(BASE + path, data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
    try:
        resp = client.opener.open(req); return resp.status, resp.headers, resp.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.headers, e.read().decode('utf-8', 'replace')

def raw(path):
    req = urllib.request.Request(BASE + path)
    try:
        resp = urllib.request.urlopen(req, timeout=20); return resp.status, resp.headers, resp.read()
    except urllib.error.HTTPError as e:
        return e.code, e.headers, e.read()

root = Client(); root.login()
toggle(root, 'products', True)
write('app/content/products/ebook.md', """---
title: Ο οδηγός
status: published
date: '2026-03-01'
translation_id: ebook0000000000d
custom_fields:
  price: 12
  kind: digital
  download_limit: 2
  download_days: 30
---
A book.
""")
write('app/content/products/ring.md', """---
title: Δαχτυλίδι
status: published
date: '2026-03-01'
translation_id: ring00000000000d
custom_fields:
  price: 150
---
Ring.
""")
root.submit('/admin/shop-settings?tab=general', has_field('order_prefix'), {'store_name': 'Aleon', 'order_prefix': 'DL', 'store_email': 'orders@example.test', 'guest_checkout': '1', 'terms_page': ''})
root.submit('/admin/shop-settings?tab=shipping', has_field('tab'), {'zones[0][name]': 'Greece', 'zones[0][countries]': 'GR', 'zones[0][methods][0][kind]': 'flat', 'zones[0][methods][0][label][el]': 'Courier', 'zones[0][methods][0][cost]': '3'})
root.submit('/admin/shop-settings?tab=payments', has_field('tab'), {'bank_transfer[enabled]': '1', 'bank_transfer[accounts]': 'Eurobank GR16 0260', 'cod[enabled]': '1'})

# ---- Shop > Files
st, _, html = root.get('/admin/shop-files')
check('Shop > Files lists the digital products, not the others', st == 200 and 'Ο οδηγός' in html and 'Δαχτυλίδι' not in html and 'No file yet' in html, st)
check('in the Shop group of the sidebar', 'admin/shop-files' in root.get('/admin/shop-orders')[2])
token = next(v for k, v in next(f for f in root.forms('/admin/shop-files') if ['action', 'upload'] in [list(x) for x in f['fields']])['fields'] if k == '_csrf')
BOOK = b'%PDF-1.4 the guide ' + b'x' * 2000
st, hdr, _ = multipart(root, '/admin/shop-files', [('_csrf', token), ('action', 'upload'), ('product', 'ebook0000000000d')], [('file', 'Ο οδηγός.pdf', BOOK)])
check('a file uploaded for it', 'done=ok' in (hdr.get('Location') or ''), hdr.get('Location'))
st, hdr, _ = multipart(root, '/admin/shop-files', [('_csrf', token), ('action', 'upload'), ('product', 'ring00000000000d')], [('file', 'x.pdf', b'x')])
check('not for a product that is not digital', 'done=fail' in (hdr.get('Location') or ''))
stored = db('select stored, name, size from shop_files')
check('kept under storage/shop-files with a random name', len(stored) == 1 and stored[0][1] == 'Ο οδηγός.pdf' and stored[0][2] == len(BOOK) and os.path.isfile('app/storage/shop-files/' + stored[0][0]), stored)
check('never served by the site', Client().get('/storage/shop-files/' + stored[0][0])[0] in (403, 404))
st, _, html = root.get('/admin/shop-files')
check('the screen lists it', 'Ο οδηγός.pdf' in html and 'Remove' in html)

# ---- the product and the checkout
pub = Client()
st, _, html = pub.get('/products/ebook')
check('the product page sells it as a download', st == 200 and 'action="/_shop/cart"' in html and 'name="product" value="ebook0000000000d"' in html and 'Ψηφιακό αρχείο' in html, st)
pub.request('/_shop/cart', data={'product': 'ebook0000000000d', 'quantity': '1'}, headers={'Origin': BASE, 'Accept': 'application/json'})
st, _, html = pub.get('/checkout')
check('downloads only: no shipping to choose, no other address, no gift', st == 200 and 'name="method" value="download"' in html and 'name="ship_elsewhere"' not in html and 'name="gift_note"' not in html, st)
check('and no cash on delivery', 'value="bank_transfer"' in html and 'value="cod"' not in html)
form = {'email': 'maria@example.test', 'billing[first_name]': 'Maria', 'billing[last_name]': 'Patera', 'billing[address]': 'Ermou 1', 'billing[city]': 'Athens', 'billing[postcode]': '10563', 'billing[country]': 'GR', 'billing[phone]': '2100000000', 'payment': 'bank_transfer'}
st, hdr, _ = pub.submit('/checkout', has_field('email'), form, button=('place', '1'))
order_url = local(hdr.get('Location'))
order = db("select id, number, status, shipping_cents, shipping_method from shop_orders order by id desc limit 1")[0]
check('placed: on hold for the transfer, nothing to ship', st == 303 and order[2] == 'on_hold' and order[3] == 0 and order[4] == 'download', order)
st, _, page = pub.get(order_url)
check('no download before it is paid', '_shop/download/' not in page)

# ---- paid
st, hdr, _ = root.submit('/admin/shop-orders?id=%d' % order[0], lambda f: ['action', 'status'] in [list(x) for x in f['fields']], {'to': 'paid', 'notify': '1'})
check('marked paid on its screen', 'done=ok' in (hdr.get('Location') or ''), hdr.get('Location'))
check('the customer is told it is paid (the email\'s links are checked in the unit test)', any('maria@example.test' == r for (r,) in db("select recipient from email_logs where subject like ?", ('%' + order[1] + '%',))))
st, _, page = pub.get(order_url)
check('the order\'s page lists the file with its link', 'Τα αρχεία σας' in page and 'Ο οδηγός.pdf' in page and '/_shop/download/' in page)
path = local(re.search(r'href="([^"]*/_shop/download/[^"]+)"', page).group(1))
st, _, html = root.get('/admin/shop-orders?id=%d' % order[0])
check('the order\'s screen shows its downloads', 'Downloads' in html and '0 of 2 times' in html)

st, hdr, data = raw(path)
check('the link sends the file as an attachment', st == 200 and data == BOOK and 'attachment' in (hdr.get('Content-Disposition') or '') and hdr.get('X-Content-Type-Options') == 'nosniff' and 'no-store' in (hdr.get('Cache-Control') or ''), (st, dict(hdr)))
check('with its name', "filename*=UTF-8''" + '%CE%9F%20%CE%BF%CE%B4%CE%B7%CE%B3%CF%8C%CF%82.pdf' in (hdr.get('Content-Disposition') or ''), hdr.get('Content-Disposition'))
raw(path)
st, _, data = raw(path)
check('two times, then used up', st == 410 and 'όσες φορές' in data.decode('utf-8', 'replace'), st)
check('counted', db('select downloads from shop_downloads where order_id = ?', (order[0],))[0][0] == 2)
st, hdr, _ = root.submit('/admin/shop-orders?id=%d' % order[0], lambda f: ['action', 'download_reset'] in [list(x) for x in f['fields']], {})
check('Allow again from the start', 'done=ok' in (hdr.get('Location') or '') and raw(path)[0] == 200, hdr.get('Location'))
m = re.match(r'/_shop/download/(\d+)-([a-f0-9]{32})', path)
check('a forged link: nothing there', raw('/_shop/download/%d-%s' % (int(m.group(1)) + 1, m.group(2)))[0] == 404 and raw('/_shop/download/%s-%s' % (m.group(1), '0' * 32))[0] == 404)

# ---- refunded
root.submit('/admin/shop-orders?id=%d' % order[0], lambda f: ['action', 'status'] in [list(x) for x in f['fields']], {'to': 'refunded'})
st, _, data = raw(path)
check('refunded: the link no longer works', st == 410 and 'δεν μπορεί πια' in data.decode('utf-8', 'replace'), st)

# ---- the account
pub2 = Client()
pub2.submit('/account', lambda f: (f['attrs'].get('action') or '').endswith('/signup'), {'name': 'Maria', 'email': 'maria@example.test', 'password': 'a long password'})
db("update shop_orders set customer_id = (select id from shop_customers where email = 'maria@example.test')")
st, _, html = pub2.get('/account')
check('the account lists the files of its orders', 'Τα αρχεία σας' in html and 'Ο οδηγός.pdf' in html, st)

# ---- removing the file
token = next(v for k, v in next(f for f in root.forms('/admin/shop-files') if ['action', 'remove'] in [list(x) for x in f['fields']])['fields'] if k == '_csrf')
st, hdr, _ = root.submit('/admin/shop-files', lambda f: ['action', 'remove'] in [list(x) for x in f['fields']], {})
check('a file removed: from the disk too', 'done=ok' in (hdr.get('Location') or '') and not os.path.isfile('app/storage/shop-files/' + stored[0][0]) and db('select count(*) from shop_files')[0][0] == 0, hdr.get('Location'))
check('its links not found', raw(path)[0] == 404)

print('ALL PASSED' if not fails else f'{len(fails)} FAILED')
sys.exit(1 if fails else 0)
