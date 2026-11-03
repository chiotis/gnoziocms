import json, os, re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
anon = Client()
def write(path, text): open('app/content/' + path, 'w', encoding='utf-8').write(text)

def page(slug, *blocks):
    write('pages/%s.md' % slug, "---\ntitle: '%s'\nstatus: published\nvisible: true\nblocks: %s\n---\n" % (slug, json.dumps(list(blocks), ensure_ascii=False)))
    return anon.get('/' + slug)[2]

def t(heading, width=None, **extra):
    b = {'type': 'text', 'heading': heading, 'body': 'Body of ' + heading}
    if width is not None: b['width'] = width
    b.update(extra)
    return b

def rows(html):
    """Each row of the page as the list of widths of its blocks, and the sections outside rows."""
    out = []
    for m in re.finditer(r'<div class="block-row[^"]*">(.*?)</div>(?=\s*(?:<section|<div class="block-row|</main|<footer|$))', html, re.S):
        out.append(re.findall(r'block--w-(\d+)', m.group(1)))
    return out

# ---- no widths: the page is as it always was
plain = page('w-plain', t('One'), t('Two'), t('Three', 100))
check('a page without widths has no row, no width class and no row styles', 'block-row' not in plain and 'block--w-' not in plain and '_row' not in plain)
check('width 100 is the same as none', [re.sub(r'\s+', ' ', plain)] == [re.sub(r'\s+', ' ', page('w-plain', t('One'), t('Two'), t('Three')))])
check('the sections are as they were', plain.count('<section class="block block-text block--default tone-default space-default"') == 3, re.findall(r'<section class="[^"]*"', plain))

# ---- rows
h = page('w-rows', t('Intro'), t('A', 25), t('B', 25), t('C', 50), t('Full'), t('D', 50), t('E', 50), t('F', 75), t('G', 25), t('Last'))
check('25 + 25 + 50 is one row, a 100% block ends it, and the next narrow blocks start another that wraps', rows(h) == [['25', '25', '50'], ['50', '50', '75', '25']], rows(h))
more = page('w-more', t('A', 50), t('B', 50), t('Full'), t('C', 75), t('D', 25), t('E', 25), t('F', 25), t('G', 25), t('H', 25))
check('50 + 50, then 75 + 25, then 25 + 25 + 25 + 25 + 25 (the last wraps)', rows(more) == [['50', '50'], ['75', '25', '25', '25', '25', '25']], rows(more))
check('blocks at 100% stay outside rows, in their place', h.index('Intro') < h.index('block-row') and h.index('Full') > h.index('Body of C') and h.index('Full') < h.index('Body of D') and h.rindex('Last') > h.rindex('block-row'))
check('a row keeps the spacing of a section once', h.count('class="block-row space-default block-row--plain"') == 2, re.findall(r'<div class="block-row[^"]*"', h))
check('a row with a block that has a background is not plain', 'block-row space-default"' in page('w-tone', t('A', 50, tone='muted'), t('B', 50)))
check('headings stay h2 and each block keeps its label', h.count('<h2 class="section-title"') == 10 and 'aria-labelledby="block-2-title"' in h, h.count('<h2'))
only = page('w-one', t('Alone', 50))
check('one narrow block is a row with the rest empty', rows(only) == [['50']], rows(only))
check('a hidden block does not break a row', rows(page('w-hidden', t('A', 25), t('H', 100, hidden=True), t('B', 75))) == [['25', '75']])
check('a block that opens the page as a hero stays full width', 'block--w-' not in page('w-hero', {'type': 'hero', 'heading': 'Top', 'width': 50}, t('A')), None)

thirds = page('w-thirds', t('A', 33), t('B', 33), t('C', 33), t('Full'), t('D', 66), t('E', 33), t('F', 33), t('G', 66), t('H', 25), t('I', 75))
check('33 + 33 + 33 is a row of three, 66 + 33 and 33 + 66 and 25 + 75 wrap in the next one', rows(thirds) == [['33', '33', '33'], ['66', '33', '33', '66', '25', '75']], rows(thirds))
rowcss = anon.get('/_themes/default/_blocks.css?b=text,_row')[2]
check('the row styles use a twelve-column grid with exact thirds', 'repeat(12' in rowcss and '.block--w-33' in rowcss and '.block--w-66' in rowcss)

# ---- the styles ride along only where needed
m = re.search(r'/_themes/default/_blocks/[0-9a-f]+\.css', h)
check('a page with a row loads the row styles with its blocks, from a file in public/', bool(m) and '.block-row' in anon.get(m.group(0))[2] and os.path.isfile('app/public' + m.group(0)), m and m.group(0))
st, _, css = anon.get('/_themes/default/_blocks.css?b=text,_row')
check('and the bundle holds them', st == 200 and '.block-row' in css and 'grid-template-columns' in css, st)
m2 = re.search(r'/_themes/default/_blocks/[0-9a-f]+\.css', plain)
check('a page without a row does not load them', bool(m2) and '.block-row' not in anon.get(m2.group(0))[2])
check('phone and tablet are in the row styles', '@media (max-width: 640px)' in css and '@media (max-width: 960px)' in css)

# ---- the editor knows the setting, writes it and reads it back
st, _, edit = root.get('/admin/edit?type=pages&slug=about&lang=en')
data = json.loads(re.search(r'id="block-editor-data"[^>]*>(.*?)</script>', edit, re.S).group(1))
defs = {d['type']: d for d in data['definitions']}
widths = [next(f for f in d['common'] if f['key'] == 'width') for d in defs.values()]
check('every block type has the Width setting, 100% by default', len(widths) == len(defs) and all([o[0] for o in w['options']] == ['100', '75', '66', '50', '33', '25'] and w['default'] == '100' for w in widths))
js = root.get('/assets/js/admin-blocks.js')[2]
check('the editor script draws chips and a row', 'bk-widths' in js and 'bk-sharing' in js and 'widthOf' in js)
def save(blocks):
    fields = {'type': 'pages', 'slug': 'w-saved', 'lang': 'en', 'title': 'w-saved', 'status': 'published', 'visible': '1', 'body': 'x', 'blocks_editor': '1', 'blocks_json': json.dumps(blocks)}
    return root.submit('/admin/edit?type=pages&slug=about&lang=en', has_field('body'), fields)
save([{'type': 'text', 'heading': 'A', 'width': '25'}, {'type': 'text', 'heading': 'B', 'width': '100'}, {'type': 'text', 'heading': 'C', 'width': '75'}, {'type': 'text', 'heading': 'D', 'width': '34'}, {'type': 'text', 'heading': 'E', 'width': '33'}, {'type': 'text', 'heading': 'F', 'width': '66'}])
raw = open('app/content/pages/w-saved.en.md', encoding='utf-8').read() if __import__('os').path.exists('app/content/pages/w-saved.en.md') else open('app/content/pages/w-saved.md', encoding='utf-8').read()
check('saved, a width is a plain number and 100 is not written', re.findall(r'width: .*', raw) == ['width: 25', 'width: 75', 'width: 33', 'width: 66'], re.findall(r'width: .*', raw))
st, _, edit2 = root.get('/admin/edit?type=pages&slug=w-saved&lang=en')
check('and the editor reads it back', '"width":25' in edit2.replace(' ', '') or '"width":"25"' in edit2.replace(' ', ''), re.findall(r'"width":[^,}]*', edit2))

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
