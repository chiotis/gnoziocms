"""
The shop's cart, checkout, orders and accounts over HTTP (docs/shop-plan.md, phase 3). Shop settings' Shipping, Payments and
Accounts tabs; a product page that stays without a session, with its Add to cart form; adding to the cart only from the site's own
pages, as JSON for the script; the cart, a coupon, the checkout working out shipping from the address, and the order placed
(stock taken, coupon counted, emails written); the order's page with its key and with nothing else; the Shop group of the admin:
orders with their screen, status changes, shipping with tracking, the timeline and the CSV; customers' accounts (sign up, sign
in, a wrong password, details, the account's orders, signing out); coupons; Shop the look; and an editor kept out.
"""
import json, os, re, sqlite3, sys
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

def cookie(client, name):
    return next((c.value for c in client.jar if c.name == name), None)

def local(location):
    return re.sub(r'^https?://[^/]+', '', location or '')

ORIGIN = {'Origin': BASE, 'Accept': 'application/json'}

root = Client(); root.login()
st, hdr, _ = toggle(root, 'products', True)
check('the shop is switched on', st in (302, 303) and 'toggled=on' in (hdr.get('Location') or ''), (st, hdr.get('Location')))

write('app/content/products/ring.md', """---
title: Δαχτυλίδι Μπάλος
status: published
date: '2026-03-01'
translation_id: ring00000000000a
collections: [balos]
custom_fields:
  price: 125
  kind: physical
  track_stock: true
  gift_wrap: true
  weight_g: 30
  options:
    - { name: Finish, values: "Gold\\nSilver" }
  variants:
    - { values: Gold, price: 150, sku: R-G }
    - { values: Silver, sku: R-S }
---
Ring.
""")
write('app/content/products/ring.en.md', """---
title: Balos ring
status: published
date: '2026-03-01'
translation_id: ring00000000000a
collections: [balos]
custom_fields:
  price: 125
---
Ring.
""")
write('app/content/products/scarf.md', """---
title: Μαντήλι
status: published
date: '2026-03-02'
translation_id: scarf0000000000a
custom_fields:
  price: 40
  tax_class: reduced
---
Scarf.
""")
write('app/content/looks/evening.md', """---
title: Evening
status: published
date: '2026-03-03'
translation_id: look00000000000a
main_image: /uploads/look.jpg
custom_fields:
  pieces:
    - { product: ring00000000000a, x: 40, 'y': 30 }
    - { product: scarf0000000000a, x: 60, 'y': 70 }
---
""")
write('app/content/pages/the-look.md', """---
title: The look
status: published
blocks:
  - { type: shop-the-look, heading: 'Shop the look', look: look00000000000a }
  - { type: looks }
---
""")
db("insert or replace into shop_stock (product_id, variant_key, on_hand, reserved, updated_at) values ('ring00000000000a', 'gold', 3, 0, 0), ('ring00000000000a', 'silver', 1, 0, 0)")

