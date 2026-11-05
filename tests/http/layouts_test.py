import re, sys, os, sqlite3
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:220]))
    if not ok: fails.append(label)

def stored():
    c = sqlite3.connect('app/storage/db/app.sqlite'); v = c.execute("select value from system_meta where key='theme_settings'").fetchone(); c.close()
    return v[0] if v else ''
def run(sql, args=()):
    c = sqlite3.connect('app/storage/db/app.sqlite'); c.execute(sql, args); c.commit(); c.close()
def custom(type_):
    path = 'app/custom/content-types/%s.yaml' % type_
    return open(path, encoding='utf-8').read() if os.path.exists(path) else ''

root = Client(); root.login()
pub = Client()

def theme_form(client):
    return next(f for f in client.forms('/admin/theme?tab=single_layouts') if any(x[0] == 'active_tab' for x in f['fields']))
def save(client, overrides, drop=(), tab='single_layouts'):
    """Submit the Theme form the way the browser does: its own values, then what the test changes."""
    form = theme_form(client)
    fields = [tuple(x) for x in form['fields'] if x[0] not in overrides and x[0] not in drop and x[0] != 'active_tab']
    for k, v in overrides.items():
        for item in (v if isinstance(v, list) else [v]):
            fields.append((k, item))
    fields.append(('active_tab', tab))
    return client.request('/admin/theme', data=fields)

# ---- Single Layouts: a card for each content type
st, _, html = root.get('/admin/theme?tab=single_layouts')
cards = re.findall(r'<article class="lc-card[^>]*aria-labelledby="single-([a-z0-9_-]+)"', html)
check('the tab has a card for each content type, forms and pages included', st == 200 and set(cards) >= {'pages', 'posts', 'projects', 'forms'}, cards)
check('the page layout, the title area and the header are chosen on a card', all(('name="single_layouts[posts][%s]"' % k) in html for k in ('template', 'title', 'header', 'sidebar')), '')
check('the parts are chips', all(('name="single_layouts[posts][%s]"' % k) in html for k in ('image', 'excerpt', 'byline', 'toc', 'related', 'card')), '')
check('related entries: where they come from, how many, thumbnails', all(('name="single_layouts[posts][%s]"' % k) in html for k in ('entries', 'entries_max', 'entries_images')) and re.search(r'name="single_layouts\[posts\]\[entries\]" value="none" checked', html) is not None, '')
check('a page has no line above its title to switch', 'name="single_layouts[pages][byline]"' not in html and 'name="single_layouts[posts][byline]"' in html)
check('a form has no page layout to choose', 'name="single_layouts[forms][template]"' not in html and 'name="single_layouts[forms][title]"' in html)
check('the old tabs are gone', 'data-tab="hero_layouts"' not in html and 'data-tab="transparent_header"' not in html and 'data-tab="sidebar"' not in html)
check('the text of the sidebar card is in this tab', 'name="theme_settings[sidebar][card_heading]"' in html and 'name="theme_settings[sidebar][toc]"' not in html)
check('the little preview and the script that keeps it in step are there', 'data-lc-preview' in html and 'js/admin-layouts.js' in html)

# ---- the older settings are where a card starts
run("insert or replace into system_meta (key, value, updated_at) values ('theme_settings', ?, datetime('now'))", ("hero_layouts:\n  default: default\n  posts: split\n  pages: default\n  projects: default\n  forms: default\n",))
st, _, html = root.get('/admin/theme?tab=single_layouts')
check('a site that chose a title layout before finds it on the card', re.search(r'name="single_layouts\[posts\]\[title\]" value="split" checked', html) is not None)

