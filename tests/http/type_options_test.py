import os, re, sys, sqlite3
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:240]))
    if not ok: fails.append(label)

def stored():
    c = sqlite3.connect('app/storage/db/app.sqlite'); v = c.execute("select value from system_meta where key='theme_settings'").fetchone(); c.close()
    return v[0] if v else ''
def custom(type_):
    path = 'app/custom/content-types/%s.yaml' % type_
    return open(path, encoding='utf-8').read() if os.path.exists(path) else ''

root = Client(); root.login()
pub = Client()

def toggle(on):
    return root.submit('/admin/content-types', lambda f: ['type', 'books'] in [list(x) for x in f['fields']] and ['action', 'toggle'] in [list(x) for x in f['fields']], {'enabled': '1' if on else '0'})
def save(overrides, tab='single_layouts'):
    form = next(f for f in root.forms('/admin/theme?tab=' + tab) if any(x[0] == 'active_tab' for x in f['fields']))
    fields = [tuple(x) for x in form['fields'] if x[0] not in overrides and x[0] != 'active_tab']
    for k, v in overrides.items():
        for item in (v if isinstance(v, list) else [v]):
            if item is not None:
                fields.append((k, item))
    fields.append(('active_tab', tab))
    return root.request('/admin/theme', data=fields)
def card(html, type_):
    m = re.search(r'<article class="lc-card[^>]*aria-labelledby="single-%s".*?</article>' % type_, html, re.S)
    return m.group(0) if m else ''
def acard(html, type_):
    m = re.search(r'<article class="lc-card[^>]*aria-labelledby="archive-type-%s".*?</article>' % type_, html, re.S)
    return m.group(0) if m else ''
def body_tag(html):
    m = re.search(r'<body class="([^"]*)"', html)
    return m.group(1) if m else ''

toggle(True)
os.makedirs('app/content/books', exist_ok=True)
open('app/content/books/dune.md', 'w', encoding='utf-8').write("---\ntitle: Dune\nstatus: published\nvisible: true\ndate: 1762732800\nexcerpt: A desert planet and a boy.\nmain_image: /uploads/media/5e6915a67b9ceec5.jpg\ncustom_fields:\n  author: Frank Herbert\n  publisher: Ace\n  year: 1965\n  buy_url: https://example.test/buy\n---\n\nIntro.\n\n## Plot\n\nSand.\n\n## Reception\n\nFamous.\n")
open('app/content/books/emma.md', 'w', encoding='utf-8').write("---\ntitle: Emma\nstatus: published\nvisible: true\ndate: 1762732900\ncustom_fields:\n  author: Jane Austen\n---\n\nEmma text.\n")

# ---- the card of a type with a page of its own
st, _, html = root.get('/admin/theme?tab=single_layouts')
b = card(html, 'books')
check('the books card has the options its content type declares', st == 200 and all(('name="single_layouts[books][options][%s]"' % k) in b for k in ('cover', 'show_author', 'show_summary', 'show_facts', 'show_buy')), re.findall(r'name="single_layouts\[books\][^"]*"', b))
check('the cover is segments: left, right, above', re.findall(r'name="single_layouts\[books\]\[options\]\[cover\]" value="([a-z]+)"', b) == ['left', 'right', 'top'])
check('and the style of the cover: a book or a flat picture, a book to begin with', re.findall(r'name="single_layouts\[books\]\[options\]\[cover_style\]" value="([a-z]+)"', b) == ['book', 'flat'] and re.search(r'\[cover_style\]" value="book" checked', b) is not None)
check('and the toggles are chips, all on to begin with', all(re.search(r'name="single_layouts\[books\]\[options\]\[%s\]" value="1" checked' % k, b) for k in ('show_author', 'show_summary', 'show_facts', 'show_buy')))
check('it has a sidebar and a header choice, as its page draws them', 'name="single_layouts[books][sidebar]"' in b and 'name="single_layouts[books][header]"' in b and 'name="single_layouts[books][toc]"' in b)
check('but no page layout, no title area (it has its own) and no little preview', 'name="single_layouts[books][template]"' not in b and 'name="single_layouts[books][title]"' not in b and 'data-lc-preview' not in b and 'layout of its own' in b)
check('a type that declares nothing is as it was: the post card has no options', 'name="single_layouts[posts][options]' not in card(html, 'posts') and 'name="single_layouts[posts][title]"' in card(html, 'posts'))

