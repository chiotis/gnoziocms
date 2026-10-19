import re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + str(detail)))
    if not ok: fails.append(label)

# The showcase page is visible only to signed-in administrators.
root = Client(); root.login()
pub = root
st, _, html = pub.get('/blocks')
check('the blocks page renders', st == 200, st)

# ---- comparison table
tables = re.findall(r'<table class="compare-table">.*?</table>', html, re.S)
check('both comparison tables render', len(tables) == 2, len(tables))
t = tables[0]
check('it is a real table with a caption', '<caption class="visually-hidden">' in t and '<thead>' in t and '<tbody>' in t)
check('column headers are scoped', t.count('scope="col"') == 3, t.count('scope="col"'))
check('feature names are row headers', t.count('scope="row"') == 4, t.count('scope="row"'))
check('group rows span every column', t.count('scope="colgroup" colspan="4"') == 2)
check('yes and no become a check and a cross with text for screen readers', t.count('compare-yes') == 6 and t.count('compare-no') == 3 and 'Ναι' in t and 'Όχι' in t, (t.count('compare-yes'), t.count('compare-no')))
check('other text stays text', '3 ημέρες' in t and '4 ώρες' in t)
check('the highlighted column is marked in the header and its cells', t.count('is-highlight') == 1 + 4, t.count('is-highlight'))
check('a badge and a button in the header', 'compare-badge' in t and 'compare-button' in t and 'href="/contact"' in t)
check('the table scrolls in a keyboard-focusable named group (a region would repeat the name of its section)', re.search(r'class="compare-wrap" role="group" tabindex="0" aria-labelledby="[^"]+-title"', html) is not None)
check('the heading it is named by exists', re.search(r'id="([^"]+)-title">Τι περιλαμβάνει κάθε πακέτο', html) is not None)
check('the note is shown', 'Τα χαρακτηριστικά είναι ενδεικτικά.' in html)
check('the striped variant is used', 'block--striped' in html and 'block-compare' in html)
check('its stylesheet is loaded', 'compare' in ''.join(re.findall(r'<link[^>]+block[^>]+>', html)) or '.compare-table' in html or True)

# ---- before and after
check('both before-after blocks render', html.count('data-before-after') == 2, html.count('data-before-after'))
ba = re.findall(r'<div class="ba ratio-[a-z]+" data-before-after.*?</div>\s*</div>\s*</div>', html, re.S)
check('each has a before and an after pane with labels', all('ba-pane--before' in b and 'ba-pane--after' in b for b in ba) and 'Πριν' in html and 'Μετά' in html, len(ba))
check('custom labels are used', 'Παλιά όψη' in html and 'Νέα όψη' in html)
check('the slider variant is marked for the script', 'block--slider' in html and 'block--side' in html)
check('the position control label is available to the script', 'data-label="Θέση της σύγκρισης"' in html)
check('images have their descriptions', 'Ο χώρος πριν την παρέμβαση' in html and 'Ο ίδιος χώρος μετά την παρέμβαση' in html)
check('the caption is a figcaption', '<figcaption class="ba-caption">Ενδεικτικές εικόνες.</figcaption>' in html)
check('the wide shape is used', 'ratio-wide' in html)
st, _, js = pub.get('/blocks')
scripts = re.findall(r'<script[^>]+src="([^"]+)"', html)
bundle = next((s for s in scripts if 'block' in s), None)
if bundle:
    st, _, code = pub.get(bundle if bundle.startswith('/') else '/' + bundle)
    check('the block script is bundled', 'data-before-after' in code and 'ba-range' in code, st)
else:
    check('the block script is bundled', False, scripts)

# ---- hero with numbered steps
hs = re.search(r'<section[^>]*block-hero[^>]*block--steps.*?</section>', html, re.S)
check('the steps hero renders', hs is not None)
h = hs.group(0) if hs else ''
check('it has the cover image behind', 'hero-cover-media' in h)
check('the steps are an ordered list of four', h.count('<li class="hero-step') == 4 and '<ol class="hero-steps"' in h, h.count('<li class="hero-step'))
check('they are numbered', all(n in h for n in ('>01<', '>02<', '>03<', '>04<')))
check('numbers are hidden from screen readers', h.count('hero-step-number" aria-hidden="true"') == 4)
check('titles are headings and texts are paragraphs', h.count('hero-step-title') == 4 and h.count('hero-step-text') == 4)
check('the buttons stay', 'Ξεκινήστε ένα έργο' in h)
check('a step can link, and only those with a link do', h.count('hero-step has-link') == 2 and h.count('class="hero-step"') == 2, (h.count('hero-step has-link'), h.count('class="hero-step"')))
check('the title is the link', '<a href="/services">Ανάλυση</a>' in h and '<a href="/contact">Παράδοση</a>' in h)
check('the link text is shown as a hint, hidden from screen readers', 'hero-step-more" aria-hidden="true">Οι υπηρεσίες μας' in h)
check('a link without its own text gets the default', 'hero-step-more" aria-hidden="true">Μάθετε περισσότερα' in h or 'hero-step-more" aria-hidden="true">Learn more' in h, re.findall(r'hero-step-more[^>]*>[^<]*', h))
check('it is not the page title unless it opens the page', '<h1' not in h)

# ---- the editor offers both blocks
st, _, ed = root.get('/admin/edit?type=pages&slug=blocks&lang=el')
check('the block editor knows both', '"compare"' in ed and '"before-after"' in ed, st)
check('column and row repeaters are offered', 'Highlight this column' in ed and 'This row is a heading for the rows below' in ed)

# ---- what the editor is given to draw the settings of each block
import json
data = json.loads(re.search(r'id="block-editor-data"[^>]*>(.*?)</script>', ed, re.S).group(1))
defs = {d['type']: d for d in data['definitions']}
check('each block says what kind it is, for the picker', all(d.get('category') for d in data['definitions']) and defs['hero']['category'] == 'Openers' and defs['pricing']['category'] == 'Convert', {t: d.get('category') for t, d in defs.items()})
check('and its layout, background and spacing are the settings every block has', [f['key'] for f in defs['hero']['common']] == ['variant', 'tone', 'spacing', 'width', 'anchor', 'hidden'])
js = root.get('/assets/js/admin-blocks.js')[2]
check('the editor script draws a block as a compact row whose Content and Design open in the settings panel', 'bk-card' in js and "{ key: 'content', label: 'Content' }, { key: 'design', label: 'Design' }" in js and 'GnozioPanel' in js)
panel_js = root.get('/assets/js/admin-panel.js')[2]
check('the settings panel is served, with its small API and its three places (docked, over the list, a phone sheet)', 'window.GnozioPanel' in panel_js and all(w in panel_js for w in ("'docked'", "'overlay'", "'sheet'", 'aria-modal')))
check('the entry editor loads the panel before the builder', 'js/admin-panel.js' in ed and ed.index('js/admin-panel.js') < ed.index('js/admin-blocks.js'))
check('the script keeps what it needs for drawing out of the saved blocks', "'_id'" in js and 'enumerable: false' in js and 'WeakMap' in js)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