# ---- Shop settings
st, hdr, _ = root.submit('/admin/shop-settings?tab=general', has_field('order_prefix'), {'store_name': 'Aleon', 'order_prefix': 'al', 'store_email': 'orders@example.test', 'guest_checkout': '1', 'terms_page': 'about'})
check('General is saved', 'done=ok' in (hdr.get('Location') or ''), hdr.get('Location'))
st, _, html = root.get('/admin/shop-settings?tab=shipping')
check('the Shipping tab opens with a template for new zones', st == 200 and 'data-zone-template' in html and 'name="zones[__Z__][name]"' in html, st)
st, hdr, _ = root.submit('/admin/shop-settings?tab=shipping', has_field('tab'), {
    'zones[0][name]': 'Islands', 'zones[0][countries]': 'GR', 'zones[0][postcodes]': '84, 85',
    'zones[0][methods][0][kind]': 'flat', 'zones[0][methods][0][label][el]': 'Courier νησιά', 'zones[0][methods][0][cost]': '8',
    'zones[1][name]': 'Greece', 'zones[1][countries]': 'gr, cy',
    'zones[1][methods][0][kind]': 'flat', 'zones[1][methods][0][label][el]': 'Courier', 'zones[1][methods][0][label][en]': 'Courier', 'zones[1][methods][0][cost]': '4.50', 'zones[1][methods][0][free_over]': '300',
    'zones[1][methods][1][kind]': 'pickup', 'zones[1][methods][1][label][el]': 'Παραλαβή', 'zones[1][methods][1][stores]': 'Athens\nGlyfada',
})
check('Shipping is saved', 'done=ok' in (hdr.get('Location') or ''), hdr.get('Location'))
st, _, html = root.get('/admin/shop-settings?tab=shipping')
check('and drawn again: two zones, the islands first, euros back from cents', 'data-z="1"' in html and 'data-z="2"' not in html and 'value="Islands"' in html and 'value="4.50"' in html and 'value="300.00"' in html and 'Athens\nGlyfada' in html, re.findall(r'data-z="[^"]+"', html))
stored = json.loads(db("select value from system_meta where key = 'shop_settings'")[0][0])
zone_greece = stored['shipping'][1]['id']
check('kept in cents, with the stores as a list', stored['shipping'][1]['methods'][0]['cost'] == 450 and stored['shipping'][1]['methods'][1]['stores'] == ['Athens', 'Glyfada'], stored['shipping'][1]['methods'])
st, hdr, _ = root.submit('/admin/shop-settings?tab=payments', has_field('tab'), {'bank_transfer[enabled]': '1', 'bank_transfer[accounts]': 'Eurobank GR16 0260 0000 0000 0000 0000 001', 'cod[enabled]': '1', 'cod[fee]': '2', 'cod[zones][]': [zone_greece]})
check('Payments is saved', 'done=ok' in (hdr.get('Location') or ''), hdr.get('Location'))
st, _, html = root.get('/admin/shop-settings?tab=payments')
check('with the accounts, the fee and the zone ticked', 'Eurobank GR16' in html and 'value="2.00"' in html and re.search(r'value="' + zone_greece + r'"[^>]*checked', html) is not None)
st, _, html = root.get('/admin/shop-settings?tab=accounts')
check('the Accounts tab: sign-up on, confirming off', re.search(r'name="signup" value="1"[^>]*checked', html) is not None and re.search(r'name="verify" value="1"[^>]*checked', html) is None)

# ---- coupons
st, hdr, _ = root.submit('/admin/shop-coupons?new=1', has_field('code'), {'code': 'spring', 'kind': 'percent', 'amount': '10', 'active': '1', 'usage_limit': '5'})
check('a coupon is made', 'done=ok' in (hdr.get('Location') or ''), hdr.get('Location'))
st, _, html = root.get('/admin/shop-coupons')
check('and listed in capitals', '>SPRING<' in html and '10%' in html)

# ---- the product page and adding to the cart
pub = Client()
st, hdr, page = pub.get('/products/ring')
check('the product page has Add to cart with the product', st == 200 and 'action="/_shop/cart"' in page and 'name="product" value="ring00000000000a"' in page and 'name="options[0]"' in page, st)
check('it starts no session, so a cache may keep it', 'Set-Cookie' not in str(hdr) and 'public' in (hdr.get('Cache-Control') or ''), (hdr.get('Set-Cookie'), hdr.get('Cache-Control')))
check('the header has the account and the cart, the count for the script', 'data-shop-cart-link' in page and 'data-shop-count' in page and '/shop.js' in page and '/shop.css' in page)
st, _, body = pub.request('/_shop/cart', data={'product': 'ring00000000000a', 'options[0]': 'Gold', 'quantity': '1'}, headers={'Accept': 'application/json'})
check('adding from another site (no Origin) is refused', st == 403, st)
st, _, body = pub.request('/_shop/cart', data={'product': 'ring00000000000a', 'options[0]': 'Gold', 'quantity': '5'}, headers=ORIGIN)
data = json.loads(body) if body.startswith('{') else {}
check('adding more than there are: what there is, said so', st == 200 and data.get('count') == 3 and 'δεν υπάρχουν τόσα' in data.get('message', '').lower(), (st, body[:200]))
check('the cart\'s cookies: its token kept from scripts, the count for them', cookie(pub, 'gz_cart') is not None and cookie(pub, 'gz_cart_n') == '3')
st, _, body = pub.request('/_shop/cart', data={'product': 'nope', 'quantity': '1'}, headers=ORIGIN)
check('a product that is not there is not added', st == 422, st)
pub.request('/_shop/cart', data={'product': 'scarf0000000000a', 'quantity': '1'}, headers=ORIGIN)
st, _, body = pub.get('/_shop/cart.json')
data = json.loads(body)
check('the cart as JSON: lines, the variant, the subtotal', [l['title'] for l in data['lines']] == ['Δαχτυλίδι Μπάλος', 'Μαντήλι'] and data['lines'][0]['variant'] == 'Gold' and data['subtotal'] == '€490', data)

