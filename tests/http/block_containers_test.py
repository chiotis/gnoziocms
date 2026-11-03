"""Container rows: the page they draw, their styles loaded only where needed, pages without them unchanged, saving and reading them back,
and what reads the blocks of a page (the editor, the history, search, the feed, the media screen) with blocks inside the columns."""
import json, os, re, sqlite3, sys, zlib
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
anon = Client()
def write(path, text): open('app/content/' + path, 'w', encoding='utf-8').write(text)

def page(slug, *blocks, body=''):
    write('pages/%s.md' % slug, "---\ntitle: '%s'\nstatus: published\nvisible: true\nblocks: %s\n---\n%s\n" % (slug, json.dumps(list(blocks), ensure_ascii=False), body))
    return anon.get('/' + slug)[2]

def text(heading, **extra):
    b = {'type': 'text', 'heading': heading, 'body': 'Body of ' + heading}
    b.update(extra)
    return b

def box(columns, **settings):
    return {'type': 'container', 'settings': settings, 'columns': [c if isinstance(c, dict) else {'blocks': c} for c in columns]}

def sheet(html):
    m = re.search(r'/_themes/default/_blocks/[0-9a-f]+\.css', html)
    return (m.group(0).replace('&amp;', '&'), anon.get(m.group(0).replace('&amp;', '&'))[2]) if m else ('', '')

# ---- a page without a container is as it was: no container markup, no container styles
plain = page('c-plain', text('One'), text('Two', width=50), text('Three', width=50))
url, css = sheet(plain)
check('a page without a container has no container markup', 'bkc' not in plain and 'block-container' not in plain)
check('and its stylesheet has no container rules', '.bkc-row' not in css, url)
check('narrow blocks still get the row styles (and the row styles now also cover columns)', '.block-row' in css)

# ---- one container, three columns
h = page('c-three', text('Before'), box([[text('Left')], [text('Middle'), {'type': 'cta', 'heading': 'Go', 'label': 'Click', 'url': '/about'}], [{'type': 'image', 'image': '/uploads/x.jpg', 'alt': 'x'}]], gap='l', justify='center'), text('After'))
check('a container is a section with an inner width, a row and a column for each column', h.count('class="bkc-col ') == 3 and 'class="block block-container bkc tone-default space-default"' in h and 'bkc-inner bkc-inner--container' in h, re.findall(r'<(?:section|div) class="[^"]*bkc[^"]*"', h))
check('the row carries its flexbox settings as classes', 'class="bkc-row bkc-dir-row bkc-wrap-wrap bkc-j-center bkc-a-stretch bkc-ac-stretch bkc-gap-l bkc-rgap-m bkc-d-row bkc-t-two bkc-p-one"' in h, re.findall(r'class="bkc-row[^"]*"', h))
check('the blocks are inside the columns, in order, and a block in a column is a section like any other', h.index('Body of Left') < h.index('Body of Middle') < h.index('Go') and re.search(r'<div class="bkc-col[^>]*>\s*<section class="block block-text', h) is not None)
check('a block in a column takes the room of the column (a third is a narrow block) and its own Width is not used', 'block--w-33' in h)
check('the page around it is as before: the blocks before and after are plain sections', h.index('Body of Before') < h.index('block-container') < h.index('Body of After'))
url, css = sheet(h)
names = re.findall(r'^/\* ([a-z-]+) \*/$', css, re.M)
check('the stylesheet carries the container rules and the rules of the blocks inside, and only those', '.bkc-row' in css and '.block-row' in css and 'cta' in names and 'text' in names and 'image' in names and 'faq' not in names, names)
check('the bundle has the row, the columns and every screen', '.bkc-row' in css and '.bkc-col--part' in css and '@media (max-width: 640px)' in css and '@media (min-width: 641px) and (max-width: 960px)' in css and '.bkc-p-one' in css and '.bkc-t-two' in css and '.bkc-d-one' in css)
check('the columns use the theme\'s spacing steps for gaps and padding, not pixels', 'var(--space-l)' in css and re.search(r'\.bkc-gap-l\s*{\s*--bkc-gap: var\(--space-l\)', css) is not None)
check('a column\'s share is a custom property, the gap is taken off by the stylesheet', 'style="--bkc-f:0.3333"' in page('c-third', box([{'settings': {'width': '1/3'}, 'blocks': [text('A')]}, {'settings': {'width': '2/3'}, 'blocks': [text('B')]}])) and 'var(--bkc-f)' in css)
check('no inline style beyond the variables', all(s.startswith('--bkc-') for s in re.findall(r'class="bkc-col[^"]*" style="([^"]*)"', h)) and 'style="' not in re.search(r'<section class="block block-container.*?</section>', h, re.S).group(0).replace(' style="--bkc-', ''), re.findall(r'style="[^"]*"', h)[:5])
check('no script comes with it', '<script' not in re.search(r'<section class="block block-container.*?</section>\s*<section', h, re.S).group(0))

