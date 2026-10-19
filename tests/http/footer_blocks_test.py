import json, re, sqlite3, sys
sys.path.insert(0, '.')
from client import Client

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:240]))
    if not ok: fails.append(label)

root = Client(); root.login()
pub = Client()

def save_blocks(blocks, editor='1'):
    """Submit the Theme form the way the browser does, with what the block editor of Footer Blocks wrote."""
    form = next(f for f in root.forms('/admin/theme?tab=footer_blocks') if any(x[0] == 'active_tab' for x in f['fields']))
    fields = [tuple(x) for x in form['fields'] if x[0] not in ('active_tab', 'blocks_json', 'blocks_editor')]
    fields += [('active_tab', 'footer_blocks'), ('blocks_editor', editor), ('blocks_json', json.dumps(blocks))]
    return root.request('/admin/theme', data=fields)

def stored():
    c = sqlite3.connect('app/storage/db/app.sqlite')
    row = c.execute("select value from system_meta where key = 'theme_settings'").fetchone()
    c.close()
    return row[0] if row else ''

def page(path='/about'):
    return pub.get(path)[2]

def before_footer(html):
    m = re.search(r'<div class="footer-blocks">(.*?)</div>\s*<footer class="site-footer', html, re.S)
    return m.group(1) if m else ''

st, _, html = root.get('/admin/theme?tab=footer_blocks')
tabs = re.findall(r'role="tab"[^>]*data-tab="([a-z_]+)"', html)
check('Theme has a Footer Blocks tab, right after Footer', st == 200 and 'footer_blocks' in tabs and tabs.index('footer_blocks') == tabs.index('footer') + 1, tabs)
check('with the block editor and the blocks it starts from', 'data-block-editor' in html and 'id="block-editor-data"' in html and 'js/admin-blocks.js' in html and 'js/admin-panel.js' in html and '"type":"logos"' in html)
check('nothing is above the footer until blocks are added', before_footer(page()) == '' and 'footer-blocks' not in page())

logos = {'type': 'logos', 'heading': 'Our partners', 'items': [{'name': 'Acme'}, {'name': 'Globex'}]}
st, _, _ = save_blocks([logos])
check('the blocks are saved', st in (200, 302) and 'Our partners' in stored(), stored()[-300:])
html = page()
inside = before_footer(html)
check('they sit above the footer on a page', 'block-logos' in inside and 'Our partners' in inside and 'Acme' in inside, inside[:200])
check('and on every kind of page: the home page, a list, a missing page, in another language',
      all('Our partners' in before_footer(pub.get(p)[2]) for p in ('/', '/posts', '/en/about', '/no-such-page-here')),
      [(p, pub.get(p)[0]) for p in ('/', '/posts', '/en/about', '/no-such-page-here')])
check('the logos block\'s own style sheet is in the head of a page that has no such block', 'block' in html.split('</head>')[0] and re.search(r'_blocks\.css\?b=[^"]*logos', html.split('</head>')[0]) is not None)
check('the editor shows what was saved', '"Our partners"' in root.get('/admin/theme?tab=footer_blocks')[2].replace('\\u0020', ' ') or 'Our partners' in root.get('/admin/theme?tab=footer_blocks')[2])

# Saving another tab without the editor's data keeps the blocks; an empty list removes them.
save_blocks([], editor='')
check('a save without the editor\'s data (no script) leaves the blocks as they are', 'Our partners' in stored() and 'Our partners' in before_footer(page()))
save_blocks([{'type': 'logos', 'heading': 'Hidden one', 'hidden': True, 'items': [{'name': 'X'}]}])
check('a hidden block is not shown', 'Hidden one' not in page())
save_blocks([])
check('an empty list takes the blocks away', 'footer_blocks' not in stored() and 'footer-blocks' not in page())

# ---- a set of blocks for each language
html = root.get('/admin/theme?tab=footer_blocks')[2]
m = re.search(r'"languages":(\[\{.*?\}\])', html)
langs = json.loads(m.group(1)) if m else []
check('with several languages the editor has a set for each, the site\'s own first', len(langs) >= 2 and langs[0]['key'] == 'default', langs)
other = langs[1]['code']
own_set = [{'type': 'logos', 'heading': 'Own partners', 'items': [{'name': 'OwnCo'}]}]
other_set = [{'type': 'logos', 'heading': 'Other partners', 'items': [{'name': 'OtherCo'}]}]
save_blocks({'default': own_set, other: other_set})
po, pt = before_footer(page('/about')), before_footer(page('/%s/about' % other))
check('each language shows its own set', 'Own partners' in po and 'Other partners' not in po and 'Other partners' in pt and 'Own partners' not in pt, (po[:80], pt[:80]))
html = root.get('/admin/theme?tab=footer_blocks')[2]
check('and the editor starts from both', 'Own partners' in html and 'Other partners' in html)
save_blocks({'default': own_set, other: []})
check('a language with no set of its own shows the site\'s own', 'Own partners' in before_footer(page('/%s/about' % other)) and 'Other partners' not in stored(), stored()[-200:])
save_blocks({'default': [], other: other_set})
check('a set for another language only shows nowhere else', before_footer(page('/about')) == '' and 'Other partners' in before_footer(page('/%s/about' % other)))
save_blocks(own_set)
check('a plain list (the editor with one language) replaces the site\'s own set and keeps the others', 'Own partners' in before_footer(page('/about')) and 'Other partners' in before_footer(page('/%s/about' % other)))
save_blocks({'default': [], other: []})
check('every set emptied takes the blocks away', 'footer_blocks' not in stored() and 'footer-blocks' not in page())

print('\nALL PASSED' if not fails else '\n%d FAILED' % len(fails))
sys.exit(1 if fails else 0)
