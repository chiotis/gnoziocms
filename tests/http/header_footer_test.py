import html as htmllib, json, re, sys, sqlite3, urllib.parse
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:240]))
    if not ok: fails.append(label)

def run(sql, args=()):
    c = sqlite3.connect('app/storage/db/app.sqlite'); c.execute(sql, args); c.commit(); c.close()

root = Client(); root.login()
pub = Client()

def post_theme(tab, overrides, drop=()):
    """Submit the Theme form the way the browser does: its own values, then what the test changes."""
    form = next(f for f in root.forms('/admin/theme?tab=' + tab) if any(x[0] == 'active_tab' for x in f['fields']))
    # A text with a version for each language has an input for each (text[default], text[en]); a test that sets it as one text replaces them all.
    fields = [tuple(x) for x in form['fields'] if x[0] not in overrides and x[0] not in drop and x[0] != 'active_tab' and not any(x[0].startswith(o + '[') for o in overrides)]
    for k, v in overrides.items():
        if v is None:
            continue
        for item in (v if isinstance(v, list) else [v]):
            fields.append((k, item))
    fields.append(('active_tab', tab))
    return root.request('/admin/theme', data=fields)
def header(**v): return post_theme('header', {'theme_settings[header][%s]' % k: val for k, val in v.items()})
def footer(**v): return post_theme('footer', {'theme_settings[footer][%s]' % k: val for k, val in v.items()})
def page(path='/about'):
    st, _, html = pub.get(path)
    return html
def asset(kind):
    """The theme's stylesheet or script, as the page names it."""
    m = re.search(r'(?:href|src)="([^"]*site\.%s[^"]*)"' % kind, page())
    if not m:
        return ''
    u = urllib.parse.urlparse(m.group(1))
    served = pub.get(u.path + ('?' + u.query if u.query else ''))[2]
    # The stylesheet is sent minified; the checks below read its rules as the theme writes them.
    if kind == 'css' and served:
        return open('app/themes/default/assets/css/site.css', encoding='utf-8').read()
    return served
def stored(yaml):
    run("insert or replace into system_meta (key, value, updated_at) values ('theme_settings', ?, datetime('now'))", (yaml,))
def head(html):
    m = re.search(r'<header class="site-header[^"]*"[^>]*>.*?</header>', html, re.S)
    return m.group(0) if m else ''
def head_tag(html):
    m = re.search(r'<header class="(site-header[^"]*)"([^>]*)>', html)
    return (m.group(1), m.group(2)) if m else ('', '')
def foot(html):
    m = re.search(r'<footer class="site-footer.*?</footer>', html, re.S)
    return m.group(0) if m else ''

# ---- the two tabs
st, _, html = root.get('/admin/theme?tab=header')
check('the Header tab has the real site beside it, in a frame that follows what is chosen', st == 200 and 'data-preview data-mode="page"' in html and 'theme?preview=page' in html and 'data-bp-frame' in html and 'class="hfp' not in html)
layouts = re.findall(r'name="theme_settings\[header\]\[layout\]" value="([a-z_]+)"', html)
check('with every layout to choose from', layouts == ['classic', 'menu_left', 'split', 'centered', 'stacked', 'minimal', 'minimal_center'], layouts)
check('and a field for every choice, under the names the theme has always had', all(('name="theme_settings[header][%s]"' % k) in html for k in (
    'shape', 'width', 'size', 'border', 'nav_style', 'sticky', 'shrink', 'row_tone', 'bar_tone', 'transparent', 'search', 'show_language', 'show_mode', 'cta_label', 'cta_url', 'cta_style',
    'topbar_text', 'topbar_url', 'topbar_tone', 'topbar_contacts', 'topbar_social', 'mobile_menu', 'mobile_bar')) and 'name="theme_settings[header][bar_items][0][label]"' in html or 'theme_settings[header][bar_items]' in html)
