import json, os, re, sys
sys.path.insert(0, '.')
from client import Client

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
anon = Client()
def write(path, text): open('app/content/' + path, 'w', encoding='utf-8').write(text)

def page(slug, *blocks, client=None):
    write('pages/%s.md' % slug, "---\ntitle: '%s'\nstatus: published\nvisible: true\nblocks: %s\n---\n" % (slug, json.dumps(list(blocks), ensure_ascii=False)))
    return (client or anon).get('/' + slug)

def section(html, kind):
    m = re.search(r'<section class="block block-%s[^"]*".*?</section>' % kind, html, re.S)
    return m.group(0) if m else ''

# ---- what the editor is given
st, _, edit = root.get('/admin/edit?type=pages&slug=about&lang=en')
data = json.loads(re.search(r'id="block-editor-data"[^>]*>(.*?)</script>', edit, re.S).group(1))
defs = {d['type']: d for d in data['definitions']}
kinds = {'image': 'Media', 'divider': 'Content', 'quote': 'Content', 'downloads': 'Content', 'checklist': 'Content', 'portfolio': 'Showcase', 'table': 'Content', 'marquee': 'Showcase'}
check('the editor offers the new blocks, each of a kind and with a picture', all(t in defs and defs[t]['category'] == c and defs[t]['preview'] != '' for t, c in kinds.items()), {t: defs.get(t, {}).get('category') for t in kinds})
check('Downloads has a field that picks a file', any(f['type'] == 'file' for f in next(f for f in defs['downloads']['fields'] if f['key'] == 'items')['fields']))
check('Pricing has the switch, and each plan a yearly price', any(f['key'] == 'billing_switch' for f in defs['pricing']['fields']) and any(f['key'] == 'yearly_price' for f in next(f for f in defs['pricing']['fields'] if f['key'] == 'items')['fields']))
check('Text has a layout with a contents list', 'contents' in [v[0] for v in next(f for f in defs['text']['common'] if f['key'] == 'variant')['options']])

# ---- Image
st, _, html = page('img', {'type': 'image', 'image': '/uploads/media/missing.jpg', 'alt': 'A lake', 'caption': 'The <b>lake</b>', 'link': '/contact', 'new_tab': True, 'ratio': 'panorama', 'variant': 'narrow'})
s = section(html, 'image')
check('an image has its caption, as text', st == 200 and '<figcaption class="image-block-caption">The &lt;b&gt;lake&lt;/b&gt;</figcaption>' in s, s)
check('and a link that can open in a new tab, safely', re.search(r'<a class="image-block-link" href="[^"]*/contact" target="_blank" rel="noopener noreferrer">', s) is not None and 'ανοίγει σε νέα καρτέλα' in s, s)
check('the shape and the width are the ones chosen', 'ratio-panorama' in s and 'container-narrow' in s)
st, _, html = page('img2', {'type': 'image', 'image': '/uploads/media/missing.jpg', 'variant': 'full', 'rounded': True})
s = section(html, 'image')
check('edge to edge has no frame and no rounding', 'image-block-full' in s and 'is-rounded' not in s and '<a ' not in s, s)
st, _, html = page('img3', {'type': 'image', 'caption': 'No picture'})
check('an image block with no picture leaves no section', 'block-image' not in html)

# ---- Divider
st, _, html = page('div', {'type': 'divider', 'variant': 'space', 'height': 'l'}, {'type': 'divider', 'variant': 'line', 'style': 'dashed', 'width': 'short'}, {'type': 'divider', 'variant': 'label', 'label': 'Our <i>work</i>'})
parts = re.findall(r'<section class="block block-divider.*?</section>', html, re.S)
check('a space is empty, as tall as chosen, and hidden from screen readers', len(parts) == 3 and 'divider-space size-l' in parts[0] and 'aria-hidden="true"' in parts[0], parts[:1])
check('a line is a rule, in the style and width chosen', '<hr class="divider-line style-dashed width-short">' in parts[1], parts[1:2])
check('a label sits between two rules, as text', 'divider-text">Our &lt;i&gt;work&lt;/i&gt;</span>' in parts[2] and parts[2].count('divider-rule') == 2, parts[2:3])
st, _, html = page('div2', {'type': 'divider', 'variant': 'label'})
check('a label divider with no label is a plain line', '<hr class="divider-line' in html)

