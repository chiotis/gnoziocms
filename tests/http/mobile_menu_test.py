import re, sys, sqlite3
sys.path.insert(0, '.')
from client import Client

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:200]))
    if not ok: fails.append(label)

def run(sql, args=()):
    c = sqlite3.connect('app/storage/db/app.sqlite'); c.execute(sql, args); c.commit(); c.close()
def header_settings(**values):
    body = 'header:\n' + ''.join('  %s: %s\n' % (k, v) for k, v in values.items())
    run("insert or replace into system_meta (key, value, updated_at) values ('theme_settings', ?, datetime('now'))", (body,))
def put(path, front, body='Text.'):
    open('app/content/' + path, 'w', encoding='utf-8').write('---\n' + front + '---\n\n' + body + '\n')

pub = Client()
def page(path='/about'):
    st, _, html = pub.get(path)
    return st, html
def drawer_class(html):
    m = re.search(r'<div class="(mobile-drawer [^"]*)" id="mobile-drawer"', html)
    return m.group(1) if m else ''

# ---- the drawer is a sibling of the header, not inside it
st, html = page()
check('the page renders', st == 200, st)
head_end = html.index('</header>')
check('the menu panel sits after the header, outside it', html.index('id="mobile-drawer"') > head_end and html.index('class="mobile-drawer-overlay"') > head_end)
check('and stays a dialog with a name, closed to assistive technology until opened', 'role="dialog" aria-modal="true" aria-label="' in html and 'aria-hidden="true" tabindex="-1"' in html.split('id="mobile-drawer"')[1][:300])
check('the header keeps the button that opens it', 'data-mobile-open aria-controls="mobile-drawer" aria-expanded="false"' in html.split('</header>')[0])
check('the side drawer from the left is the default', drawer_class(html) == 'mobile-drawer mobile-drawer--drawer', drawer_class(html))

# ---- five ways to open it
for value in ('drawer', 'drawer_right', 'fullscreen', 'top_sheet', 'bottom_sheet'):
    header_settings(mobile_menu=value)
    st, html = page()
    check('phone menu style ' + value, drawer_class(html) == 'mobile-drawer mobile-drawer--' + value, drawer_class(html))
header_settings(mobile_menu='sideways')
check('a style the theme does not offer falls back to the drawer', drawer_class(page()[1]) == 'mobile-drawer mobile-drawer--drawer', drawer_class(page()[1]))
header_settings(mobile_menu='fullscreen', mobile_bar='true')
st, html = page()
check('the bottom bar still opens the same panel', 'class="mobile-bar-item" data-mobile-open aria-controls="mobile-drawer"' in html)

# ---- the same panel under a transparent header over a dark hero: it is not part of the header any more
put('pages/dark-lead.md', "title: Dark lead\nstatus: published\nvisible: true\nblocks:\n  - type: hero\n    variant: cover\n    heading: Opening\n")
header_settings(mobile_menu='drawer', transparent='true', sticky='always')
st, html = page('/dark-lead')
check('over a dark hero the header turns light, the panel is not inside it', 'is-on-dark' in html.split('<main')[0] and html.index('id="mobile-drawer"') > html.index('</header>'))

# ---- the theme settings offer the choices
root = Client(); root.login()
st, _, html = root.get('/admin/theme')
check('Theme settings list the phone menu styles', st == 200 and all(label in html for label in ('Side drawer from the left', 'Side drawer from the right', 'Full screen with large links', 'Sheet dropping from the top', 'Sheet rising from the bottom')), st)
check('with a name and help text', 'Phone menu' in html and 'How the menu opens on small screens' in html)

# ---- no empty lists for screen readers to announce
put('pages/no-cards.md', "title: No cards\nstatus: published\nvisible: true\nblocks:\n  - type: cards\n    source: projects\n    heading: Recent work\n")
st, html = page('/no-cards')
shown = lambda h: h[h.find('<body'):]  # the page itself: a small block stylesheet in its head names the same classes
check('a cards block with nothing to show leaves out its empty list', st == 200 and 'cards-grid' not in shown(html), st)
put('pages/some-cards.md', "title: Some cards\nstatus: published\nvisible: true\nblocks:\n  - type: cards\n    source: manual\n    heading: Two\n    items:\n      - {title: One, text: First}\n      - {title: Two, text: Second}\n")
st, html = page('/some-cards')
check('and a cards block with items still lists them', st == 200 and html.count('class="content-item"') == 2 and '<ul class="content-grid cards-grid' in html, html.count('class="content-item"'))

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