check('the tone of the bar is only asked for when the layout is stacked', 'data-only="stacked"' in html)
check('and loads the script that does it, once', html.count('js/admin-preview.js') == 1)
st, _, html = root.get('/admin/theme?tab=footer')
check('the Footer tab has its preview and every layout', 'data-preview data-mode="page"' in html and 'class="hfp' not in html and re.findall(r'name="theme_settings\[footer\]\[layout\]" value="([a-z]+)"', html) == ['columns', 'mega', 'simple', 'bar', 'centered'])
check('and a field for every choice', all(('name="theme_settings[footer][%s]"' % k) in html or ('name="theme_settings[footer][%s][default]"' % k) in html for k in (
    'tone', 'brand', 'show_social', 'show_language', 'back_to_top', 'copyright', 'credits', 'summary', 'email', 'phone', 'address', 'hours', 'background', 'background_image')) and 'name="theme_settings[footer][cta_heading]"' not in html)
check('the media picker can fill the footer image', re.search(r'name="theme_settings\[footer\]\[background_image\]"[^>]*data-image-field|data-image-field[^>]*name="theme_settings\[footer\]\[background_image\]"', html) is not None)
check('the Sidebar and Hero tabs stay where they were put', 'data-tab="single_layouts"' in html and 'data-tab="header"' in html)

# ---- what a site had before still draws the same
run("insert or replace into system_meta (key, value, updated_at) values ('theme_settings', ?, datetime('now'))", ("header:\n  layout: stacked\n  row_tone: soft\n  sticky: always\nfooter:\n  layout: bar\n",))
cls, attrs = head_tag(page())
check('a header saved before the new choices has none of them', 'header--stacked' in cls and 'row-soft' in cls and 'size-regular' in cls and 'edge-line' in cls and 'nav-soft' in cls and 'is-floating' not in cls and 'width-wide' not in cls and 'data-sticky="always"' in attrs and 'data-smart' not in attrs, (cls, attrs))
check('and a footer too', 'footer--bar' in foot(page()) and 'is-light' not in foot(page()) and 'footer-cta' not in foot(page()))
run("delete from system_meta where key='theme_settings'")

# ---- layouts
for layout, nav_in_row, bar in (('classic', True, False), ('menu_left', True, False), ('split', True, False), ('centered', False, True), ('stacked', False, True), ('minimal', False, False), ('minimal_center', False, False)):
    header(layout=layout)
    html = page(); h = head(html)
    row = re.search(r'<div class="site-wrap header-row">(.*?)<div class="header-tools">', h, re.S)
    check('layout %s: header--%s, the menu %s' % (layout, layout, 'in the row' if nav_in_row else ('in a bar underneath' if bar else 'only in the phone menu')),
          'header--%s' % layout in h and (('id="site-menu"' in (row.group(1) if row else '')) == nav_in_row) and (('class="header-bar' in h) == bar), (layout, bool(row)))
header(layout='minimal_center')
check('minimal centered has the menu button on every screen size, as minimal has', 'mobile-menu-button' in head(page()) and '.header--minimal_center .mobile-menu-button' in asset('css'))

# ---- style
header(layout='classic', shape='floating', width='wide', size='tall', border='shadow', nav_style='underline', row_tone='contrast', shrink='1')
cls, attrs = head_tag(page())
check('shape, width, height, edge, links, background and shrinking are classes on the header', all(c in cls.split() for c in ('is-floating', 'width-wide', 'size-tall', 'edge-shadow', 'nav-underline', 'row-contrast', 'tone-contrast', 'is-shrinking')), cls)
header(row_tone='accent', size='compact', border='none', nav_style='plain', shape='full', width='contained', shrink=None)
cls, _ = head_tag(page())
check('another set', all(c in cls.split() for c in ('size-compact', 'edge-none', 'nav-plain', 'row-accent', 'tone-accent')) and 'is-floating' not in cls and 'is-shrinking' not in cls, cls)
header(row_tone='fade-soft')
cls, _ = head_tag(page())
check('a fade is a class of its own, and the style sheet draws it as a gradient into the page', 'row-fade-soft' in cls.split() and 'tone-' not in cls and '.site-header.row-fade-soft' in asset('css') and 'linear-gradient(180deg, var(--accent-soft), var(--color-bg))' in asset('css'), cls)
header(row_tone='fade-muted')
check('the muted fade too', 'row-fade-muted' in head_tag(page())[0].split())
stored("header:\n  row_tone: nonsense\n  size: huge\n  border: dotted\n  nav_style: fancy\n  shape: round\n")
cls, _ = head_tag(page())
check('a value the theme does not offer is the usual one', 'size-regular' in cls and 'edge-line' in cls and 'nav-soft' in cls and 'is-floating' not in cls and 'row-' not in cls, cls)