# ---- Quote
st, _, html = page('quote', {'type': 'quote', 'quote': 'It <b>worked</b>.\nSecond line.', 'name': 'Anna', 'role': 'COO'}, {'type': 'quote', 'quote': '', 'name': 'Nobody'})
s = section(html, 'quote')
check('a quote is a figure with the words, the name and the role, as text', '<blockquote class="quote-text"><p>It &lt;b&gt;worked&lt;/b&gt;.<br />' in s and 'quote-name">Anna' in s and 'quote-role">COO' in s, s)
check('with a quotation mark that is hidden from screen readers', 'class="quote-mark" aria-hidden="true"' in s)
check('a quote with no words leaves no section', html.count('block-quote') == 1)

# ---- Downloads
os.makedirs('app/public/uploads/media', exist_ok=True)
open('app/public/uploads/media/Annual_report-2025.pdf', 'wb').write(b'%PDF-1.4\n' + b'x' * 2040)
st, _, html = page('dl', {'type': 'downloads', 'variant': 'cards', 'heading': 'Resources', 'items': [
    {'file': '/uploads/media/Annual_report-2025.pdf', 'description': 'All about 2025.'},
    {'title': 'Handbook', 'file': '/uploads/media/Annual_report-2025.pdf', 'size': '9 MB'},
    {'title': 'Elsewhere', 'file': 'https://example.com/files/pack.zip'},
    {'title': 'Traversal', 'file': '/uploads/../content/pages/dl.md'},
    {'title': 'Draft', 'description': 'Not ready'}]})
s = section(html, 'downloads')
check('a file is a link that downloads, with its kind and the size read from the file', 'href="/uploads/media/Annual_report-2025.pdf" download' in s.replace('http://localhost', '') or re.search(r'href="[^"]*/uploads/media/Annual_report-2025\.pdf" download', s) is not None, s)
check('the title is the file name when there is none', 'download-title">Annual report 2025</h3>' in s and 'PDF · 2.0 KB' in s, re.findall(r'download-meta">[^<]*', s))
check('a size the editor typed wins over the file', 'PDF · 9 MB' in s)
check('a file on another site opens in a new tab and has no size', re.search(r'href="https://example.com/files/pack.zip" target="_blank" rel="noopener noreferrer"', s) is not None and 'ZIP</span>' in s)
check('an address that leaves the uploads folder gives no size', 'Traversal' in s and not re.search(r'Traversal.*?download-meta">[^<]*KB', s, re.S))
check('a row with no file is not shown to a visitor', 'Not ready' not in s and 'Draft' not in s)
st, _, html = page('dl', {'type': 'downloads', 'items': [{'title': 'Draft', 'description': 'Not ready'}]}, client=root)
check('but the person who is signed in sees it, with why', 'is-missing' in html and 'Δεν έχει επιλεγεί αρχείο' in html, html[-600:])

# ---- Checklist
st, _, html = page('ck', {'type': 'checklist', 'heading': 'Included', 'icon': 'star', 'items': [{'text': 'Hosting', 'note': 'On our servers'}, {'text': 'Phone <b>support</b>', 'excluded': True}, {'text': 'Reviews', 'icon': 'bolt'}]})
s = section(html, 'checklist')
check('a checklist lists its points with a mark each', s.count('<li class="check-item') == 3 and 'check-note">On our servers' in s, s)
check('a point can be left out, with a cross, struck through, and said so to screen readers', 'check-item is-excluded' in s and 'δεν περιλαμβάνεται' in s and 'Phone &lt;b&gt;support&lt;/b&gt;' in s)
check('it does not take the class that styles lists inside text', re.search(r'class="checklist[ "]', s) is None)

# ---- Portfolio
st, _, html = page('pf', {'type': 'portfolio', 'heading': 'Work', 'items': [
    {'title': 'Alpha', 'category': 'Web, Branding', 'url': '/contact'},
    {'title': 'Beta', 'category': 'Branding'},
    {'title': 'Gamma', 'category': 'Print'},
    {'title': 'Delta <i>', 'category': 'web'}]})
