"""Settings and Shop settings: the section list on the left (admin/templates/partials/section-nav.twig).

Groups under small headings, an icon and a label for each section, the current one marked once (?tab= opens it, an
unknown tab opens General), the other panels hidden, the select for narrow screens offering the same sections, Limits
only for whoever may change it, and the Shop settings sections as links to their own pages.
"""
import re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:200]))
    if not ok: fails.append(label)

def nav(html):
    m = re.search(r'<nav class="section-nav-list" aria-label="([^"]+)">(.*?)</nav>', html, re.S)
    return (m.group(1), m.group(2)) if m else ('', '')
def headings(body): return re.findall(r'<p class="section-nav-heading"[^>]*>([^<]+)</p>', body)
def items(body): return re.findall(r'data-section="([a-z_]+)"', body)
def current(body): return re.findall(r'data-section="([a-z_]+)" aria-current="page"', body)
def options(html):
    m = re.search(r'<select data-section-select[^>]*>(.*?)</select>', html, re.S)
    return (re.findall(r'<option value="([a-z_]+)"', m.group(1)), re.findall(r'<option value="([a-z_]+)"[^>]*selected', m.group(1))) if m else ([], [])
def panel_hidden(html, key):
    m = re.search(r'<div class="([^"]*)" data-panel="' + key + '"', html)
    return None if not m else ('hidden' in m.group(1).split())

root = Client(); root.login()

st, _, html = root.get('/admin/settings')
label, body = nav(html)
check('Settings has the section list', st == 200 and label == 'Settings' and body != '', st)
check('under the group headings, in order', headings(body) == ['Site', 'Communication', 'Access', 'System'], headings(body))
check('with every section, in order', items(body) == ['basics', 'smtp', 'auth', 'apis', 'backup', 'updates', 'limits'], items(body))
check('each with an icon and a label', re.search(r'data-section="smtp"><svg[^>]*aria-hidden="true">.+?</svg><span>Email / SMTP</span>', body) is not None)
check('each a link that opens its section', re.search(r'href="[^"]*admin/settings\?tab=backup" class="section-nav-link" data-section="backup"', body) is not None)
check('General is current, once', current(body) == ['basics'], current(body))
check('the list switches panels in the page', 'data-section-nav="switch"' in html)
check('no old tab bar is left', 'class="tab-button' not in html and 'data-tab="smtp"' not in html)
check('only the General panel shows', panel_hidden(html, 'basics') is False and all(panel_hidden(html, k) for k in ('smtp', 'auth', 'apis', 'backup', 'updates', 'limits')), [(k, panel_hidden(html, k)) for k in ('basics', 'smtp', 'limits')])
opts, sel = options(html)
check('the select offers the same sections, General chosen', opts == items(body) and sel == ['basics'], (opts, sel))
check('the select is not sent with the form', not re.search(r'<select data-section-select[^>]*\bname=', html))

st, _, html = root.get('/admin/settings?tab=smtp')
label, body = nav(html)
check('?tab=smtp opens Email / SMTP', current(body) == ['smtp'] and panel_hidden(html, 'smtp') is False and panel_hidden(html, 'basics') is True, current(body))
check('and the form remembers it', 'name="active_tab" value="smtp"' in html)
check('the select follows', options(html)[1] == ['smtp'], options(html))
check('Discard stays on the section', re.search(r'href="[^"]*admin/settings\?tab=smtp"[^>]*data-section-discard', html) is not None)

st, _, html = root.get('/admin/settings?tab=nonsense')
check('an unknown section opens General', current(nav(html)[1]) == ['basics'])

st, hdr, _ = root.submit('/admin/settings?tab=backup', lambda f: any(x[0] == 'active_tab' for x in f['fields']), {'active_tab': 'backup'})
loc = hdr.get('Location') or ''
check('saving comes back to the same section', 'tab=backup' in loc, loc)
st, _, html = root.get(loc)
check('with it current', current(nav(html)[1]) == ['backup'], current(nav(html)[1]))

root.submit('/admin/users-edit', has_field('username'), {'username': 'navadm', 'email': 'navadm@example.test', 'display_name': 'navadm', 'role': 'admin', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
adm = Client(); adm.login('navadm', 'Sturdy-pass-99')
st, _, html = adm.get('/admin/settings?tab=limits')
body = nav(html)[1]
check('an admin without the Limits permission has no Limits entry', st == 200 and 'limits' not in items(body) and 'limits' not in options(html)[0], items(body))
check('and System keeps Backups and Updates', items(body)[-2:] == ['backup', 'updates'], items(body))

# ---- Shop settings: the same list, its sections are pages of their own
root.submit('/admin/content-types', lambda f: ['type', 'products'] in [list(x) for x in f['fields']] and ['action', 'toggle'] in [list(x) for x in f['fields']], {'enabled': '1'})
st, _, html = root.get('/admin/shop-settings?tab=payments')
label, body = nav(html)
if st == 200 and body:
    check('Shop settings has the section list', label == 'Shop settings')
    check('under its group headings', headings(body) == ['Store', 'Checkout', 'Customers', 'Growth', 'Data'], headings(body))
    check('with every section', sorted(items(body)) == sorted(['general', 'details', 'shipping', 'payments', 'accounts', 'emails', 'marketing', 'import']), items(body))
    check('Payments is current, once', current(body) == ['payments'], current(body))
    check('the sections are links', 'data-section-nav="links"' in html and re.search(r'href="[^"]*admin/shop-settings\?tab=shipping"', body) is not None)
    check('the select offers them too', options(html)[1] == ['payments'] and len(options(html)[0]) == 8, options(html))
    check('no old tab bar is left', html.count('aria-label="Shop settings"') == 1)
else:
    check('Shop settings opens once the shop is on', False, st)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
