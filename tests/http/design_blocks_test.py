"""
The design blocks of docs/shop-plan.md (phase 2), for every site, over HTTP: the Divider's band of colours (its own colours or a
taxonomy's tones), the Quote's Statement with its second line and dots, the Hero's Mosaic, Text and image's Story, Features' Strip,
Term tiles (tiles and the list with a changing picture), the Newsletter with a form (none without one) and Image hotspots (points that
are buttons, and their list). A collection's page takes its tones; built with blocks it opens with the Chapter title (number, name,
quote), offers its filters as chips and leads to the next collection; and the header's band of colours.
"""
import json, os, re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

def write(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    open(path, 'w', encoding='utf-8').write(text)

root = Client(); root.login()
pub = Client()

write('app/content/taxonomies/collections.yaml', """title: Collections
terms:
  - { id: apella, slug: apella, labels: { el: Apella, en: Apella }, number: 1, tones: { bg: '#e7e9de', surface: '#cdd1bc', deep: '#5c6a4c' } }
  - { id: balos, slug: balos, labels: { el: Balos, en: Balos }, number: 2, image: /uploads/balos.jpg, tones: { bg: '#e3ecea', surface: '#c3d6d3', deep: '#3d6b6d' }, quote: { el: 'Η λιμνοθάλασσα.', en: 'The lagoon.' }, quote_author: Maria Patera }
  - { id: kampos, slug: kampos, labels: { el: Kampos, en: Kampos }, number: 3, tones: { bg: '#f3e5dc', surface: '#e4c7b5', deep: '#96583f' } }
""")
write('app/content/posts/lagoon-notes.md', """---
title: Lagoon notes
status: published
date: '2026-03-01'
collections: [balos]
categories: [news]
---

Notes.
""")
write('app/content/pages/design.md', """---
title: Design
status: published
blocks:
  - { type: divider, variant: thread, thread_from: terms, thread_taxonomy: collections }
  - { type: divider, variant: thread, colour_1: '#112233', colour_2: '#445566' }
  - { type: quote, variant: statement, quote: 'One, but a lion.', translation: 'Ένα, αλλά λιοντάρι.', dots: true }
  - { type: hero, variant: mosaic, heading: 'One piece. One lion.', image: /uploads/a.jpg, image_2: /uploads/b.jpg, image_3: /uploads/c.jpg }
  - { type: text-image, variant: story, eyebrow: 'The myth', dots: true, heading: 'Maria', pull_quote: 'I make each piece like a lion.', body: 'Three generations.', image: /uploads/maria.jpg }
  - type: features
    variant: strip
    columns: '4'
    items:
      - { title: Handmade in Greece, text: Every piece by hand. }
      - { title: Shipping, text: Worldwide. }
  - { type: term-tiles, taxonomy: collections, heading: 'Five coasts' }
  - { type: term-tiles, variant: list, taxonomy: collections }
  - { type: newsletter, heading: 'Letters from the Aegean', form: contact, dots: true }
  - { type: newsletter, heading: 'No form, no block' }
  - type: image-hotspots
    image: /uploads/look.jpg
    points:
      - { x: 30, 'y': 40, title: Balos Earrings, text: '€145', link: '/about' }
      - { x: 60, 'y': 70, title: Lampi Ring }
---
""")

st, _, page = pub.get('/design')
check('the page opens', st == 200, st)
check('a band of colours from the collections, in their order', re.search(r'divider-thread[^>]*>\s*<span style="background: #5c6a4c"></span><span style="background: #3d6b6d"></span><span style="background: #96583f"></span>', page) is not None, re.findall(r'divider-thread.{0,200}', page)[:1])
check('and one of its own colours', '<span style="background: #112233"></span><span style="background: #445566"></span>' in page)
check('the Statement: the words, the second line, the dots', 'block--statement' in page and 'One, but a lion.' in page and 'class="quote-second">Ένα, αλλά λιοντάρι.' in page and 'quote-dots' in page)
check('the Mosaic: the Split\'s styles and three pictures', 'block--mosaic block--split' in page and 'hero-mosaic has-3' in page and page.count('hero-mosaic-more') == 2, re.findall(r'class="section block[^"]*block-hero[^"]*"', page))
check('the Story: the quote and the eyebrow\'s dots', 'block--story' in page and 'class="ti-pull">I make each piece like a lion.' in page and 'eyebrow-dots' in page)
check('the Strip: a dot before each title', 'block--strip' in page and page.count('class="feature-dot"') == 2, page.count('class="feature-dot"'))
check('Term tiles: the collections, numbered, with their tones and addresses', 'term-tile-name">Balos' in page and '>02<' in page and '/collections/balos' in page and '--c-deep:#3d6b6d' in page)
check('the list with a changing picture', 'data-term-list' in page and len(re.findall(r'<div class="term-list-picture[ "]', page)) == 3, len(re.findall(r'<div class="term-list-picture[ "]', page)))
check('the Newsletter draws its form; one without a form is not drawn', 'Letters from the Aegean' in page and 'newsletter-form' in page and '<form' in page.split('Letters from the Aegean', 1)[1][:4000] and 'No form, no block' not in page)
check('Image hotspots: points that are buttons, closed, with the list', page.count('class="hotspot-dot"') == 2 and 'aria-expanded="false"' in page and 'left: 30%; top: 40%' in page and 'hotspots-list' in page and 'href="/about"' in page)
check('the scripts of the new blocks are loaded', re.search(r'_blocks/[0-9a-f]+\.js\?b=[^"]*image-hotspots', page) is not None and re.search(r'_blocks/[0-9a-f]+\.js\?b=[^"]*term-tiles', page) is not None, re.findall(r'_blocks/[^"]+\.js[^"]*', page))
check('an ordinary page takes no tones', 'has-tones' not in page)

# ---- a collection's page
st, _, term = pub.get('/collections/balos')
check('a collection\'s page takes its tones', st == 200 and re.search(r'<body class="[^"]*has-tones[^"]*" style="--c-bg:#e3ecea;--c-surface:#c3d6d3;--c-deep:#3d6b6d"', term) is not None, re.findall(r'<body[^>]*>', term))

form = next(f for f in root.forms('/admin/theme?tab=archive_layouts') if any(x[0] == 'active_tab' for x in f['fields']))
fields = [tuple(x) for x in form['fields'] if x[0] not in ('archive_template[taxonomy:collections]', 'archive_template[posts]', 'active_tab')] + [('archive_template[taxonomy:collections]', 'built'), ('archive_template[posts]', 'built'), ('active_tab', 'archive_layouts')]
root.request('/admin/theme', data=fields)
def save_archive(key, blocks):
    path = '/admin/theme?archive_template=' + key.replace(':', '%3A') + '&part=page'
    tpl = next(f for f in root.forms(path) if any(x[0] == 'blocks_json' for x in f['fields']))
    data = [tuple(x) for x in tpl['fields'] if x[0] not in ('blocks_json', 'blocks_editor', 'use', 'part')] + [('part', 'page'), ('blocks_editor', '1'), ('blocks_json', json.dumps(blocks)), ('use', '1')]
    return root.request('/admin/theme?archive_template=' + key.replace(':', '%3A'), data=data)
save_archive('taxonomy:collections', [{'type': 'archive-title', 'variant': 'chapter'}, {'type': 'entry-list'}, {'type': 'next-term', 'variant': 'both'}])
save_archive('posts', [{'type': 'archive-title'}, {'type': 'filter-bar', 'variant': 'chips'}, {'type': 'entry-list'}])
st, _, term = pub.get('/collections/balos')
check('the Chapter: picture, number, name, quote and its author', 'chapter-picture' in term and 'Κεφάλαιο 02' in term and 'class="chapter-title">Balos' in term and 'Η λιμνοθάλασσα.' in term and 'Maria Patera' in term, re.findall(r'chapter[^"]*"', term)[:6])
check('the header does not sit over the Chapter', 'is-transparent' not in re.search(r'<header class="site-header[^"]*"', term).group(0))
check('the next and the previous collection', 'rel="next"' in term and 'Kampos →' in term and 'rel="prev"' in term and 'Apella' in term)
st, _, posts = pub.get('/posts')
check('the filters as chips, "All" chosen', 'filter-chips' in posts and re.search(r'class="filter-chip" href="\?#filters"\s*aria-current="true"', posts) is not None, re.findall(r'filter-chip[^>]*>', posts)[:3])
check('a chip filters by its value', re.search(r'href="\?filter%5B[a-z-]+%5D=[a-z-]+#filters"', posts) is not None, re.findall(r'href="\?[^"]*"', posts)[:4])
st, _, chosen = pub.get('/posts?filter%5Bcategories%5D=news')
check('the chosen chip is marked', re.search(r'href="\?filter%5Bcategories%5D=news#filters"\s*aria-current="true"', chosen) is not None, re.findall(r'filter-chip[^>]*>', chosen)[:4])
st, _, last = pub.get('/collections/kampos')
check('after the last, the first again', 'Apella →' in last)
st, _, en = pub.get('/en/collections/balos')
check('in English: Chapter and the English quote', 'Chapter 02' in en and 'The lagoon.' in en)

# ---- the header's band of colours
form = next(f for f in root.forms('/admin/theme?tab=header') if any(x[0] == 'active_tab' for x in f['fields']))
names = [x[0] for x in form['fields']]
field = next((n for n in names if n.endswith('[thread]')), None)
check('the header has the band of colours choice', field is not None, [n for n in names if 'topbar' in n][:3])
if field:
    fields = [tuple(x) for x in form['fields'] if x[0] not in (field, 'active_tab')] + [(field, 'terms'), ('active_tab', 'header')]
    root.request('/admin/theme', data=fields)
    st, _, home = pub.get('/about')
    check('and with it every page opens with the collections\' colours', 'header-thread' in home and '#5c6a4c' in home)

print('ALL PASSED' if not fails else f'{len(fails)} FAILED')
sys.exit(1 if fails else 0)
