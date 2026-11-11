"""
Orders and coupons through MCP (docs/mcp-shop-plan.md, step 5): the orders tools only with shop:orders; list_orders with its
filters, get_order in full with the statuses it can move to; update_order along the order's steps (never to paid or refunded),
shipped with carrier and tracking and the customer told, a cancelled order giving its stock back, a note; coupons listed, made,
changed, renamed, refused when wrong, and deleted only with shop:delete.
"""
import json, os, re, sqlite3, sys, urllib.error, urllib.request
sys.path.insert(0, '.')
from client import BASE, Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:500]))
    if not ok: fails.append(label)

def rpc(token, method, params=None):
    body = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': params or {}}).encode()
    req = urllib.request.Request(BASE + '/mcp', data=body, method='POST', headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token})
    try:
        return json.loads(urllib.request.urlopen(req, timeout=60).read().decode())
    except urllib.error.HTTPError as e:
        return {'error': 'HTTP %d' % e.code}

def call(token, name, arguments=None):
    result = rpc(token, 'tools/call', {'name': name, 'arguments': arguments or {}}).get('result', {})
    return {'isError': result.get('isError'), 'text': result.get('content', [{}])[0].get('text', ''), 'data': result.get('structuredContent', {})}

def tools(token):
    return [t['name'] for t in rpc(token, 'tools/list').get('result', {}).get('tools', [])]

def db(query, args=()):
    c = sqlite3.connect('app/storage/db/app.sqlite')
    rows = c.execute(query, args).fetchall()
    c.close()
    return rows

ORIGIN = {'Origin': BASE, 'Accept': 'application/json'}
root = Client(); root.login()
pick = lambda f: ['type', 'products'] in [list(x) for x in f['fields']] and ['action', 'toggle'] in [list(x) for x in f['fields']]
root.submit('/admin/content-types', pick, {'enabled': '1'})
os.makedirs('app/content/products', exist_ok=True)
open('app/content/products/scarf.md', 'w', encoding='utf-8').write("---\ntitle: Silk scarf\nstatus: published\ntranslation_id: scarf0000000000a\ncustom_fields:\n  price: 30\n  sku: SC-1\n  track_stock: true\n---\n\nSoft.\n")
c = sqlite3.connect('app/storage/db/app.sqlite'); c.execute("update system_meta set value='0' where key='content_index_checked_at'"); c.commit(); c.close()
c = sqlite3.connect('app/storage/db/app.sqlite'); c.execute("insert or replace into shop_stock (product_id, variant_key, on_hand, reserved, updated_at) values ('scarf0000000000a', '', 5, 0, 0)"); c.commit(); c.close()
root.submit('/admin/shop-settings?tab=general', has_field('order_prefix'), {'store_name': 'Aleon', 'order_prefix': 'al', 'store_email': 'orders@example.test', 'guest_checkout': '1'})
root.submit('/admin/shop-settings?tab=shipping', has_field('tab'), {'zones[0][name]': 'Greece', 'zones[0][countries]': 'GR', 'zones[0][methods][0][kind]': 'flat', 'zones[0][methods][0][label][el]': 'Courier', 'zones[0][methods][0][cost]': '4'})
root.submit('/admin/shop-settings?tab=payments', has_field('tab'), {'bank_transfer[enabled]': '1', 'bank_transfer[accounts]': 'Eurobank GR16 0260 0000 0000 0000 0000 001'})

def place(email, first):
    shopper = Client()
    shopper.request('/_shop/cart', data={'product': 'scarf0000000000a', 'quantity': '2'}, headers=ORIGIN)
    st, _, html = shopper.get('/checkout')
    m = re.search(r'name="method" value="([a-z0-9]+)"', html)
    form = {'email': email, 'billing[first_name]': first, 'billing[last_name]': 'Patera', 'billing[address]': 'Ermou 1', 'billing[city]': 'Athens', 'billing[postcode]': '10551', 'billing[country]': 'GR', 'billing[phone]': '2100000000', 'payment': 'bank_transfer', 'method': m.group(1) if m else '', 'terms': '1'}
    st, hdr, _ = shopper.submit('/checkout', has_field('email'), form, button=('place', '1'))
    found = re.search(r'/checkout/order/([A-Z]+-\d{4}-\d{5})', hdr.get('Location') or '')
    return found.group(1) if found else None
first = place('maria@example.test', 'Maria')
second = place('eleni@example.test', 'Eleni')
check('two orders are placed', first and second and first != second, (first, second))
check('their stock is taken (bank transfer: on hold)', db("select on_hand, reserved from shop_stock where product_id = 'scarf0000000000a'") == [(1, 0)], db("select on_hand, reserved from shop_stock"))

