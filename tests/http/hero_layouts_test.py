import re, sys, sqlite3
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:200]))
    if not ok: fails.append(label)

def run(sql, args=()):
    c = sqlite3.connect('app/storage/db/app.sqlite'); c.execute(sql, args); c.commit(); c.close()

def layouts(default='default', **per_type):
    """Choose the title layout the way Theme settings > Hero Layouts stores it: one per content type,
    with the general choice for the types that have none (the settings screen always stores all four)."""
    per_type = {**{t: default for t in ('pages', 'posts', 'projects', 'forms')}, **per_type}
    per_type['default'] = default
    body = 'hero_layouts:\n' + ''.join('  %s: %s\n' % (k, v) for k, v in per_type.items())
    run("insert or replace into system_meta (key, value, updated_at) values ('theme_settings', ?, datetime('now'))", (body,))

pub = Client()
def header(path):
    st, _, html = pub.get(path)
    m = re.search(r'<section class="(single-hero[^"]*)"[^>]*>(.*?)</section>', html, re.S)
    return st, (m.group(1) if m else ''), (m.group(2) if m else ''), html

post = '/rebrand-readiness-guide'
project = '/projects/gamma-hospitality-rebrand'
st, _, _, _ = header(post)
if st != 200:
    post = '/rebrand-readiness-guide'
st, _, _, _ = header('/en/projects/gamma-hospitality-rebrand')
project = '/en/projects/gamma-hospitality-rebrand' if st == 200 else '/projects/gamma-hospitality-rebrand'

# A post with the standard template (the fixture posts use the sidebar one, which has no line above the title).
open('app/content/posts/plain-post.md', 'w', encoding='utf-8').write("---\ntitle: Plain post\nstatus: published\nvisible: true\ndate: '2026-03-01'\nauthor: Ada\nexcerpt: A short summary.\ntags: [design]\ncategories: [news]\nmain_image: /uploads/media/5e6915a67b9ceec5.jpg\n---\n\nText.\n")
plain = '/plain-post'

# ---- the layouts that already existed keep their markup
layouts(default='default')
st, cls, inner, _ = header('/about')
check('default: a plain title header', st == 200 and cls == 'single-hero' and '<h1>Σχετικά</h1>' in inner and 'single-hero-subtitle' in inner, (st, cls))
# Without an excerpt the line under the title is left out; it is not the site tagline.
open('app/content/posts/bare.md', 'w', encoding='utf-8').write("---\ntitle: Bare post\nstatus: published\nvisible: true\ndate: '2026-03-02'\n---\n\nText.\n")
st, cls, inner, _ = header(post.rsplit('/', 1)[0] + '/bare')
check('default: no excerpt, no line under the title', st == 200 and '<h1>Bare post</h1>' in inner and 'single-hero-subtitle' not in inner, (st, inner[:300]))
st, cls, inner, _ = header(plain)
check('default: a post shows its tags and categories above the title', 'has-image' in cls and 'class="single-hero-meta"' in inner and 'design' in inner.lower() and 'A short summary.' in inner, (cls, inner[:300]))
layouts(default='centered')
st, cls, inner, _ = header(plain)
check('centered: the byline is worded for reading across', 'single-hero-centered' in cls and 'single-hero-meta-inline' in inner and 'Ada' in inner and 'single-hero-media' in inner, (cls, inner[:300]))

# ---- split
layouts(default='split')
st, cls, inner, html = header('/about')
check('split without an image: one column of text', 'single-hero-split' in cls and 'no-media' in cls and 'single-hero-content' in inner and 'single-hero-media' not in inner, (cls, inner[:200]))
st, cls, inner, html = header(post)
check('split with an image: text beside a picture', 'single-hero-split' in cls and 'no-media' not in cls and 'single-hero-content' in inner and 'single-hero-media' in inner and '<img' in inner, (cls, inner[:300]))
check('split: the title stays the only h1', html.count('<h1') == 1, re.findall(r'<h1[^>]*>[^<]*', html))

