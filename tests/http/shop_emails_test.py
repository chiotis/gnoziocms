"""
The shop's emails over HTTP (docs/shop-plan.md, section 8): Shop settings > Emails (how they look, what each says in each
language, the theme's words shown greyed), the preview of each as HTML and as text (on its own, with no scripts), a changed
subject in the preview, the test, and an order's email sent with its HTML part.
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

root = Client(); root.login()
toggle(root, 'products', True)
write('app/content/products/ring.md', """---
title: Δαχτυλίδι
status: published
date: '2026-03-01'
translation_id: ring00000000000e
custom_fields:
  price: 150
---
Ring.
""")
root.submit('/admin/shop-settings?tab=general', has_field('order_prefix'), {'store_name': 'Aleon', 'order_prefix': 'EM', 'store_email': 'orders@example.test', 'guest_checkout': '1', 'terms_page': ''})
root.submit('/admin/shop-settings?tab=shipping', has_field('tab'), {'zones[0][name]': 'Greece', 'zones[0][countries]': 'GR', 'zones[0][methods][0][kind]': 'flat', 'zones[0][methods][0][label][el]': 'Courier', 'zones[0][methods][0][cost]': '0'})
root.submit('/admin/shop-settings?tab=payments', has_field('tab'), {'bank_transfer[enabled]': '1', 'bank_transfer[accounts]': 'Eurobank GR16 0260'})

# ---- the tab
st, _, html = root.get('/admin/shop-settings?tab=emails')
check('the Emails tab: how they look, each email, a test', st == 200 and 'name="emails[html]"' in html and 'Order received' in html and 'Subscription not renewed' in html and 'name="test_to"' in html, st)
check('the theme\'s words greyed in each language', re.search(r'placeholder="[^"]*\{number\}[^"]*"', html) is not None)
check('in the menu of tabs', 'tab=emails' in html)
st, hdr, _ = root.submit('/admin/shop-settings?tab=emails', has_field('emails[accent]'), {'emails[html]': '1', 'emails[accent]': '#B4532A', 'emails[footer]': 'Ermou 1, Athens', 'emails[texts][received][el][subject]': 'Λάβαμε την {number}!', 'emails[logo]': 'javascript:alert(1)'})
check('saved', 'done=ok' in (hdr.get('Location') or ''), hdr.get('Location'))
stored = json.loads(db("select value from system_meta where key = 'shop_settings'")[0][0])['emails']
check('kept tidy: the colour, no unsafe logo, the changed subject', stored['accent'] == '#b4532a' and stored['logo'] == '' and stored['texts'] == {'received': {'el': {'subject': 'Λάβαμε την {number}!', 'text': ''}}}, stored)
st, _, html = root.get('/admin/shop-settings?tab=emails')
check('the changed one is marked', 'changed' in html and 'value="Λάβαμε την {number}!"' in html)

# ---- the preview
st, hdr, page = root.get('/admin/shop-settings?preview=received&lang=el')
check('the preview: the email as HTML, with no scripts allowed', st == 200 and page.startswith('<!doctype html>') and "default-src 'none'" in (hdr.get('Content-Security-Policy') or '') and '#b4532a' in page and 'Ermou 1, Athens' in page, (st, hdr.get('Content-Security-Policy')))
check('with the changed subject and a made-up order', '<title>Λάβαμε την AL-2026-00042!</title>' in page and 'Eurobank GR16 0260' in page)
st, hdr, text = root.get('/admin/shop-settings?preview=paid&lang=en&format=text')
check('and as its plain text', st == 200 and 'text/plain' in (hdr.get('Content-Type') or '') and text.startswith('Subject: Aleon: order AL-2026-00042 is paid'), text[:120])
check('nothing for an unknown email', root.get('/admin/shop-settings?preview=nonsense')[0] in (302, 303))
check('not for a visitor', Client().get('/admin/shop-settings?preview=received')[0] in (302, 303, 403))

# ---- the test
st, hdr, _ = root.submit('/admin/shop-settings?tab=emails', has_field('emails[accent]'), {'emails[html]': '1', 'test_kind': 'shipped', 'test_lang': 'el', 'test_to': 'me@example.test'}, button=('email_action', 'test'))
loc = hdr.get('Location') or ''
log = db("select recipient, subject, context_json from email_logs where recipient = 'me@example.test'")
check('the test is sent (or tried), marked as a test, with its HTML', len(log) == 1 and log[0][1].startswith('[Test] ') and '"html":true' in (log[0][2] or ''), (loc, log))

# ---- a real order's email
pub = Client()
pub.request('/_shop/cart', data={'product': 'ring00000000000e', 'quantity': '1'}, headers={'Origin': BASE, 'Accept': 'application/json'})
form = {'email': 'maria@example.test', 'billing[first_name]': 'Maria', 'billing[last_name]': 'Patera', 'billing[address]': 'Ermou 1', 'billing[city]': 'Athens', 'billing[postcode]': '10563', 'billing[country]': 'GR', 'billing[phone]': '2100000000', 'payment': 'bank_transfer'}
st, hdr, _ = pub.submit('/checkout', has_field('email'), form, button=('place', '1'))
number = db('select number from shop_orders order by id desc limit 1')[0][0]
log = db("select subject, context_json from email_logs where recipient = 'maria@example.test'")
check('an order\'s email: the changed subject, with its HTML part', log and log[-1][0] == 'Λάβαμε την %s!' % number and '"html":true' in (log[-1][1] or ''), log)
root.submit('/admin/shop-settings?tab=emails', has_field('emails[accent]'), {}, drop=['emails[html]'])
pub.request('/_shop/cart', data={'product': 'ring00000000000e', 'quantity': '1'}, headers={'Origin': BASE, 'Accept': 'application/json'})
pub.submit('/checkout', has_field('email'), form, button=('place', '1'))
log = db("select context_json from email_logs where recipient = 'maria@example.test' order by id desc limit 1")
check('HTML off: plain text only', '"html":false' in (log[0][0] or ''), log)

print('ALL PASSED' if not fails else f'{len(fails)} FAILED')
sys.exit(1 if fails else 0)