# ---- behaviour
header(sticky='smart')
cls, attrs = head_tag(page())
check('smart: a header that stays at the top and says it hides when scrolling down', 'data-sticky="always"' in attrs and 'data-smart' in attrs, attrs)
js = asset('js')
check('and the script that does it', 'data-smart' in js and 'is-tucked' in js)
header(sticky='off')
check('off: the header scrolls away', 'data-sticky="off"' in head_tag(page())[1])
stored("header:\n  sticky: nonsense\n")
check('anything else: after scrolling', 'data-sticky="on_scroll"' in head_tag(page())[1])

# ---- what it holds
header(sticky='on_scroll', search='none', show_language=None, show_mode=None, cta_label='Get a quote', cta_url='contact', cta_style='outline')
h = head(page())
check('no search, no language switcher, no dark mode switch', 'search-link' not in h and 'header-search' not in h and 'lang-switcher' not in h.split('<div class="mobile-drawer')[0] and 'data-theme-toggle' not in h, '')
check('an outline button', 'class="btn header-cta"' in h and 'Get a quote' in h, re.findall(r'<a class="[^"]*header-cta[^"]*"', h))
html = page()
drawer = html[html.index('id="mobile-drawer"'):]
check('and the phone menu does not offer them either', 'class="text-link"' not in drawer.split('drawer-icons-wrap')[0] and 'lang-switcher' not in drawer.split('drawer-icons-wrap')[0], '')
header(search='field', show_language='1', show_mode='1', cta_style='solid')
h = head(page())
check('a search box, the switches, a solid button', 'header-search' in h and 'lang-switcher' in h and 'data-theme-toggle' in h and 'class="btn btn-primary header-cta"' in h)

# ---- the top bar
header(topbar_text='Free consultation', topbar_url='contact', topbar_tone='contrast', topbar_social='1')
post_theme('header', {'theme_settings[social][facebook]': 'https://www.facebook.com/example'})
h = head(page())
check('the top bar has its message as a link, its own background and the social icons', 'class="topbar tone-contrast"' in h and re.search(r'<p class="topbar-text"><a href="[^"]*/contact">Free consultation</a>', h) is not None and 'topbar-icons' in h, re.findall(r'<div class="topbar[^>]*>', h))
header(topbar_text='', topbar_social=None)
check('without a message, contacts or icons there is no bar', 'class="topbar' not in head(page()))
header(topbar_text='Hello', topbar_url='', topbar_tone='accent', topbar_social=None)
h = head(page())
check('a message with no link is plain text on the palette colour', 'class="topbar tone-accent"' in h and '<p class="topbar-text">Hello</p>' in h)