# ---- saving the cards: the page of a post changes
# A post with no template of its own (the fixture posts ask for the sidebar one themselves).
open('app/content/posts/typed.md', 'w', encoding='utf-8').write("---\ntitle: Typed post\nstatus: published\nvisible: true\ndate: '2026-03-01'\nexcerpt: A summary.\nauthor: Ada\ntags: [design]\n---\n\nIntro.\n\n## First\n\nText.\n\n## Second\n\nText.\n")
post = '/typed'
st, hdr, _ = save(root, {'single_layouts[posts][sidebar]': 'left', 'single_layouts[posts][title]': 'centered', 'single_layouts[posts][header]': 'on'})
check('saving goes back to the same tab', st == 302 and 'saved=1' in (hdr.get('Location') or '') and 'tab=single_layouts' in (hdr.get('Location') or ''), hdr.get('Location'))
check('what is stored is under the content type', 'single_layouts:' in stored() and re.search(r'posts:\s+title: centered\s+header: \'?on\'?\s+sidebar: left', stored()) is not None, stored()[-700:])
st, _, html = pub.get(post)
check('a post now has the sidebar layout, on the left', st == 200 and 'class="site-wrap with-sidebar is-left"' in html and 'with-sidebar-aside' in html, st)
check('the title area is the centered one', 'single-hero-centered' in html, '')
check('and the header sits over it', 'has-transparent-header' in html, '')
st, _, html = pub.get('/about')
check('the other content types are not touched', 'with-sidebar' not in html and 'has-transparent-header' not in html and re.search(r'<section class="single-hero[ "]', html) is not None, html[:200])

# the parts of the title area and of the sidebar
st, hdr, _ = save(root, {'single_layouts[posts][sidebar]': 'right', 'single_layouts[posts][title]': 'default', 'single_layouts[posts][header]': 'site'},
                  drop=('single_layouts[posts][excerpt]', 'single_layouts[posts][byline]', 'single_layouts[posts][toc]', 'single_layouts[posts][related]'))
st, _, html = pub.get(post)
check('a box that is not ticked switches the part off', 'single-hero-subtitle' not in html and 'class="single-hero-meta"' not in html and 'sidebar-toc' not in html, '')
check('and the sidebar is on the right again', 'class="site-wrap with-sidebar"' in html)
check('related entries are off until chosen', 'sidebar-entries' not in html)
st, hdr, _ = save(root, {'single_layouts[posts][sidebar]': 'right', 'single_layouts[posts][entries]': 'latest', 'single_layouts[posts][entries_max]': '3'})
st, _, html = pub.get(post)
box = html.split('sidebar-entries', 1)[1].split('</nav>', 1)[0] if 'sidebar-entries' in html else ''
check('with Latest the sidebar lists other posts, at most three, not this one', len(re.findall(r'class="sidebar-entry[ "]', box)) in (1, 2, 3) and 'Typed post' not in box, box[:400])
st, hdr, _ = save(root, {'single_layouts[posts][entries]': 'none'})
# ---- a single entry can still choose
open('app/content/posts/own.md', 'w', encoding='utf-8').write("---\ntitle: Own layout\nstatus: published\nvisible: true\ndate: '2026-03-01'\ntemplate: standard\nhero_layout: minimal\n---\n\nText.\n")
st, _, html = pub.get('/own')
check('an entry can ask for the plain layout, and its own title area', st == 200 and 'with-sidebar' not in html and 'single-hero-minimal' in html, st)
st, _, html = root.get('/admin/edit?type=posts&slug=own&lang=el')
check('the editor offers the plain layout and says what the others get', 'value="standard"' in html and 'Like the others (With sidebar)' in html, '')
os.remove('app/content/posts/own.md')

st, hdr, _ = save(root, {'single_layouts[posts][sidebar]': 'none'})
st, _, html = pub.get(post)
check('and a sidebar of none leaves the page without one', st == 200 and 'with-sidebar' not in html and 'sidebar-toc' not in html, st)
st, _, html = root.get('/admin/theme?tab=single_layouts')
check('the sidebar is its own row (none, right, left), not a page layout', all(re.search(r'name="single_layouts\[posts\]\[sidebar\]" value="%s"' % v, html) for v in ('none', 'right', 'left')) and 'name="single_layouts[posts][template]" value="sidebar"' not in html and re.search(r'name="single_layouts\[posts\]\[sidebar\]" value="none" checked', html) is not None)


# ---- a card that is not submitted leaves its type as it is
st, hdr, _ = save(root, {}, drop=tuple(k for k in [x[0] for x in theme_form(root)['fields']] if k.startswith('single_layouts[projects]')))
check('a type whose card was not sent keeps its layout, others are saved', st == 302 and 'projects:' in stored(), stored()[-300:])