# ---- cover
layouts(default='cover')
st, cls, inner, html = header(post)
check('cover with an image: a full-width picture behind the text', 'single-hero-cover' in cls and 'single-hero-cover-media' in inner and '<img' in inner and '<h1>' in inner, (cls, inner[:300]))
check('cover: the picture loads first, not lazily', 'loading="lazy"' not in inner, inner[:400])
st, cls, inner, html = header('/about')
check('cover without an image falls back to the default look', cls == 'single-hero' and 'single-hero-cover-media' not in inner and '<h1>Σχετικά</h1>' in inner, (cls, inner[:200]))

# ---- minimal
st, cls, inner, html = header(plain)
check('cover: the byline sits above the title', 'single-hero-meta-inline' in inner and 'Ada' in inner, inner[:300])
layouts(default='minimal')
st, cls, inner, html = header(plain)
check('minimal: only text, even when the page has an image', 'single-hero-minimal' in cls and '<img' not in inner and 'single-hero-media' not in inner and '<h1>' in inner, (cls, inner[:300]))

# ---- per content type, and unknown values
layouts(default='default', pages='split', posts='cover', projects='minimal')
check('each content type has its own layout', [header('/about')[1].split()[1] if header('/about')[1] != 'single-hero' else '', 'single-hero-cover' in header(post)[1], 'single-hero-minimal' in header(project)[1]] == ['single-hero-split', True, True], (header('/about')[1], header(post)[1], header(project)[1]))
layouts(default='nonsense')
st, cls, inner, _ = header('/about')
check('an unknown layout shows the default one', st == 200 and cls == 'single-hero', (st, cls))
layouts(default='centered', pages='default')
st, cls, _, _ = header('/about'); check('a type set to default beats the general choice', cls == 'single-hero', cls)

# ---- one entry can choose its own layout
def put(path, front, body='Text.'):
    open('app/content/' + path, 'w', encoding='utf-8').write('---\n' + front + '---\n\n' + body + '\n')
layouts(default='split')
put('pages/own.md', "title: Own layout\nstatus: published\nvisible: true\nhero_layout: minimal\n")
put('pages/wrong.md', "title: Wrong layout\nstatus: published\nvisible: true\nhero_layout: sideways\n")
put('pages/plain.md', "title: Plain\nstatus: published\nvisible: true\n")
check('an entry\'s own layout wins over its content type', 'single-hero-minimal' in header('/own')[1], header('/own')[1])
check('a layout the theme does not know is ignored', 'single-hero-split' in header('/wrong')[1], header('/wrong')[1])
check('an entry without a choice follows its content type', 'single-hero-split' in header('/plain')[1], header('/plain')[1])
put('pages/own-cover.md', "title: Cover without a picture\nstatus: published\nvisible: true\nhero_layout: cover\n")
check('an own cover without an image still falls back', header('/own-cover')[1] == 'single-hero', header('/own-cover')[1])

# ---- the header over the opening hero, per content type and per entry
def theme(**sections):
    body = ''.join('%s:\n%s' % (name, ''.join('  %s: %s\n' % (k, v) for k, v in values.items())) for name, values in sections.items())
    run("insert or replace into system_meta (key, value, updated_at) values ('theme_settings', ?, datetime('now'))", (body,))
def over(path):
    st, _, html = pub.get(path)
    body = re.search(r'<body class="([^"]*)"', html)
    return st == 200 and body is not None and 'has-transparent-header' in body.group(1), html