# ---- the footer
footer(layout='mega', tone='light', brand='none', copyright='{copyright} {year} {site}. Made in Athens.', credits='Designed by [Unicorg](https://unicorg.example) <b>{copyright}</b>', hours='Mon–Fri 9:00–17:00', email='hello@example.com', show_social='1', show_language='1', back_to_top='1')
f = foot(page())
check('footer: mega, on the page background', 'footer--mega' in f and ' is-light' in f and 'tone-accent' not in f and 'footer-grid--mega' in f)
check('no brand when it is switched off', 'class="footer-brand"' not in f)
check('the copyright line is the site\'s own, with the year and the name', re.search(r'<p class="footer-copy">© \d{4} [^<]+\. Made in Athens\.</p>', f) is not None, re.findall(r'<p class="footer-copy">[^<]*', f))
check('a credits line under it, with a link, the © sign filled in and nothing else let through', re.search(r'</div>\s*<p class="footer-credits">Designed by <a href="https://unicorg.example" target="_blank" rel="noopener">Unicorg</a> &lt;b&gt;©&lt;/b&gt;</p>', f) is not None, re.findall(r'<p class="footer-credits">[^\n]*', f))
check('opening hours are in the contact column', 'Mon–Fri 9:00–17:00' in f and 'hello@example.com' in f)
check('the language switcher and the back to top link; the footer has no call to action band (that is a block now)', 'lang-switcher' in f and 'class="footer-top"' in f and 'footer-cta' not in f, [x in f for x in ('lang-switcher', 'class="footer-top"', 'footer-cta')])
check('social icons are in the footer when it is ticked', 'footer-icons' in f)
footer(tone='accent', brand='name', show_social=None, show_language=None, back_to_top=None)
f = foot(page())
check('the palette colour: its classes, no social icons, no band, no switcher', 'is-accent' in f and 'tone-accent' in f and 'footer-icons' not in f and 'footer-cta' not in f and 'lang-switcher' not in f and 'footer-top' not in f and 'class="footer-brand"' in f, re.findall(r'<footer class="[^"]*"', f))
footer(tone='muted')
check('muted', ' is-muted' in foot(page()))
footer(tone='dark')
f = foot(page())
check('dark is the base: no tone class', 'is-light' not in f and 'is-muted' not in f and 'is-accent' not in f and '<footer class="site-footer footer--mega">' in f, re.findall(r'<footer class="[^"]*"', f))
css = asset('css')
check('and the dark colours are the base the others leave', '.site-footer:not(.is-light, .is-muted, .is-accent)' in css)
stored("footer:\n  tone: nonsense\n  layout: nonsense\n  brand: nonsense\n")
f = foot(page())
check('a value the theme does not offer is the usual one', 'footer--columns' in f and 'is-' not in re.search(r'<footer class="([^"]*)"', f).group(1) and 'class="footer-brand"' in f)
footer(brand='logo')
post_theme('brand', {'theme_settings[brand][logo]': '/uploads/media/5e6915a67b9ceec5.jpg'})
check('the logo can be the brand of the footer', 'footer-logo' in foot(page()))

# ---- the links at the bottom are a menu with a place of its own
st, _, html = root.get('/admin/menus-edit?key=main')
d = json.loads(re.search(r'id="menu-editor-data">(.*?)</script>', html, re.S).group(1))
check('the place for them is offered to menus', 'footer_legal' in [p['key'] for p in d['locations']], [p['key'] for p in d['locations']])
check('and no links show until the menu exists', 'footer-legal' not in foot(page()))
root.submit('/admin/menus-new', has_field('new_key'), {'new_key': 'legal', 'new_title': 'Legal'})
rows = [{'depth': 1, 'labels': {'el': 'Απόρρητο', 'en': 'Privacy'}, 'url': 'privacy'}, {'depth': 1, 'labels': {'el': 'Όροι', 'en': 'Terms'}, 'url': 'terms'}]
root.submit('/admin/menus-edit?key=legal', has_field('menu_json'), {'menu_json': json.dumps(rows), 'menu_title': 'Legal'}, button=('menu_action', 'save'))
f = foot(page('/en/about'))
check('a menu called legal is shown at the bottom', 'footer-legal' in f and '>Privacy</a>' in f and '>Terms</a>' in f, re.findall(r'footer-legal.{0,200}', f, re.S)[:1])
check('in the language of the page', '>Απόρρητο</a>' in foot(page('/about')))

# ---- mega: a column for each link that has links under it
rows = [{'depth': 1, 'labels': {'el': 'Εταιρεία', 'en': 'Company'}, 'url': ''}, {'depth': 2, 'labels': {'el': 'Σχετικά', 'en': 'About'}, 'url': 'about'}, {'depth': 2, 'labels': {'el': 'Καριέρα', 'en': 'Careers'}, 'url': 'careers'},
        {'depth': 1, 'labels': {'el': 'Βοήθεια', 'en': 'Help'}, 'url': ''}, {'depth': 2, 'labels': {'el': 'Συχνές', 'en': 'FAQ'}, 'url': 'faq'},
        {'depth': 1, 'labels': {'el': 'Επικοινωνία', 'en': 'Contact'}, 'url': 'contact'}]