s = section(html, 'portfolio')
check('a portfolio shows each piece with its categories', s.count('class="portfolio-item"') == 4 and 'work-cats">Web · Branding' in s, s)
check('with a button for each category, in the order they first appear, and one for all', re.findall(r'data-filter="([^"]*)"', s) == ['', 'web', 'branding', 'print'], re.findall(r'data-filter="([^"]*)"', s))
check('each piece says which categories it is in', 'data-categories="web branding"' in s and 'data-categories="print"' in s)
check('the buttons are hidden until the script shows them', re.search(r'data-portfolio-filters hidden', s) is not None)
check('the script comes with the block', re.search(r'_blocks/[0-9a-f]+\.js\?b=[^"]*portfolio', html) is not None, re.findall(r'_blocks/[0-9a-f]+\.js\?b=[^&"]*', html))
check('a title is text, and a piece with a link is one', 'Delta &lt;i&gt;' in s and re.search(r'class="work-link" href="[^"]*/contact">Alpha', s) is not None)
st, _, html = page('pf2', {'type': 'portfolio', 'items': [{'title': 'One', 'category': 'Web'}, {'title': 'Two', 'category': 'Web'}]}, {'type': 'portfolio', 'filters': False, 'items': [{'title': 'One', 'category': 'Web'}, {'title': 'Two', 'category': 'Print'}]})
check('one category is nothing to choose, and the buttons can be switched off', 'data-portfolio-filters' not in html)
write('posts/work-one.md', "---\ntitle: 'First job'\nstatus: published\nvisible: true\ndate: '2026-01-02'\ncategories: [news, guides]\n---\nText.\n")
write('posts/work-two.md', "---\ntitle: 'Second job'\nstatus: published\nvisible: true\ndate: '2026-01-03'\ncategories: [news]\n---\nText.\n")
st, _, html = page('pf3', {'type': 'portfolio', 'source': 'posts', 'limit': 6})
s = section(html, 'portfolio')
check('it can show the entries of a content type, filtered by their categories', 'First job' in s and 'Second job' in s and re.findall(r'data-filter="([^"]*)"', s)[:2] == ['', 'news'] and 'data-categories="news guides"' in s, re.findall(r'data-filter="([^"]*)"', s))
st, _, html = page('pf4', {'type': 'portfolio', 'items': []})
check('a portfolio with nothing in it leaves no section', 'block-portfolio' not in html)

# ---- Table
st, _, html = page('tb', {'type': 'table', 'heading': 'Plans', 'head': 'Plan | Users | Price', 'rows': 'Starter | 3 | €9\nTeam | **20** | €29\n---|---|---\nBig <b>one</b> | [Talk](/contact) | [Bad](javascript:alert(1))\nTab\tdelimited\t€1.200', 'caption': 'Prices exclude VAT.'})
s = section(html, 'table')
check('a table has a header row and a row for each line', s.count('<th scope="col"') == 3 and s.count('<tr>') == 5 and 'Prices exclude VAT.' in s, s)
check('the first column holds the row titles', '<th scope="row">Starter</th>' in s)
check('bold and links work in a cell, other markup does not', '<strong>20</strong>' in s and re.search(r'<a href="[^"]*/contact">Talk</a>', s) is not None and 'Big &lt;b&gt;one&lt;/b&gt;' in s)
check('a link that is not safe stays as the text typed', 'href="javascript' not in s and '[Bad](javascript:alert(1))' in s)
check('a copy from a spreadsheet (tabs) works', '<td>delimited</td>' in s and '€1.200' in s)
check('the table can be reached with the keyboard and has a name', 'role="region" aria-label="Plans" tabindex="0"' in s)
st, _, html = page('tb2', {'type': 'table', 'head': 'A | B', 'rows': ''})
check('a table with no rows leaves no section', 'block-table' not in html)
st, _, html = page('tb3', {'type': 'table', 'rows': 'x | 10\ny | 20', 'first_column': False, 'align_numbers': True})
s = section(html, 'table')
check('with no header row there is no thead, and a column of figures is aligned', '<thead' not in s and s.count('<td class="is-num">') == 2 and '<th scope="row">' not in s, s)

# ---- Marquee
st, _, html = page('mq', {'type': 'marquee', 'heading': 'Trusted by', 'items': [{'text': 'Acme', 'url': '/contact'}, {'text': 'Globex'}]}, {'type': 'marquee', 'items': []})
s = section(html, 'marquee')
check('a marquee has its items twice, and the copy is hidden from screen readers and the keyboard', s.count('class="marquee-group"') == 2 and s.count('<ul class="marquee-group" role="list" aria-hidden="true">') == 1 and 'tabindex="-1"' in s, s)
check('the speed and the direction are classes, never inline', 'marquee speed-normal dir-left' in s and 'style=' not in s)
check('the labels of the pause button come with the page', 'data-label-pause="Παύση της κινούμενης ταινίας"' in s)
check('a marquee with no items leaves no section', html.count('block-marquee') == 1)
check('its script comes with the block', re.search(r'_blocks/[0-9a-f]+\.js\?b=[^"]*marquee', html) is not None)

