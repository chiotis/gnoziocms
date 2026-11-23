"""
The Entry title block's trail written as a pattern, on a product page: "Collections|/shop > {term:collections} >
{term:product-categories:alone}" with // between the parts draws Collections // Balos // Earrings, each with its link and none
marked as the page itself; the same pattern ending with {title} marks the title as the page; and an empty pattern keeps the trail
of old (Home, the type, the title) with the theme's separator.
"""
import json, os, re, sys
sys.path.insert(0, '.')
from client import Client

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

def trail(html):
    """The trail of the title block: its class and [(label, href or None, current)]."""
    m = re.search(r'<nav class="(crumbs[^"]*)"[^>]*>(.*?)</nav>', html, re.S)
    if not m:
        return None, []
    items = []
    for li in re.findall(r'<li>(.*?)</li>', m.group(2), re.S):
        a = re.search(r'<a href="([^"]*)">([^<]*)</a>', li)
        s = re.search(r'<span( aria-current="page")?>([^<]*)</span>', li)
        items.append((a.group(2), a.group(1), False) if a else (s.group(2), None, bool(s.group(1))))
    return m.group(1), items

root = Client(); root.login()
st, hdr, _ = toggle(root, 'products', True)
check('the shop is switched on', st in (302, 303), (st, hdr.get('Location')))

write('app/content/taxonomies/collections.yaml', """title: Collections
terms:
  - { id: balos, slug: balos, labels: { el: Balos } }
""")
write('app/content/taxonomies/product-categories.yaml', """title: Product categories
hierarchical: true
terms:
  - { id: jewellery, slug: jewellery, labels: { el: Jewellery } }
  - { id: earrings, slug: earrings, parent: jewellery, labels: { el: Earrings } }
""")
write('app/content/products/balos-earrings.md', """---
title: Balos Earrings
status: published
date: '2026-03-01'
translation_id: trail0000000000a
collections: [balos]
product-categories: [earrings]
custom_fields:
  price: 90
---
Earrings.
""")

def page(entry_title):
    """The product page with its template built with blocks: this Entry title block, then the text (Edit template, as sent)."""
    form = next(f for f in root.forms('/admin/theme?template=products') if any(x[0] == 'blocks_json' for x in f['fields']))
    fields = [tuple(x) for x in form['fields'] if x[0] not in ('blocks_json', 'blocks_editor', 'use')]
    fields += [('blocks_editor', '1'), ('blocks_json', json.dumps([dict({'type': 'entry-title'}, **entry_title), {'type': 'content'}])), ('use', '1')]
    root.request('/admin/theme?template=products', data=fields)
    st, _, html = Client().get('/products/balos-earrings')
    return st, html

st, html = page({'breadcrumbs': True, 'trail': 'Collections|/shop > {term:collections} > {term:product-categories:alone}', 'separator': 'double'})
cls, items = trail(html)
check('the product page is drawn', st == 200, st)
check('the trail is Collections // Balos // Earrings', [i[0] for i in items] == ['Collections', 'Balos', 'Earrings'], items)
check('each with its link', [bool(i[1]) for i in items] == [True, True, True] and items[0][1] == '/shop' and items[1][1].endswith('/collections/balos') and items[2][1].endswith('/product-categories/jewellery/earrings'), items)
check('none of them marked as the page itself', not any(i[2] for i in items), items)
check('with // between the parts', cls == 'crumbs crumbs-sep-double', cls)

st, html = page({'breadcrumbs': True, 'trail': 'Collections|/shop > {term:collections} > {term:product-categories} > {title}', 'separator': 'slash'})
cls, items = trail(html)
check('with the terms above the category and the title', [i[0] for i in items] == ['Collections', 'Balos', 'Jewellery', 'Earrings', 'Balos Earrings'], items)
check('the title is the page itself, without a link', items[-1] == ('Balos Earrings', None, True) and items[-2][1], items)
check('and / between the parts', cls == 'crumbs crumbs-sep-slash', cls)

st, html = page({'breadcrumbs': True})
cls, items = trail(html)
check('an empty pattern keeps Home, the type and the title', [i[1] is not None for i in items] == [True, True, False] and items[-1][0] == 'Balos Earrings' and items[-1][2], items)
check('with the theme\'s separator', cls == 'crumbs', cls)

st, html = page({'breadcrumbs': False, 'trail': 'Collections > {title}'})
check('the trail switched off draws none', trail(html)[1] == [], trail(html))

print('\n%s' % ('ALL PASSED' if not fails else '%d FAILED' % len(fails)))
sys.exit(1 if fails else 0)