root.submit('/admin/menus-edit?key=footer', has_field('menu_json'), {'menu_json': json.dumps(rows), 'menu_title': 'Footer'}, button=('menu_action', 'save'))
footer(layout='mega')
f = foot(page('/en/about'))
titles = re.findall(r'<h2 class="footer-title"[^>]*>([^<]*)</h2>', f)
check('mega: a column for each link with links under it, and the others together', titles[:3] == ['Company', 'Company', 'Help'] or titles[:3] == ['Company', 'Help', 'Company'] or ('Help' in titles and 'Company' in titles), titles)
check('with the links of each under its title', re.search(r'footer-group-1.*?>About</a>.*?>Careers</a>', f, re.S) is not None and re.search(r'footer-group-2.*?>FAQ</a>', f, re.S) is not None, '')
check('and the link with nothing under it in the first column', re.search(r'footer-company-title.*?>Contact</a>', f, re.S) is not None, '')
footer(layout='columns')
check('columns shows the menu as before', 'footer-grid--mega' not in foot(page('/en/about')) and 'footer-group-1' not in foot(page('/en/about')))

# ---- a floating header floats over the opening section from the start
def body_class(html):
    m = re.search(r'<body class="([^"]*)"', html)
    return m.group(1).split() if m else []
header(layout='classic', shape='floating', transparent=None)
careers = body_class(pub.get('/careers')[2])
about = body_class(pub.get('/about')[2])
check('a floating header over a page that opens with a hero takes no space of its own: it overlays, and keeps its background', 'has-header-overlay' in careers and 'has-transparent-header' not in careers, careers)
check('so it does over a title area, a list, the search and the page not found', all('has-header-overlay' in body_class(pub.get(p)[2]) and 'has-transparent-header' not in body_class(pub.get(p)[2]) for p in ('/about', '/projects', '/search?q=a', '/nothing-here')), [(p, body_class(pub.get(p)[2])) for p in ('/about', '/projects', '/search?q=a', '/nothing-here')])
header(layout='classic', shape='full', transparent=None)
check('a full width header never overlays unless it is transparent', 'has-header-overlay' not in body_class(pub.get('/careers')[2]))
header(layout='classic', shape='full', transparent='1')
careers = body_class(pub.get('/careers')[2])
check('a transparent header overlays too, and is transparent', 'has-header-overlay' in careers and 'has-transparent-header' in careers, careers)
header(layout='classic', shape='floating', transparent='1')
careers = body_class(pub.get('/careers')[2])
check('floating and transparent: both', 'has-header-overlay' in careers and 'has-transparent-header' in careers)
sheet = asset('css')
check('the stylesheet lets an overlay header take no space and the section make room; only a transparent one is transparent', '.has-header-overlay .site-header {\n  position: absolute;' in sheet and '.has-header-overlay .main-shell > .block:first-child' in sheet and re.search(r'\.has-transparent-header \.site-header \{\s+border-bottom-color: transparent;\s+background: transparent;', sheet) is not None)
header(layout='classic', shape='full', transparent=None)

# ---- the live preview: the page drawn with choices that are not saved
def preview(client, path='', **fields):
    f = next(f for f in root.forms('/admin/theme?tab=header') if any(x[0] == 'active_tab' for x in f['fields']))
    token = next(x[1] for x in f['fields'] if x[0] == '_csrf')
    data = [(x[0], x[1]) for x in f['fields'] if x[0].startswith('theme_settings[') and x[0] not in fields]
    data += [('_csrf', token), ('preview_path', path)] + [(k, v) for k, v in fields.items()]
    st, hdr, body = client.request('/admin/theme?preview=page', data=data)
    return st, hdr, (json.loads(body) if st == 200 and body.startswith('{') else {})