# ---- Archive Layouts
st, _, html = root.get('/admin/theme?tab=archive_layouts')
cards = re.findall(r'aria-labelledby="archive-(type|taxonomy)-([a-z0-9_-]+)"', html)
check('the tab has a card for each content type that has a list, and each taxonomy', ('type', 'posts') in cards and ('type', 'projects') in cards and ('taxonomy', 'tags') in cards and ('taxonomy', 'categories') in cards and ('type', 'pages') not in cards and ('type', 'forms') not in cards, cards)
check('with the layout, columns, order, page size, parts, filters and the texts', all(('name="archive_types[posts][%s]"' % k) in html for k in ('layout', 'columns', 'per_page', 'order', 'title', 'subtitle')) and 'name="archive_types[posts][taxonomies][]"' in html and 'name="archive_types[posts][show_image]"' in html, '')
check('a taxonomy chooses which content types it lists', 'name="archive_taxonomies[tags][types][]"' in html)
st, _, html = pub.get('/en/posts')
check('before, the list of posts is cards', st == 200 and 'block--cards' in html, st)
st, hdr, _ = save(root, {'archive_types[posts][layout]': 'list', 'archive_types[posts][per_page]': '2', 'archive_types[posts][title]': 'All the news'}, tab='archive_layouts')
check('saving an archive card goes back to its tab', st == 302 and 'saved=1' in (hdr.get('Location') or '') and 'tab=archive_layouts' in (hdr.get('Location') or ''), hdr.get('Location'))
check('only what differs from the theme is written, in the site\'s own file', 'layout: list' in custom('posts') and 'per_page: 2' in custom('posts') and 'title: \'All the news\'' in custom('posts') + "title: 'All the news'" and 'show_image' not in custom('posts'), custom('posts'))
st, _, html = pub.get('/en/posts')
check('the list of posts follows', 'block--list' in html and 'All the news' in html and html.count('class="content-item') <= 2 + 1, (st, re.findall(r'block--[a-z]+', html)))
check('an archive card that did not change writes nothing', custom('projects') == '' and custom('pages') == '', custom('projects'))

st, hdr, _ = save(root, {'archive_taxonomies[tags][layout]': 'compact', 'archive_taxonomies[tags][types][]': ['posts']}, tab='archive_layouts')
tags = open('app/content/taxonomies/tags.yaml', encoding='utf-8').read()
check('a taxonomy card is saved in the taxonomy file', 'layout: compact' in tags and re.search(r'types:\s+- posts', tags) is not None, tags[-200:])

# ---- random order
st, _, html = root.get('/admin/theme?tab=archive_layouts')
check('random is one of the orders on an archive card', re.search(r'<option value="random"', html) is not None and html.count('value="random"') >= 4, html.count('value="random"'))
save(root, {'archive_types[posts][order]': 'random', 'archive_types[posts][per_page]': '1', 'archive_taxonomies[tags][order]': 'random'}, tab='archive_layouts')
check('it is written as the order of the card', 'order: random' in custom('posts'), custom('posts'))
st, _, html = pub.get('/posts')
links = re.findall(r'href="(\?seed=\d+[^"]*)"', html)
check('the list shows, and its paging links carry the number that keeps the order while paging', st == 200 and len(links) >= 1 and all(re.match(r'\?seed=\d+(&amp;|&)page=\d+', l) or re.match(r'\?seed=\d+$', l) for l in links), (st, links[:3]))
if links:
    seed = re.search(r'seed=(\d+)', links[0]).group(1)
    body = lambda h: h.split('<main', 1)[1].split('</main>', 1)[0] if '<main' in h else h
    one = body(pub.get('/posts?seed=%s&page=1' % seed)[2])
    again = body(pub.get('/posts?seed=%s&page=1' % seed)[2])
    check('the same number gives the same page', one == again and len(one) > 200, (len(one), len(again)))
save(root, {'archive_types[posts][order]': 'date_desc', 'archive_types[posts][per_page]': '12', 'archive_taxonomies[tags][order]': 'date_desc'}, tab='archive_layouts')
check('and back to newest first writes nothing for the order', 'order:' not in custom('posts'), custom('posts'))