# ---- Pricing
plans = [{'name': 'Starter', 'price': '€9', 'period': 'per month', 'yearly_price': '€90', 'yearly_period': 'per year'}, {'name': 'Team', 'price': '€29', 'yearly_price': '€290'}, {'name': 'Custom', 'price': 'Talk'}]
st, _, html = page('pr', {'type': 'pricing', 'billing_switch': True, 'yearly_note': 'Save 20%', 'items': plans})
s = section(html, 'pricing')
check('pricing can offer a monthly and a yearly switch, hidden until the script shows it', 'data-pricing-switch hidden' in s and 'Μηνιαία' in s and 'Ετήσια<span class="pricing-save">Save 20%</span>' in s, s)
check('a plan with a yearly price carries both prices', s.count('data-billing-price="monthly"') == 2 and s.count('data-billing-price="yearly"') == 2 and '€290' in s)
check('a yearly price with no unit of its own says per year, not per month', re.search(r'data-billing-price="yearly"><span class="plan-amount">€290</span>[^<]*<span class="plan-period">ανά έτος</span>', s) is not None, re.findall(r'data-billing-price="yearly">.*?</p>', s, re.S))
check('a plan with no yearly price is the same either way', s.count('Talk') == 1 and re.search(r'<p class="plan-price"><span class="plan-amount">Talk', s) is not None)
st, _, html = page('pr2', {'type': 'pricing', 'items': plans})
check('without the switch it is as it was', 'data-pricing' not in html and 'data-billing-price' not in html)
check('the script comes with the block', re.search(r'_blocks/[0-9a-f]+\.js\?b=[^"]*pricing', page('pr', {'type': 'pricing', 'billing_switch': True, 'items': plans})[2]) is not None)

# ---- Text with a contents list
body = 'Intro.\n\n## First part\n\nOne.\n\n## Second part\n\nTwo.\n\n### Detail\n\nThree.'
st, _, html = page('toc', {'type': 'text', 'variant': 'contents', 'heading': 'Terms', 'body': body})
s = section(html, 'text')
check('text can have a contents list made of its ## headings', 'text-contents-list' in s and re.findall(r'<li><a href="#([^"]+)">([^<]*)</a></li>', s) == [('first-part', 'First part'), ('second-part', 'Second part')], re.findall(r'<li><a href="#([^"]+)">', s))
check('and the headings have the addresses it links to', '<h2 id="first-part">' in s and '<h3 id="detail">' in s)
st, _, html = page('toc2', {'type': 'text', 'variant': 'contents', 'body': 'One paragraph.\n\n## Only one'})
check('with fewer than two headings there is no list', 'text-contents-list' not in html and 'has-no-list' in html)

# ---- the page layouts
st, _, edit = root.get('/admin/edit?type=pages&slug=about&lang=en')
data = json.loads(re.search(r'id="block-editor-data"[^>]*>(.*?)</script>', edit, re.S).group(1))
presets = {p['id']: p for p in data['presets']}
layouts = ['lead-generation', 'pricing', 'portfolio', 'case-study', 'careers', 'blog-home', 'help-center', 'resources', 'event', 'services-overview', 'legal', 'coming-soon', 'link-in-bio']
ids = {i.split(':', 1)[1].replace('page-', '', 1): i for i in presets}
check('the editor offers the 13 new page layouts', all(l in ids and presets[ids[l]]['kind'] == 'page' for l in layouts), [l for l in layouts if l not in ids])
check('some of them suggest the Landing template, the others none', {l: presets[ids[l]].get('template', '') for l in ('lead-generation', 'event', 'coming-soon', 'link-in-bio', 'pricing', 'legal')} == {'lead-generation': 'landing', 'event': 'landing', 'coming-soon': 'landing', 'link-in-bio': 'landing', 'pricing': '', 'legal': ''}, {l: presets[ids[l]].get('template') for l in layouts})
for l in layouts:
    blocks = presets[ids[l]]['blocks']
    st, _, html = page('layout-' + l, *blocks)
    n = len(re.findall(r'<section class="block ', html))
    check('the %s layout is a page that opens, with its blocks' % l, st == 200 and n >= max(1, len(blocks) - 2) and 'Fatal error' not in html and 'Twig\\Error' not in html and 'Uncaught' not in html, (st, n, len(blocks)))