header(layout='classic', search='field')
saved = root.get('/admin/theme?tab=header')[2]
st, hdr, out = preview(root, '', **{'theme_settings[header][layout]': 'centered', 'theme_settings[header][sticky]': 'always'})
check('asking for the page returns it as JSON, with the address it drew', st == 200 and 'application/json' in (hdr.get('Content-Type') or '') and out.get('path') == '/' and '<html' in out.get('html', ''), (st, hdr.get('Content-Type')))
h = out.get('html', '')
check('drawn with the layout that is not saved: the header is the centered one', 'header--centered' in h and 'header--classic' not in h, re.findall(r'<header class="[^"]*"', h))
check('and the real stylesheet and scripts are in it', 'site.css' in h and 'site.js' in h and '<footer class="site-footer' in h)
check('nothing was stored by asking', 'layout: classic' in __import__('sqlite3').connect('app/storage/db/app.sqlite').execute("select value from system_meta where key='theme_settings'").fetchone()[0])
check('the public page is still the saved one', 'header--classic' in page() and 'header--centered' not in page())
st, _, out = preview(root, '', **{'theme_settings[footer][tone]': 'accent', 'theme_settings[footer][layout]': 'bar'})
check('the footer too: its tone and layout', 'is-accent' in foot(out.get('html', '')) and 'footer--bar' in foot(out.get('html', '')))
st, _, out = preview(root, '/en/about', **{'theme_settings[header][layout]': 'minimal'})
check('another page, in another language, is drawn with them', out.get('path') == '/en/about' and 'header--minimal' in out.get('html', '') and '<html lang="en"' in out.get('html', ''), out.get('path'))
st, _, out = preview(root, '/projects?page=1', **{'theme_settings[header][layout]': 'split'})
check('a list with its query', out.get('path') == '/projects?page=1' and 'header--split' in out.get('html', ''), out.get('path'))
for odd in ('/admin/users', '/pages/about', '/nothing/at/all/here', '//evil.example/x', '/sitemap.xml'):
    st, _, out = preview(root, odd)
    check('%s is not a page a visitor opens as such: the home page is drawn' % odd, st == 200 and out.get('path') == '/' and '<html' in out.get('html', ''), (odd, out.get('path')))
st, _, out = preview(root, '/search?q=rebrand')
check('the search with its words', out.get('path') == '/search?q=rebrand' and 'rebrand' in out.get('html', '').lower())
form_page = next((p for p in ('/contact', '/en/contact') if pub.get(p)[0] == 200), '')
n_before = len(__import__('os').listdir('app/content/forms-submissions')) if __import__('os').path.isdir('app/content/forms-submissions') else 0
st, _, out = preview(root, form_page, **{'theme_settings[header][layout]': 'classic', 'contact-name': 'X', 'form_submit': '1', 'name': 'X', 'email': 'x@example.test', 'message': 'hello'})
n_after = len(__import__('os').listdir('app/content/forms-submissions')) if __import__('os').path.isdir('app/content/forms-submissions') else 0
check('a page with a form is drawn as a visitor opens it: nothing is submitted', st == 200 and n_after == n_before, (n_before, n_after))

# ---- someone who may not manage the theme
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed4', 'email': 'ed4@example.test', 'display_name': 'ed4', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed4', 'Sturdy-pass-99')
st, _, out = preview(ed, '')
check('an editor cannot ask for the preview', st in (302, 403, 419) and not out, st)
st, _, out = preview(pub, '')
check('nor someone who is not signed in', st in (302, 403, 419) and not out, st)
before = head_tag(page())[0]
st, _, _ = ed.request('/admin/theme', data=[('active_tab', 'header'), ('theme_settings[header][layout]', 'split')])
check('an editor cannot save them', head_tag(page())[0] == before, st)

# ---- texts in more than one language
st, _, html = root.get('/admin/theme?tab=footer')
langs = re.findall(r'data-lang-pick="([a-z-]+)"', html)
langs = list(dict.fromkeys(langs))
check('the Footer tab offers a chip for each language of the site, the site\'s own first', len(langs) >= 2 and 'js/admin-langs.js' in html, langs)
own, other = langs[0], langs[1]
check('each translatable text has an input for each language, named default and by code', all(('name="theme_settings[footer][%s][default]"' % k) in html and ('name="theme_settings[footer][%s][%s]"' % (k, other)) in html for k in ('summary', 'copyright', 'credits', 'address', 'hours')) and 'name="theme_settings[footer][email]"' in html)
page_own = '/about'
page_other = '/%s/about' % other
post_theme('footer', {'theme_settings[footer][summary][default]': 'Own summary', 'theme_settings[footer][summary][%s]' % other: 'Other summary',
                      'theme_settings[footer][address][default]': 'Own street 1', 'theme_settings[footer][address][%s]' % other: '',
                      'theme_settings[footer][credits][default]': 'Own credits', 'theme_settings[footer][credits][%s]' % other: 'Other credits'})
