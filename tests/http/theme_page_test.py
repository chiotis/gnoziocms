import re, sys, sqlite3
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:220]))
    if not ok: fails.append(label)

def stored():
    c = sqlite3.connect('app/storage/db/app.sqlite'); v = c.execute("select value from system_meta where key='theme_settings'").fetchone(); c.close()
    return v[0] if v else ''

root = Client(); root.login()
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed1', 'Sturdy-pass-99')

st, _, html = root.get('/admin/theme')
check('the theme has its own screen', st == 200 and re.search(r'<h1[^>]*>\s*Theme\s*</h1>', html) is not None, st)
labels = re.findall(r'role="tab"[^>]*data-tab="([a-z_]+)"[^>]*>([^<]+)</button>', html)
keys = [k for k, _ in labels]
check('every section the theme declares is a tab: Branding gathers appearance, brand and design; the two that gather how pages look come after the header; Footer Blocks follows the footer, Custom CSS then Export and import are last', keys == ['branding', 'header', 'single_layouts', 'archive_layouts', 'social', 'footer', 'footer_blocks', 'custom_css', 'transfer'], keys)
check('with the section names as labels', ('Branding' in [l for _, l in labels]) and ('Appearance' not in [l for _, l in labels]) and ('Brand' not in [l for _, l in labels]) and ('Header' in [l for _, l in labels]) and ('Single Layouts' in [l for _, l in labels]) and ('Archive Layouts' in [l for _, l in labels]) and 'Hero Layouts' not in [l for _, l in labels], labels)
check('the first tab is open, the others hidden', html.count('data-panel=') == len(keys) and re.search(r'data-panel="%s"' % keys[0], html) is not None and html.count('tab-panel space-y-4 hidden') == len(keys) - 1, (len(keys), html.count('space-y-4 hidden')))
check('tabs say which is selected', html.count('aria-selected="true"') == 1)
check('one Save for all of them', html.count('Save changes') == 1 and 'name="theme_settings[header][layout]"' in html)
st, _, html = root.get('/admin/theme?tab=header')
check('a tab can be opened by address', re.search(r'class="[^"]*\bactive\b[^"]*" data-tab="header"', html) is not None and 'name="active_tab" value="header"' in html)
st, _, html = root.get('/admin/theme?tab=nonsense')
check('an unknown tab opens the first', 'name="active_tab" value="%s"' % keys[0] in html)

# ---- saving
form = next(f for f in root.forms('/admin/theme') if any(x[0].startswith('theme_settings[') for x in f['fields']))
fields = [tuple(x) for x in form['fields'] if x[0] not in ('theme_settings[header][transparent]', 'active_tab', 'theme_settings[header][sticky]')]
fields += [('theme_settings[header][transparent]', '1'), ('active_tab', 'header'), ('theme_settings[header][sticky]', 'always')]
st, hdr, _ = root.request('/admin/theme', data=fields)
check('saving returns to the same tab', st == 302 and 'admin/theme' in (hdr.get('Location') or '') and 'tab=header' in (hdr.get('Location') or ''), hdr.get('Location'))
check('and stores the values', 'transparent: true' in stored() and 'sticky: always' in stored(), stored()[:300])
st, _, html = root.get(hdr.get('Location') or '/admin/theme?saved=1&tab=header')
check('with a confirmation', 'Saved.' in html)
st, _, html = root.get('/admin/activity-logs')
check('the change is in the activity log', 'theme.update' in html or 'Theme settings updated' in html)

# ---- Custom CSS: the site's own stylesheet is a text area, written to custom/assets/css/custom.css
import os
cssfile = 'app/custom/assets/css/custom.css'
st, _, html = root.get('/admin/theme?tab=custom_css')
check('Custom CSS shows a text area for the stylesheet, empty when the site has none', re.search(r'<textarea id="custom-css" name="custom_css"[^>]*></textarea>', html) is not None and not os.path.exists(cssfile), st)
st, hdr, _ = root.request('/admin/theme', data=fields + [('custom_css', '.mine { color: red; }\r\n'), ('active_tab', 'custom_css')])
check('saving writes the file and stays on the tab', st == 302 and 'tab=custom_css' in (hdr.get('Location') or '') and os.path.exists(cssfile) and open(cssfile).read() == '.mine { color: red; }\n', (st, hdr.get('Location')))
st, _, html = root.get('/admin/theme?tab=custom_css')
check('and the box shows it again', '.mine { color: red; }' in html)
st, _, html = root.get('/')
check('the pages load it after the theme', '/_custom/css/custom.css' in html, st)
st, _, css = root.get('/_custom/css/custom.css')
check('and it is served', st == 200 and '.mine' in css, st)
root.request('/admin/theme', data=fields + [('custom_css', ''), ('active_tab', 'custom_css')])
check('an empty box empties the file', os.path.exists(cssfile) and open(cssfile).read() == '')

# ---- Settings no longer has it, and no longer touches it
st, _, html = root.get('/admin/settings')
check('Settings has no Theme tab and none of its fields', st == 200 and 'data-tab="theme"' not in html and 'theme_settings[' not in html, st)
st, hdr, _ = root.request('/admin/settings?tab=theme')
check('an old link to the Theme tab goes to the new screen', st == 302 and (hdr.get('Location') or '').endswith('/admin/theme'), (st, hdr.get('Location')))
sform = next(f for f in root.forms('/admin/settings') if any(x[0] == 'title' for x in f['fields']))
root.request('/admin/settings', data=[tuple(x) for x in sform['fields']])
check('saving Settings leaves the theme options alone', 'transparent: true' in stored() and 'sticky: always' in stored(), stored()[:300])

# ---- who may
st, _, html = ed.get('/admin/theme'); check('an editor cannot open it', st in (302, 403), st)
st, _, _ = ed.request('/admin/theme', data=[('theme_settings[header][layout]', 'minimal')]); check('nor save', st in (302, 403, 419) and 'layout: minimal' not in stored(), st)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