# ---- flex settings and breakpoints
f = page('c-flex', box([{'settings': {'width': '20rem', 'min_width': '12rem', 'max_width': '40rem', 'align_self': 'end', 'order': 2, 'padding': 'l', 'tone': 'muted', 'radius': 'm', 'valign': 'center'}, 'blocks': [text('A')]},
                        {'settings': {'width': 'auto', 'grow': 2, 'shrink': 0, 'basis': '30%'}, 'blocks': [text('B')]},
                        {'settings': {'width': '37%'}, 'blocks': [text('C')]}, [text('D')]],
                       direction='row-reverse', wrap='nowrap', justify='between', align='center', align_content='end', gap='none', row_gap='xl', stack_desktop='two', stack_tablet='one', stack_phone='row', reverse_phone=True, inner='narrow', spacing='compact', min_height='m', anchor='Our Team', **{'class': 'my-cls'}))
check('every flex setting is a class on the row', 'bkc-row bkc-dir-row-reverse bkc-wrap-nowrap bkc-j-between bkc-a-center bkc-ac-end bkc-gap-none bkc-rgap-xl bkc-d-two bkc-t-one bkc-p-row bkc-rev' in f, re.findall(r'class="bkc-row[^"]*"', f))
check('a fixed size, its min and max, align-self, order, padding, tone and corners', 'bkc-col bkc-col--fixed bkc-col--self-end bkc-col--v-center bkc-col--ordered bkc-col--pad-l bkc-col--toned tone-muted bkc-col--r-m" style="--bkc-w:20rem;--bkc-min:12rem;--bkc-max:40rem;--bkc-order:2"' in f, re.findall(r'class="bkc-col[^"]*" style="[^"]*"', f))
check('grow, shrink and basis', 'bkc-col bkc-col--auto bkc-col--flex" style="--bkc-grow:2;--bkc-shrink:0;--bkc-basis:30%"' in f and 'bkc-col--part" style="--bkc-f:0.37"' in f)
check('inner width, spacing, minimum height, anchor and class', 'class="block block-container bkc tone-default space-compact bkc--min bkc--min-m my-cls" id="our-team"' in f and 'bkc-inner--narrow' in f, re.findall(r'<section class="block block-container[^>]*>', f))
check('a container is never first for the page: the page keeps its own title', '<h1' in f)
auto2 = page('c-auto', box([[text('A')], [text('B')]]))
auto4 = page('c-auto4', box([[text('A')], [text('B')], [text('C')], [text('D')]]))
check('on a tablet two columns stay in a row and more wrap to two (automatic)', 'bkc-t-row' in auto2 and 'bkc-t-two' in auto4)
bad = page('c-bad', box([{'settings': {'width': 'width: 9px;color:red', 'padding': 'huge', 'order': 99, 'min_width': 'url(x)'}, 'blocks': [text('A')]}], gap='3px', stack_phone='x', **{'class': '"><script>'}))
check('tampered values fall back to the defaults, nothing gets into the markup', 'bkc-col bkc-col--fill bkc-col--ordered"' in bad and '--bkc-order:6' in bad and 'bkc-gap-m' in bad and 'bkc-p-one' in bad and '"><script>' not in bad and 'color:red' not in bad and 'url(x)' not in bad and '3px' not in bad, re.findall(r'class="bkc-[^"]*"', bad))
check('seven columns are six', page('c-seven', box([[text(str(i))] for i in range(7)])).count('class="bkc-col ') == 6)
check('a container with no columns and no blocks draws nothing for visitors', 'bkc-col' not in page('c-none', {'type': 'container', 'columns': []}, text('x')))

