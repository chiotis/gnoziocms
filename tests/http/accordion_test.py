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

def acc(**extra):
    b = {'type': 'accordion', 'heading': 'Details', 'items': [
        {'title': 'First', 'content': 'Some **bold** text\n\n- one\n- [two](/contact)'},
        {'title': 'Second', 'content': 'Second body'},
        {'title': 'Empty', 'content': ''}]}
    b.update(extra)
    return b

def bundle(html):
    m = re.search(r'/_themes/default/_blocks/[0-9a-f]+\.css', html)
    return anon.get(m.group(0).replace('&amp;', '&'))[2] if m else ''

# ---- all closed by default, several can be open
h = page('a-default', acc())
items = re.findall(r'<details class="accordion-item"[^>]*>', h)
check('three items with a title and content make two, the empty one is left out', len(items) == 2, items)
check('by default every item is closed', all(' open' not in i for i in items), items)
check('several may be open: no name that groups them, no single-open flag', 'name="' not in ''.join(items) and 'data-single' not in h and 'data-accordion' in h, items)
check('the title is a heading one level under the block heading, inside the summary', re.search(r'<summary class="accordion-summary">\s*<h3 class="accordion-title">First</h3>', h) is not None and '<h2 class="section-title"' in h, re.findall(r'<h\d[^>]*>', h))
check('the content is Markdown: bold, a list and a link', '<strong>bold</strong>' in h and '<li>one</li>' in h and 'href="' in h and '>two</a>' in h)
check('the icon is a chevron and hidden from screen readers', re.search(r'<svg[^>]*accordion-icon[^>]*aria-hidden="true"', h) is not None or ('accordion-icon' in h and 'aria-hidden' in h), re.findall(r'<svg[^>]*>', h)[:2])
check('a block without a heading puts its titles at h2', '<h2 class="accordion-title">First' in page('a-nohead', acc(heading='')))

# ---- first open
f = page('a-first', acc(initial='first'))
fi = re.findall(r'<details class="accordion-item"[^>]*>', f)
check('First item open: only the first has open', ' open' in fi[0] and ' open' not in fi[1], fi)

# ---- one at a time
s = page('a-single', acc(multiple=False, initial='first'))
si = re.findall(r'<details class="accordion-item"[^>]*>', s)
check('One at a time: the script is told, and the items share a name so it works without it', 'data-single' in s and all('name="block-1-items"' in i for i in si), si)

# ---- options
o = page('a-options', acc(icon='plus', style='cards'))
check('Plus and minus and separate cards are classes of the list', 'accordion-cards' in o and 'accordion-icon-plus' in o and 'accordion-plus' in o and 'accordion-lines' not in o)
check('lines and chevron are the default', 'accordion-lines' in h and 'accordion-icon-chevron' in h)

# ---- no FAQ data
check('no structured data, unlike the FAQ', 'FAQPage' not in h and 'FAQPage' not in o)
faq = page('a-faq', {'type': 'faq', 'items': [{'question': 'Q?', 'answer': 'A.'}]})
check('while the FAQ keeps it', 'FAQPage' in faq)

# ---- styles and script only where used
css = bundle(h)
check('the stylesheet has the accordion', '.accordion-item' in css and '.accordion-summary' in css and 'prefers-reduced-motion' in css)
plain = page('a-none', {'type': 'text', 'heading': 'No accordion', 'body': 'x'})
check('a page without it loads neither the styles nor the markup', '.accordion-item' not in bundle(plain) and 'data-accordion' not in plain)
js = re.findall(r'<script[^>]+src="([^"]+)"', h)
bundles = [anon.get(u.replace('&amp;', '&'))[2] for u in js if '_blocks' in u]
check('the script is bundled on a page that has it, and says it respects reduced motion', any('data-accordion' in b and 'prefers-reduced-motion' in b for b in bundles), js)
js2 = [anon.get(u.replace('&amp;', '&'))[2] for u in re.findall(r'<script[^>]+src="([^"]+)"', plain) if '_blocks' in u]
check('and not on a page without it', not any('data-accordion' in b for b in js2))

# ---- the editor: definition, Design tab, round trip
st, _, edit = root.get('/admin/edit?type=pages&slug=about&lang=en')
data = json.loads(re.search(r'id="block-editor-data"[^>]*>(.*?)</script>', edit, re.S).group(1))
d = {x['type']: x for x in data['definitions']}['accordion']
check('the picker lists it under Content, with a picture and a description that names the FAQ', d['category'] == 'Content' and d['preview'] != '' and 'FAQ' in d['description'], d['category'])
check('the three options are Design tab fields', d['design_fields'] == ['multiple', 'icon', 'style'], d['design_fields'])
fk = {f['key']: f for f in d['fields']}
check('with the defaults: closed, several, chevron, lines', [fk['initial']['default'], fk['multiple']['default'], fk['icon']['default'], fk['style']['default']] in ([ 'closed', True, 'chevron', 'lines'], ['closed', 'true', 'chevron', 'lines']), [fk[k]['default'] for k in ('initial', 'multiple', 'icon', 'style')])
blocks = [acc(initial='first', multiple=False, icon='plus', style='cards')]
fields = {'type': 'pages', 'slug': 'a-saved', 'lang': 'en', 'title': 'a-saved', 'status': 'published', 'visible': '1', 'body': 'x', 'blocks_editor': '1', 'blocks_json': json.dumps(blocks)}
root.submit('/admin/edit?type=pages&slug=about&lang=en', has_field('body'), fields)
path = 'app/content/pages/a-saved.en.md'
raw = open(path if os.path.exists(path) else 'app/content/pages/a-saved.md', encoding='utf-8').read()
check('saved, the choices are written and the defaults are not', 'initial: first' in raw and 'multiple: false' in raw and 'icon: plus' in raw and 'style: cards' in raw and 'Allow' not in raw, raw)
saved = page('a-saved-view', *blocks)
st, _, edit2 = root.get('/admin/edit?type=pages&slug=a-saved&lang=en')
back = json.loads(re.search(r'id="block-editor-data"[^>]*>(.*?)</script>', edit2, re.S).group(1))
b0 = back['blocks'][0]
check('and the editor reads them back, with the items', b0['type'] == 'accordion' and b0['initial'] == 'first' and b0['icon'] == 'plus' and b0['style'] == 'cards' and len(b0['items']) == 3 and b0['items'][0]['title'] == 'First', b0)
write('pages/a-defaults.md', "---\ntitle: d\nstatus: published\nvisible: true\nblocks:\n  - { type: accordion, items: [{ title: T, content: C }], initial: closed, multiple: true, icon: chevron, style: lines }\n---\n")
fields.update({'slug': 'a-defaults', 'title': 'a-defaults', 'blocks_json': json.dumps([acc(initial='closed', multiple=True, icon='chevron', style='lines')])})
root.submit('/admin/edit?type=pages&slug=about&lang=en', has_field('body'), fields)
p2 = 'app/content/pages/a-defaults.en.md'
raw2 = open(p2 if os.path.exists(p2) else 'app/content/pages/a-defaults.md', encoding='utf-8').read()
check('a block left at its defaults writes none of them', not re.search(r'^\s+(initial|multiple|icon|style):', raw2, re.M), raw2)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