root.submit('/admin/mcp', has_field('mcp_action'), button=('mcp_action', 'enable'))
st, _, html = root.get('/admin/mcp')
check('the orders scope is offered, unticked', re.search(r'name="scopes\[\]" value="shop:orders" class', html) is not None)
def make_token(name, scopes):
    root.submit('/admin/mcp', has_field('days'), {'name': name, 'days': '30', 'scopes[]': scopes})
    return re.search(r'value="(gat_[A-Za-z0-9_-]+)"', root.get('/admin/mcp')[2]).group(1)
desk = make_token('Order desk', ['shop:read', 'shop:write', 'shop:orders'])
owner = make_token('Owner', ['shop:read', 'shop:write', 'shop:delete'])
check('the orders tools only with shop:orders', {'list_orders', 'get_order', 'update_order'} <= set(tools(desk)) and not any(t in tools(owner) for t in ('list_orders', 'get_order', 'update_order')), (tools(desk), tools(owner)))
check('the coupon tools with the shop scopes; deleting only with shop:delete', {'list_coupons', 'save_coupon'} <= set(tools(desk)) and 'delete_coupon' not in tools(desk) and 'delete_coupon' in tools(owner))

# ---- reading
raw = call(desk, 'list_orders')
print(raw['text'][:600]) if raw['isError'] else None
r = raw['data']
check('list_orders: both, newest first, with customer, total and status', r.get('total') == 2 and [o['order'] for o in r['orders']] == [second, first] and r['orders'][1]['customer'] == 'Maria Patera' and r['orders'][1]['total'] == '64.00' and r['orders'][1]['status'] == 'on_hold', r)
check('filters: words, status', [o['order'] for o in call(desk, 'list_orders', {'query': 'eleni'})['data']['orders']] == [second] and call(desk, 'list_orders', {'status': 'shipped'})['data']['total'] == 0)
got = call(desk, 'get_order', {'order': first})['data']
check('get_order: lines, money, address, timeline', got.get('lines', [{}])[0].get('sku') == 'SC-1' and got['lines'][0]['quantity'] == 2 and got['money']['total'] == '64.00' and any('Athens' in l for l in got.get('shipping', [])) and got.get('timeline'), got)
check('and where it can go: never to paid or refunded', 'paid' not in got.get('can_move_to', []) and 'processing' in got['can_move_to'] and 'cancelled' in got['can_move_to'], got.get('can_move_to'))
r = call(desk, 'get_order', {'order': 'AL-1999-00001'})
check('an unknown order is named', r['isError'] and 'AL-1999-00001' in r['text'], r)

# ---- moving it along
r = call(desk, 'update_order', {'order': first, 'status': 'paid'})
check('paid is refused: a person confirms money', r['isError'] and 'Paid and refunded' in r['text'], r)
r = call(desk, 'update_order', {'order': first, 'status': 'completed', 'tracking': 'X'})
check('tracking with another status is refused', r['isError'] and 'shipped' in r['text'], r)
r = call(desk, 'update_order', {'order': first})
check('nothing to change is said', r['isError'] and 'changes nothing' in r['text'], r)
r = call(desk, 'update_order', {'order': first, 'status': 'processing', 'note': 'Payment seen in the bank.'})
check('on hold to processing, with a note why', r['isError'] is False and r['data']['order']['status'] == 'processing', r)
events = call(desk, 'get_order', {'order': first})['data']['timeline']
check('the timeline says who did it and why', any('Payment seen in the bank.' in e['message'] and 'Order desk' in e['by'] for e in events), events[:3])
r = call(desk, 'update_order', {'order': first, 'carrier': 'ACS', 'tracking': '1234567890', 'notify_customer': True})
check('shipped with carrier and tracking', r['isError'] is False and r['data']['order']['status'] == 'shipped' and r['data']['order']['tracking'] == '1234567890' and r['data']['order']['carrier'] == 'ACS', r)
events = call(desk, 'get_order', {'order': first})['data']['timeline']
check('and the customer is told (or the timeline says it could not be sent)', any(e['kind'] == 'email' for e in events), events[:4])
r = call(desk, 'update_order', {'order': first, 'status': 'cancelled'})
check('a shipped order cannot be cancelled', r['isError'] and 'cannot become cancelled' in r['text'], r)
r = call(desk, 'update_order', {'order': second, 'status': 'cancelled', 'note': 'The customer asked.'})
check('an order on hold is cancelled', r['isError'] is False and r['data']['order']['status'] == 'cancelled', r)
check('and its stock is given back', db("select on_hand, reserved from shop_stock where product_id = 'scarf0000000000a'") == [(3, 0)], db("select on_hand, reserved from shop_stock"))
r = call(desk, 'update_order', {'order': second, 'note': 'Called her back.'})
check('a note alone', r['isError'] is False and any('Called her back.' in e['message'] for e in call(desk, 'get_order', {'order': second})['data']['timeline']), r)
r = call(owner, 'update_order', {'order': second, 'note': 'x'})
check('a connection without shop:orders cannot touch orders', r['isError'] and 'may not use' in r['text'], r)

