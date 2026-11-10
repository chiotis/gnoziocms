"""
Shop settings › Marketing over HTTP: the product feed at /feeds/products.xml (only once it is switched on, ?lang= for another
language, the physical products with their variants), the Marketing tab with its access token kept as a secret, the Meta Pixel
on the pages with the consent banner's texts and the product's ViewContent, AddToCart in the cart's answer, and the Shop manager
role: the shop, its settings and Analytics, but no site settings or users.
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

root = Client(); root.login()
pub = Client()
pick = lambda f: ['type', 'products'] in [list(x) for x in f['fields']] and ['action', 'toggle'] in [list(x) for x in f['fields']]
root.submit('/admin/content-types', pick, {'enabled': '1'})

write('app/content/products/ring.md', """---
title: Δαχτυλίδι
status: published
date: '2026-03-01'
translation_id: ring00000000000m
main_image: /uploads/ring.jpg
custom_fields:
  price: 120
  compare_at_price: 150
  options:
    - { name: Χρώμα, values: "Gold\\nSilver" }
  variants:
    - { values: Gold, price: 130 }
    - { values: Silver }
---

A ring of the lagoon.
""")
write('app/content/products/ring.en.md', """---
title: Ring
status: published
date: '2026-03-01'
translation_id: ring00000000000m
custom_fields:
  price: 120
---

A ring.
""")
write('app/content/products/guide.md', """---
title: Guide
status: published
translation_id: guide0000000000m
custom_fields:
  price: 9
  kind: digital
---
""")

# ---- the feed
check('no feed until it is switched on', pub.get('/feeds/products.xml')[0] == 404)
st, _, html = root.get('/admin/shop-settings?tab=marketing')
check('Shop settings has a Marketing tab', st == 200 and 'name="marketing[pixel_id]"' in html and 'name="meta_secret[capi_token]"' in html, st)
st, hdr, _ = root.submit('/admin/shop-settings?tab=marketing', has_field('marketing[pixel_id]'), {
    'marketing[feed]': '1', 'marketing[out_of_stock]': '1', 'marketing[category]': '188', 'marketing[pixel_id]': '1234567890123',
    'meta_secret[capi_token]': 'EAAsecret-token-9876', 'marketing[privacy_page]': 'about',
})
check('saved', st in (302, 303) and 'done=ok' in (hdr.get('Location') or ''), hdr.get('Location'))
st, _, html = root.get('/admin/shop-settings?tab=marketing')
check('the token is never shown, only its end', 'EAAsecret-token-9876' not in html and 'ends in 9876' in html)
check('the feed addresses are listed', '/feeds/products.xml' in html and 'products.xml?lang=en' in html)
stored = sqlite3.connect('app/storage/db/app.sqlite').execute("select value from system_meta where key = 'shop_settings'").fetchone()[0]
check('the token is not in the shop settings', 'EAAsecret' not in stored)

st, hdr, xml = pub.get('/feeds/products.xml')
check('the feed is XML', st == 200 and 'xml' in (hdr.get('Content-Type') or ''), (st, hdr.get('Content-Type')))
ids = re.findall(r'<g:id>([^<]+)</g:id>', xml)
check('each variant of the physical product, none of the digital one', len(ids) == 2 and all(i.startswith('ring00000000000m-') for i in ids) and 'guide' not in xml, ids)
check('grouped, with the "was" price and the sale price, the colour and the category', '<g:item_group_id>ring00000000000m</g:item_group_id>' in xml and '<g:price>150.00 EUR</g:price>' in xml and '<g:sale_price>130.00 EUR</g:sale_price>' in xml and '<g:color>Gold</g:color>' in xml and '<g:google_product_category>188</g:google_product_category>' in xml, xml[:800])
check('absolute links and pictures', BASE + '/products/ring' in xml and BASE + '/uploads/ring.jpg' in xml)
st, _, xml_en = pub.get('/feeds/products.xml?lang=en')
check('in English: the translation', st == 200 and '<g:title>Ring</g:title>' in xml_en, xml_en[:600])

# ---- the Pixel on the pages
st, _, page = pub.get('/products/ring')
check('the Pixel script with the banner texts and the privacy page', 'shop-pixel.js' in page and 'data-pixel="1234567890123"' in page and 'data-consent="banner"' in page and 'data-more-url="/about"' in page, st)
track = re.search(r'<script type="application/json" data-shop-track>(.*?)</script>', page, re.S)
data = json.loads(track.group(1)) if track else {}
check('the product page gives ViewContent of the product group', data.get('event') == 'ViewContent' and data['data']['content_ids'] == ['ring00000000000m'] and data['data']['content_type'] == 'product_group', data)
st, _, answer = pub.request('/_shop/cart', data={'product': 'ring00000000000m', 'variant': 'Gold', 'quantity': '2'}, headers={'Origin': BASE, 'Accept': 'application/json'})
added = json.loads(answer)
check('adding to the cart answers with AddToCart', added.get('ok') and added['track']['event'] == 'AddToCart' and added['track']['data']['value'] == 260 and added['track']['data']['content_ids'][0].startswith('ring00000000000m-'), answer[:400])
st, _, admin_page = root.get('/products/ring')
check('no Pixel for someone signed in to the admin', 'shop-pixel.js' not in admin_page)

# ---- the Shop manager
root.submit('/admin/users-edit', has_field('username'), {'username': 'shopkeeper', 'email': 'shopkeeper@example.test', 'display_name': 'Shopkeeper', 'role': 'shop_manager', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
mgr = Client(); mgr.login('shopkeeper', 'Sturdy-pass-99')
st, _, html = mgr.get('/admin')
check('the Shop manager signs in', st == 200 and 'Shopkeeper' in html, st)
check('and has the shop, its settings and Analytics', all(mgr.get(p)[0] == 200 for p in ['/admin/shop-settings?tab=marketing', '/admin/shop-orders', '/admin/analytics', '/admin/content?type=products']), [mgr.get(p)[0] for p in ['/admin/shop-settings?tab=marketing', '/admin/shop-orders', '/admin/analytics', '/admin/content?type=products']])
check('but not the site settings or the users', [mgr.get('/admin/settings')[0], mgr.get('/admin/users')[0]] == [403, 403], [mgr.get('/admin/settings')[0], mgr.get('/admin/users')[0]])
st, _, html = root.get('/admin/roles')
check('the roles screen lists it', 'Shop manager' in html, st)

print('ALL PASSED' if not fails else f'{len(fails)} FAILED')
sys.exit(1 if fails else 0)