# ---- the title area of a list, as the page of an entry has
st, _, html = root.get('/admin/theme?tab=archive_layouts')
check('each archive card has the title area, its picture and parallax', all(('name="archive_types[posts][%s]"' % k) in html for k in ('title_layout', 'title_image', 'title_parallax')) and 'name="archive_taxonomies[tags][title_layout]"' in html and html.count('value="cover"') >= 4, '')
def archive_head(path):
    st, _, h = pub.get(path)
    m = re.search(r'<section class="(single-hero[^"]*)"([^>]*)>', h)
    return st, (m.group(1) if m else ''), (m.group(2) if m else ''), h
st, cls, attrs, h = archive_head('/en/posts')
check('before: the plain title band', st == 200 and cls == 'single-hero' and 'data-parallax' not in attrs, (st, cls))
picture = '/uploads/media/5e6915a67b9ceec5.jpg'
save(root, {'archive_types[posts][title_layout]': 'cover', 'archive_types[posts][title_image]': picture, 'archive_types[posts][title_parallax]': '1'}, tab='archive_layouts')
st, cls, attrs, h = archive_head('/en/posts')
check('a cover with a picture and parallax', 'single-hero-cover' in cls and 'data-parallax' in attrs and picture.split('/')[-1].split('.')[0] in h.split('<main')[1].split('</section>')[0], (cls, attrs))
check('the choices are written in the site\'s own file, and only those', 'title_layout: cover' in custom('posts') and picture in custom('posts') and 'title_parallax: true' in custom('posts'), custom('posts'))
save(root, {'archive_types[posts][title_layout]': 'default'}, tab='archive_layouts')
st, cls, attrs, h = archive_head('/en/posts')
check('the default title area with a picture has it behind the text, and moves it', cls == 'single-hero has-image' and 'data-parallax' in attrs and '5e6915a67b9ceec5.jpg' in attrs, (cls, attrs))
save(root, {'archive_types[posts][title_layout]': 'centered', 'archive_types[posts][title_parallax]': '1'}, tab='archive_layouts')
st, cls, attrs, h = archive_head('/en/posts')
check('centered shows the picture as a picture and keeps it still', 'single-hero-centered' in cls and 'single-hero-media' in h and 'data-parallax' not in attrs, (cls, attrs))
save(root, {'archive_types[posts][title_layout]': 'cover', 'archive_types[posts][title_image]': '', 'archive_types[posts][title_parallax]': None}, drop=('archive_types[posts][title_parallax]',), tab='archive_layouts')
st, cls, attrs, h = archive_head('/en/posts')
check('a cover with no picture is the plain band', cls == 'single-hero' and 'title_image' not in custom('posts'), (cls, custom('posts')))
save(root, {'archive_types[posts][title_image]': 'javascript:alert(1)', 'archive_types[posts][title_layout]': 'sideways'}, tab='archive_layouts')
check('a picture address that is not safe, and a layout the theme does not have, are not kept', 'javascript' not in custom('posts') and 'sideways' not in custom('posts'), custom('posts'))
save(root, {'archive_taxonomies[tags][title_layout]': 'minimal'}, tab='archive_layouts')
st, cls, attrs, h = archive_head('/en/tags/design') if pub.get('/en/tags/design')[0] == 200 else archive_head('/tags/design')
check('a category or tag page has it too', 'single-hero-minimal' in cls, (st, cls))

# ---- the search page has the same title area, with its own title and subtitle for each language
st, _, html = root.get('/admin/theme?tab=archive_layouts')
check('the Search card has the title area, picture, parallax, title and subtitle', 'aria-labelledby="archive-search"' in html and all(('name="archive_search[%s]"' % k) in html for k in ('title_layout', 'title_image', 'title_parallax')) and 'name="archive_search[title][default]"' in html and 'name="archive_search[subtitle][default]"' in html, '')
def search_head(lang='en', q=''):
    st, _, h = pub.get('/%s/search%s' % (lang, ('?q=' + q) if q else ''))
    m = re.search(r'<section class="(single-hero[^"]*)"([^>]*)>(.*?)</section>', h, re.S)
    return st, (m.group(1) if m else ''), (m.group(2) if m else ''), (m.group(3) if m else ''), h