# ---- the page follows the card
st, _, html = pub.get('/books/dune')
check('the page of a book is as it always was by default: cover left, everything shown', st == 200 and 'book-hero is-cover-left' in html and 'class="book-author"' in html and 'class="book-lead"' in html and 'Ace' in html and 'class="book-buy"' in html and 'with-sidebar' not in html, st)
check('the cover is drawn as a book: the shape of a cover, a spine, pages under it', 'book-cover media-frame is-book' in html)
check('and the header is the site\'s own: solid', 'has-transparent-header' not in body_tag(html))
st, hdr, _ = save({'single_layouts[books][options][cover]': 'right', 'single_layouts[books][options][show_author]': None, 'single_layouts[books][options][show_buy]': None,
                   'single_layouts[books][sidebar]': 'right', 'single_layouts[books][header]': 'on'})
check('saving goes back to the tab', st == 302 and 'tab=single_layouts' in (hdr.get('Location') or ''), hdr.get('Location'))
check('the options are stored under the type', re.search(r'books:.*?options:\s+cover: right\s+cover_style: book\s+show_author: false\s+show_summary: true\s+show_facts: true\s+show_buy: false', stored(), re.S) is not None, stored()[-500:])
st, _, html = pub.get('/books/dune')
check('the cover moves, what is switched off is gone, what is on stays', 'book-hero is-cover-right' in html and 'class="book-author"' not in html and 'class="book-buy"' not in html and 'class="book-lead"' in html and 'Ace' in html, None)
check('the book keeps its own layout (it did not become a standard page)', 'book-hero' in html and 'single-hero' not in html)
check('with a sidebar beside the text: contents, drawn by the page itself', 'class="site-wrap with-sidebar"' in html and 'with-sidebar-aside' in html and 'sidebar-toc' in html and 'Plot' in html, None)
check('and the header sits over the opening section', 'has-transparent-header' in body_tag(html) and re.search(r'<header class="site-header[^"]*is-transparent', html) is not None, body_tag(html))
st, hdr, _ = save({'single_layouts[books][options][cover]': 'top', 'single_layouts[books][sidebar]': 'left', 'single_layouts[books][header]': 'off', 'single_layouts[books][options][show_buy]': '1', 'single_layouts[books][options][show_author]': '1', 'single_layouts[books][options][show_facts]': None})
st, _, html = pub.get('/books/dune')
check('another choice: the cover above, the sidebar on the left, a solid header, the facts off', 'is-cover-top' in html and 'with-sidebar is-left' in html and 'has-transparent-header' not in body_tag(html) and 'Ace' not in html and 'class="book-buy"' in html, body_tag(html))
save({'single_layouts[books][options][cover_style]': 'flat'})
check('a flat picture is drawn as it is', 'is-book' not in pub.get('/books/dune')[2] and 'book-cover media-frame' in pub.get('/books/dune')[2])
save({'single_layouts[books][options][cover_style]': 'book'})
st, _, html = pub.get('/books/emma')
check('a book with no sidebar content still has its page, and no author line when it has none', st == 200 and 'Jane Austen' in html and 'class="book-buy"' not in html, st)
st, hdr, _ = save({'single_layouts[books][options][cover]': 'zzz', 'single_layouts[books][sidebar]': 'none', 'single_layouts[books][header]': 'site'})
st, _, html = pub.get('/books/dune')
check('a value the type does not offer keeps the last choice', 'is-cover-top' in html and 'with-sidebar' not in html, None)

# ---- a header that sits over: the opening section clears it
sheet = ''
m = re.search(r'(?:href)="([^"]*site\.css[^"]*)"', pub.get('/books/dune')[2])
if m:
    import urllib.parse
    u = urllib.parse.urlparse(m.group(1)); sheet = pub.get(u.path + ('?' + u.query if u.query else ''))[2]
flat = lambda s: re.sub(r'\s+', '', s)  # stylesheets are sent minified: compare without white space
check('the stylesheet makes room under a transparent header, and has the cover places', '.has-header-overlay .main-shell > .book-hero:first-child' in sheet and '.book-hero.is-cover-right' in sheet and '.book-hero.is-cover-top' in sheet and '.book-cover.is-book' in sheet and 'aspect-ratio:2/3' in flat(sheet))

# ---- the entry's own choice of header wins, as for any page
open('app/content/books/emma.md', 'w', encoding='utf-8').write("---\ntitle: Emma\nstatus: published\nvisible: true\nheader_transparent: on\ncustom_fields:\n  author: Jane Austen\n---\n\nEmma text.\n")
check('an entry that asks for the header over its opening section gets it', 'has-transparent-header' in body_tag(pub.get('/books/emma')[2]))