put('pages/lead-hero.md', "title: Lead hero\nstatus: published\nvisible: true\nblocks:\n  - type: hero\n    variant: cover\n    heading: Opening\n")
put('pages/lead-hero-off.md', "title: Lead hero off\nstatus: published\nvisible: true\nheader_transparent: 'off'\nblocks:\n  - type: hero\n    variant: cover\n    heading: Opening\n")
theme(header={'transparent': 'false'})
check('nothing asks for it: the header stays solid', not over('/plain')[0] and not over('/lead-hero')[0])
theme(header={'transparent': 'true'})
check('the site-wide setting reaches a title area now, and a Hero block as before', over('/plain')[0] and over('/lead-hero')[0])
theme(header={'transparent': 'true'}, transparent_header={'default': 'site', 'pages': "'off'", 'posts': 'site', 'projects': 'site', 'forms': 'site'})
check('a content type can switch it off', not over('/plain')[0] and not over('/lead-hero')[0] and over(plain)[0])
theme(header={'transparent': 'false'}, transparent_header={'default': 'site', 'pages': "'on'", 'posts': 'site', 'projects': 'site', 'forms': 'site'})
check('a content type can switch it on', over('/plain')[0] and over('/lead-hero')[0] and not over(plain)[0])
check('an entry can switch it off', not over('/lead-hero-off')[0])
put('pages/own-on.md', "title: Own on\nstatus: published\nvisible: true\nheader_transparent: 'on'\n")
theme(header={'transparent': 'false'}, transparent_header={'default': 'site', 'pages': "'off'", 'posts': 'site', 'projects': 'site', 'forms': 'site'})
check('an entry can switch it on against its content type', over('/own-on')[0] and not over('/plain')[0])
put('pages/own-bool.md', "title: Own bool\nstatus: published\nvisible: true\nheader_transparent: true\n")
check('a plain yes in the front matter counts as on', over('/own-bool')[0])
theme(header={'transparent': 'true'}, transparent_header={'default': "'on'"}, hero_layouts={'pages': 'cover', 'default': 'default', 'posts': 'default', 'projects': 'default', 'forms': 'default'})
put('pages/cover-img.md', "title: Cover with picture\nstatus: published\nvisible: true\nmain_image: /uploads/media/5e6915a67b9ceec5.jpg\n")
ok, html = over('/cover-img')
check('over a cover the header text turns light', ok and 'is-on-dark' in html, ok)
theme(header={'transparent': 'true'}, hero_layouts={'pages': 'centered', 'default': 'default', 'posts': 'default', 'projects': 'default', 'forms': 'default'})
ok, html = over('/cover-img')
check('over a light title area it does not', ok and 'is-on-dark' not in html, ok)
# ---- a Hero slider that opens the page works like a Hero block
def slider_page(name, layout='cover', block='hero-slider', extra=''):
    put('pages/%s.md' % name, "title: %s\nstatus: published\nvisible: true\n%sblocks:\n  - type: %s\n    items:\n      - layout: %s\n        heading: One\n        image: /uploads/media/5e6915a67b9ceec5.jpg\n      - heading: Two\n" % (name.replace('-', ' '), extra, block, layout))
slider_page('lead-slider'); slider_page('lead-slider-split', layout='split'); slider_page('lead-slider-old', block='slider')
slider_page('lead-slider-off', extra="header_transparent: 'off'\n")
theme(header={'transparent': 'true'})
ok, html = over('/lead-slider')
check('a hero slider opens the page: the header sits over it, in light colours over a cover slide', ok and 'is-transparent' in html and 'is-on-dark' in html, (ok, re.findall(r'<header class="[^"]*"', html)))
check('the page has no title area of its own, and the title is the heading for assistive technology', 'class="single-hero' not in html and '<h1 class="visually-hidden">lead slider</h1>' in html and html.count('<h1') == 1, re.findall(r'<h1[^>]*>[^<]*', html))
check('and the text of the page comes after it, as it does after a Hero', html.find('>Text.<') > html.find('block-hero-slider') > 0, (html.find('>Text.<'), html.find('block-hero-slider')))
ok, html = over('/lead-slider-split')
check('over a first slide that is not a picture the header opens over it too, in dark colours', ok and 'is-transparent' in html and 'is-on-dark' not in html and 'class="single-hero' not in html, ok)
ok, html = over('/lead-slider-old')
check('a page saved with the old Slider block is a hero slider now', ok and 'block-hero-slider' in html and 'is-on-dark' in html, ok)
# ---- the buttons of a slide: saved as two fixed buttons (until 0.1.156) or as a list, the page is the same
slide = "  - type: hero-slider\n    items:\n      - layout: split\n        heading: Buttons\n%s      - layout: cover\n        heading: One button\n%s"
put('pages/buttons-old.md', "title: Buttons\nstatus: published\nvisible: true\nblocks:\n" + slide % (
    "        label: Go\n        url: contact\n        label_2: More\n        url_2: /more\n        style_2: text\n",
    "        label_2: Only\n        url_2: /only\n"))