# ---- the cart page
st, hdr, html = pub.get('/cart')
check('the cart page: its own session, never kept', st == 200 and cookie(pub, 'gz_shop') is not None and 'no-store' in (hdr.get('Cache-Control') or '') and 'noindex' in (hdr.get('X-Robots-Tag') or ''), (hdr.get('Cache-Control'), [c.name for c in pub.jar]))
check('its lines with their totals and the sum', '€450' in html and '€40' in html and '€490' in html and 'name="qty[' in html)
key = re.search(r'name="qty\[([a-f0-9]{16})\]" value="3"', html).group(1)
st, hdr, _ = pub.submit('/cart', lambda f: any(x[0].startswith('qty[') for x in f['fields']), {'qty[' + key + ']': '2'}, button=('update', '1'))
st, _, html = pub.get('/cart')
check('a quantity is changed', re.search(r'name="qty\[' + key + r'\]" value="2"', html) is not None and '€340' in html)
st, hdr, _ = pub.submit('/cart', lambda f: any(x[0] == 'coupon' for x in f['fields']), {'coupon': 'nope'})
st, _, html = pub.get('/cart')
check('an unknown coupon is refused', 'Δεν υπάρχει τέτοιο κουπόνι' in html)
pub.submit('/cart', lambda f: any(x[0] == 'coupon' for x in f['fields']), {'coupon': 'spring'})
st, _, html = pub.get('/cart')
check('a coupon is applied: 10% off', 'SPRING' in html and '−€34' in html and '€306' in html, re.findall(r'€[\d.,]+', html)[:8])
st, _, en = pub.get('/en/cart')
check('the cart in English', st == 200 and '<h1>Cart</h1>' in en and 'Balos ring' in en)

# ---- the checkout
st, _, html = pub.get('/checkout')
check('the checkout opens with Greece chosen and its shipping, free over €300', st == 200 and 'name="email"' in html and re.search(r'<option value="GR" selected', html) is not None and 'Courier' in html and 'Δωρεάν' in html, st)
check('both ways to pay, the fee of cash on delivery', 'Τραπεζική κατάθεση' in html and 'Αντικαταβολή' in html and '+ €2' in html)
check('the terms, since the shop has them', 'name="terms"' in html and 'href="/about"' in html)
method = re.search(r'name="method" value="([a-z0-9]+)" checked', html).group(1)
form = {'email': 'maria@example.test', 'billing[first_name]': 'Maria', 'billing[last_name]': 'Patera', 'billing[address]': 'Ermou 1', 'billing[city]': 'Syros', 'billing[postcode]': '84100', 'billing[country]': 'GR', 'billing[phone]': '2281000000', 'payment': 'bank_transfer', 'method': method}
st, _, html = pub.submit('/checkout', has_field('email'), form, button=('refresh', '1'))
check('an island\'s postcode: the islands\' courier', st == 200 and 'Courier νησιά' in html and '€8' in html, st)
island = re.search(r'name="method" value="([a-z0-9]+)"', html).group(1)
st, _, html = pub.submit('/checkout', has_field('email'), dict(form, method=island), button=('place', '1'))
check('without the terms the order is not placed', st == 200 and 'Αποδεχτείτε τους όρους' in html, st)
st, hdr, _ = pub.submit('/checkout', has_field('email'), dict(form, method=island, terms='1', gift_note='Χρόνια πολλά'), button=('place', '1'))
loc = local(hdr.get('Location'))
check('the order is placed and its page is next', st == 303 and re.match(r'^/checkout/order/AL-\d{4}-00001\?key=[a-f0-9]{32}$', loc) is not None, (st, loc))
st, _, page = pub.get(loc)
check('its page: thank you, the bank account and the reference', st == 200 and 'Λάβαμε την παραγγελία σας' in page and 'Eurobank GR16' in page and 'AL-' in page and 'Αναμένεται η κατάθεση' in page, st)
check('the totals: €306 with €8 shipping', '€314' in page and '€8' in page)
check('the cart is emptied', cookie(pub, 'gz_cart_n') in (None, '', '0') and 'Το καλάθι σας είναι άδειο' in pub.get('/cart')[2])
order = db("select id, number, status, total_cents, discount_cents, shipping_cents, tax_cents, coupon, stock_held, gift_note from shop_orders order by id desc limit 1")[0]
check('the order in the database', order[1].startswith('AL-') and order[2:9] == ('on_hold', 31400, 3400, 800, order[6], 'SPRING', 2) and order[9] == 'Χρόνια πολλά', order)
check('the stock is taken', db("select on_hand, reserved from shop_stock where product_id = 'ring00000000000a' and variant_key = 'gold'")[0] == (1, 0))
check('the coupon\'s use is counted', db("select used from shop_coupons where code = 'SPRING'")[0][0] == 1)
mails = db("select recipient, subject from email_logs order by id")
check('the customer\'s email and the shop\'s notice are written (sent or logged)', any(r == 'maria@example.test' and order[1] in s for r, s in mails) and any(r == 'orders@example.test' and 'New order' in s for r, s in mails), mails[-3:])
other = Client()
st, _, _ = other.get('/checkout/order/' + order[1])
check('without its key nobody else sees the order', st == 404, st)
st, _, _ = other.get('/checkout/order/' + order[1] + '?key=' + '0' * 32)
check('nor with a wrong key', st == 404, st)

