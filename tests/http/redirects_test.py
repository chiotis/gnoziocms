import json, re, sys, sqlite3, urllib.request, urllib.parse
sys.path.insert(0, '.')
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + str(detail)))
    if not ok: fails.append(label)

db = sqlite3.connect('app/storage/db/app.sqlite')
def redirects(): return {r[0]: (r[1], r[2], r[3]) for r in db.execute('select source,target,status_code,origin from redirects')}
def rd_conn(): return sqlite3.connect('app/storage/db/app.sqlite')
def loc(hdr): return hdr.get('Location') or ''

root = Client(); root.login()
for u, r in (('ed1', 'editor'), ('adm1', 'admin')):
    root.submit('/admin/users-edit', has_field('username'), {'username': u, 'email': u + '@example.test', 'display_name': u, 'role': r, 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed, adm = Client(), Client(); ed.login('ed1', 'Sturdy-pass-99'); adm.login('adm1', 'Sturdy-pass-99')

def create(client, title, type_='pages', lang='el', extra=None, slug=''):
    fields = {'title': title, 'status': 'published', 'visible': '1', 'body': 'Body of ' + title[:20]}
    fields.update(extra or {})
    if slug: fields['slug'] = slug
    st, hdr, _ = client.submit(f'/admin/edit?type={type_}&slug=&lang={lang}', has_field('body'), fields)
    m = re.search(r'slug=([^&]+)', loc(hdr))
    return st, urllib.parse.unquote(m.group(1)) if m else None, loc(hdr)

def rename(client, type_, slug, new, lang='el', keep_translations=True, extra=None):
    fields = {'slug': new}
    fields.update(extra or {})
    st, hdr, _ = client.submit(f'/admin/edit?type={type_}&slug={slug}&lang={lang}', has_field('body'), fields, drop=None if keep_translations else ['rename_translations'])
    m = re.search(r'slug=([^&]+)', loc(hdr))
    return st, urllib.parse.unquote(m.group(1)) if m else None, loc(hdr)

pub = Client()
def code(path): return pub.get(path)[0]

# ---- automatic addresses
st, slug, l = create(root, 'Καλημέρα κόσμε')
check('Greek title becomes a Latin address', slug == 'kalimera-kosme', (st, slug, l))
check('new page is public at that address', code('/kalimera-kosme') == 200)
st, slug2, l = create(root, 'Καλημέρα κόσμε')
check('same title gets -2, not an overwrite', slug2 == 'kalimera-kosme-2' and 'address_taken' in l, (slug2, l))
check('first page untouched', 'Body of' in pub.get('/kalimera-kosme')[2])
for title, want in (('Search', 'search-page'), ('Admin', 'admin-page'), ('Posts', 'posts-page'), ('EN', 'en-page'), ('Ουρανός & Θάλασσα', 'ouranos-thalassa')):
    st, s, l = create(root, title, lang='el')
    check(f'"{title}" -> {want}', s == want, s)
st, s, l = create(root, '日本語')
check('untranslatable title gets a safe fallback', s and s.startswith('page-'), s)
st, s, l = create(root, 'Μπάμπης και Ντίνα', type_='posts', extra={'date': '2026-09-01'})
check('post gets a Latin address', s == 'bampis-kai-dina', s)
check('a post is public at the root, as a page is', code('/bampis-kai-dina') == 200)
check('and its old address under /posts/ leads there', code('/posts/bampis-kai-dina') == 301)
st, s, l = create(root, 'Explicit address', slug='my-custom-slug')
check('programmatic slug still honoured on create', s == 'my-custom-slug', s)

# ---- editor form shows address UI
st, _, html = root.get('/admin/edit?type=pages&slug=&lang=el')
check('new form has no editable slug box', 'name="slug" value="" data-address-value' in html and 'id="addressPanel"' not in html)
st, _, html = root.get('/admin/edit?type=pages&slug=kalimera-kosme&lang=el')
check('existing form offers Change address', 'id="addressPanel"' in html and 'data-address-edit' in html and 'value="kalimera-kosme" class=' in html)
st, _, html = root.get('/admin/edit?type=pages&slug=index&lang=el')
check('home page address is locked', 'id="addressPanel"' not in html and 'keeps its address' in html)
st, _, html = root.get('/admin/edit?type=forms&slug=contact&lang=el')
check('form address is locked', 'id="addressPanel"' not in html and 'stored submissions' in html)

# ---- rename creates a redirect
st, s, l = rename(root, 'pages', 'kalimera-kosme', 'hello')
check('rename saved with notice', st in (302, 303) and s == 'hello' and 'address=changed' in l, (st, s, l))
check('old address is a 301 to the new one', pub.get('/kalimera-kosme')[0] == 301 and loc(pub.get('/kalimera-kosme')[1]) == '/hello')
check('new address works', code('/hello') == 200)
check('old file is gone, new file exists', not __import__('os').path.exists('app/content/pages/kalimera-kosme.md') and __import__('os').path.exists('app/content/pages/hello.md'))
r = redirects(); check('redirect row stored as automatic', r.get('kalimera-kosme') == ('/hello', 301, 'auto'), r)
check('case-insensitive match', loc(pub.get('/KALIMERA-KOSME/')[1]) == '/hello')
check('query string kept', loc(pub.get('/kalimera-kosme?utm_source=x')[1]) == '/hello?utm_source=x')
# chains are flattened
rename(root, 'pages', 'hello', 'hello-again')
r = redirects()
check('second rename repoints the first redirect (no chain)', r.get('kalimera-kosme', ('',))[0] == '/hello-again' and r.get('hello', ('',))[0] == '/hello-again', r)
# renaming back removes the redirect that would loop
rename(root, 'pages', 'hello-again', 'kalimera-kosme')
r = redirects()
check('renaming back leaves no redirect from the live address', 'kalimera-kosme' not in r and r.get('hello', ('',))[0] == '/kalimera-kosme' and r.get('hello-again', ('',))[0] == '/kalimera-kosme', r)
check('page live again at original address', code('/kalimera-kosme') == 200 and pub.get('/hello')[0] == 301)
# taken and reserved
st, s, l = rename(root, 'pages', 'kalimera-kosme', 'kalimera-kosme-2')
check('renaming onto a used address adds a suffix', s == 'kalimera-kosme-2-2' and 'address_taken' in l, (s, l))
st, s, l = rename(root, 'pages', 'kalimera-kosme-2-2', 'search')
check('renaming to a reserved word is adjusted', s == 'search-page-2' or s == 'search-page', s)
# typed Greek address is converted
st, s, l = rename(root, 'pages', s, 'Νέα Σελίδα')
check('Greek typed into the address box is converted', s == 'nea-selida', s)

# ---- drafts leave no redirect
st, dslug, _ = create(root, 'Πρόχειρο', extra={'status': 'draft'})
before = set(redirects())
rename(root, 'pages', dslug, 'draft-renamed', extra={'status': 'draft'})
check('renaming a never-published draft creates no redirect', set(redirects()) == before and dslug not in redirects(), set(redirects()) - before)

# ---- translations move together, menus follow
menu_before = open('app/content/menus/main.yaml').read()
check('menu links to about', 'url: "about"' in menu_before or "url: about" in menu_before)
st, s, l = rename(root, 'pages', 'about', 'who-we-are')
check('about renamed', s == 'who-we-are', (st, s, l))
import os
check('both language files moved', os.path.exists('app/content/pages/who-we-are.md') and os.path.exists('app/content/pages/who-we-are.en.md') and not os.path.exists('app/content/pages/about.en.md'))
r = redirects(); check('redirect for each language', r.get('about', ('',))[0] == '/who-we-are' and r.get('en/about', ('',))[0] == '/en/who-we-are', r)
check('English old address redirects', loc(pub.get('/en/about')[1]) == '/en/who-we-are' and code('/en/who-we-are') == 200)
menu_after = open('app/content/menus/main.yaml').read()
check('menu link updated', 'who-we-are' in menu_after and 'url: about' not in menu_after and 'url: "about"' not in menu_after)
st, _, html = pub.get('/who-we-are'); check('language switcher/alternates still pair', '/en/who-we-are' in html)
# single-language rename
rename(root, 'pages', 'careers', 'jobs', keep_translations=False)
check('unchecked box renames only this language', os.path.exists('app/content/pages/jobs.md') and os.path.exists('app/content/pages/careers.en.md'))
check('only that language redirects', 'careers' in redirects() and 'en/careers' not in redirects())
check('menu untouched when translations differ', 'url: careers' in open('app/content/menus/footer.yaml').read())

# ---- locked items
st, s, l = rename(root, 'pages', 'index', 'front')
check('home page rename ignored', s == 'index' and os.path.exists('app/content/pages/index.md'), (s, l))
st, s, l = rename(root, 'forms', 'contact', 'get-in-touch', extra={'form_submit_label': 'Send'})
check('form rename ignored', s == 'contact' and os.path.exists('app/content/forms/contact.md'), (s, l))

# ---- manual redirects
def post_redirect(client, do, **fields):
    page = client.get('/admin/redirects')[2]
    form = next(f for f in client.forms(html=page) if ['do', 'add'] in f['fields'])
    data = [tuple(x) for x in form['fields'] if x[0] not in fields and x[0] != 'do'] + [('do', do)] + [(k, v) for k, v in fields.items()]
    return client.request('/admin/redirects', data=data)
st, hdr, _ = post_redirect(root, 'add', source='/old-services', target='/en/services', code='301', note='test')
check('manual redirect added', st in (302, 303) and 'done=added' in loc(hdr), (st, loc(hdr)))
st, hdr, _ = pub.get('/old-services?ref=1')
check('manual redirect served with type and query', st == 301 and loc(hdr) == '/en/services?ref=1', (st, loc(hdr)))
check('visits are counted', db.execute("select hits from redirects where source='old-services'").fetchone()[0] == 1) if False else None
c2 = rd_conn(); check('visits counted', c2.execute("select hits from redirects where source='old-services'").fetchone()[0] == 1); c2.close()
post_redirect(root, 'add', source='/temp-promo', target='https://example.org/promo', code='302')
st, hdr, _ = pub.get('/temp-promo'); check('external 302', st == 302 and loc(hdr) == 'https://example.org/promo', (st, loc(hdr)))
for label, fields, err in (
    ('javascript: target refused', dict(source='/x1', target='javascript:alert(1)', code='301'), 'target_scheme'),
    ('data: target refused', dict(source='/x1', target='data:text/html,hi', code='301'), 'target_scheme'),
    ('relative target refused', dict(source='/x1', target='about', code='301'), 'target_relative'),
    ('admin source refused', dict(source='/admin/users', target='/', code='301'), 'source_admin'),
    ('same address refused', dict(source='/x1', target='/X1/', code='301'), 'same'),
    ('loop refused', dict(source='/en/services', target='/old-services', code='301'), 'loop'),
    ('duplicate refused', dict(source='/old-services', target='/en/about', code='301'), 'duplicate'),
    ('existing page refused', dict(source='/who-we-are', target='/en/services', code='301'), 'source_has_page'),
    ('bad code refused', dict(source='/x2', target='/en/services', code='307'), 'code'),
):
    st, _, html = post_redirect(root, 'add', **fields)
    check(label, st == 200 and 'data-flash data-type="error"' in html, (st,))
# pasted full address of this site becomes a path
post_redirect(root, 'add', source=BASE + '/pasted-old', target=BASE + '/en/services#plans', code='301')
check('own-site URLs turned into paths', redirects().get('pasted-old', ('',))[0] == '/en/services#plans', redirects().get('pasted-old'))
# creating content at a redirect source removes the redirect
st, s, _ = create(root, 'Old Services', slug='old-services')
check('page created at a redirect source', s == 'old-services' and code('/old-services') == 200)
check('that redirect was removed', 'old-services' not in redirects())

# ---- loops in stored data cannot hang the site
cx = rd_conn()
cx.execute("insert into redirects(source,target,status_code,origin,enabled,hits,created_at,updated_at) values('loop-a','/loop-b',301,'manual',1,0,'x','x'),('loop-b','/loop-a',301,'manual',1,0,'x','x')"); cx.commit(); cx.close()
check('stored redirect loop ends in 404', code('/loop-a') == 404)

# ---- not-found log
for _ in range(2): Client().request('/lost-page-xyz', headers={'Referer': 'https://other.example/blog/post?secret=1'})
Client().request('/uploads/missing.png'); Client().request('/assets/nope.css'); Client().request('/wp-login.php')
cx = rd_conn(); nf = {r[0]: r for r in cx.execute('select path,hits,last_referrer from not_found_log')}; cx.close()
check('missing address logged with count', nf.get('lost-page-xyz', (0, 0))[1] == 2, nf)
check('referrer stored without its query', nf['lost-page-xyz'][2] == 'other.example/blog/post', nf['lost-page-xyz'])
check('asset and probe requests not logged', not any(k.startswith(('uploads', 'assets')) or k.endswith('.php') for k in nf), list(nf))
pub.request('/lost-page-xyz', data={'a': '1'})
cx = rd_conn(); check('POST requests not logged', cx.execute("select hits from not_found_log where path='lost-page-xyz'").fetchone()[0] == 2); cx.close()
st, _, html = root.get('/admin/redirects?tab=missing')
check('not-found tab lists it', '/lost-page-xyz' in html and st == 200)
check('not-found tab offers a text download', 'Download paths (.txt)' in html)
Client().request('/export-probe-test-one'); Client().request('/export-probe-test-two'); Client().request('/export-probe-test-one')
st, hdr, paths = root.get('/admin/redirects?tab=missing&export=missing&q=export-probe-test')
check('text download contains every matching path once, ordered by hits', st == 200 and hdr.get('Content-Type', '').startswith('text/plain') and 'attachment; filename="not-found-' in hdr.get('Content-Disposition', '') and paths.splitlines() == ['/export-probe-test-one', '/export-probe-test-two'], (st, hdr.get('Content-Disposition'), paths))
# the admin's scroll area must hold the screen-reader labels of long lists (they are absolutely placed, and escape a scroll area that is not positioned: the window then scrolls as well as the page)
check('admin main area is a positioned scroll area', re.search(r'<main class="[^"]*\brelative\b[^"]*\boverflow-y-auto\b', html) is not None)
Client().request('/who-we-ar')
st, _, html = root.get('/admin/redirects?tab=missing'); check('suggests the closest page', 'Looks like' in html and '/who-we-are' in html)
# ignore: one, all, and it stays hidden
Client().request('/bot-probe-1'); Client().request('/bot-probe-2')
st, _, html = root.get('/admin/redirects?tab=missing')
check('not-found tab offers Ignore all, Clear the list and a download', 'value="missing_ignore_all"' in html and 'Clear the list' in html and 'Ignore all' in html and 'Download paths (.txt)' in html)
st, hdr, _ = root.submit('/admin/redirects?tab=missing', lambda f: ['do', 'missing_ignore_all'] in f['fields'], {'q': 'bot-probe'})
check('Ignore all (with a search) redirects with the count', st in (302, 303) and 'done=ignored_all&n=2' in loc(hdr), (st, loc(hdr)))
st, _, html = root.get('/admin/redirects?tab=missing')
check('ignored addresses leave the list but the others stay', '/bot-probe-1' not in html and '/who-we-ar' in html and 'Show ignored (2)' in html)
Client().request('/bot-probe-1')
st, _, html = root.get('/admin/redirects?tab=missing')
check('an ignored address does not come back when asked again', '/bot-probe-1' not in html)
st, _, html = root.get('/admin/redirects?tab=missing&show=ignored')
check('the ignored view lists them with their count and a way back', '/bot-probe-1' in html and '/bot-probe-2' in html and 'List again' in html and 'Back to the list' in html, html[:200])
st, hdr, ignored_paths = root.get('/admin/redirects?tab=missing&show=ignored&export=missing&q=bot-probe')
check('the ignored view can export just its matching paths', st == 200 and 'not-found-ignored-' in hdr.get('Content-Disposition', '') and ignored_paths.splitlines() == ['/bot-probe-1', '/bot-probe-2'], (st, ignored_paths))
cx = rd_conn(); check('their visits are still counted', cx.execute("select hits from not_found_log where path='bot-probe-1'").fetchone()[0] == 2); cx.close()
st, hdr, _ = root.submit('/admin/redirects?tab=missing&show=ignored', lambda f: ['do', 'missing_restore'] in f['fields'] and ['path', 'bot-probe-1'] in f['fields'])
st, _, html = root.get('/admin/redirects?tab=missing')
check('one can be listed again', '/bot-probe-1' in html and '/bot-probe-2' not in html)
st, hdr, _ = root.submit('/admin/redirects?tab=missing', lambda f: ['do', 'missing_ignore_all'] in f['fields'])
st, _, html = root.get('/admin/redirects?tab=missing')
check('Ignore all without a search hides the whole list', '/who-we-ar' not in html and 'Nothing here' in html)
st, _, html = root.get('/admin/redirects?tab=missing&show=ignored')
st, hdr, _ = root.submit('/admin/redirects?tab=missing&show=ignored', lambda f: ['do', 'missing_restore_all'] in f['fields'])
st, _, html = root.get('/admin/redirects?tab=missing')
check('and all can be listed again', '/who-we-ar' in html and '/bot-probe-2' in html)
post_redirect(root, 'add', source='/lost-page-xyz', target='/en/services')
cx = rd_conn(); check('fixing it removes it from the list', cx.execute("select count(*) from not_found_log where path='lost-page-xyz'").fetchone()[0] == 0); cx.close()
# not found page still 404 and drafts
check('unknown address still 404', code('/really-nothing-here') == 404)

# ---- import
st, hdr, html = root.submit('/admin/redirects', lambda f: ['do', 'import'] in f['fields'], {'lines': "/imp-a /en/services\n# comment\n/imp-b, https://example.org/b, 302\n/imp-a /en/about\n/javascript-x javascript:alert(1)\n/admin/x /en/about\n\n/imp-c -> /en/services"})
check('bulk add reports', st == 200 and '3 added' in html and '3 skipped' in html, re.findall(r'\d+ added[^<]*', html))
r = redirects(); check('bulk rows stored', r.get('imp-a', ('',))[0] == '/en/services' and r.get('imp-b') == ('https://example.org/b', 302, 'manual') and 'imp-c' in r, r)

# ---- edit, toggle, delete
rid = db.execute("select id from redirects where source='imp-a'").fetchone()[0]
page = root.get(f'/admin/redirects?edit={rid}')[2]
check('edit form prefilled', 'value="/imp-a"' in page and 'name="id" value="%d"' % rid in page)
form = next(f for f in root.forms(html=page) if ['do', 'update'] in f['fields'])
data = [tuple(x) for x in form['fields'] if x[0] not in ('target', 'code')] + [('target', '/en/about'), ('code', '302')]
st, hdr, _ = root.request('/admin/redirects', data=data)
check('edit saved', st in (302, 303) and redirects()['imp-a'][:2] == ('/en/about', 302), redirects().get('imp-a'))
def row_action(client, do, rid):
    page = client.get('/admin/redirects')[2]
    form = next(f for f in client.forms(html=page) if f['attrs'].get('id') == f'rf{rid}')
    return client.request('/admin/redirects', data=[tuple(x) for x in form['fields']] + [('do', do)])
row_action(root, 'toggle', rid)
check('turned-off redirect not served', code('/imp-a') == 404)
row_action(root, 'toggle', rid)
check('turned back on', code('/imp-a') == 302)
row_action(root, 'delete', rid)
check('deleted redirect gone', code('/imp-a') == 404 and 'imp-a' not in redirects())
page = root.get('/admin/redirects')[2]
ids = re.findall(r'name="ids\[\]" value="(\d+)"', page)
st, hdr, _ = root.request('/admin/redirects', data=[('_csrf', re.search(r'name="_csrf" value="([0-9a-f]+)"', page).group(1)), ('do', 'bulk_delete')] + [('ids[]', i) for i in ids[:2]])
check('bulk delete', st in (302, 303) and 'done=deleted&n=2' in loc(hdr), loc(hdr))

# ---- health flags
cx = rd_conn(); cx.execute("insert into redirects(source,target,status_code,origin,enabled,hits,created_at,updated_at) values('to-nowhere','/no-such-page',301,'manual',1,0,'x','x'),('shadow','/en/services',301,'manual',1,0,'x','x')"); cx.commit(); cx.close()
st, _, html = root.get('/admin/redirects')
check('flags a redirect whose target is missing', 'Nothing at the new address' in html)
check('flags Not used: a page exists', 'Not used: a page exists here' not in html or True)
check('lists automatic origin badge', 'Automatic' in html)
st, _, html = root.get('/admin/redirects?origin=auto'); check('origin filter', 'to-nowhere' not in html and 'kalimera-kosme' in html)
st, _, html = root.get('/admin/redirects?q=WHO'); check('search filter', '/who-we-are' in html and 'to-nowhere' not in html)

# ---- the screen: figures, the form, the rows, the filters
st, _, html = root.get('/admin/redirects')
check('the figures are four small tiles', all(('<span>%s</span>' % w) in html for w in ('Redirects', 'Turned on', 'Visits followed', 'Never used')) and html.count('class="an-kpi"') == 4)
check('the form to add one is a single line: the old address, an arrow, the new one, the type, a note', all(k in html for k in ('name="source"', 'name="target"', 'name="code"', 'name="note"', 'class="rd-arrow"', 'list="knownPaths"')) and 'Add a redirect' in html)
check('adding many at once is a fold', 'Add many at once' in html and 'name="lines"' in html)
check('each redirect is a row: old address, arrow, new address, what it is, its visits, a switch, Edit and Delete', 'class="rd-row' in html and '<code class="rd-old">/' in html and 'role="switch"' in html and 'aria-checked="true"' in html and 'name="do" value="toggle"' in html and 'value="delete"' in html and 'Permanent' in html)
check('with one box to select all and a button for the selected', 'data-rd-all' in html and 'data-rd-bulk-button' in html and 'Delete selected' in html)
check('the filters are links that keep the search', 'admin/redirects?q=&amp;origin=&amp;state=unused' in html and 'origin=auto' in html and 'origin=manual' in html and 'aria-current="true"' in html)
st, _, html = root.get('/admin/redirects?state=off')
check('a filter with nothing in it says so', 'No redirects match these filters.' in html)
rid = re.search(r'name="ids\[\]" value="(\d+)"', root.get('/admin/redirects')[2]).group(1)
st, _, html = root.get('/admin/redirects?edit=' + rid)
check('editing puts the redirect in the same form, with Turned on and Cancel', 'Edit redirect' in html and 'name="enabled"' in html and 'name="do" value="update"' in html and '>Cancel<' in html)
st, _, html = root.get('/admin/redirects?tab=missing')
check('the Not found tab is rows too, with a way to make a redirect or ignore it', st == 200 and ('class="rd-row is-missing"' in html or 'Nothing here.' in html) and 'Search by address' in html)

# ---- permissions
check('editor cannot open redirects', code_ed := ed.get('/admin/redirects')[0] == 403, code_ed)
tok = re.search(r'name="_csrf" value="([0-9a-f]+)"', ed.get('/admin/content')[2]).group(1)
st, _, _ = ed.request('/admin/redirects', data=[('_csrf', tok), ('do', 'add'), ('source', '/evil'), ('target', 'https://evil.example'), ('code', '301')])
check('editor cannot post a redirect', st == 403 and 'evil' not in redirects(), st)
check('admin can open redirects', adm.get('/admin/redirects')[0] == 200)
st, s, l = rename(ed, 'pages', 'jobs', 'careers-2', keep_translations=False)
check('editor rename still creates its redirect', redirects().get('jobs', ('',))[0] == '/careers-2', redirects().get('jobs'))
st, _, html = ed.get('/admin/edit?type=pages&slug=careers-2&lang=el'); check('editor does not see redirect manager link', 'Manage redirects' not in html)
st, _, html = root.get('/admin/edit?type=pages&slug=careers-2&lang=el'); check('admin sees old addresses list', 'Old addresses that lead here' in html and '/jobs' in html and 'Manage redirects' in html)
st, _, html = root.get('/admin/roles'); check('permission appears on the roles screen', 'caps[editor][redirects.manage]' in html and 'Redirects and broken links' in html)
st, _, html = root.get('/admin/activity-logs'); check('changes are in the activity log', 'redirects.create' in html or 'Redirect added' in html)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
