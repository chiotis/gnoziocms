"""
The site through MCP beyond its entries: terms:write, site:read and site:write are off by default; terms of every taxonomy added,
changed (the same name twice refused) and removed, the shop's only with the shop's scopes; the site's name and tagline; SEO
settings changed in part (a value the screen would not keep named, the ownership codes refused); redirects added, changed and
removed (another website refused); a menu read, changed, added and placed; Theme settings changed in part (a switch left out
stays on, a wrong choice and a list refused); a form read (where its emails go, to read only), changed keeping its emails, and
added.
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

def meta(key):
    c = sqlite3.connect('app/storage/db/app.sqlite')
    row = c.execute('select value from system_meta where key = ?', (key,)).fetchone()
    c.close()
    return row[0] if row else ''

root = Client(); root.login()
root.submit('/admin/mcp', has_field('mcp_action'), button=('mcp_action', 'enable'))

st, _, html = root.get('/admin/mcp')
check('the new scopes are offered, unticked', all(re.search(r'name="scopes\[\]" value="%s" class' % s, html) for s in ('terms:write', 'site:read', 'site:write')), re.findall(r'name="scopes\[\]"[^>]*>', html))

def make_token(name, scopes):
    root.submit('/admin/mcp', has_field('days'), {'name': name, 'days': '30', 'scopes[]': scopes})
    return re.search(r'value="(gat_[A-Za-z0-9_-]+)"', root.get('/admin/mcp')[2]).group(1)
news = make_token('News job', ['content:read', 'content:write'])
editor = make_token('Site editor', ['content:read', 'terms:write', 'site:write'])
reader = make_token('Site reader', ['site:read'])

site_tools = {'get_site_settings', 'update_site_settings', 'get_seo_settings', 'update_seo_settings', 'list_redirects', 'save_redirect', 'delete_redirect',
              'list_menus', 'get_menu', 'save_menu', 'get_theme_settings', 'update_theme_settings', 'list_forms', 'get_form', 'save_form'}
check('a content connection has none of them', not (site_tools | {'create_term', 'update_term', 'remove_term'}) & set(tools(news)), tools(news))
check('site:write and terms:write list them all', (site_tools | {'create_term', 'update_term', 'remove_term'}) <= set(tools(editor)), sorted(set(site_tools) - set(tools(editor))))
check('site:read lists only the reading ones', {t for t in site_tools if t.startswith(('get_', 'list_'))} == site_tools & set(tools(reader)), tools(reader))
r = call(reader, 'save_redirect', {'source': '/a', 'target': '/b'})
check('reading cannot write', r['isError'] and 'may not use' in r['text'], r)

# ---- terms of every taxonomy
r = call(editor, 'create_term', {'taxonomy': 'categories', 'labels': {'el': 'Εκδηλώσεις', 'en': 'Events'}, 'description': {'en': 'What is on.'}})
term = r['data'].get('term', {})
check('a category is added', r['isError'] is False and term.get('id') and 'Events' in read('app/content/taxonomies/categories.yaml'), r)
r = call(editor, 'create_term', {'taxonomy': 'categories', 'labels': {'el': 'εκδηλώσεις'}})
check('the same name twice is refused with the id to use', r['isError'] and term.get('id', '?') in r['text'], r)
r = call(editor, 'create_term', {'taxonomy': 'tags', 'labels': {'el': 'Χ', 'xx': 'X'}, 'number': 2})
check('every problem at once', r['isError'] and '"xx"' in r['text'] and 'collections only' in r['text'], r)
r = call(editor, 'update_term', {'taxonomy': 'categories', 'term': term.get('id'), 'labels': {'en': 'Happenings'}})
check('a category is renamed in one language, the other kept', r['isError'] is False and r['data']['term']['labels'] == {'el': 'Εκδηλώσεις', 'en': 'Happenings'}, r)
r = call(editor, 'create_term', {'taxonomy': 'tags', 'labels': {'el': 'Καλοκαίρι'}})
check('a tag is added', r['isError'] is False and r['data']['term'].get('id'), r)
r = call(editor, 'remove_term', {'taxonomy': 'categories', 'term': term.get('id')})
check('a category is removed', r['isError'] is False and r['data'].get('removed') == term.get('id') and 'Happenings' not in read('app/content/taxonomies/categories.yaml'), r)
r = call(editor, 'create_term', {'taxonomy': 'product-categories', 'labels': {'el': 'Χ'}})
check('the shop\'s taxonomies need the shop', r['isError'] and ('shop' in r['text'] or 'There is no taxonomy' in r['text']), r)

# ---- the site's name
r = call(reader, 'get_site_settings')
check('the site\'s name is read', r['isError'] is False and r['data'].get('title') and 'languages' in r['data'], r)
r = call(editor, 'update_site_settings', {'tagline': 'Slow travel in Crete'})
check('the tagline changes and the name stays', r['isError'] is False and r['data']['tagline'] == 'Slow travel in Crete' and r['data']['title'] == call(reader, 'get_site_settings')['data']['title'], r)
check('and the pages show it', 'Slow travel in Crete' in root.get('/')[2] or 'Slow travel in Crete' in meta('site_settings'))
r = call(editor, 'update_site_settings', {'title': '  '})
check('an empty name is refused', r['isError'] and 'empty' in r['text'], r)

# ---- SEO
seo = call(reader, 'get_seo_settings')['data']
check('the SEO settings are read with their choices', 'home_title' in seo.get('settings', {}) and 'verification' not in seo.get('settings', {}) and seo.get('choices', {}).get('separator'), seo)
r = call(editor, 'update_seo_settings', {'settings': {'home_title': 'Crete, slowly', 'identity': {'name': 'Slow Crete', 'country': 'gr'}, 'noindex_taxonomies': True}})
s = r['data'].get('settings', {})
check('parts of three tabs change, the rest kept', r['isError'] is False and s.get('home_title') == 'Crete, slowly' and s['identity']['name'] == 'Slow Crete' and s['identity']['country'] == 'GR'
      and s['noindex_taxonomies'] is True and s['identity']['type'] == seo['settings']['identity']['type'] and s['sitemap'] == seo['settings']['sitemap'], r)
r = call(editor, 'update_seo_settings', {'settings': {'separator': '#', 'twitter_card': 'huge', 'verification': {'google': 'x'}}})
check('a value the screen would not keep is named, the ownership codes refused, nothing saved', r['isError'] and 'separator' in r['text'] and 'verification' in r['text']
      and call(reader, 'get_seo_settings')['data']['settings']['twitter_card'] == seo['settings']['twitter_card'], r)
r = call(editor, 'update_seo_settings', {'settings': {'separator': '#', 'twitter_card': 'huge'}})
check('every value that does not fit is named', r['isError'] and 'separator' in r['text'] and 'twitter_card' in r['text'], r)

# ---- redirects
r = call(editor, 'save_redirect', {'source': '/old-tours', 'target': '/about', 'note': 'renamed'})
red = r['data'].get('redirect') or {}
check('a redirect is added', r['isError'] is False and red.get('id') and red.get('code') == 301 and red.get('source', '').strip('/') == 'old-tours', r)
st, headers, _ = Client().get('/old-tours')
check('and visitors are sent on', st in (301, 302) and '/about' in str(headers), (st, headers))
r = call(editor, 'save_redirect', {'source': '/old-deals', 'target': 'https://elsewhere.example/'})
check('a redirect to another website is refused', r['isError'] and 'another website' in r['text'], r)
r = call(editor, 'save_redirect', {'id': red.get('id'), 'source': '/old-tours', 'enabled': False})
check('a redirect is turned off, its target kept', r['isError'] is False and r['data']['redirect']['enabled'] is False and r['data']['redirect']['target'] == '/about', r)
r = call(reader, 'list_redirects', {'query': 'old-tours'})
check('redirects are listed', r['isError'] is False and r['data']['total'] >= 1 and r['data']['redirects'][0]['note'] == 'renamed', r)
r = call(editor, 'delete_redirect', {'id': red.get('id')})
check('a redirect is removed', r['isError'] is False and call(reader, 'list_redirects', {'query': 'old-tours'})['data']['total'] == 0, r)

# ---- menus
menus = call(reader, 'list_menus')['data']
check('the menus and the places are listed', {'main', 'footer'} <= {m['menu'] for m in menus.get('menus', [])} and any(p['place'] == 'header' for p in menus.get('places', [])), menus)
main = call(reader, 'get_menu', {'menu': 'main'})['data']
check('a menu is read as a tree', main.get('items') and all('labels' in i and 'url' in i for i in main['items']), main)
items = main['items'] + [{'labels': {'el': 'Εκδηλώσεις', 'en': 'Events'}, 'url': '/events', 'children': [{'labels': {'el': 'Καλοκαίρι'}, 'url': '/events/summer'}]}]
r = call(editor, 'save_menu', {'menu': 'main', 'items': items})
check('an item with one under it is added, the others kept', r['isError'] is False and len(r['data']['items']) == len(main['items']) + 1 and r['data']['items'][-1]['children'][0]['url'] == '/events/summer'
      and '/events/summer' in read('app/content/menus/main.yaml'), r)
check('and the site shows it', '/events' in root.get('/')[2])
r = call(editor, 'save_menu', {'menu': 'main', 'items': [{'labels': {'fr': 'X'}, 'url': 'javascript:alert(1)', 'colour': 'red'}]})
check('a bad menu is refused whole', r['isError'] and '"fr"' in r['text'] and 'not javascript' in r['text'] and '"colour"' in r['text'] and '/events/summer' in read('app/content/menus/main.yaml'), r)
r = call(editor, 'save_menu', {'menu': 'legal', 'title': 'Legal', 'items': [{'labels': {'el': 'Όροι'}, 'url': '/terms'}], 'places': ['footer']})
check('a menu is added and placed in the footer', r['isError'] is False and r['data'].get('added') and 'footer' in r['data'].get('places', []), r)

# ---- Theme settings
theme = call(reader, 'get_theme_settings', {'section': 'footer'})['data']
footer = theme.get('sections', {}).get('footer', {}).get('fields', {})
check('a section of the Theme settings is read', 'back_to_top' in footer and footer['back_to_top']['writable'] and 'value' in footer['back_to_top'], theme)
before = call(reader, 'get_theme_settings')['data']['sections']
on = [k for k, f in before['footer']['fields'].items() if f['type'] == 'toggle' and f['value'] is True]
r = call(editor, 'update_theme_settings', {'settings': {'footer': {'copyright': '© Slow Crete'}, 'header': {'shrink': True}}})
after = call(reader, 'get_theme_settings')['data']['sections']
check('two settings change, the switches left out stay as they were', r['isError'] is False and after['footer']['fields']['copyright']['value'] == '© Slow Crete' and after['header']['fields']['shrink']['value'] is True
      and all(after['footer']['fields'][k]['value'] is True for k in on), (r, on))
r = call(editor, 'update_theme_settings', {'settings': {'footer': {'layout': 'zigzag', 'nope': 1}, 'sidebar': {'card_url': '/x'}, 'colours': {}}})
check('a wrong choice, an unknown field, a link and an unknown section are named at once', r['isError'] and 'footer.layout' in r['text'] and 'footer.nope' in r['text'] and 'Admin > Theme' in r['text'] and '"colours"' in r['text'], r)
r = call(editor, 'update_theme_settings', {'settings': {'design': {'light_accent': 'blue'}}})
check('a colour must be #rrggbb', r['isError'] and 'light_accent' in r['text'], r)

# ---- forms
forms = call(reader, 'list_forms')['data']
check('the forms are listed', any(f['form'] == 'contact' for f in forms.get('forms', [])), forms)
form = call(reader, 'get_form', {'form': 'contact'})['data']
check('a form is read, with where its emails go to read only', form.get('fields') and 'read_only' in form and 'emails' in form['read_only'], form)
emails_before = form['read_only']
fields = form['fields'] + [{'type': 'select', 'name': 'how', 'label': 'Πώς μας βρήκατε', 'options': [{'value': 'friend', 'label': 'Φίλος'}, {'value': 'web', 'label': 'Διαδίκτυο'}]}]
r = call(editor, 'save_form', {'form': 'contact', 'fields': fields, 'submit_label': 'Στείλτε'})
emails_after = r['data'].get('read_only')
check('a field is added and the button renamed, its emails kept', r['isError'] is False and r['data']['fields'][-1]['name'] == 'how' and r['data']['submit_label'] == 'Στείλτε'
      and emails_before == emails_after and 'to:' in read('app/content/forms/contact.md'), (r['text'][-400:], emails_before, emails_after))
r = call(editor, 'save_form', {'form': 'contact', 'fields': [{'type': 'radio', 'name': 'x', 'label': 'X'}, {'type': 'magic', 'name': 'y'}]})
check('a field without its options and an unknown kind are refused', r['isError'] and 'needs options' in r['text'] and '"magic"' in r['text'], r)
r = call(editor, 'save_form', {'form': 'signup', 'title': 'Εγγραφή', 'fields': [{'type': 'email', 'name': 'email', 'label': 'Email', 'required': True}]})
check('a form is added, sending no emails', r['isError'] is False and r['data'].get('added') and r['data']['read_only']['emails']['enabled'] is False and os.path.exists('app/content/forms/signup.md'), r)

# ---- the log
c = sqlite3.connect('app/storage/db/app.sqlite')
logged = [r[0] for r in c.execute("select action from activity_logs where message like '%(MCP: Site editor)%'").fetchall()]
c.close()
check('the changes are in the activity log with the connection', {'seo.update', 'theme.update', 'menus.update', 'forms.update'} <= set(logged), logged)

print('\nALL PASSED' if not fails else '\n%d FAILED' % len(fails))
sys.exit(1 if fails else 0)