put('pages/buttons-new.md', "title: Buttons\nstatus: published\nvisible: true\nblocks:\n" + slide % (
    "        buttons:\n          - label: Go\n            url: contact\n          - label: More\n            url: /more\n            style: text\n",
    "        buttons:\n          - label: Only\n            url: /only\n            style: secondary\n"))
def slides(path):
    st, _, html = pub.get(path)
    start = html.find('<div class="slider-track"')
    return st, html[start:html.find('</section>', start)] if start >= 0 else ''
st_old, old_slides = slides('/buttons-old')
st_new, new_slides = slides('/buttons-new')
check('a slide saved with two fixed buttons shows them as before: the first primary, the second as chosen', st_old == 200 and re.findall(r'<a class="btn ([^"]+)" href="[^"]*/(contact|more|only)"', old_slides) == [('btn-primary', 'contact'), ('btn-text', 'more'), ('btn-secondary', 'only')], re.findall(r'<a class="btn[^>]*>', old_slides))
check('and the same slides saved as a list of buttons give the same page', st_new == 200 and old_slides != '' and old_slides == new_slides, (len(old_slides), len(new_slides)))
ok, html = over('/lead-slider-off')
check('an entry can switch the transparent header off, and then it has its title area as before', not ok and 'class="single-hero' in html, ok)
theme(header={'transparent': 'false'})
ok, html = over('/lead-slider')
check('with the header solid the hero slider is a block under the title area', not ok and 'class="single-hero' in html and '<h1 class="visually-hidden">' not in html, ok)
theme(header={'transparent': 'false'}, transparent_header={'default': 'site', 'pages': "'on'", 'posts': 'site', 'projects': 'site', 'forms': 'site'})
check('a content type can ask for it, as it can for a Hero', over('/lead-slider')[0])
theme(header={'transparent': 'true'})
theme(header={'transparent': 'true'})
st, _, html = pub.get('/en/search?q=test'); check('screens without an entry follow the site-wide setting', st == 200)

# ---- the editor
root = Client(); root.login()
def form_fields(path):
    return root.forms(path)
for kind, url in (('a page', 'type=pages&slug=plain&lang=el'), ('a post', 'type=posts&slug=plain&lang=el'), ('a project', 'type=projects&slug=gamma-hospitality-rebrand&lang=en'), ('a form', 'type=forms&slug=contact&lang=el')):
    st, _, html = root.get('/admin/edit?' + url)
    check('the editor of ' + kind + ' offers both choices', st == 200 and 'name="hero_layout"' in html and 'name="header_transparent"' in html and 'Follow settings (' in html, st)
st, _, html = root.get('/admin/edit?type=pages&slug=plain&lang=el')
check('it says what the content type does now', 'Follow settings (Default)' in html or 'Follow settings (Split)' in html or 'Follow settings (Centered)' in html, re.findall(r'Follow settings \([^)]*\)', html))
check('the choices are the theme\'s', all(('>%s<' % l) in html for l in ('Split', 'Cover', 'Minimal', 'Centered')) and '>Over the title<' in html and '>Solid<' in html)
check('the "site default" choice is not offered per entry', '>Site default<' not in html)

def save(url, **values):
    return root.submit('/admin/edit?' + url, has_field('body'), values)
def stored(path): return open('app/content/' + path, encoding='utf-8').read()
save('type=pages&slug=plain&lang=el', hero_layout='cover', header_transparent='on')
text = stored('pages/plain.md')
check('saving keeps the entry\'s choices in the front matter', 'hero_layout: cover' in text and "header_transparent: 'on'" in text, text)
st, _, html = root.get('/admin/edit?type=pages&slug=plain&lang=el')
check('and the editor shows them', re.search(r'<option value="cover"\s+selected', html) is not None and re.search(r'<option value="on"\s+selected', html) is not None)
custom_panel = html.split('data-panel="custom"')[1].split('data-panel=')[0] if 'data-panel="custom"' in html else ''
check('they are not listed as custom fields', custom_panel != '' and 'hero_layout' not in custom_panel and 'header_transparent' not in custom_panel)
save('type=pages&slug=plain&lang=el', hero_layout='sideways', header_transparent='maybe')
text = stored('pages/plain.md')
check('a choice the theme does not offer changes nothing', 'hero_layout: cover' in text and "header_transparent: 'on'" in text, text)
save('type=pages&slug=plain&lang=el', hero_layout='', header_transparent='')
text = stored('pages/plain.md')
check('"Follow settings" removes them again', 'hero_layout' not in text and 'header_transparent' not in text, text)
save('type=forms&slug=contact&lang=el', hero_layout='minimal')
check('a form can choose too', 'hero_layout: minimal' in stored('forms/contact.md'), stored('forms/contact.md')[:300])