st, _, html = page('layout-resources', *presets[ids['resources']]['blocks'], client=root)
check('the resources layout shows the person who is signed in the rows that wait for a file', html.count('is-missing') == 7, html.count('is-missing'))
st, _, html = page('layout-portfolio', *presets[ids['portfolio']]['blocks'])
check('the portfolio layout has filters for its categories', re.findall(r'data-filter="([^"]*)"', html) == ['', 'web', 'branding', 'print'], re.findall(r'data-filter="([^"]*)"', html))
st, _, html = page('layout-legal', *presets[ids['legal']]['blocks'])
check('the legal layout has its contents list', html.count('<li><a href="#') >= 6)
st, _, edit_el = root.get('/admin/edit?type=pages&slug=about&lang=el')
data_el = json.loads(re.search(r'id="block-editor-data"[^>]*>(.*?)</script>', edit_el, re.S).group(1))
presets_el = {p['id']: p for p in data_el['presets']}
check('and the same in Greek, for a Greek page', all(ids[l] in presets_el and presets_el[ids[l]]['blocks'][0]['type'] == presets[ids[l]]['blocks'][0]['type'] and (l == 'link-in-bio' or presets_el[ids[l]]['label'] != presets[ids[l]]['label']) for l in layouts))

# ---- Logos: they fill the width of the content however many there are, in the colour that was chosen
def logos(n, colors=None, variant='row', size=None):
    block = {'type': 'logos', 'variant': variant, 'items': [{'name': 'Logo %d' % i} for i in range(n)]}
    if colors is not None: block['colors'] = colors
    if size is not None: block['size'] = size
    st, _, html = page('logos-%d-%s-%s-%s' % (n, variant, colors, size), block)
    return section(html, 'logos')
def cols(html):
    m = re.search(r'--logo-cols: (\d+)', html)
    return int(m.group(1)) if m else None
check('one row of up to five logos at the usual height, each its own column', [cols(logos(n)) for n in (1, 2, 4, 5)] == [1, 2, 4, 5], [cols(logos(n)) for n in (1, 2, 4, 5)])
check('more are in rows of the same length (6 in 3 and 3, 7 in 4 and 3, 12 in 4, 13 in 5)', [cols(logos(n)) for n in (6, 7, 12, 13)] == [3, 4, 4, 5], [cols(logos(n)) for n in (6, 7, 12, 13)])
check('the colour is black and white with colour on hover when nothing is chosen, as it was', 'data-colors="hover"' in logos(3), logos(3)[:200])
check('and can be always black and white, or their own colours', 'data-colors="mono"' in logos(3, 'mono') and 'data-colors="color"' in logos(3, 'color'))
check('a choice that is not one of them is the usual', 'data-colors="hover"' in logos(3, 'rainbow'))
check('the height is medium when nothing is chosen, and can be small, large or extra large', 'data-size="medium"' in logos(3) and all(('data-size="%s"' % z) in logos(3, size=z) for z in ('small', 'large', 'xlarge')))
check('a height that is not one of them is medium', 'data-size="medium"' in logos(3, size='huge'))
check('taller logos need more room, so a row holds fewer (seven, five, four, three)', [cols(logos(7, size=z)) for z in ('small', 'medium', 'large', 'xlarge')] == [7, 4, 4, 3] and [cols(logos(4, size='large')), cols(logos(4, size='xlarge')), cols(logos(3, size='xlarge'))] == [4, 2, 3], [[cols(logos(7, size=z)) for z in ('small', 'medium', 'large', 'xlarge')], cols(logos(4, size='xlarge'))])
check('the framed grid works the same', 'block--grid' in logos(7, 'color', 'grid') and cols(logos(7, 'color', 'grid')) == 4)

# ---- Hero: the text over a picture can be centred (a layout of its own); the editor draws each layout
def hero(slug, **values):
    st, _, html = page(slug, {'type': 'hero', 'heading': 'Centre me', 'text': 'Some text.', 'background': 'image', 'image': '/uploads/media/5e6915a67b9ceec5.jpg', **values})
    return html