# ---- the admin's orders
st, _, html = root.get('/admin')
check('the Shop group: Orders, Customers, Coupons', 'admin/shop-orders' in html and 'admin/shop-customers' in html and 'admin/shop-coupons' in html)
st, _, html = root.get('/admin/shop-orders')
check('the order is listed, On hold', order[1] in html and 'On hold' in html)
st, _, html = root.get('/admin/shop-orders?status=on_hold&q=Patera')
check('found by status and name', order[1] in html)
st, _, html = root.get('/admin/shop-orders?id=%d' % order[0])
check('its screen: items, VAT, addresses, timeline, the next statuses', st == 200 and 'R-G' in html and 'VAT 24%' in html and 'VAT 13%' in html and '84100 Syros' in html and 'Order placed as a guest' in html and '<option value="paid">' in html, st)
st, hdr, _ = root.submit('/admin/shop-orders?id=%d' % order[0], lambda f: ['action', 'status'] in [list(x) for x in f['fields']], {'to': 'paid', 'notify': '1'})
check('marked paid', 'done=ok' in (hdr.get('Location') or ''), hdr.get('Location'))
st, hdr, _ = root.submit('/admin/shop-orders?id=%d' % order[0], lambda f: ['action', 'ship'] in [list(x) for x in f['fields']], {'carrier': 'ACS', 'tracking': '7012345678', 'notify': '1'})
check('shipped with its tracking', 'done=ok' in (hdr.get('Location') or ''), hdr.get('Location'))
st, _, html = root.get('/admin/shop-orders?id=%d' % order[0])
check('the timeline has it all', 'On hold → Paid' in html and 'Paid → Shipped' in html and '7012345678' in html, re.findall(r'<span class="grow text-slate-700">([^<]+)', html))
check('a shipped order cannot go back to waiting', '<option value="on_hold">' not in html and '<option value="completed">' in html)
st, _, page = pub.get(loc)
check('the customer\'s page shows it shipped, with the tracking', 'Στάλθηκε' in page and '7012345678' in page)
st, hdr, csv = root.get('/admin/shop-orders?export=csv')
check('the CSV for the accountant: a row per line with VAT', 'text/csv' in (hdr.get('Content-Type') or '') and order[1] in csv and 'VAT rate' in csv and '306.00' not in csv.split('\n')[0] and csv.count(order[1]) == 2, csv[:300])
st, _, slip = root.get('/admin/shop-orders?id=%d&print=1' % order[0])
check('the packing slip', st == 200 and 'Packing slip' in slip and 'R-G' in slip and 'Χρόνια πολλά' in slip)