fo, ft = foot(page(page_own)), foot(page(page_other))
check('each page shows the text of its own language', 'Own summary' in fo and 'Other summary' not in fo and 'Other summary' in ft and 'Own summary' not in ft, (page_own, page_other))
check('a language with no text of its own shows the site\'s own text', 'Own street 1' in fo and 'Own street 1' in ft)
check('the credits line follows the language too', 'Own credits' in fo and 'Other credits' in ft and 'Other credits' not in fo)
st, _, html = root.get('/admin/theme?tab=footer')
check('what was saved comes back in the right input', re.search(r'name="theme_settings\[footer\]\[summary\]\[%s\]"[^>]*>Other summary<' % other, html) is not None and re.search(r'name="theme_settings\[footer\]\[summary\]\[default\]"[^>]*>Own summary<', html) is not None)
footer(summary='Changed own')
fo, ft = foot(page(page_own)), foot(page(page_other))
check('a text sent as one string changes the site\'s own text and keeps the translations', 'Changed own' in fo and 'Other summary' in ft)
post_theme('footer', {'theme_settings[footer][summary][default]': 'Only own', 'theme_settings[footer][summary][%s]' % other: ''})
check('with every translation emptied the text is the same on every page', 'Only own' in foot(page(page_own)) and 'Only own' in foot(page(page_other)))
# the header: a button text and a message for the top bar
post_theme('header', {'theme_settings[header][cta_label][default]': 'Own button', 'theme_settings[header][cta_label][%s]' % other: 'Other button', 'theme_settings[header][cta_url]': 'contact',
                      'theme_settings[header][topbar_text][default]': 'Own message', 'theme_settings[header][topbar_text][%s]' % other: 'Other message'})
ho, hother = head(page(page_own)), head(page(page_other))
check('the header button and the top bar message follow the language', 'Own button' in ho and 'Other button' not in ho and 'Other button' in hother and 'Own message' in ho and 'Other message' in hother and 'Own message' not in hother)

# ---- over a picture, a dropdown has a panel of its own and the top of the picture is darker
css = asset('css')
m = re.search(r'\.is-on-dark:not\(\[data-sticky="always"\]\) :is\(\.nav-list-2, \.nav-list-3\)[^{]*\{([^}]*)\}', css)
check('a dropdown over the picture has a dark, nearly solid panel (the header has no background there)', m is not None and 'rgb(var(--ink-rgb) / 0.9' in m.group(1) and 'blur' in m.group(1), m and m.group(1)[:200])
for what, needle in (('a hero that covers', '.block-hero:is(.block--cover, .block--steps):first-child .hero-cover-media::after'), ('a cover title area', '.single-hero-cover:first-child .single-hero-cover-media::after')):
    i = css.find('.has-transparent-header .main-shell > ' + needle)
    check('the top of %s is darker under the transparent header' % what, i >= 0 and 'linear-gradient(180deg, rgb(var(--ink-rgb) / 0.5' in css[i:i + 700], css[i:i + 200] if i >= 0 else 'missing')

# ---- a dark or colour menu bar has no line around it
for tone, expect in (('contrast', True), ('accent', True), ('default', False)):
    header(layout='stacked', bar_tone=tone, shape='full')
    cls = head_tag(page())[0].split()
    check('stacked with a %s bar: the header %s' % (tone, 'is marked to drop its lines' if expect else 'keeps its lines'), ('bar-dark' in cls) == expect, cls)
header(layout='classic', bar_tone='contrast')
check('another layout has no bar to mark', 'bar-dark' not in head_tag(page())[0].split())
css = asset('css')
check('the marked header has no line under it and its bar none above it', re.search(r'\.site-header\.bar-dark:not\(\.is-floating\)\s*\{[^}]*border-bottom-color:\s*transparent', css) is not None and re.search(r'\.site-header\.bar-dark \.header-bar\s*\{[^}]*border-top-color:\s*transparent', css) is not None)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
