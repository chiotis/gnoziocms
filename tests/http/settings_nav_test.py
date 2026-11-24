"""Settings and Shop settings: the section list on the left (admin/templates/partials/section-nav.twig), with MCP and
Omni listed in Settings (partials/settings-sidebar.twig) instead of the main menu; Theme, AI publishing and SEO have the
same list.

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
def aside(html):
    m = re.search(r'<aside.*?</aside>', html, re.S)
    return m.group(0) if m else ''
def menu_current(html):
    return [re.sub(r'<[^>]+>', '', c).strip() for c in re.findall(r'<a href="[^"]*"[^>]*aria-current="page"[^>]*>(.*?)</a>', aside(html), re.S)]
def panel_hidden(html, key):
    m = re.search(r'<div class="([^"]*)" data-panel="' + key + '"', html)
    return None if not m else ('hidden' in m.group(1).split())

root = Client(); root.login()

st, _, html = root.get('/admin/settings')
label, body = nav(html)
check('Settings has the section list', st == 200 and label == 'Settings' and body != '', st)
check('under the group headings, in order', headings(body) == ['Site', 'Communication', 'Access', 'Integrations', 'System'], headings(body))
check('with every section, in order, MCP and Omni among them', items(body) == ['basics', 'smtp', 'auth', 'apis', 'mcp', 'omni', 'limits'], items(body))
check('MCP and Omni are links to their own screens', re.search(r'href="[^"]*admin/mcp" class="section-nav-link" data-section="mcp"', body) is not None and re.search(r'href="[^"]*admin/omni" class="section-nav-link" data-section="omni"', body) is not None)
check('each with an icon and a label', re.search(r'data-section="smtp"><svg[^>]*aria-hidden="true">.+?</svg><span>Email / SMTP</span>', body) is not None)
check('each a link that opens its section', re.search(r'href="[^"]*admin/settings\?tab=smtp" class="section-nav-link" data-section="smtp"', body) is not None)
check('Backups and Updates are not in it: their settings are on their own screens', 'backup' not in items(body) and 'updates' not in items(body) and 'name="backup_schedule"' not in html and 'name="update_repository"' not in html, items(body))
check('General is current, once', current(body) == ['basics'], current(body))
check('the list switches panels in the page', 'data-section-nav="switch"' in html)
check('no old tab bar is left', 'class="tab-button' not in html and 'data-tab="smtp"' not in html)
check('only the General panel shows', panel_hidden(html, 'basics') is False and all(panel_hidden(html, k) for k in ('smtp', 'auth', 'apis', 'limits')), [(k, panel_hidden(html, k)) for k in ('basics', 'smtp', 'limits')])
opts, sel = options(html)
check('the select offers the same sections, General chosen', opts == items(body) and sel == ['basics'], (opts, sel))
side = aside(html)
check('the main menu has no MCP or Omni entries any more', 'admin/mcp"' not in side and 'admin/omni"' not in side and 'admin/settings"' in side)
check('the select is not sent with the form', not re.search(r'<select data-section-select[^>]*\bname=', html))

st, _, html = root.get('/admin/settings?tab=smtp')
label, body = nav(html)
check('?tab=smtp opens Email / SMTP', current(body) == ['smtp'] and panel_hidden(html, 'smtp') is False and panel_hidden(html, 'basics') is True, current(body))
check('and the form remembers it', 'name="active_tab" value="smtp"' in html)
check('the select follows', options(html)[1] == ['smtp'], options(html))
check('Discard stays on the section', re.search(r'href="[^"]*admin/settings\?tab=smtp"[^>]*data-section-discard', html) is not None)

for path, key in (('/admin/mcp', 'mcp'), ('/admin/omni', 'omni')):
    st, _, html = root.get(path)
    label, body = nav(html)
    check(path + ' opens with the Settings list, itself current', st == 200 and label == 'Settings' and current(body) == [key], (st, current(body)))
    check(path + ': its list is links, Settings\' sections included', 'data-section-nav="links"' in html and re.search(r'href="[^"]*admin/settings\?tab=smtp"', body) is not None)
    check(path + ': Settings is the current entry of the main menu', menu_current(html) == ['Settings'], menu_current(html))

st, _, html = root.get('/admin/settings?tab=nonsense')
check('an unknown section opens General', current(nav(html)[1]) == ['basics'])

st, hdr, _ = root.submit('/admin/settings?tab=auth', lambda f: any(x[0] == 'active_tab' for x in f['fields']), {'active_tab': 'auth'})
loc = hdr.get('Location') or ''
check('saving comes back to the same section', 'tab=auth' in loc, loc)
st, _, html = root.get(loc)
check('with it current', current(nav(html)[1]) == ['auth'], current(nav(html)[1]))

# ---- Backups' and Updates' settings: on their own screens, saved on their own
for tab, there in (('backup', '/admin/backups?view=settings'), ('updates', '/admin/updates?view=settings')):
    st, hdr, _ = root.request('/admin/settings?tab=' + tab)
    check('the old Settings > %s address goes to its own screen' % tab, st in (301, 302, 303) and (hdr.get('Location') or '').endswith(there), (st, hdr.get('Location')))
st, _, html = root.get('/admin/backups?view=settings')
check('Backups > Settings has the schedule, the passphrase and remote storage', st == 200 and all(('name="%s"' % n) in html for n in ('backup_schedule', 'backup_passphrase', 'backup_remote_bucket', 'test_remote_backup')) and 'name="settings_section" value="backup"' in html, st)
check('with Backups current in the main menu', menu_current(html) == ['Backups'], menu_current(html))
st, _, html = root.get('/admin/backups')
check('the Backups screen links to it', 'admin/backups?view=settings' in html and 'admin/settings?tab=backup' not in html)
st, hdr, _ = root.submit('/admin/backups?view=settings', has_field('backup_schedule'), {'backup_schedule': 'weekly', 'backup_keep_local': '7'})
check('saving Backups > Settings comes back to it', (hdr.get('Location') or '').endswith('/admin/backups?view=settings&saved=1'), hdr.get('Location'))
st, _, html = root.get('/admin/backups?view=settings')
check('with the new values', re.search(r'<option value="weekly"\s+selected', html) is not None and 'name="backup_keep_local" value="7"' in html)
title_before = re.search(r'name="title" value="([^"]*)"', root.get('/admin/settings')[2]).group(1)
root.submit('/admin/settings', has_field('title'), {'tagline': 'Kept apart'})
st, _, html = root.get('/admin/backups?view=settings')
check('saving Settings leaves the backup settings as they are', re.search(r'<option value="weekly"\s+selected', html) is not None and 'name="backup_keep_local" value="7"' in html)
check('and saving Backups > Settings left the site\'s name alone', re.search(r'name="title" value="([^"]*)"', root.get('/admin/settings')[2]).group(1) == title_before)
st, _, html = root.get('/admin/updates?view=settings')
check('Updates > Source has the repository and the token', st == 200 and all(('name="%s"' % n) in html for n in ('update_repository', 'update_release_url', 'update_github_token')) and menu_current(html) == ['Updates'], (st, menu_current(html)))
check('the Updates screen links to it', 'admin/updates?view=settings' in root.get('/admin/updates')[2])

root.submit('/admin/users-edit', has_field('username'), {'username': 'navadm', 'email': 'navadm@example.test', 'display_name': 'navadm', 'role': 'admin', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
adm = Client(); adm.login('navadm', 'Sturdy-pass-99')
st, _, html = adm.get('/admin/settings?tab=limits')
body = nav(html)[1]
check('an admin without the Limits permission has no Limits entry', st == 200 and 'limits' not in items(body) and 'limits' not in options(html)[0], items(body))
check('and no System group at all, with Backups and Updates on their own screens', 'System' not in headings(body), headings(body))
check('nor Omni, which only the super admin may open', 'omni' not in items(body) and 'admin/omni"' not in html, items(body))

# ---- Theme, AI publishing and SEO: the same list
st, _, html = root.get('/admin/theme?tab=footer')
label, body = nav(html)
check('Theme has the list, switching its sections in the page', st == 200 and label == 'Theme' and 'data-section-nav="switch"' in html and current(body) == ['footer'] and panel_hidden(html, 'footer') is False and panel_hidden(html, 'branding') is True, (st, label, current(body)))
check('Theme: its groups', headings(body)[:3] == ['Brand', 'Header and pages', 'Footer'] and headings(body)[-1] == 'Advanced', headings(body))
check('Theme: no old tab bar is left', 'class="tab-button' not in html and 'role="tablist" aria-label="Theme sections"' not in html)
# The Branding preview: what to show (Site / Style samples / Buttons and links) sits in the one toolbar with size and colour mode.
st, _, html = root.get('/admin/theme?tab=branding')
bar = re.search(r'<div class="bp-bar">(.*?)<div class="bp-stage"', html, re.S)
check('Branding preview: one toolbar holds size, colour mode and what to show', st == 200 and bar is not None and all(x in bar.group(1) for x in ['aria-label="Size of the preview"', 'aria-label="Colour mode of the preview"', 'aria-label="Preview content"', 'data-bp-reload']), st)
st, _, html = root.get('/admin/ai-publishing?tab=runs')
label, body = nav(html)
check('AI publishing has the list, its sections as links', st == 200 and label == 'AI publishing' and 'data-section-nav="links"' in html and current(body) == ['runs'] and headings(body) == ['Publishing', 'Setup', 'Reports'] and items(body) == ['schedules', 'runs', 'instructions', 'settings', 'usage'], (st, label, current(body), headings(body), items(body)))
check('AI publishing: no old tab bar is left', 'aria-label="AI publishing">' not in html.replace('<nav class="section-nav-list" aria-label="AI publishing">', ''))
st, _, html = root.get('/admin/seo?tab=crawling')
label, body = nav(html)
check('SEO has the list, its sections as links', st == 200 and label == 'SEO' and current(body) == ['crawling'] and headings(body) == ['Health', 'Appearance', 'Search engines'] and items(body) == ['overview', 'search', 'social', 'crawling', 'identity', 'verification'], (st, label, current(body), headings(body), items(body)))
check('SEO: Overview is the screen itself', re.search(r'href="[^"]*admin/seo" class="section-nav-link" data-section="overview"', body) is not None and 'aria-label="SEO sections"' not in html)

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