# ---- coupons
r = call(desk, 'save_coupon', {'code': 'summer'})
check('a new coupon needs a kind and an amount', r['isError'] and 'kind' in r['text'] and 'amount' in r['text'], r)
r = call(desk, 'save_coupon', {'code': 'summer', 'kind': 'percent', 'amount': 15, 'starts_at': '2026-07-01', 'ends_at': '2026-06-01', 'products': ['scarf', 'nothing'], 'collections': ['nope']})
check('every problem at once: dates the wrong way round, an unknown product, an unknown collection', r['isError'] and 'before starts_at' in r['text'] and '"nothing"' in r['text'] and 'nope' in r['text'], r)
r = call(desk, 'save_coupon', {'code': 'summer', 'kind': 'percent', 'amount': 15, 'ends_at': '2026-08-31', 'products': ['scarf'], 'usage_limit': 100})
check('a coupon is made, for a product, in capitals', r['isError'] is False and r['data'].get('created') is True and r['data']['coupon']['code'] == 'SUMMER' and r['data']['coupon']['products'] == ['scarf'], r)
check('kept with the product\'s id', db("select products, kind, amount, active from shop_coupons where code = 'SUMMER'") == [('scarf0000000000a', 'percent', 15.0, 1)], db("select products, kind, amount, active from shop_coupons"))
r = call(desk, 'save_coupon', {'code': 'SUMMER', 'active': False, 'new_code': 'summer-26'})
check('changed and renamed; what was not given stays', r['isError'] is False and r['data']['coupon']['code'] == 'SUMMER-26' and r['data']['coupon']['active'] is False and r['data']['coupon']['ends_at'] == '2026-08-31' and r['data']['coupon']['usage_limit'] == 100, r)
check('list_coupons lists it', [c['code'] for c in call(desk, 'list_coupons')['data']['coupons']] == ['SUMMER-26'])
r = call(desk, 'delete_coupon', {'code': 'SUMMER-26'})
check('deleting needs shop:delete', r['isError'] and 'may not use' in r['text'], r)
r = call(owner, 'delete_coupon', {'code': 'summer-26'})
check('a coupon is deleted', r['isError'] is False and db("select count(*) from shop_coupons")[0][0] == 0, r)

# ---- sales summary
import datetime
today = datetime.date.today().isoformat()
third = place('nikos@example.test', 'Nikos')
r = call(desk, 'sales_summary')
t = r['data'].get('totals', {})
check('sales_summary: the shipped order counts; the cancelled one and the one on hold do not', r['isError'] is False and t.get('orders') == 1 and t.get('units') == 2 and t.get('items') == '60.00' and t.get('shipping') == '4.00' and t.get('total') == '64.00' and t.get('net') == '64.00' and t.get('average_order') == '64.00', r)
check('per day, today', [p['period'] for p in r['data'].get('periods', [])] == [today] and r['data']['periods'][0]['total'] == '64.00', r['data'].get('periods'))
check('the best seller, with its units and sales', r['data']['best_sellers']['rows'] == [{'product': 'scarf', 'title': 'Silk scarf', 'units': 2, 'sales': '60.00', 'orders': 1}], r['data'].get('best_sellers'))
check('no customer is named', 'Maria' not in r['text'] and 'maria@' not in r['text'])
r = call(desk, 'sales_summary', {'include_unpaid': True, 'period': 'month', 'by': 'category'})
check('with orders not paid yet, by month and by category', r['isError'] is False and r['data']['totals']['orders'] == 2 and r['data']['totals']['units'] == 4 and [p['period'] for p in r['data']['periods']] == [today[:7]] and r['data']['best_sellers']['rows'][0]['category'] == 'none', r)
r = call(desk, 'sales_summary', {'from': '2020-01-01', 'to': '2020-01-31'})
check('a time with no sales', r['isError'] is False and r['data']['totals']['orders'] == 0 and r['data']['periods'] == [] and r['data']['totals']['average_order'] is None, r)
r = call(desk, 'sales_summary', {'from': today, 'to': '2020-01-01'})
check('from after to is refused', r['isError'] and 'after' in r['text'], r)
r = call(desk, 'sales_summary', {'from': '2026-02-30'})
check('a day that does not exist is refused', r['isError'] and 'YYYY-MM-DD' in r['text'], r)
check('reading the shop is enough for it', call(owner, 'sales_summary')['isError'] is False)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