# ---- accounts
shopper = Client()
st, _, html = shopper.get('/account')
check('the account page: sign in and create an account', st == 200 and 'action="/account/signin"' in html and 'action="/account/signup"' in html)
st, hdr, _ = shopper.submit('/account', lambda f: (f['attrs'].get('action') or '').endswith('/signup'), {'name': 'Eleni', 'email': 'eleni@example.test', 'password': 'short'})
st, _, html = shopper.get(local(hdr.get('Location')))
check('a short password is refused', 'τουλάχιστον 8' in html.lower())
st, hdr, _ = shopper.submit('/account', lambda f: (f['attrs'].get('action') or '').endswith('/signup'), {'name': 'Eleni', 'email': 'eleni@example.test', 'password': 'a long password'})
st, _, html = shopper.get(local(hdr.get('Location')))
check('signed up and in: the account with no orders yet', 'Τα στοιχεία σας' in html and 'Δεν υπάρχουν παραγγελίες' in html and 'eleni@example.test' in html)
check('a link to confirm the address is sent', any(r == 'eleni@example.test' for r, _ in db("select recipient, subject from email_logs")))
shopper.request('/_shop/cart', data={'product': 'scarf0000000000a', 'quantity': '1'}, headers=ORIGIN)
st, _, html = shopper.get('/checkout')
check('the checkout knows who is signed in', 'eleni@example.test' in html and 'name="email"' not in html)
method = re.search(r'name="method" value="([a-z0-9]+)" checked', html).group(1)
st, hdr, _ = shopper.submit('/checkout', lambda f: (f['attrs'].get('action') or '').endswith('/checkout'), {'billing[first_name]': 'Eleni', 'billing[last_name]': 'K', 'billing[address]': 'Athinas 2', 'billing[city]': 'Athens', 'billing[postcode]': '10551', 'billing[country]': 'GR', 'billing[phone]': '210', 'payment': 'cod', 'method': method, 'terms': '1'}, button=('place', '1'))
check('an order with cash on delivery', st == 303 and '/checkout/order/' in (hdr.get('Location') or ''), (st, hdr.get('Location')))
second = db("select number, status, fee_cents, customer_id from shop_orders order by id desc limit 1")[0]
check('processing, with the fee, and the customer\'s', second[1] == 'processing' and second[2] == 200 and second[3] is not None, second)
st, _, html = shopper.get('/account')
check('the account lists it, and keeps the address for next time', second[0] in html and 'value="Athinas 2"' in html)
st, _, html = shopper.get('/account/orders/' + second[0])
check('and opens it', st == 200 and 'Αντικαταβολή' in html)
st, _, _ = shopper.get('/account/orders/' + order[1])
check('but not another customer\'s order', st == 404, st)
shopper.submit('/account', lambda f: (f['attrs'].get('action') or '').endswith('/signout'), {})
st, _, html = shopper.get('/account')
check('signed out', 'action="/account/signin"' in html)
st, hdr, _ = shopper.submit('/account', lambda f: (f['attrs'].get('action') or '').endswith('/signin'), {'email': 'eleni@example.test', 'password': 'wrong one'})
st, _, html = shopper.get(local(hdr.get('Location')))
check('a wrong password signs in nobody', 'δεν είναι σωστά' in html)
st, hdr, _ = shopper.submit('/account', lambda f: (f['attrs'].get('action') or '').endswith('/signin'), {'email': 'ELENI@example.test', 'password': 'a long password'})
check('the right one, in any case of the address', local(hdr.get('Location')) == '/account', hdr.get('Location'))
st, _, html = root.get('/admin/shop-customers')
check('the admin\'s Customers list her with one order', 'eleni@example.test' in html)
cid = db("select id from shop_customers where email = 'eleni@example.test'")[0][0]
st, _, html = root.get('/admin/shop-customers?id=%d' % cid)
check('and her screen lists it', second[0] in html and 'Not confirmed' in html)

# ---- Shop the look
st, _, html = pub.get('/the-look')
check('Shop the look: the look\'s pieces, the total, Add the look', st == 200 and 'look-pieces' in html and 'name="items[0][product]" value="ring00000000000a"' in html and 'name="items[0][variant]" value="gold"' in html and '€190' in html, re.findall(r'look-total[^<]*<span>[^<]*</span><strong>[^<]*', html))
check('and the Looks block: 2 pieces', 'looks-card' in html and '2 κομμάτια' in html)
st, _, body = pub.request('/_shop/cart', data={'items[0][product]': 'ring00000000000a', 'items[0][variant]': 'gold', 'items[1][product]': 'scarf0000000000a', 'items[1][variant]': ''}, headers=ORIGIN)
check('Add the look puts both in the cart', st == 200 and json.loads(body)['count'] == 2, body[:200])

# ---- an editor
root.submit('/admin/users-edit', has_field('username'), {'username': 'shop_ed2', 'email': 'shop_ed2@example.test', 'display_name': 'shop_ed2', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('shop_ed2', 'Sturdy-pass-99')
st, _, html = ed.get('/admin')
check('an editor has no Shop group', 'admin/shop-orders' not in html)
st, _, _ = ed.get('/admin/shop-orders')
check('nor the orders\' screen', st in (302, 303, 403), st)

# ---- off
toggle(root, 'products', False)
st, _, _ = pub.get('/cart')
check('with the shop off, /cart is an ordinary address again', st == 404, st)

print('ALL PASSED' if not fails else f'{len(fails)} FAILED')
sys.exit(1 if fails else 0)