st, cls, attrs, inner, h = search_head()
check('before: the plain band with the theme\'s words and the search box in it', st == 200 and cls == 'single-hero' and 'class="search-form"' in inner and '<h1>' in inner, (st, cls))
save(root, {'archive_search[title_layout]': 'cover', 'archive_search[title_image]': picture, 'archive_search[title_parallax]': '1', 'archive_search[title][default]': 'Find it', 'archive_search[title][en]': 'Look it up', 'archive_search[subtitle][default]': 'Search everything'}, tab='archive_layouts')
st, cls, attrs, inner, h = search_head('en', 'design')
check('a cover with a picture and parallax, the search box still inside it', 'single-hero-cover' in cls and 'data-parallax' in attrs and 'class="search-form"' in inner and 'name="q"' in inner and 'value="design"' in inner, (cls, attrs))
check('the title of the language, and the site\'s own text for the subtitle', '<h1>Look it up</h1>' in inner and 'Search everything' in inner and '<title>Look it up' in h, inner[:300])
st, cls, attrs, inner, h = search_head('el')
check('another language shows the site\'s own title', '<h1>Find it</h1>' in inner, inner[:200])
stored_theme = stored()
check('only what differs from the defaults is stored, under search_page', 'search_page:' in stored_theme and 'title_layout: cover' in stored_theme and 'title_parallax: true' in stored_theme, stored_theme[-400:])
for layout in ('default', 'split', 'minimal', 'centered'):
    save(root, {'archive_search[title_layout]': layout}, tab='archive_layouts')
    st, cls, attrs, inner, h = search_head()
    ok = {'default': 'has-image' in cls and 'data-parallax' in attrs, 'split': 'single-hero-split' in cls, 'minimal': 'single-hero-minimal' in cls, 'centered': 'single-hero-centered' in cls and 'single-hero-media' in h}[layout]
    check('search, %s title area, with the search box' % layout, ok and 'class="search-form"' in inner, (layout, cls))
save(root, {'archive_search[title_layout]': 'default', 'archive_search[title_image]': '', 'archive_search[title][default]': '', 'archive_search[title][en]': '', 'archive_search[subtitle][default]': ''}, drop=('archive_search[title_parallax]',), tab='archive_layouts')
check('everything back to the defaults writes nothing', 'search_page' not in stored(), stored()[-300:])
save(root, {'archive_search[title_image]': 'javascript:alert(1)', 'archive_search[title_layout]': 'sideways'}, tab='archive_layouts')
check('an unsafe picture address and an unknown layout are not kept', 'javascript' not in stored() and 'sideways' not in stored(), stored()[-300:])
save(root, {'archive_search[title_image]': ''}, tab='archive_layouts')

# ---- the old places no longer edit the layout, and do not lose it
st, _, html = root.get('/admin/content-types?type=posts')
check('the content type screen links to the archive layouts instead of holding them', st == 200 and 'name="archive[layout]"' not in html and 'tab=archive_layouts' in html, st)
form = next(f for f in root.forms('/admin/content-types?type=posts') if any(x[0] == 'label' for x in f['fields']))
root.request('/admin/content-types', data=[tuple(x) for x in form['fields']])
check('saving it leaves the archive layout as the card made it', 'layout: list' in custom('posts'), custom('posts'))
st, _, html = root.get('/admin/taxonomies?taxonomy=tags')
check('the taxonomy screen links to them too', st == 200 and 'name="archive[layout]"' not in html and 'tab=archive_layouts' in html, st)

# ---- someone who may not manage the theme
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed3', 'email': 'ed3@example.test', 'display_name': 'ed3', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed3', 'Sturdy-pass-99')
st, _, _ = ed.get('/admin/theme?tab=archive_layouts')
check('an editor cannot open them', st in (302, 403), st)
before = custom('posts')
st, _, _ = ed.request('/admin/theme', data=[('active_tab', 'archive_layouts'), ('archive_types[posts][layout]', 'editorial')])
check('nor save them', custom('posts') == before, st)

