import re, sys, sqlite3, urllib.parse
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + str(detail)))
    if not ok: fails.append(label)

db = lambda: sqlite3.connect('app/storage/db/app.sqlite')
def redirect_rows(): return {r[0]: (r[1], r[2], r[3], r[4]) for r in db().execute('select source,target,status_code,origin,content_type from redirects')}
def loc(h): return h.get('Location') or ''

root = Client(); root.login()
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
root.submit('/admin/users-edit', has_field('username'), {'username': 'usr1', 'email': 'usr1@example.test', 'display_name': 'usr1', 'role': 'user', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed, usr = Client(), Client(); ed.login('ed1', 'Sturdy-pass-99'); usr.login('usr1', 'Sturdy-pass-99')
pub = Client()

def is_tax_form(f): return any(x[0] == 'taxonomy_title' for x in f['fields'])
def save(client, taxonomy, overrides=None, drop=None):
    return client.submit('/admin/taxonomies?taxonomy=' + taxonomy, is_tax_form, overrides, drop=drop)
def asave(client, taxonomy, overrides=None, drop=None):
    """Saves the layout of a taxonomy's pages the way Theme > Archive Layouts does: the fields of its card, then the changes.
    `archive[x]` names are those of the card, `archive_taxonomies[<taxonomy>][x]` in the form."""
    prefix = 'archive_taxonomies[%s][' % taxonomy
    rename = lambda k: prefix + k[len('archive['):]
    over = {rename(k): v for k, v in (overrides or {}).items()}
    gone = {rename(k) for k in (drop or [])}
    form = next(f for f in client.forms('/admin/theme?tab=archive_layouts') if any(x[0] == 'active_tab' for x in f['fields']))
    fields = [tuple(x) for x in form['fields'] if x[0] not in over and x[0] not in gone and x[0] != 'active_tab']
    for k, v in over.items():
        for item in (v if isinstance(v, list) else [v]):
            fields.append((k, item))
    fields.append(('active_tab', 'archive_layouts'))
    return client.request('/admin/theme', data=fields)
def terms_of(taxonomy):
    """Current rows of the form as (id, slug, {lang: name})."""
    f = next(f for f in root.forms('/admin/taxonomies?taxonomy=' + taxonomy) if is_tax_form(f))
    ids = [v for k, v in f['fields'] if k == 'term_id[]']
    slugs = [v for k, v in f['fields'] if k == 'term_slug[]']
    langs = {}
    for k, v in f['fields']:
        m = re.match(r'term_label\[(\w+)\]\[\]', k)
        if m: langs.setdefault(m.group(1), []).append(v)
    return [(ids[i], slugs[i], {l: langs[l][i] for l in langs}) for i in range(len(ids))]
def save_terms(client, taxonomy, rows, extra=None, drop=None):
    """rows: (id, slug, el, en)"""
    ov = {'term_id[]': [r[0] for r in rows], 'term_slug[]': [r[1] for r in rows], 'term_label[el][]': [r[2] for r in rows], 'term_label[en][]': [r[3] for r in rows]}
    ov.update(extra or {})
    return save(client, taxonomy, ov, drop)

# ---- the screen
st, _, html = root.get('/admin/taxonomies?taxonomy=tags')
check('screen loads', st == 200 and 'How its pages look' in html)
check('the layout of its pages is edited in Theme, with a link to it', 'archive[layout]' not in html and 'tab=archive_layouts' in html)
st, _, th = root.get('/admin/theme?tab=archive_layouts')
check('where it has the same options a content type has', all(('name="archive_taxonomies[tags][%s]"' % k) in th for k in ('layout', 'columns', 'order', 'per_page', 'title', 'subtitle', 'show_image', 'show_excerpt', 'show_date', 'show_meta')))
check('filters offered are the other taxonomies', re.findall(r'name="archive_taxonomies\[tags\]\[taxonomies\]\[\]" value="([a-z-]+)"', th) == ['categories'], re.findall(r'name="archive_taxonomies\[tags\]\[taxonomies\]\[\]" value="([a-z-]+)"', th))
check('content types can be chosen', 'archive_taxonomies[tags][types][]' in th and 'value="posts"' in th and 'value="projects"' in th)
check('existing terms show their usage', 'entr' in html and 'data-used=' in html)
check('the screen is a list of terms with search, add, sort and a settings tab', all(k in html for k in ('data-term-rows', 'data-term-search', 'data-term-add', 'data-term-sort', 'data-tab="settings"', 'data-term-dialog', 'role="tablist"')))
check('taxonomies are switched with links that show their size', 'href="/admin/taxonomies?taxonomy=categories"' in html.replace('http://127.0.0.1', '') or 'taxonomy=categories' in html)
check('every row carries the fields the server reads', all(k in html for k in ('name="term_id[]"', 'name="term_slug[]"', 'name="term_label[el][]"', 'name="term_label[en][]"', 'name="term_description[el][]"', 'name="term_description[en][]"')))
check('a term links to the entries filed under it', re.search(r'/admin/content\?type=posts&(amp;)?taxonomy=tags&(amp;)?term=strategy', html) is not None)
check('the dialog fields are never submitted', not re.search(r'<dialog[^>]*data-term-dialog.*?name="', html, re.S))
# ---- the entries under a term
st, _, html = root.get('/admin/content?type=posts&lang=en&taxonomy=tags&term=strategy')
check('the content list can be narrowed to a term', st == 200 and 'Tags: Strategy' in html and 'aria-label="Remove Tags Strategy"' in html and 'Rebrand' in html, st)
st, _, html = root.get('/admin/content?type=posts&lang=en&taxonomy=tags&term=growth')
check('a term nothing in that list uses gives an empty list', st == 200 and 'aria-label="Remove Tags ' in html and 'When Is It Time' not in html)
st, _, html = root.get('/admin/content?type=posts&lang=en&taxonomy=tags&term=nonexistent')
check('an unknown term is ignored, not an error', st == 200 and 'Filed under' not in html)
st, _, html = root.get('/admin/content?type=posts&lang=en&taxonomy=nothing&term=strategy')
check('so is an unknown taxonomy', st == 200 and 'Filed under' not in html)
st, _, html = root.get('/admin/content-types?type=posts')
check('the content type screen links to the archive layouts instead of holding them', 'archive[layout]' not in html and 'tab=archive_layouts' in html)
st, hdr, _ = root.submit('/admin/content-types?type=posts', lambda f: any(x[0] == 'label' for x in f['fields']), {'label': 'Posts'})
check('content type still saves', st == 302 and 'saved=1' in loc(hdr), loc(hdr))

# ---- defaults
st, _, html = pub.get('/en/tag/strategy')
check('tag page lists posts and projects together', 'Rebrand' in html and 'Gamma Hospitality Rebrand' in html, st)
check('default layout is cards', 'block--cards' in html)
check('default title', '<h1>Tag: Strategy</h1>' in html)
st, _, html = pub.get('/en/category/news'); check('category page has the default layout too', 'block--cards' in html)

# ---- each taxonomy has its own layout
st, hdr, _ = asave(root, 'tags', {'archive[layout]': 'list', 'archive[per_page]': '1', 'archive[order]': 'title_asc', 'archive[title]': 'Articles about {term}', 'archive[subtitle]': 'Everything filed under {term}'})
check('saving tags succeeds', st == 302 and 'saved=1' in loc(hdr), loc(hdr))
st, _, html = pub.get('/en/tag/strategy')
shown = lambda h: h[h.find('<body'):]  # the page itself: a small block stylesheet in its head names the same classes
check('tag page uses the chosen layout', 'block--list' in shown(html) and 'block--cards' not in shown(html))
check('tag page uses the chosen title and subtitle', '<h1>Articles about Strategy</h1>' in html and 'Everything filed under Strategy' in html, re.findall(r'<h1>[^<]*</h1>', html))
check('tag page is paged', 'class="pagination"' in html and 'rel="next"' in html)
st, _, page2 = pub.get('/en/tag/strategy?page=2')
check('second page shows the other entry', st == 200 and 'aria-current="page"' in page2 and ('Gamma Hospitality Rebrand' in page2) != ('Gamma Hospitality Rebrand' in html), st)
check('second page has its own canonical address', 'rel="canonical" href="' in page2 and 'page=2' in re.search(r'rel="canonical" href="([^"]*)"', page2).group(1))
first_title = 'Gamma Hospitality Rebrand' in html
check('order is applied (title A to Z)', first_title, 'first page should show Gamma before Rebrand')
st, _, html = pub.get('/en/category/news')
check('categories are untouched by the tags choice', 'block--cards' in html and '<h1>Category: News</h1>' in html and 'class="pagination"' not in html)
st, _, html = pub.get('/tag/strategy')
check('other language keeps the choices and its own names', 'block--list' in html and 'Στρατηγική' in html, re.findall(r'<h1>[^<]*</h1>', html))

# a different choice for categories
asave(root, 'categories', {'archive[layout]': 'compact', 'archive[title]': 'Stories: {term}'})
st, _, html = pub.get('/en/category/news')
check('categories have their own layout', 'block--compact' in html and '<h1>Stories: News</h1>' in html, re.findall(r'<h1>[^<]*</h1>', html))
st, _, html = pub.get('/en/tag/strategy?page=1'); check('and tags did not change', 'block--list' in html)

# filters and content types
asave(root, 'tags', {'archive[taxonomies][]': ['categories'], 'archive[per_page]': '0'})
st, _, html = pub.get('/en/tag/strategy')
check('a filter from another taxonomy is offered', 'name="filter[categories]"' in html and 'archive-filters' in html)
st, _, html = pub.get('/en/tag/strategy?filter[categories]=news')
check('choosing it narrows the list and asks search engines to skip it', 'Gamma Hospitality Rebrand' not in html and 'Rebrand' in html and 'noindex' in html)
asave(root, 'tags', {'archive[types][]': ['posts']})
st, _, html = pub.get('/en/tag/strategy')
check('only the chosen content types are listed', 'Gamma Hospitality Rebrand' not in html and 'Rebrand' in html)
asave(root, 'tags', {'archive[types][]': ['posts', 'projects']})
st, _, html = pub.get('/en/tag/strategy')
check('choosing every type is the same as choosing none', 'Gamma Hospitality Rebrand' in html)
st, _, html = root.get('/admin/theme?tab=archive_layouts')
check('the Theme screen shows what is stored', re.search(r'name="archive_taxonomies\[tags\]\[layout\]" value="list" checked', html) is not None)
check('the layout file holds only differences', 'types' not in open('app/content/taxonomies/tags.yaml').read() and 'layout: list' in open('app/content/taxonomies/tags.yaml').read())
# turning things off
asave(root, 'tags', {}, drop=['archive[show_image]', 'archive[show_excerpt]', 'archive[show_date]', 'archive[show_meta]'])
st, _, html = pub.get('/en/tag/strategy'); check('switching parts off hides them', 'entry-excerpt' not in html and st == 200, st)

# ---- who can change it
st, _, html = ed.get('/admin/taxonomies'); check('editor can open taxonomies, but is not sent to a screen that is not theirs', st == 200 and 'How its pages look' in html and 'tab=archive_layouts' not in html)
st, hdr, _ = save(ed, 'categories'); check('editor can save a taxonomy', st == 302 and 'saved=1' in loc(hdr), st)
st, _, _ = usr.get('/admin/taxonomies'); check('basic user cannot', st in (302, 403), st)
tok = re.search(r'name="_csrf" value="([0-9a-f]+)"', usr.get('/admin')[2]).group(1)
st, _, _ = usr.request('/admin/taxonomies', data=[('_csrf', tok), ('taxonomy', 'tags'), ('archive[layout]', 'list')]); check('basic user cannot save either', st in (302, 403), st)
st, _, html = pub.get('/en/category/news'); check('...and saving a taxonomy leaves the layout of its pages as it was', 'block--compact' in html and '<h1>Stories: News</h1>' in html)

# ---- addresses of terms are made from their names
before = terms_of('tags')
check('existing terms keep their id and address', ('strategy', 'strategy') in [(t[0], t[1]) for t in before], before)
rows = [(i, s, l['el'], l['en']) for i, s, l in before] + [('', '', 'Ελληνική κουζίνα', 'Greek cuisine'), ('', '', 'Ουρανός', '')]
st, hdr, _ = save_terms(root, 'tags', rows)
after = terms_of('tags')
check('a new term gets a Latin address from its name', ('elliniki-kouzina', 'elliniki-kouzina') in [(t[0], t[1]) for t in after], after)
check('so does one named only in the default language', ('ouranos', 'ouranos') in [(t[0], t[1]) for t in after])
check('the new term has a public page', pub.get('/tag/elliniki-kouzina')[0] == 200 and pub.get('/en/tag/elliniki-kouzina')[0] == 200)
check('and shows its name', 'Greek cuisine' in pub.get('/en/tag/elliniki-kouzina')[2])
st, hdr, _ = save_terms(root, 'tags', [(i, s, l['el'], l['en']) for i, s, l in after] + [('', '', 'Greek cuisine', '')])
after2 = terms_of('tags')
check('a name used before gets -2, not a clash', len([t for t in after2 if t[1].startswith('greek-cuisine')]) == 1 and any(t[1] == 'greek-cuisine' for t in after2), after2)

# ---- changing an address leaves a redirect
rows = [(i, 'stratigiki' if i == 'strategy' else s, l['el'], l['en']) for i, s, l in terms_of('tags')]
st, hdr, _ = save_terms(root, 'tags', rows)
check('changing an address is reported', 'moved=1' in loc(hdr), loc(hdr))
rd = redirect_rows()
check('the old address redirects in the default language', rd.get('tag/strategy', ('',))[0] == '/tag/stratigiki' and rd['tag/strategy'][1] == 301 and rd['tag/strategy'][2] == 'auto', rd.get('tag/strategy'))
check('and in the other language', rd.get('en/tag/strategy', ('',))[0] == '/en/tag/stratigiki', rd.get('en/tag/strategy'))
check('the redirect knows what it belongs to', rd['tag/strategy'][3] == 'tags', rd['tag/strategy'])
st, hdr, _ = pub.get('/en/tag/strategy'); check('visitors are sent to the new address', st == 301 and loc(hdr).endswith('/en/tag/stratigiki'), (st, loc(hdr)))
st, _, html = pub.get('/en/tag/stratigiki'); check('the new address shows the same entries', st == 200 and 'Gamma Hospitality Rebrand' in html and 'Rebrand' in html)
check('entries are still filed under the same term', 'strategy' in open('app/content/posts/rebrand-readiness-guide.md').read())
check('the label is unchanged', 'Στρατηγική' in pub.get('/tag/stratigiki')[2])
st, _, html = root.get('/admin/redirects'); check('the redirect appears on the redirects screen', '/tag/strategy' in html or 'tag/strategy' in html)
# and back again: no chain, no loop
rows = [(i, 'strategy' if i == 'strategy' else s, l['el'], l['en']) for i, s, l in terms_of('tags')]
save_terms(root, 'tags', rows)
rd = redirect_rows()
check('going back removes the redirect that would loop', 'tag/strategy' not in rd and rd.get('tag/stratigiki', ('',))[0] == '/tag/strategy', rd)
check('and the address works again', pub.get('/tag/strategy')[0] == 200)
# an address typed in Greek
rows = [(i, 'Στρατηγική' if i == 'strategy' else s, l['el'], l['en']) for i, s, l in terms_of('tags')]
save_terms(root, 'tags', rows)
check('an address typed in Greek is converted', pub.get('/tag/stratigiki')[0] == 200)

# ---- descriptions
def with_description(text, term='strategy'):
    f = next(f for f in root.forms('/admin/taxonomies?taxonomy=tags') if is_tax_form(f))
    ids = [v for k, v in f['fields'] if k == 'term_id[]']
    el = [text if i == term else '' for i in ids]
    return root.submit('/admin/taxonomies?taxonomy=tags', is_tax_form, {'term_description[el][]': el, 'term_description[en][]': [('About growth strategy' if i == term else '') for i in ids]})
asave(root, 'tags', {'archive[subtitle]': ''})
st, hdr, _ = with_description('Ό,τι αφορά τη στρατηγική')
check('a description saves', st == 302 and 'saved=1' in loc(hdr), loc(hdr))
st, _, html = root.get('/admin/taxonomies?taxonomy=tags')
check('and comes back in the form', 'value="Ό,τι αφορά τη στρατηγική"' in html or 'Ό,τι αφορά τη στρατηγική' in html)
st, _, html = pub.get('/tag/stratigiki')
check('the term page shows it under the title', 'Ό,τι αφορά τη στρατηγική' in html, re.findall(r'<h1>[^<]*</h1>', html))
st, _, html = pub.get('/en/tag/stratigiki')
check('in the language of the page', 'About growth strategy' in html)
asave(root, 'tags', {'archive[subtitle]': 'Own subtitle for {term}'})
st, _, html = pub.get('/en/tag/stratigiki')
check('a subtitle written for the taxonomy takes its place', 'Own subtitle for Strategy' in html and 'About growth strategy' not in html)
asave(root, 'tags', {'archive[subtitle]': ''})
# a form with no description boxes at all does not wipe them
f = next(f for f in root.forms('/admin/taxonomies?taxonomy=tags') if is_tax_form(f))
save(root, 'tags', {}, drop=['term_description[el][]', 'term_description[en][]'])
check('a form sent without description boxes leaves them', 'About growth strategy' in pub.get('/en/tag/stratigiki')[2])

# ---- a term chooses how its entries open
st, _, html = root.get('/admin/taxonomies?taxonomy=tags')
check('every row carries a title area, and the dialog offers the theme\'s styles', 'name="term_hero_layout[]"' in html and 'data-dialog-hero' in html and all(('value="%s"' % k) in html.split('data-dialog-hero')[1] for k in ('default', 'centered', 'split', 'cover', 'minimal')))
st, _, html = pub.get('/en/rebrand-readiness-guide')
check('without a choice the entry opens as its type says', 'single-hero-centered' not in html and 'single-hero-minimal' not in html, st)
rows = terms_of('tags')
hero = ['minimal' if i == 'strategy' else '' for i, s, l in rows]
st, hdr, _ = save_terms(root, 'tags', [(i, s, l['el'], l['en']) for i, s, l in rows], {'term_hero_layout[]': hero})
check('saving a title area for a term', 'saved=1' in loc(hdr), loc(hdr))
st, _, html = root.get('/admin/taxonomies?taxonomy=tags')
check('shows it in the row', 'Title area: <span data-term-hero-label>' in html and re.search(r'name="term_hero_layout\[\]" value="minimal"', html) is not None)
st, _, html = pub.get('/en/rebrand-readiness-guide')
check('and the entries filed under the term open with it', 'single-hero-minimal' in html, st)
open('app/content/posts/rebrand-readiness-guide.en.md', 'a', encoding='utf-8').write('')
src = open('app/content/posts/rebrand-readiness-guide.en.md', encoding='utf-8').read()
open('app/content/posts/rebrand-readiness-guide.en.md', 'w', encoding='utf-8').write(src.replace("status: published\n", "status: published\nhero_layout: centered\n", 1))
st, _, html = pub.get('/en/rebrand-readiness-guide')
check('an entry\'s own choice wins over its term\'s', 'single-hero-centered' in html and 'single-hero-minimal' not in html, st)
open('app/content/posts/rebrand-readiness-guide.en.md', 'w', encoding='utf-8').write(src)
st, hdr, _ = save_terms(root, 'tags', [(i, s, l['el'], l['en']) for i, s, l in rows], {'term_hero_layout[]': ['' for _ in rows]})
check('an empty choice takes it away', 'single-hero-minimal' not in pub.get('/en/rebrand-readiness-guide')[2])
rows = terms_of('tags')

# ---- removing a term
rows = [(i, s, l['el'], l['en']) for i, s, l in terms_of('tags') if i != 'growth']
st, hdr, _ = save_terms(root, 'tags', rows)
check('removing a term that entries use takes it off them', 'removed=1' in loc(hdr) and 'unfiled=' in loc(hdr), loc(hdr))
st, _, html = root.get(loc(hdr)); check('the screen says so, and names no entry still filed under it', 'taken off the' in html and 'no longer has:' not in html)
check('the removed term has no page', pub.get('/tag/growth')[0] == 404)

# ---- a bad id cannot smuggle anything in
rows = [(i, s, l['el'], l['en']) for i, s, l in terms_of('tags')] + [('../../x', '../evil', 'Evil', '')]
save_terms(root, 'tags', rows)
check('odd characters are cleaned', all(re.fullmatch(r'[a-z0-9\-_]+', t[0]) and re.fullmatch(r'[a-z0-9\-_]+', t[1]) for t in terms_of('tags')), terms_of('tags'))

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
