"""
The shop's catalogue over HTTP (docs/plans/shop-plan.md, phase 1): while Products is off there is no Shop settings entry or screen and
no shop block; switching it on brings Looks, the Collections and Product categories taxonomies, System > Shop settings (saved
and checked) and the Stock box of a product, whose quantities are kept apart from the file. A product drawn with its starting
template shows its pictures, price, options and Details sections (then the shop's, from Shop settings > Product details), a Product in its structured data, and more from its collection;
a shared field saved in one language reaches the other; an editor sees neither the screen nor the menu entry.
"""
import json, os, re, sqlite3, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

def write(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    open(path, 'w', encoding='utf-8').write(text)

def toggle(client, type_name, on):
    pick = lambda f: ['type', type_name] in [list(x) for x in f['fields']] and ['action', 'toggle'] in [list(x) for x in f['fields']]
    return client.submit('/admin/content-types', pick, {'enabled': '1' if on else '0'})

def db(query, args=()):
    c = sqlite3.connect('app/storage/db/app.sqlite')
    rows = c.execute(query, args).fetchall()
    c.close()
    return rows

root = Client(); root.login()
pub = Client()
root.submit('/admin/users-edit', has_field('username'), {'username': 'shop_ed', 'email': 'shop_ed@example.test', 'display_name': 'shop_ed', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('shop_ed', 'Sturdy-pass-99')

# ---- off
st, _, html = root.get('/admin')
check('while Products is off there is no Shop settings entry', st == 200 and 'admin/shop-settings' not in html)
st, hdr, _ = root.get('/admin/shop-settings')
check('and its address goes back to Content types', st in (302, 303) and 'content-types' in (hdr.get('Location') or ''), st)
st, _, html = root.get('/admin/edit?type=pages&slug=about&lang=el')
check('no editor offers a shop block', st == 200 and '"product-grid"' not in html, st)

# ---- on
st, hdr, _ = toggle(root, 'products', True)
check('switching Products on', st in (302, 303) and 'toggled=on' in (hdr.get('Location') or ''), (st, hdr.get('Location')))
st, _, html = root.get('/admin/content-types')
check('Looks comes on with it', re.search(r'name="type" value="looks">\s*<input type="hidden" name="enabled" value="0"', html) is not None)
check('the Collections and Product categories taxonomies are written', os.path.isfile('app/content/taxonomies/collections.yaml') and os.path.isfile('app/content/taxonomies/product-categories.yaml'))
st, _, html = root.get('/admin')
check('the Shop group has Products, Looks and Shop settings', 'admin/shop-settings' in html and 'Shop settings' in html and re.search(r'>Shop</p>.*?admin/content\?type=products.*?admin/content\?type=looks.*?admin/shop-settings', html, re.S) is not None)
st, _, html = root.get('/admin/shop-settings')
check('the screen opens on General', st == 200 and 'name="order_prefix"' in html and 'name="tax_rates[standard]"' in html, st)
st, hdr, _ = root.submit('/admin/shop-settings?tab=general', has_field('order_prefix'), {'store_name': 'ALALEON', 'order_prefix': 'al', 'reserve_minutes': '45', 'tax_rates[reduced]': '13', 'store_email': 'orders@example.test'}, drop=['guest_checkout'])
loc = hdr.get('Location') or ''
check('General is saved', st in (302, 303) and 'done=ok' in loc, (st, loc))
st, _, html = root.get(re.sub(r'^https?://[^/]+', '', loc))
check('and shown as saved: name, prefix in capitals, minutes, guest checkout off', 'value="ALALEON"' in html and 'value="AL"' in html and 'value="45"' in html and 'name="guest_checkout" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-300" >' in html, re.findall(r'name="guest_checkout"[^>]*>', html))
stored = db("select value from system_meta where key = 'shop_settings'")
check('kept apart from the site settings', stored and json.loads(stored[0][0])['order_prefix'] == 'AL')

# ---- Product details: sections every product page shows after the product's own Details
st, _, html = root.get('/admin/shop-settings?tab=details')
check('the Product details tab draws, with a field per language and a template for a new section', st == 200 and 'data-shop-details' in html and 'name="details[__D__][title][el]"' in html and 'name="details[__D__][text][en]"' in html, st)
st, hdr, _ = root.submit('/admin/shop-settings?tab=details', lambda f: any(x == ['tab', 'details'] for x in [list(y) for y in f['fields']]), {
    'details[0][title][el]': 'Αποστολή', 'details[0][text][el]': 'Δωρεάν **αποστολή** στην Ελλάδα.',
    'details[0][title][en]': 'Shipping', 'details[0][text][en]': 'Free **shipping** in Greece.',
    'details[1][title][el]': 'Care', 'details[1][text][el]': 'The shop\'s care.',
    'details[1][title][en]': '', 'details[1][text][en]': '',
}, drop=[n for n in ('details[__D__][title][el]', 'details[__D__][text][el]', 'details[__D__][title][en]', 'details[__D__][text][en]')])
loc = hdr.get('Location') or ''
check('Product details is saved', st in (302, 303) and 'done=ok' in loc and 'tab=details' in loc, (st, loc))
st, _, html = root.get('/admin/shop-settings?tab=details')
check('and shown as saved, in both languages', 'value="Αποστολή"' in html and 'value="Shipping"' in html and 'Free **shipping** in Greece.</textarea>' in html and 'value="Care"' in html, re.findall(r'name="details\[0\][^"]*"[^>]*', html)[:4])
stored = db("select value from system_meta where key = 'shop_settings'")
check('kept with the Shop settings, the General tab untouched', stored and json.loads(stored[0][0])['details'][0]['title'] == {'el': 'Αποστολή', 'en': 'Shipping'} and json.loads(stored[0][0])['order_prefix'] == 'AL', stored[0][0][:300] if stored else '')

check('an editor does not see the entry', 'admin/shop-settings' not in ed.get('/admin')[2])
st, _, html = root.get('/admin/shop-settings?tab=import')
check('the import tab draws (Shop settings > Import & export)', st == 200 and 'tab=import' in html, st)
st, hdr, body = root.get('/admin/shop-import?status=1')
check('and the import answers how far it is, as JSON', st == 200 and 'application/json' in (hdr.get('Content-Type') or '') and isinstance(json.loads(body), dict), (st, body[:200]))
check('a plain visit goes back to the tab', (lambda r: r[0] in (302, 303) and 'tab=import' in (r[1].get('Location') or ''))(root.get('/admin/shop-import')))
check('nor open the screen', ed.get('/admin/shop-settings')[0] == 403)

# ---- products and looks are the shop's: an editor (content.manage, no shop.manage) cannot list, open or save them
st, _, html = ed.get('/admin')
check('an editor has no Products or Looks in the menu', 'type=products' not in html and 'type=looks' not in html)
check('nor can list them', ed.get('/admin/content?type=products')[0] == 403 and ed.get('/admin/content?type=looks')[0] == 403)
check('nor start one', ed.get('/admin/new?type=products')[0] == 403)
st, _, _ = ed.request('/admin/save', data=[('type', 'products'), ('slug', 'sneaky'), ('lang', 'el'), ('title', 'Sneaky'), ('_csrf', re.search(r'name="_csrf" value="([^"]+)"', ed.get('/admin/content?type=pages')[2]).group(1))])
check('nor save one by address', st == 403 and not os.path.exists('app/content/products/sneaky.md'), st)
check('nor export or import them', ed.get('/admin/export?type=products')[0] == 403 and ed.get('/admin/import?type=looks')[0] == 403)
st, _, _ = ed.request('/admin/content-bulk', data=[('type', 'products'), ('lang', 'el'), ('bulk_action', 'draft'), ('slugs[]', 'x'), ('_csrf', re.search(r'name="_csrf" value="([^"]+)"', ed.get('/admin/content?type=pages')[2]).group(1))])
check('nor change them in bulk', st == 403, st)

# ---- a collection and products
root.submit('/admin/taxonomies?taxonomy=collections', has_field('taxonomy_title'), {
    'term_id[]': [''], 'term_slug[]': ['balos'], 'term_label[el][]': ['Μπάλος'], 'term_label[en][]': ['Balos'],
    'term_number[]': ['2'], 'term_tone_bg[]': ['#e3ecea'], 'term_tone_surface[]': ['#c3d6d3'], 'term_tone_deep[]': ['#3d6b6d'],
    'term_quote[el][]': ['Η λιμνοθάλασσα.'], 'term_quote[en][]': ['The lagoon.'], 'term_quote_author[]': ['Maria Patera'],
})
y = open('app/content/taxonomies/collections.yaml', encoding='utf-8').read()
check('a collection keeps its chapter, tones and quote', 'number: 2' in y and '#3d6b6d' in y and 'Maria Patera' in y and 'The lagoon.' in y, y[-400:])
st, _, html = root.get('/admin/taxonomies?taxonomy=collections')
check('the Collections screen has the collection fields', 'data-dialog-collection' in html and 'name="term_tone_deep[]"' in html)
st, _, html = root.get('/admin/taxonomies?taxonomy=tags')
check('other taxonomies do not', 'data-dialog-collection' not in html)

write('app/content/products/balos-earrings.md', """---
title: Balos Earrings
status: published
date: '2026-03-01'
translation_id: bbbb000000000001
main_image: /uploads/earrings.jpg
collections: [balos]
custom_fields:
  price: 145
  sku: BE-1
  track_stock: true
  options:
    - { name: Finish, values: "Gold\\nSilver" }
  variants:
    - { values: Gold, price: 160 }
    - { values: Silver, compare_at_price: 175 }
  details:
    - { title: Care, text: 'Keep it **dry**.' }
---

The shape of the lagoon.
""")
write('app/content/products/balos-earrings.en.md', """---
title: Balos Earrings EN
status: published
date: '2026-03-01'
translation_id: bbbb000000000001
collections: [balos]
custom_fields:
  price: 145
---

EN text.
""")
write('app/content/products/balos-cuff.md', """---
title: Balos Cuff
status: published
date: '2026-02-01'
translation_id: bbbb000000000002
main_image: /uploads/cuff.jpg
collections: [balos]
custom_fields:
  price: 210
  hover_image: /uploads/cuff-worn.jpg
---

A cuff.
""")

st, _, html = root.get('/admin/edit?type=products&slug=balos-earrings&lang=el')
check('a product has the Stock box, one row per variant', st == 200 and 'id="en-h-stock"' in html and 'name="shop_stock[gold]"' in html and 'name="shop_stock[silver]"' in html, st)
pick = lambda f: any(x[0] == 'shop_stock[gold]' for x in f['fields'])
st, hdr, _ = root.submit('/admin/edit?type=products&slug=balos-earrings&lang=el', pick, {'shop_stock[gold]': '0', 'shop_stock[silver]': '4'})
check('saving the product saves its stock', st in (302, 303) and db("select variant_key, on_hand from shop_stock where product_id = 'bbbb000000000001' order by variant_key") == [('gold', 0), ('silver', 4)], (st, db('select * from shop_stock')))
en = open('app/content/products/balos-earrings.en.md', encoding='utf-8').read()
check('the shared fields reach the other language, its own title stays', 'sku: BE-1' in en and 'Balos Earrings EN' in en and 'Gold' in en, en[:500])
check('the product file holds no stock', 'shop_stock' not in open('app/content/products/balos-earrings.md', encoding='utf-8').read())

# ---- the product page, built with blocks from its starting template
form = next(f for f in root.forms('/admin/theme?tab=single_layouts') if any(x[0] == 'active_tab' for x in f['fields']))
fields = [tuple(x) for x in form['fields'] if x[0] not in ('single_template[products]', 'active_tab')] + [('single_template[products]', 'built'), ('active_tab', 'single_layouts')]
root.request('/admin/theme', data=fields)
st, _, page = pub.get('/products/balos-earrings')
check('the product page opens', st == 200, st)
check('it shows the pictures, the collection, the name and the price', 'product-gallery' in page and 'Μπάλος' in page and 'class="product-summary-title">Balos Earrings' in page and '€145' in page, page[:300])
check('the options as choices, with the variant data the script reads', 'value="Gold"' in page and 'value="Silver"' in page and 'data-variants' in page and '"key":"gold"' in page, re.findall(r'data-variants>[^<]*', page))
check('a variant on sale: its "was" price waits, hidden, for the script to show when it is chosen', re.search(r'<s class="product-was" data-was hidden>', page) is not None and re.search(r'"key":"silver"[^}]*"was":"(?:€|\\u20ac)175"', page) is not None and re.search(r'"key":"gold"[^}]*"was":""', page) is not None, re.findall(r'data-variants>[^<]*', page))
check('the Details sections with the Accordion styles', 'Keep it <strong>dry</strong>.' in page and 'accordion-item' in page)
check('then the shop\'s Details (Shop settings > Product details), after the product\'s own', 'Δωρεάν <strong>αποστολή</strong> στην Ελλάδα.' in page and page.find('Keep it <strong>dry</strong>.') < page.find('>Αποστολή<'), re.findall(r'class="accordion-title">[^<]*', page))
check('a shop section with the title of one of the product\'s own is left out', 'The shop\'s care.' not in page and len(re.findall(r'class="accordion-title">Care<', page)) == 1, re.findall(r'class="accordion-title">[^<]*', page))
st, _, page_en = pub.get('/en/products/balos-earrings')
check('in English, the English words of the shop\'s Details, and its section with no English in Greek words', st == 200 and 'Free <strong>shipping</strong> in Greece.' in page_en and 'The shop\'s care.' in page_en and 'αποστολή</strong>' not in page_en, (st, re.findall(r'class="accordion-title">[^<]*', page_en)))
st, _, page_cuff = pub.get('/products/balos-cuff')
check('a product with no Details of its own shows the shop\'s', st == 200 and '>Αποστολή<' in page_cuff and 'The shop\'s care.' in page_cuff, re.findall(r'class="accordion-title">[^<]*', page_cuff))
check('more from the same collection, without the product itself', 'Balos Cuff' in page and page.count('class="product-card"') == 1)
m = re.search(r'<script type="application/ld\+json">(.*?)</script>', page, re.S)
graph = json.loads(m.group(1)).get('@graph', []) if m else []
products = [n for n in graph if n.get('@type') == 'Product']
check('its structured data has a Product with prices in euro', products and products[0]['offers']['@type'] == 'AggregateOffer' and products[0]['offers']['priceCurrency'] == 'EUR' and products[0]['offers']['availability'] == 'https://schema.org/InStock', products)
check('a product without a picture on hover has none over its first picture', 'class="product-gallery-hover"' not in page and 'product-gallery-frame has-hover' not in page)
st, _, page = pub.get('/products/balos-cuff')
check('a product with one has it over its first picture, shown on hover', st == 200 and re.search(r'<li class="product-gallery-frame has-hover">.*?cuff-worn\.jpg.*?</li>', page, re.S) is not None and re.search(r'\.product-gallery-frame\.has-hover:hover \.product-gallery-hover', page) is not None, re.findall(r'<li class="product-gallery[^>]*>', page))

# ---- the shop built with blocks: each product is a Product card under its entry's link
form = next(f for f in root.forms('/admin/theme?tab=archive_layouts') if any(x[0] == 'active_tab' for x in f['fields']))
root.request('/admin/theme', data=[tuple(x) for x in form['fields'] if x[0] not in ('archive_template[products]', 'active_tab')] + [('archive_template[products]', 'built'), ('active_tab', 'archive_layouts')])
st, _, page = pub.get('/products')
check('the shop opens with its cards', st == 200 and 'class="entry-card-link"' in page and 'block-product-card' in page, str(st) + page[:200])
check('a product with a picture on hover has it in its card', re.search(r'product-card-media has-hover">.*?cuff-worn\.jpg', page, re.S) is not None and 'class="product-card-hover"' in page)
check('hovering the card (its link covers it) shows that picture', re.search(r'\.entry-card:hover \.product-card-hover', page) is not None, re.findall(r'[^{}]*product-card-hover[^{}]*\{', page))

# ---- off again
toggle(root, 'products', False)
check('switching it off hides Shop settings again', 'admin/shop-settings' not in root.get('/admin')[2])
st, _, html = root.get('/admin/content-types')
check('and Looks with it', re.search(r'name="type" value="looks">\s*<input type="hidden" name="enabled" value="1"', html) is not None)

print('ALL PASSED' if not fails else f'{len(fails)} FAILED')
sys.exit(1 if fails else 0)
