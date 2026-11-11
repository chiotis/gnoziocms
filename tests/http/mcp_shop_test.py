"""
The shop through MCP (docs/mcp-shop-plan.md, step 3): the shop scopes are off by default; the shop's tools are listed only while
the shop is on and only for a connection given them; shop_info, list_products with its filters and get_product; set_stock (a
product with variants needs one, on_hand or adjust, a check that saves nothing); update_prices with a check first, sales started
and ended, a variant's own price shared by the translations, every problem at once; the price limit, also for update_entry; and
the limit of shop changes in 24 hours.
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

def read(path):
    return open(path, encoding='utf-8').read() if os.path.exists(path) else ''

def db(query, args=()):
    c = sqlite3.connect('app/storage/db/app.sqlite')
    rows = c.execute(query, args).fetchall()
    c.close()
    return rows

def look_again():
    # Files written outside the admin are seen within half a minute; here the clock is moved instead of waiting.
    c = sqlite3.connect('app/storage/db/app.sqlite'); c.execute("update system_meta set value='0' where key='content_index_checked_at'"); c.commit(); c.close()

root = Client(); root.login()
root.submit('/admin/mcp', has_field('mcp_action'), button=('mcp_action', 'enable'))

# ---- the shop scopes are ticked on purpose
st, _, html = root.get('/admin/mcp')
check('the shop scopes are offered, unticked', re.search(r'name="scopes\[\]" value="shop:write" class', html) is not None and re.search(r'name="scopes\[\]" value="content:read" checked', html) is not None, re.findall(r'name="scopes\[\]"[^>]*>', html))

def make_token(name, scopes):
    root.submit('/admin/mcp', has_field('days'), {'name': name, 'days': '30', 'scopes[]': scopes})
    return re.search(r'value="(gat_[A-Za-z0-9_-]+)"', root.get('/admin/mcp')[2]).group(1)
shop = make_token('Shop job', ['content:read', 'content:write', 'content:publish', 'shop:read', 'shop:write'])
news = make_token('News job', ['content:read', 'content:write'])
reader = make_token('Shop reader', ['shop:read'])

check('while the shop is off its tools are not listed', 'shop_info' not in tools(shop) and 'site_info' in tools(shop), tools(shop))
check('and site_info says it is off', call(shop, 'site_info')['data'].get('connection', {}).get('shop') == 'off')

# ---- the shop on, with three products
pick = lambda f: ['type', 'products'] in [list(x) for x in f['fields']] and ['action', 'toggle'] in [list(x) for x in f['fields']]
root.submit('/admin/content-types', pick, {'enabled': '1'})
def product(slug, front, lang=''):
    os.makedirs('app/content/products', exist_ok=True)
    open('app/content/products/%s%s.md' % (slug, '.' + lang if lang else ''), 'w', encoding='utf-8').write('---\n' + front + '\n---\n\nText.\n')
earrings = "title: Balos Earrings\nstatus: published\ndate: '2026-03-01'\ntranslation_id: bbbb000000000001\ncustom_fields:\n  price: 145\n  sku: BE-1\n  track_stock: true\n  options:\n    - { name: Finish, values: \"Gold\\nSilver\" }\n  variants:\n    - { values: Gold, price: 160 }\n    - { values: Silver }"
product('balos-earrings', earrings)
product('balos-earrings', earrings.replace('Balos Earrings', 'Balos Earrings (en)'), 'en')
product('balos-cuff', "title: Balos Cuff\nstatus: published\ndate: '2026-02-01'\ntranslation_id: bbbb000000000002\ncustom_fields:\n  price: 210\n  compare_at_price: 240\n  sku: BC-1")
product('ring', "title: Ring\nstatus: draft\ndate: '2026-01-01'\ntranslation_id: bbbb000000000003\ncustom_fields:\n  price: 100\n  sku: RG-1")
look_again()

check('with the shop on, a connection with the shop scopes has its tools', {'shop_info', 'list_products', 'get_product', 'update_prices', 'set_stock'} <= set(tools(shop)), tools(shop))
check('one without them does not', not any(t in tools(news) for t in ('shop_info', 'list_products', 'update_prices')), tools(news))
check('reading the shop does not give its writing tools', 'list_products' in tools(reader) and 'update_prices' not in tools(reader) and 'set_stock' not in tools(reader), tools(reader))
check('site_info points to shop_info', 'shop_info' in call(shop, 'site_info')['data'].get('connection', {}).get('shop', ''))
r = call(news, 'list_products')
check('a tool it does not have is refused', r['isError'] and 'may not use' in r['text'], r)

# ---- reading
info = call(shop, 'shop_info')['data']
check('shop_info: currency, the product fields, the taxonomies and the limits', info.get('currency') == 'EUR' and 'variants' in info.get('fields', {}) and info['fields']['variants'].get('writable') and {t['name'] for t in info.get('taxonomies', [])} >= {'collections', 'product-categories'} and info.get('limits', {}).get('price_change_percent') == 50, {k: info.get(k) for k in ('currency', 'limits', 'taxonomies')})
r = call(shop, 'list_products')['data']
by = {p['product']: p for p in r.get('products', [])}
check('list_products lists them all, drafts too', r.get('total') == 3 and set(by) == {'balos-earrings', 'balos-cuff', 'ring'}, r)
check('with the price, the "was" price and the sale', by['balos-cuff']['price'] == 210 and by['balos-cuff']['compare_at_price'] == 240 and by['balos-cuff']['on_sale'] is True and by['ring']['on_sale'] is False, by.get('balos-cuff'))
check('and each variant with its price, its own or the product\'s, and its stock', [(v['variant'], v['price'], v['own_price'], v['stock']['free']) for v in by['balos-earrings']['variants']] == [('Gold', 160, True, 0), ('Silver', 145, False, 0)], by['balos-earrings']['variants'])
check('filters: on sale', [p['product'] for p in call(shop, 'list_products', {'on_sale': True})['data']['products']] == ['balos-cuff'])
check('out of stock (a tracked product with nothing left)', [p['product'] for p in call(shop, 'list_products', {'stock': 'out'})['data']['products']] == ['balos-earrings'])
check('a draft, by status', [p['product'] for p in call(shop, 'list_products', {'status': 'draft'})['data']['products']] == ['ring'])
check('words in the title or SKU', [p['product'] for p in call(shop, 'list_products', {'query': 'bc-1'})['data']['products']] == ['balos-cuff'])
got = call(shop, 'get_product', {'product': 'balos-earrings', 'lang': 'en'})['data']
check('get_product: the product and its entry in the language asked', got.get('product', {}).get('sku') == 'BE-1' and got.get('entry', {}).get('title') == 'Balos Earrings (en)' and 'variants' in got['entry'].get('fields', {}), got)
r = call(shop, 'get_product', {'product': 'nothing'})
check('an unknown product is named', r['isError'] and '"nothing"' in r['text'], r)

# ---- stock
r = call(shop, 'set_stock', {'changes': [{'product': 'balos-earrings', 'on_hand': 3}, {'product': 'balos-cuff', 'variant': 'Gold', 'on_hand': 1}, {'product': 'ring', 'on_hand': 2, 'adjust': 1}]})
check('stock: every problem at once: a variant to name, one that has none, on_hand with adjust', r['isError'] and 'Gold, Silver' in r['text'] and 'no variants' in r['text'] and 'one of them' in r['text'], r)
r = call(shop, 'set_stock', {'changes': [{'product': 'balos-earrings', 'variant': 'gold', 'on_hand': 5}], 'dry_run': True})
check('a check saves nothing', r['isError'] is False and r['data']['changes'][0]['on_hand'] == {'from': 0, 'to': 5} and db("select count(*) from shop_stock")[0][0] == 0, r)
r = call(shop, 'set_stock', {'changes': [{'product': 'balos-earrings', 'variant': 'Gold', 'on_hand': 5}, {'product': 'balos-earrings', 'variant': 'Silver', 'adjust': 3}, {'product': 'balos-earrings', 'variant': 'Silver', 'adjust': -1}, {'product': 'balos-cuff', 'on_hand': 4}]})
rows = dict(((p, k), n) for p, k, n in db("select product_id, variant_key, on_hand from shop_stock"))
check('stock is set and adjusted; two changes to one variant add up', r['isError'] is False and rows == {('bbbb000000000001', 'gold'): 5, ('bbbb000000000001', 'silver'): 2, ('bbbb000000000002', ''): 4}, (r, rows))
check('a product that does not track stock is warned about', any('balos-cuff' in w and 'track_stock' in w for w in r['data'].get('warnings', [])), r['data'])
check('and the list shows it', [v['stock']['free'] for v in call(shop, 'list_products', {'query': 'earrings'})['data']['products'][0]['variants']] == [5, 2] and call(shop, 'list_products', {'stock': 'out'})['data']['total'] == 0)
r = call(reader, 'set_stock', {'changes': [{'product': 'balos-cuff', 'on_hand': 1}]})
check('a connection that only reads the shop cannot set stock', r['isError'], r)

# ---- prices: a check first
before = {n: read('app/content/products/' + n) for n in os.listdir('app/content/products')}
changes = [{'product': 'ring', 'sale_price': 80}, {'product': 'balos-cuff', 'end_sale': True}, {'product': 'balos-earrings', 'variant': 'Gold', 'price': '170'}, {'product': 'balos-earrings', 'variant': 'Silver', 'sale_price': 120}]
r = call(shop, 'update_prices', {'changes': changes})
table = {(c['product'], c['variant']): c for c in r['data'].get('changes', [])}
check('update_prices checks first and saves nothing', r['isError'] is False and r['data'].get('dry_run') is True and r['data'].get('products_changed') == 3 and before == {n: read('app/content/products/' + n) for n in os.listdir('app/content/products')}, r)
check('the table: a sale started, a sale ended, a variant\'s new price, a variant on sale from the product\'s price',
      table[('ring', None)]['price'] == {'from': 100, 'to': 80} and table[('ring', None)]['compare_at_price']['to'] == 100 and table[('ring', None)]['on_sale']
      and table[('balos-cuff', None)]['price'] == {'from': 210, 'to': 240} and table[('balos-cuff', None)]['on_sale'] is False
      and table[('balos-earrings', 'Gold')]['price'] == {'from': 160, 'to': 170}
      and table[('balos-earrings', 'Silver')]['price'] == {'from': 145, 'to': 120} and table[('balos-earrings', 'Silver')]['compare_at_price']['to'] == 145, table)
bad = [{'product': 'ring', 'price': 'cheap'}, {'product': 'balos-cuff', 'sale_price': 300}, {'product': 'ring', 'end_sale': True}, {'product': 'balos-earrings', 'variant': 'Rose', 'price': 1}, {'product': 'ring', 'price': 10, 'sale_price': 5}, {'product': 'ring'}, {'product': 'nothing', 'price': 1}]
r = call(shop, 'update_prices', {'changes': bad})
t = r['text']
check('every problem at once: not a price, a sale price not lower, not on sale, an unknown variant, mixed, nothing to change, an unknown product',
      r['isError'] and 'cheap' in t and 'must be lower' in t and 'not on sale' in t and '"Rose"' in t and 'go alone' in t and 'changes nothing' in t and '"nothing"' in t, t)

# ---- the price limit
r = call(shop, 'update_prices', {'changes': [{'product': 'ring', 'price': 10}]})
check('a price that moves more than 50% is refused, with the numbers', r['isError'] and '100.00 to 10.00' in r['text'] and '90%' in r['text'] and '50%' in r['text'], r)
r = call(shop, 'update_entry', {'type': 'products', 'slug': 'ring', 'fields': {'price': 1000}})
check('also through update_entry', r['isError'] and '900%' in r['text'], r)
r = call(shop, 'update_entry', {'type': 'products', 'slug': 'balos-earrings', 'fields': {'variants': [{'values': 'Gold', 'price': 1}, {'values': 'Silver'}]}})
check('and for a variant\'s price', r['isError'] and 'Gold' in r['text'] and '99%' in r['text'], r)

# ---- saved
r = call(shop, 'update_prices', {'changes': changes, 'dry_run': False})
check('with dry_run false the changes are saved', r['isError'] is False and r['data'].get('saved') is True, r)
ring = read('app/content/products/ring.md'); cuff = read('app/content/products/balos-cuff.md')
check('a sale: the price and the "was" price', re.search(r'\bprice: 80\b', ring) and re.search(r'compare_at_price: 100\b', ring), ring)
check('a sale ended: the old price back, no "was" price', re.search(r'\bprice: 240\b', cuff) and not re.search(r'compare_at_price: \d', cuff), cuff)
check('the ring is still a draft, the cuff still published', 'status: draft' in ring and 'status: published' in cuff)
for name in ('balos-earrings.md', 'balos-earrings.en.md'):
    text = read('app/content/products/' + name)
    def row(v):
        m = re.search(r'-\s+values: ' + v + r'\n((?:\s{4,}\w+: .*\n)+)', text)
        return m.group(1) if m else ''
    check('the variants changed in every language: ' + name, re.search(r'\bprice: 170\b', row('Gold')) and re.search(r'\bprice: 120\b', row('Silver')) and re.search(r'compare_at_price: 145\b', row('Silver')), text)
check('the change is in the history', db("select count(*) from content_revisions where type = 'products' and slug = 'ring' and actor like '%Shop job%'")[0][0] >= 1)
r = call(shop, 'list_products', {'on_sale': True})['data']
check('and in the list: the ring and the earrings on sale, the cuff no longer', sorted(p['product'] for p in r['products']) == ['balos-earrings', 'ring'], r)

# ---- the limit of shop changes
st, _, html = root.get('/admin/mcp')
conn = [c for c in re.findall(r'data-mcp-connection="(\d+)"', html) if re.search(r'data-mcp-connection="' + c + r'".{0,600}Shop job', html, re.S)][0]
check('the rules of a shop connection have its limits', 'name="price_limit"' in html and 'name="shop_limit"' in html)
used = 3 + 4  # three products' prices, four rows of stock
root.submit('/admin/mcp', lambda f: ('connection', conn) in [tuple(x) for x in f['fields']] and ('mcp_action', 'policy') in [tuple(x) for x in f['fields']], {'daily_limit': '10', 'price_limit': '0', 'shop_limit': str(used + 1)})
info = call(shop, 'shop_info')['data']
check('shop_info counts what is left', info['limits'] == {'price_change_percent': 'no limit', 'shop_changes_per_24h': used + 1, 'shop_changes_left': 1}, info.get('limits'))
r = call(shop, 'set_stock', {'changes': [{'product': 'balos-cuff', 'on_hand': 1}, {'product': 'ring', 'on_hand': 1}]})
check('more changes than are left are refused, all of them', r['isError'] and '1 of its %d' % (used + 1) in r['text'] and rows == dict(((p, k), n) for p, k, n in db("select product_id, variant_key, on_hand from shop_stock")), r)
r = call(shop, 'update_prices', {'changes': [{'product': 'ring', 'price': 5}], 'dry_run': False})
check('with no price limit, any price goes; it was the last change left', r['isError'] is False and re.search(r'\bprice: 5\b', read('app/content/products/ring.md')), r)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