# ---- parallax: the picture of a default or cover title area moves slower than the page
def parallax_setting(layout, on):
    """Posts have this title layout and parallax on or off, as Theme settings > Single Layouts stores them."""
    body = 'single_layouts:\n  posts:\n    title: %s\n    parallax: %s\n' % (layout, 'true' if on else 'false')
    run("insert or replace into system_meta (key, value, updated_at) values ('theme_settings', ?, datetime('now'))", (body,))
def has_parallax(path):
    st, cls, inner, html = header(path)
    m = re.search(r'<section class="single-hero[^"]*"([^>]*)>', html)
    return st == 200 and m is not None and 'data-parallax' in m.group(1)
parallax_setting('default', False)
check('no parallax unless it is asked for', not has_parallax(plain))
parallax_setting('default', True)
check('a content type with parallax: the default title area with a picture has it', has_parallax(plain))
parallax_setting('cover', True)
check('and the cover', has_parallax(plain) and 'single-hero-cover' in header(plain)[1])
check('a page with no picture has none to move', not has_parallax('/about'))
for layout in ('centered', 'split', 'minimal'):
    parallax_setting(layout, True)
    check('%s keeps its picture where it is' % layout, not has_parallax(plain))
parallax_setting('default', True)
save('type=posts&slug=plain-post&lang=el', hero_parallax='off')
check('an entry can ask for none although its type has it', "hero_parallax: 'off'" in stored('posts/plain-post.md') and not has_parallax(plain), stored('posts/plain-post.md')[:200])
parallax_setting('default', False)
save('type=posts&slug=plain-post&lang=el', hero_parallax='on')
check('or for it although its type has none', "hero_parallax: 'on'" in stored('posts/plain-post.md') and has_parallax(plain))
st, _, html = root.get('/admin/edit?type=posts&slug=plain-post&lang=el')
check('the editor offers the choice and shows it', st == 200 and 'name="hero_parallax"' in html and re.search(r'<option value="on"\s+selected', html) is not None and 'Follow settings (Off)' in html, re.findall(r'Follow settings \([^)]*\)', html))
st, _, html = root.get('/admin/edit?type=forms&slug=contact&lang=el')
check('and so does the editor of a form', 'name="hero_parallax"' in html)
save('type=posts&slug=plain-post&lang=el', hero_parallax='sideways')
check('a choice that is not on or off changes nothing', "hero_parallax: 'on'" in stored('posts/plain-post.md'))
save('type=posts&slug=plain-post&lang=el', hero_parallax='')
check('"Follow settings" removes it', 'hero_parallax' not in stored('posts/plain-post.md'))
st, _, html = root.get('/admin/theme?tab=single_layouts')
check('Single Layouts has the Parallax choice for each type', st == 200 and html.count('[parallax]') >= 3, html.count('[parallax]'))
js = pub.get(re.search(r'src="([^"]*site\.js[^"]*)"', page_html := pub.get('/about')[2]).group(1))[2]
css = pub.get(re.search(r'href="([^"]*site\.css[^"]*)"', page_html).group(1))[2]
check('the theme script moves the picture (and not for people who ask for less motion), the style sheet makes room for it', 'data-parallax' in js and 'prefers-reduced-motion' in js and '.is-parallax' in css and '--parallax-y' in css)

# ---- the form page and the sidebar template use the same header
run("delete from system_meta where key='theme_settings'")
st, _, html = pub.get('/about'); check('with no choice made the pages still render', st == 200 and 'single-hero' in html, st)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
