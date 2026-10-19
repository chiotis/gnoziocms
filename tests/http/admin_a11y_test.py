"""
Accessibility of the admin, as far as it can be checked without a browser: heading order, names for controls, buttons,
links and table headers, tab bars, and the colours of muted text. (Contrast of everything else, focus, and the popups
are checked with axe-core in a browser; see docs/admin-accessibility.md.)
"""
import re, sys, urllib.request
from html.parser import HTMLParser
sys.path.insert(0, '.')
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:6000]))
    if not ok: fails.append(label)

VOID = {'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'}
NO_NAME_NEEDED = {'hidden', 'submit', 'button', 'image', 'reset'}

class Page(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.stack, self.headings, self.controls, self.label_for, self.unnamed = [], [], [], set(), []
        self.tablists, self.tabs_without_key = 0, 0
        self.skip = 0
        self.texts = []          # one list of text per open element

    def hidden(self):
        return any(('hidden' in a) or a.get('aria-hidden') == 'true' for _, a, _ in self.stack)

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag in ('script', 'style', 'template'):
            self.skip += 1
        if tag in VOID:
            self.void(tag, a)
            return
        self.stack.append((tag, a, []))

    def void(self, tag, a):
        if self.skip or tag != 'input': 
            # an image's alt counts as text for the link or button around it
            if tag == 'img' and self.stack and a.get('alt'):
                self.stack[-1][2].append(a['alt'])
            return
        if a.get('aria-label') and not self.skip:
            for _, _, texts in self.stack:   # the label of a box inside a header or button names that header or button
                texts.append(a['aria-label'])
        if (a.get('type') or 'text').lower() in NO_NAME_NEEDED or self.hidden():
            return
        wrapped = any(t == 'label' for t, _, _ in self.stack)
        self.controls.append(('input', a, wrapped))

    def handle_data(self, data):
        if self.skip or not self.stack or not data.strip():
            return
        for _, _, texts in self.stack:
            texts.append(data.strip())

    def handle_endtag(self, tag):
        if tag in ('script', 'style', 'template'):
            self.skip = max(0, self.skip - 1)
            return
        for i in range(len(self.stack) - 1, -1, -1):
            if self.stack[i][0] == tag:
                t, a, texts = self.stack[i]
                hidden = any(('hidden' in x) or x.get('aria-hidden') == 'true' for _, x, _ in self.stack[:i + 1])
                named = bool(texts) or a.get('aria-label') or a.get('aria-labelledby') or a.get('title')
                if not hidden and not self.skip:
                    if re.fullmatch(r'h[1-6]', t):
                        self.headings.append((int(t[1]), ' '.join(texts)[:40]))
                    if t == 'label' and a.get('for'):
                        self.label_for.add(a['for'])
                    if t in ('select', 'textarea'):
                        wrapped = any(x == 'label' for x, _, _ in self.stack[:i])
                        self.controls.append((t, a, wrapped))
                    if t in ('button', 'th') and not named:
                        self.unnamed.append((t, a.get('class', '')[:50] or a.get('name', '')))
                    if t == 'a' and a.get('href') and not named:
                        self.unnamed.append(('a', a.get('href')[:50]))
                    if a.get('role') == 'tablist':
                        self.tablists += 1
                del self.stack[i:]
                return

def audit(html):
    p = Page(); p.feed(html); return p

def unlabeled(p):
    bad = []
    for tag, a, wrapped in p.controls:
        if wrapped or a.get('aria-label') or a.get('aria-labelledby') or a.get('title') or (a.get('id') and a['id'] in p.label_for):
            continue
        bad.append(f"{tag}[name={a.get('name', '')}]")
    return bad

def heading_problems(p):
    issues, last = [], 0
    if [h for h, _ in p.headings].count(1) != 1:
        issues.append('h1 count = %d' % [h for h, _ in p.headings].count(1))
    for level, text in p.headings:
        if last and level > last + 1:
            issues.append(f'h{last} -> h{level} "{text}"')
        last = level
    return issues

root = Client(); root.login()
PAGES = ['/admin', '/admin/content?type=pages', '/admin/content?type=posts', '/admin/edit?type=pages&slug=index&lang=en', '/admin/edit?type=posts&slug=&lang=en',
         '/admin/edit?type=forms&slug=contact&lang=el', '/admin/forms-new', '/admin/media', '/admin/media?view=thumbs', '/admin/theme', '/admin/theme?template=posts', '/admin/theme?archive_template=posts', '/admin/theme?archive_template=posts&part=card', '/admin/theme?archive_template=taxonomy%3Acategories', '/admin/theme?tab=archive_layouts', '/admin/settings', '/admin/settings?tab=limits',
         '/admin/menus', '/admin/menus-edit?key=main', '/admin/forms', '/admin/taxonomies', '/admin/users', '/admin/users-edit?id=1', '/admin/roles', '/admin/redirects',
         '/admin/translations', '/admin/activity-logs', '/admin/email-logs', '/admin/backups', '/admin/updates', '/admin/omni', '/admin/system', '/admin/content-types',
         '/admin/content-types?type=posts', '/admin/import', '/admin/revisions',
         '/admin/seo', '/admin/seo?tab=search', '/admin/seo?tab=social', '/admin/seo?tab=crawling', '/admin/seo?tab=identity', '/admin/seo?tab=verification', '/admin/analytics', '/admin/analytics?tab=settings']
problems = {'headings': {}, 'controls': {}, 'names': {}, 'tabs': {}}
for path in PAGES:
    st, _, html = root.get(path)
    if st != 200:
        problems['headings'][path] = 'status %s' % st
        continue
    p = audit(html)
    if heading_problems(p): problems['headings'][path] = heading_problems(p)
    if unlabeled(p): problems['controls'][path] = unlabeled(p)
    if p.unnamed: problems['names'][path] = p.unnamed[:4]
    if p.tablists:
        tab_buttons = re.findall(r'<button[^>]*\bdata-tab="([^"]+)"', html)
        panels = set(re.findall(r'data-(?:tab-)?panel="([^"]+)"', html))
        missing = [k for k in tab_buttons if k not in panels]
        if missing: problems['tabs'][path] = missing
check('every admin screen has one h1 and no heading level is skipped', not problems['headings'], problems['headings'])
check('every form control has a name a screen reader can read', not problems['controls'], problems['controls'])
check('every button, link and table header has a name', not problems['names'], problems['names'])
check('every tab of a tab bar has a panel to control', not problems['tabs'], problems['tabs'])
check('the screens were all reachable', len(PAGES) == 44 and all(p not in problems['headings'] or 'status' not in str(problems['headings'][p]) for p in PAGES))

# ---- the parser itself sees what it should (so a pass means something)
bad = audit('<h1>A</h1><h3>B</h3><input name="x"><label>L <input name="y"></label><button></button><th></th><a href="/x"><img alt="Named"></a>')
check('the checker finds a skipped heading, a bare input, an empty button and header', heading_problems(bad) != [] and unlabeled(bad) == ['input[name=x]'] and [u[0] for u in bad.unnamed] == ['button', 'th'], (heading_problems(bad), unlabeled(bad), bad.unnamed))

# ---- the colours of muted text: enough contrast on white and on the page background
def lum(rgb):
    f = lambda c: (c / 255) / 12.92 if c / 255 <= 0.03928 else (((c / 255) + 0.055) / 1.055) ** 2.4
    return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2])