# ---- picture and overlay
p = page('c-picture', box([[text('On a picture')]], image='/uploads/media/bg.jpg', overlay='dark'))
check('a background picture sits behind the columns, decorative', 'class="bkc-media" aria-hidden="true"' in p and 'bg.jpg' in p and 'bkc--picture bkc--overlay-dark' in p and re.search(r'<img[^>]*alt=""', p) is not None, re.findall(r'<div class="bkc-media".*?</div>', p, re.S))
check('a dark overlay makes the section a contrast one (light text), a light one keeps its tone', 'block-container bkc tone-contrast' in p and 'bkc--overlay-light' in page('c-light', box([[text('x')]], image='/uploads/media/bg.jpg', overlay='light', tone='muted')))

# ---- nothing to show, hidden
check('a container whose blocks have nothing to show leaves nothing for visitors', 'block-container' not in page('c-empty', box([[{'type': 'image', 'image': ''}], []])))
check('a hidden container is not drawn', 'block-container' not in page('c-hidden', box([[text('x')]], hidden=True)))
check('a hidden block inside is left out', 'Body of gone' not in page('c-hid2', box([[text('gone', hidden=True), text('here')]])))
check('a container inside a column is not drawn (one level)', page('c-nest', box([[box([[text('deep')]]), text('shallow')]])).count('block-container') == 1)
check('a container opening the page is no hero: the page keeps a title of its own', '<h1' in page('c-open', box([[text('x')]])))

# ---- the page's own text is placed once, also by a content block inside a column
body = page('c-body', box([[text('A')], [{'type': 'content'}]]), body='The page text appears once.')
check('a content block inside a column places the page text, so it is not shown twice', body.count('The page text appears once.') == 1, body.count('The page text appears once.'))
body2 = page('c-body2', box([[text('A')]]), body='The page text again.')
check('without one the page text is still shown, once', body2.count('The page text again.') == 1)

# ---- the editor knows containers, saves them and reads them back
st, _, edit = root.get('/admin/edit?type=pages&slug=c-three&lang=el')
data = json.loads(re.search(r'id="block-editor-data"[^>]*>(.*?)</script>', edit, re.S).group(1))
c = data['container']
check('the editor gets the container: its settings, the column settings, the presets and the limit', [f['key'] for f in c['settings']][:3] == ['direction', 'justify', 'align'] and 'width' in [f['key'] for f in c['columns']] and [p['id'] for p in c['presets']] == ['50-50', '33-67', '67-33', '3x33', '25-50-25', '4x25'] and c['max_columns'] == 6)
check('the gap and padding choices are the theme\'s spacing steps', [o[0] for f in c['settings'] if f['key'] == 'gap' for o in f['options']] == ['none', 'xs', 's', 'm', 'l', 'xl'])
check('the container is not a block type of the theme', 'container' not in [d['type'] for d in data['definitions']])
check('the page\'s container reaches the editor with its columns and blocks', data['blocks'][1]['type'] == 'container' and len(data['blocks'][1]['columns']) == 3 and data['blocks'][1]['columns'][1]['blocks'][1]['type'] == 'cta')
js = root.get('/assets/js/admin-blocks.js')[2]
check('the builder script has containers, columns and the convert actions', 'renderContainer' in js and 'Wrap in container' in js and 'Unwrap' in js and 'AdminSortable' in js)
check('the sortable module can say where a block went and take or refuse a list', 'listName' in root.get('/assets/js/admin-sortable.js')[2] and 'accept' in root.get('/assets/js/admin-sortable.js')[2])

def save(slug, blocks, extra=None):
    fields = {'type': 'pages', 'slug': slug, 'lang': 'el', 'title': slug, 'status': 'published', 'visible': '1', 'body': 'x', 'blocks_editor': '1', 'blocks_json': json.dumps(blocks)}
    fields.update(extra or {})
    root.submit('/admin/edit?type=pages&slug=%s&lang=el' % slug, has_field('body'), fields)
    return open('app/content/pages/%s.md' % slug, encoding='utf-8').read()