h = hero('hero-left', variant='cover')
check('a cover hero keeps its text on the left', 'hero-inner is-centered' not in h and 'hero-cover-media is-centered' not in h and 'hero-cover-media' in h and 'block--cover-centered' not in h)
h = hero('hero-centre', variant='cover-centered')
check('the centred cover marks its text and its shade, and is a cover for the styles', 'hero-inner is-centered' in h and 'hero-cover-media is-centered' in h and 'block--cover-centered block--cover ' in h, re.findall(r'<section class="block block-hero[^"]*"', h))
h = hero('hero-steps-centre', variant='steps-centered', items=[{'title': 'One', 'text': 'First'}])
check('and so does the steps layout, with its steps', 'hero-inner is-centered' in h and 'hero-steps' in h and 'block--steps-centered block--steps ' in h, re.findall(r'<section class="block block-hero[^"]*"', h))
h = hero('hero-legacy', variant='cover', align='center')
check('a hero saved with the old alignment (0.1.86) still shows centred', 'hero-inner is-centered' in h and 'block--cover-centered' in h)
h = hero('hero-split', variant='split')
check('the other layouts are not centred', 'is-centered' not in h.split('<main')[1])
st, _, edit = root.get('/admin/edit?type=pages&slug=about&lang=en')
data = json.loads(re.search(r'id="block-editor-data"[^>]*>(.*?)</script>', edit, re.S).group(1))
hero_def = next(d for d in data['definitions'] if d['type'] == 'hero')
check('the editor gets a drawing of each layout, the two centred ones included', sorted(hero_def['variant_previews']) == ['centered', 'cover', 'cover-centered', 'minimal', 'split', 'steps', 'steps-centered'], sorted(hero_def['variant_previews']))
check('and the labels of the two new layouts', [o[1] for o in next(f for f in hero_def['common'] if f['key'] == 'variant')['options'] if o[0].endswith('-centered')] == ['Centered text over a full-width image', 'Centered text over a full-width image, with numbered steps'])
app_css = root.get('/assets/css/app.css')[2]
check('the admin style sheet has the drawn layout choice', '.bk-vtiles' in app_css)

# ---- Columns: a picture with no text and no link has nothing to sit beside, so it takes the whole column
st, _, html = page('cols-bare', {'type': 'columns', 'variant': 'image-side', 'items': [
    {'title': 'Only a picture', 'image': '/uploads/a.png', 'image_alt': 'A'},
    {'title': 'Picture and text', 'image': '/uploads/b.png', 'image_alt': 'B', 'text': 'Some words.'},
]})
c = section(html, 'columns')
arts = re.findall(r'<article class="col[^"]*".*?</article>', c, re.S)
check('a column with only a picture is marked and has no empty text area', len(arts) == 2 and 'is-bare' in arts[0] and 'col-body' not in arts[0], arts[:1])
check('a column with a picture and text keeps the picture beside the text', 'is-bare' not in arts[1] and 'col-body' in arts[1], arts[1:])
check('the narrow picture column applies only to columns that are not bare', ':not(.is-bare) .col-main' in open('app/themes/default/blocks/columns/block.css', encoding='utf-8').read())

# ---- Text and image: the picture can also sit above or below the text
ti = defs['text-image']
check('Text and image offers the picture above and below, drawn, and a wide shape', [o[0] for o in next(f for f in ti['common'] if f['key'] == 'variant')['options']] == ['image-right', 'image-left', 'image-top', 'image-bottom'] and sorted(ti['variant_previews']) == ['image-bottom', 'image-left', 'image-right', 'image-top'] and 'wide' in [o[0] for o in next(f for f in ti['fields'] if f['key'] == 'image_ratio')['options']], sorted(ti['variant_previews']))
for variant in ('image-top', 'image-bottom'):
    st, _, html = page('ti-' + variant, {'type': 'text-image', 'variant': variant, 'heading': 'Above or below', 'image': '/uploads/a.png', 'image_ratio': 'wide'})
    t = section(html, 'text-image')
    check('%s renders its variant class and the wide frame' % variant, ('block--' + variant) in t and 'ratio-wide' in t and 'ti-text' in t and 'ti-media' in t, t[:200])
ti_css = open('app/themes/default/blocks/text-image/block.css', encoding='utf-8').read()
check('its style sheet puts the picture first for "above" and keeps one column', '.block--image-top .ti-media' in ti_css and '.ti-media.ratio-wide' in ti_css)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