# ---- the list of books
st, _, html = root.get('/admin/theme?tab=archive_layouts')
a = acard(html, 'books')
check('the list card of books has the option its content type declares for the list', 'name="archive_types[books][options][cover_shape]"' in a and re.findall(r'name="archive_types\[books\]\[options\]\[cover_shape\]" value="([a-z]+)"', a) == ['portrait', 'square', 'landscape'], re.findall(r'name="archive_types\[books\][^"]*"', a)[:20])
check('portrait is on to begin with', re.search(r'\[cover_shape\]" value="portrait" checked', a) is not None)
check('a list that has none declares nothing', 'options]' not in acard(html, 'posts'))
st, _, html = pub.get('/books')
check('the list is drawn with covers in portrait', st == 200 and 'opt-cover-shape-portrait' in html, re.findall(r'class="block-latest[^"]*"', html))
st, hdr, _ = save({'archive_types[books][options][cover_shape]': 'square'}, tab='archive_layouts')
check('square is written to the site\'s own file, and only that', st == 302 and 'cover_shape: square' in custom('books') and custom('books').count('options') == 1, custom('books'))
check('the list follows', 'opt-cover-shape-square' in pub.get('/books')[2] and 'opt-cover-shape-portrait' not in pub.get('/books')[2])
save({'archive_types[books][options][cover_shape]': 'zzz'}, tab='archive_layouts')
check('a value it does not offer keeps the last choice', 'opt-cover-shape-square' in pub.get('/books')[2])
save({'archive_types[books][options][cover_shape]': 'portrait'}, tab='archive_layouts')
check('what the theme says is not stored: back to portrait removes it from the file', 'cover_shape' not in custom('books') and 'opt-cover-shape-portrait' in pub.get('/books')[2], custom('books'))
sheet_ok = '.block-latest .latest-item.type-books .latest-media' in sheet and '.opt-cover-shape-square .latest-item.type-books' in sheet
check('the stylesheet gives covers their shape', sheet_ok)

# ---- a site can add options to a type of its own, and to a theme type
root.submit('/admin/content-types', lambda f: ['action', 'create'] in [list(x) for x in f['fields']] or any(x[0] == 'name' for x in f['fields']), {'action': 'create', 'name': 'recipes', 'label': 'Recipes'})
open('app/custom/content-types/recipes.yaml', 'a', encoding='utf-8').write("""
single:
  options:
    layout:
      type: select
      label: Recipe layout
      default: classic
      options: {classic: Classic, photo: Photo first}
    show_time: {type: toggle, label: Cooking time, default: true}
    servings: {type: number, label: Servings shown, default: 4, min: 1, max: 12}
archive_options:
  grid_gap:
    type: select
    label: Gap
    default: normal
    options: {normal: Normal, wide: Wide}
""")
st, _, html = root.get('/admin/theme?tab=single_layouts')
r = card(html, 'recipes')
check('a type the site makes gets its options on its card', all(('name="single_layouts[recipes][options][%s]"' % k) in r for k in ('layout', 'show_time', 'servings')), re.findall(r'name="single_layouts\[recipes\][^"]*"', r))
check('a number is a box with its limits', re.search(r'name="single_layouts\[recipes\]\[options\]\[servings\]" value="4" min="1" max="12"', r) is not None)
check('and its page has the standard choices as before (its page is the standard one)', 'name="single_layouts[recipes][title]"' in r and 'name="single_layouts[recipes][sidebar]"' in r)
save({'single_layouts[recipes][options][layout]': 'photo', 'single_layouts[recipes][options][servings]': '6', 'single_layouts[recipes][options][show_time]': None})
st, _, html = root.get('/admin/theme?tab=single_layouts')
r = card(html, 'recipes')
check('what is saved comes back', re.search(r'\[layout\]" value="photo" checked', r) and 'value="6" min="1"' in r and not re.search(r'\[show_time\]" value="1" checked', r), re.findall(r'name="single_layouts\[recipes\][^"]*"[^>]*', r)[-6:])
save({'single_layouts[recipes][options][servings]': '99'})
check('a number is held to its limits', 'servings: 12' in stored())
st, _, html = root.get('/admin/theme?tab=archive_layouts')
check('and its list has its option', 'name="archive_types[recipes][options][grid_gap]"' in acard(html, 'recipes'))
# the site adds an option to a theme type, and the theme's own stay
open('app/custom/content-types/books.yaml', 'a', encoding='utf-8').write("""
single:
  options:
    ribbon: {type: toggle, label: Ribbon, default: false}
""")
st, _, html = root.get('/admin/theme?tab=single_layouts')
b = card(html, 'books')
check('an option a site adds to the books page joins those of the theme', 'name="single_layouts[books][options][ribbon]"' in b and 'name="single_layouts[books][options][cover]"' in b and 'name="single_layouts[books][options][show_buy]"' in b, re.findall(r'\[options\]\[[a-z_]+\]', b))
check('and is off, as it says', not re.search(r'\[options\]\[ribbon\]" value="1" checked', b))

# ---- the Content types screen is untouched by all this
st, _, html = root.get('/admin/content-types?type=books')
check('the content type editor still opens', st == 200 and 'name="archive[layout]"' not in html or st == 200, st)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