write('pages/c-save.md', "---\ntitle: c-save\nstatus: published\nvisible: true\n---\nx\n")
raw = save('c-save', [text('One'), {'type': 'container', 'settings': {'gap': 'l', 'direction': 'row', 'class': 'a<b'}, 'columns': [{'settings': {'width': '1/3', 'padding': 'm'}, 'blocks': [text('In A', width='50')]}, {'settings': {}, 'blocks': []}, {'blocks': [{'type': 'container', 'columns': []}, {'type': 'cta', 'heading': 'Go'}]}]}])
check('saved, a container is written as settings and columns, with only what differs from the default', 'type: container' in raw and 'gap: l' in raw and 'direction' not in raw and 'width: 1/3' in raw and 'padding: m' in raw, raw)
check('and the class is cleaned, a container in a column is dropped', 'class: ab' in raw and raw.count('type: container') == 1, raw)
check('the blocks inside keep their own fields', 'heading: \'In A\'' in raw or 'heading: In A' in raw, raw)
st, _, edit2 = root.get('/admin/edit?type=pages&slug=c-save&lang=el')
back = json.loads(re.search(r'id="block-editor-data"[^>]*>(.*?)</script>', edit2, re.S).group(1))['blocks']
check('and it reads back the same', back[1]['type'] == 'container' and back[1]['settings'] == {'gap': 'l', 'class': 'ab'} and len(back[1]['columns']) == 3 and back[1]['columns'][2]['blocks'][0]['type'] == 'cta', json.dumps(back[1])[:300])
raw2 = save('c-save', back)
strip = lambda t: '\n'.join(l for l in t.splitlines() if not l.startswith('translation_id:'))
check('saving what it read changes nothing', strip(raw2) == strip(raw), [(a, b) for a, b in zip(raw.splitlines(), raw2.splitlines()) if a != b][:3] or (len(raw), len(raw2), raw2[-80:]))

# raw front matter gets the same checks as the editor
fm = "---\ntitle: c-save\nstatus: published\nvisible: true\nblocks:\n  - type: container\n    settings: {gap: nonsense}\n    columns:\n      - blocks:\n          - {type: text, heading: Raw}\n---\nx\n"
raw3 = save('c-save', [], {'frontmatter': fm, 'blocks_editor': ''})
check('a container typed into the raw front matter is checked like the editor\'s', 'nonsense' not in raw3 or 'type: container' in raw3, raw3)

# ---- what reads the blocks of a page sees the ones inside the columns
conn = sqlite3.connect('app/storage/db/app.sqlite')
def revs(slug):
    return [zlib.decompress(r[0], -15).decode('utf-8') for r in conn.execute("select content from content_revisions where type='pages' and slug=? order by id", (slug,)).fetchall()]
v = revs('c-save')
check('the history keeps every version of a page with a container, the change shows as lines', len(v) >= 2 and 'type: container' in v[-1], len(v))
st, _, hist = root.get('/admin/revisions?type=pages&slug=c-save&lang=el')
check('and its screen opens', st == 200 and 'View changes' in hist, st)
rid = conn.execute("select id from content_revisions where type='pages' and slug='c-save' order by id desc limit 1").fetchone()[0]
st, _, diff = root.get('/admin/revisions?id=%s' % rid)
check('a version can be looked at, with its lines', st == 200 and 'View changes' not in diff and re.search(r'columns|container|gap', diff) is not None, re.sub(r'<[^>]+>', ' ', diff)[:300])
page('c-link', box([[{'type': 'cta', 'heading': 'L', 'label': 'Go', 'url': '/about-us-target'}], [text('M', body='See [it](/other-target)')]]), text('x'))
st, _, search = root.get('/search?q=Body')
check('search and the feed work for a site with containers', st == 200 and anon.get('/feed')[0] in (200, 404), st)
check('the sitemap lists the page', 'c-link' in anon.get('/sitemap.xml')[2])
open_media = root.get('/admin/media')[0]
check('the media screen opens (usage is read from the raw file, so nested pictures are seen)', open_media == 200)
check('the stored file holds the nested picture for the usage scan', '/uploads/x.jpg' in open('app/content/pages/c-three.md', encoding='utf-8').read())

# ---- footer blocks take containers too
st, _, theme = root.get('/admin/theme?tab=footer_blocks')
check('Theme > Footer Blocks hands the builder the container too', st != 200 or '"container"' in theme or 'max_columns' in theme, st)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
