"""The gallery of an entry over HTTP: the field in the editor of a route (a box of addresses that the script turns into thumbnails),
saving it as a YAML list, a gallery written at the top of the front matter (as an import may write it) shown and moved under
custom_fields on the next save, the strip on the page of a route (under the title by default, in the body, or off, from the
Single Layouts card) with the gallery's style sheet and script, nothing for an entry with no pictures, and a Gallery block in a
page's blocks showing the entry's gallery."""
import os, re, sys
sys.path.insert(0, '.')
from client import Client

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
anon = Client()
def write(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    open(path, 'w', encoding='utf-8').write(text)
def read(path): return open(path, encoding='utf-8').read()

# Three pictures in the library (any bytes do for a link; the page links the full picture).
PNG = bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6300010000000500010d0a2db40000000049454e44ae426082')
for name in ('one', 'two', 'three'):
    os.makedirs('app/public/uploads/media', exist_ok=True)
    open('app/public/uploads/media/%s.png' % name, 'wb').write(PNG)
ONE, TWO, THREE = '/uploads/media/one.png', '/uploads/media/two.png', '/uploads/media/three.png'

write('app/content/routes/gorge.en.md', "---\ntitle: 'The gorge'\nstatus: published\nvisible: true\ncustom_fields:\n  area: Sitia\n  location: '35.2, 26.1'\n  gallery:\n    - %s\n    - %s\n---\nWalk down the gorge.\n" % (TWO, ONE))
write('app/content/routes/bare.en.md', "---\ntitle: 'No pictures'\nstatus: published\nvisible: true\ncustom_fields:\n  location: '35.3, 26.2'\n---\nNothing to see.\n")
write('app/content/routes/imported.en.md', "---\ntitle: 'Imported'\nstatus: published\nvisible: true\ngallery: ['%s', '%s']\ncustom_fields:\n  location: '35.4, 26.3'\n---\nFrom the old site.\n" % (THREE, ONE))

def strip_links(html, where=None):
    part = html
    if where == 'title':
        part = html.split('class="place-hero')[1].split('place-layout')[0]
    elif where == 'body':
        part = html.split('class="place-main')[1].split('</article>')[0]
    return re.findall(r'<a class="gallery-link" href="([^"]+)"', part)

# ---- the page of a route: under the title by default
st, _, html = anon.get('/en/routes/gorge')
check('the strip of the gallery is under the title band, in the order chosen', st == 200 and 'entry-strip--title' in html and strip_links(html, 'title') == [TWO, ONE], (st, strip_links(html)))
check('it is a Gallery block, Scrolling strip, opening in the viewer', 'block-gallery block--strip' in html and 'data-gallery ' in html)
check('the gallery\'s style sheet and script come with the map\'s', re.search(r'_blocks\.css\?b=[^"]*gallery', html) is not None and re.search(r'_blocks\.css\?b=[^"]*map', html) is not None and re.search(r'_blocks\.js\?b=[^"]*gallery', html) is not None, re.findall(r'_blocks\.(?:css|js)\?b=[^&"]*', html))
check('the gallery is not a line of the information card', 'Gallery:' not in html and '/uploads/media/two.png</' not in html)
st, _, html = anon.get('/en/routes/bare')
check('a route with no pictures has no strip and no gallery styles', st == 200 and 'entry-strip' not in html and 'block-gallery' not in html and re.search(r'_blocks\.css\?b=[^"]*gallery', html) is None)
st, _, html = anon.get('/en/routes/imported')
check('a gallery at the top of the front matter is shown too', strip_links(html, 'title') == [THREE, ONE], strip_links(html))

# ---- the editor
st, _, html = root.get('/admin/edit?type=routes&slug=gorge&lang=en')
box = re.search(r'<div class="gal" data-gallery-field data-max="100">\s*<textarea name="details\[gallery\]"[^>]*>([^<]*)</textarea>', html)
check('a route has a gallery field: a box with one address on each line, for the script to turn into thumbnails', st == 200 and box is not None and box.group(1).split('\n') == [TWO, ONE], html[html.find('data-gallery-field') - 200:html.find('data-gallery-field') + 400] if 'data-gallery-field' in html else st)
check('the admin loads the script of the gallery field', 'js/admin-gallery-field.js' in html)
def edit_form(f): return any(x[0] == 'title' for x in f['fields']) and any(x[0] == 'details[gallery]' for x in f['fields'])
root.submit('/admin/edit?type=routes&slug=gorge&lang=en', edit_form, {'details[gallery]': '%s\n%s\n\n%s\njavascript:alert(1)\n%s' % (THREE, ONE, TWO, THREE)})
saved = read('app/content/routes/gorge.en.md')
check('saved as a YAML list under custom_fields, in the new order, once each and only addresses', re.search(r"gallery:\n\s+- /uploads/media/three\.png\n\s+- /uploads/media/one\.png\n\s+- /uploads/media/two\.png\n", saved) is not None and 'javascript' not in saved, saved)
check('and the page follows', strip_links(anon.get('/en/routes/gorge')[2], 'title') == [THREE, ONE, TWO])
root.submit('/admin/edit?type=routes&slug=imported&lang=en', edit_form, {})
saved = read('app/content/routes/imported.en.md')
front = saved.split('---')[1]
check('a gallery at the top is offered in the editor and moved under custom_fields when the entry is saved', re.search(r"custom_fields:(.|\n)*gallery:\n\s+- /uploads/media/three\.png\n\s+- /uploads/media/one\.png", front) is not None and not re.search(r'^gallery:', front, re.M), front)
root.submit('/admin/edit?type=routes&slug=gorge&lang=en', edit_form, {'details[gallery]': ''})
saved = read('app/content/routes/gorge.en.md')
check('an emptied gallery leaves nothing behind, and the page no strip', 'gallery' not in saved.split('---')[1] and 'entry-strip' not in anon.get('/en/routes/gorge')[2], saved)
root.submit('/admin/edit?type=routes&slug=gorge&lang=en', edit_form, {'details[gallery]': '%s\n%s' % (ONE, TWO)})

# ---- the Single Layouts card
def theme_form(client):
    return next(f for f in client.forms('/admin/theme?tab=single_layouts') if any(x[0] == 'active_tab' for x in f['fields']))
def save_card(value):
    fields = [tuple(x) for x in theme_form(root)['fields'] if x[0] not in ('single_layouts[routes][options][gallery_strip]', 'active_tab')]
    return root.request('/admin/theme', data=fields + [('single_layouts[routes][options][gallery_strip]', value), ('active_tab', 'single_layouts')])
st, _, html = root.get('/admin/theme?tab=single_layouts')
card = html.split('aria-labelledby="single-routes"')[1].split('</article>')[0] if 'aria-labelledby="single-routes"' in html else ''
check('the card of routes offers the strip: off, under the title (chosen until changed), in the body', st == 200 and re.findall(r'name="single_layouts\[routes\]\[options\]\[gallery_strip\]" value="(\w+)"', card) == ['off', 'title', 'body'] and re.search(r'name="single_layouts\[routes\]\[options\]\[gallery_strip\]" value="title"\s+checked', card) is not None, card[card.find('gallery_strip') - 300:card.find('gallery_strip') + 500])
check('a type without a gallery is not offered it', 'name="single_layouts[pages][options][gallery_strip]"' not in html and 'name="single_layouts[posts][options][gallery_strip]"' not in html)
save_card('body')
html = anon.get('/en/routes/gorge')[2]
check('in the body: the strip is at the end of the text, not under the title', 'entry-strip--body' in html and strip_links(html, 'body') == [ONE, TWO] and strip_links(html, 'title') == [] and html.index('Walk down the gorge') < html.index('entry-strip--body'), strip_links(html))
save_card('off')
html = anon.get('/en/routes/gorge')[2]
check('off: no strip, no gallery styles', 'entry-strip' not in html and 'block-gallery' not in html and re.search(r'_blocks\.css\?b=[^"]*gallery', html) is None)
save_card('title')

# ---- the Gallery block with the entry's gallery as its source
write('app/content/routes/blocks.en.md', "---\ntitle: 'With a block'\nstatus: published\nvisible: true\ncustom_fields:\n  location: '35.5, 26.4'\n  gallery: ['%s']\nblocks:\n  - type: gallery\n    source: entry\n    heading: 'All the pictures'\n  - type: gallery\n    images:\n      - image: '%s'\n        caption: 'Typed'\n---\nText.\n" % (THREE, ONE))
html = anon.get('/en/routes/blocks')[2]
blocks = html[html.index('All the pictures'):] if 'All the pictures' in html else ''
check('a Gallery block in the entry\'s blocks shows the entry\'s gallery, and a typed one its own', re.findall(r'<a class="gallery-link" href="([^"]+)"', blocks) == [THREE, ONE] and 'Typed' in blocks, re.findall(r'<a class="gallery-link" href="([^"]+)"', html))
write('app/content/pages/pictures.md', "---\ntitle: 'Pictures'\nstatus: published\nvisible: true\nblocks:\n  - type: gallery\n    source: entry\n  - type: text\n    body: 'Still here'\n---\n")
st, _, html = anon.get('/pictures')
check('on a page with no gallery the block draws nothing, the rest is there', st == 200 and 'block-gallery' not in html and 'Still here' in html, st)

print('\nALL PASSED' if not fails else '\n%d FAILED' % len(fails))
sys.exit(1 if fails else 0)