def ratio(a, b):
    la, lb = sorted((lum(a), lum(b)), reverse=True); return (la + 0.05) / (lb + 0.05)
def hexrgb(h): h = h.lstrip('#'); return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))
tw = urllib.request.urlopen(BASE + '/assets/css/admin.build.css').read().decode()
app = urllib.request.urlopen(BASE + '/assets/css/app.css').read().decode()
def tw_color(cls):
    """The colour Tailwind gives a class (rules with equal declarations are grouped: `.a,.b{...}`)."""
    for selectors, body in re.findall(r'([^{}]+)\{([^{}]*)\}', tw):
        if ('.' + cls) in [x.strip() for x in selectors.split(',')]:
            m = re.search(r'(?<![-\w])color:rgb\((\d+) (\d+) (\d+)', body)
            if m: return tuple(int(x) for x in m.groups())
    return None
WHITE, PAGEBG = (255, 255, 255), hexrgb('#f1f5f9')
for cls in ('text-slate-400', 'text-slate-500', 'text-emerald-600', 'text-orange-600'):
    c = tw_color(cls)
    check(f'{cls} reads on white and on the page background (4.5:1)', c is not None and ratio(c, WHITE) >= 4.5 and ratio(c, PAGEBG) >= 4.5, (c, c and round(ratio(c, PAGEBG), 2)))
check('white text on emerald-600 buttons reaches 4.5:1', ratio(WHITE, tw_color('bg-emerald-600') or (0, 0, 0)) >= 4.5 if re.search(r'\.bg-emerald-600\{', tw) else True)
m = re.search(r'\.tab-button\s*\{[^}]*?color:\s*(#[0-9a-f]{6})', app)
check('the colour of a tab reads on the page background', m is not None and ratio(hexrgb(m.group(1)), PAGEBG) >= 4.5, m and m.group(1))
DARK = hexrgb('#0f172a')
for cls in ('text-slate-400', 'text-slate-500'):
    m = re.search(r'\.dark \.' + cls + r'\s*\{\s*color:\s*(#[0-9a-f]{6})', app)
    check(f'{cls} in dark mode reads on the dark panel', m is not None and ratio(hexrgb(m.group(1)), DARK) >= 4.5, m and m.group(1))

js_early = urllib.request.urlopen(BASE + '/assets/js/admin.js').read().decode()
# ---- the colour mode is applied before the first paint, so a page does not show light and then dark
def before_paint(html):
    mode, sheet = html.find("localStorage.getItem('admin.theme')"), html.find('admin.build.css')
    return 0 <= mode < sheet
for label, html in (('an admin page', root.get('/admin')[2]), ('the sign in page', Client().get('/admin/login')[2])):
    check(f'{label} sets the colour mode in the head, before the style sheets', before_paint(html), html[:300])
check('the script that stores the choice reads and writes the same key and default', "localStorage.getItem('admin.theme') || 'light'" in js_early and "localStorage.getItem('admin.theme') || 'light'" in root.get('/admin')[2])

# ---- the scripts that give tabs and scroll boxes their semantics are there
js = urllib.request.urlopen(BASE + '/assets/js/admin.js').read().decode()
check('the script gives tab bars their roles, keys, and focus order', 'function enhanceTabs' in js and "'ArrowRight'" in js and "setAttribute('role', 'tab')" in js)
check('and makes wide tables that cannot be reached by keyboard focusable', 'function makeScrollersFocusable' in js)
check('a message in the bar is shown to assistive technology while it is up', "layer.removeAttribute('aria-hidden')" in js and "layer.setAttribute('aria-hidden', 'true')" in js)

if problems['controls'] or problems['names']: print(problems)
print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