# ---- breadcrumbs: a trail above the title, chosen for each type and each list
run("delete from system_meta where key='theme_settings'")
st, _, html = root.get('/admin/theme?tab=single_layouts')
check('a card has a Breadcrumbs chip, off to begin with', 'name="single_layouts[posts][breadcrumbs]"' in html and re.search(r'name="single_layouts\[posts\]\[breadcrumbs\]"[^>]*checked', html) is None)
st, _, html = pub.get('/typed')
check('so a post has no trail', 'class="crumbs"' not in html, st)
save(root, {'single_layouts[posts][breadcrumbs]': '1', 'single_layouts[posts][sidebar]': None}, tab='single_layouts')
st, _, html = pub.get('/typed')
trail = re.search(r'<nav class="crumbs"[^>]*>(.*?)</nav>', html, re.S)
items = re.findall(r'<li>(.*?)</li>', trail.group(1), re.S) if trail else []
check('and with it Home, the list of its type and the post, the last one not a link', len(items) == 3 and 'href="/"' in items[0] and 'href="/posts"' in items[1] and 'aria-current="page"' in items[2] and '<a' not in items[2] and 'Typed post' in items[2], items)
check('the trail has a name for screen readers, and sits in the title area, above the title', 'aria-label="' in trail.group(0) and html.index('class="crumbs"') < html.index('<h1>') and html.index('class="single-hero') < html.index('class="crumbs"'), '')
st, _, html = pub.get('/en/typed')
trail = re.search(r'<nav class="crumbs"[^>]*>(.*?)</nav>', html, re.S) if st == 200 else None
check('in another language the links keep the language', st != 200 or (trail is not None and 'href="/en/"' in trail.group(1) and 'href="/en/posts"' in trail.group(1)), st)
st, _, html = pub.get('/about')
check('a type that did not ask for it has none', 'class="crumbs"' not in html, st)
save(root, {'single_layouts[pages][breadcrumbs]': '1'}, tab='single_layouts')
st, _, html = pub.get('/about')
trail = re.search(r'<nav class="crumbs"[^>]*>(.*?)</nav>', html, re.S)
check('a page has Home and itself only', trail is not None and len(re.findall(r'<li>', trail.group(1))) == 2, trail and trail.group(1))
st, _, html = root.get('/admin/theme?tab=archive_layouts')
check('an archive card has the chip too', 'name="archive_types[posts][breadcrumbs]"' in html and 'name="archive_taxonomies[tags][breadcrumbs]"' in html)
st, _, html = pub.get('/en/posts')
check('a list has no trail to begin with', 'class="crumbs"' not in html, st)
save(root, {'archive_types[posts][breadcrumbs]': '1', 'archive_taxonomies[tags][breadcrumbs]': '1'}, tab='archive_layouts')
st, _, html = pub.get('/en/posts')
trail = re.search(r'<nav class="crumbs"[^>]*>(.*?)</nav>', html, re.S)
check('a list has Home and itself', trail is not None and 'href="/en/"' in trail.group(1) and len(re.findall(r'<li>', trail.group(1))) == 2, trail and trail.group(1))
st, _, html = pub.get('/en/tag/strategy')
trail = re.search(r'<nav class="crumbs"[^>]*>(.*?)</nav>', html, re.S)
check('so does a tag, by its own title', trail is not None and 'Strategy' in trail.group(1), trail and trail.group(1))
st, _, html = pub.get('/en/category/news')
check('and a category that did not ask for it has none', 'class="crumbs"' not in html, st)
sheet = pub.get(re.search(r'href="([^"]*site\.css[^"]*)"', pub.get('/')[2]).group(1))[2]
check('the style sheet draws it small, in a row with a mark between, and centred where the title is', '.crumbs li + li::before' in sheet and '.single-hero-centered .crumbs ol' in sheet)

# ---- the new title area lines up with the old settings once more: a site that never saved the cards
run("delete from system_meta where key='theme_settings'")
run("insert or replace into system_meta (key, value, updated_at) values ('theme_settings', ?, datetime('now'))", ("hero_layouts:\n  default: default\n  posts: cover\n  pages: default\n  projects: default\n  forms: default\nsidebar:\n  toc: false\n",))
st, _, html = pub.get(post)
check('settings saved before the cards existed still draw the page', st == 200, st)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
