"""
The "Add as a preferred source on Google" button (Admin > SEO > Social) over HTTP: off by default; the card after a post, the chip
above its title and the link in the footer as the Social tab chooses; only on the chosen content types; nothing without the site's
own domain; and the Preferred source block, which shows whether the setting is on or off.
"""
import os, re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

def write(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    open(path, 'w', encoding='utf-8').write(text)

def is_settings(f): return any(x[0] == 'title' for x in f['fields']) and any(x[0] == 'date_format' for x in f['fields'])
def save_social(fields):
    # The form's action carries the site's address, which is not this test server once it is set: post to the path itself.
    tok = re.search(r'name="_csrf" value="([0-9a-f]+)"', root.get('/admin/seo?tab=social')[2]).group(1)
    data = [('_csrf', tok), ('twitter_card', 'auto')] + [(k, v) for k, v in fields]
    return root.request('/admin/seo?tab=social', data=data)

root = Client(); root.login()
pub = Client()
GOOGLE = 'https://www.google.com/preferences/source?q=mag.example.org'

write('app/content/posts/lead-story.md', "---\ntitle: Lead story\nstatus: published\nvisible: true\ndate: '2026-03-01'\n---\n\nThe story.\n")
write('app/content/pages/follow-us.md', """---
title: Follow us
status: published
blocks:
  - { type: preferred-source, heading: 'Keep us in your Top Stories', align: center }
  - { type: preferred-source, variant: button }
---
""")

def page(path):
    st, _, html = pub.get(path)
    return st, html

# ---- off, and without a domain
st, html = page('/lead-story')
check('a post opens', st == 200, st)
check('by default there is no button anywhere', 'google.com/preferences/source' not in html)
st, html = page('/follow-us')
check('the block draws nothing for visitors while the site has no domain', st == 200 and 'preferred-' not in html.split('<footer', 1)[0], re.findall(r'preferred-[a-z]+', html)[:4])
st, _, html = root.get('/admin/seo?tab=social')
check('the Social tab has the card, and says the site needs its own domain', st == 200 and 'Preferred source on Google' in html and 'name="preferred_enabled"' in html and 'needs the site' in html, st)

root.submit('/admin/settings', is_settings, {'base_url': 'https://www.mag.example.org'})
st, _, html = root.get('/admin/seo?tab=social')
check('with a domain it names the address readers go to', 'google.com/preferences/source?q=mag.example.org' in html and 'needs the site' not in html)
st, html = page('/follow-us')
check('the block shows the card with its own line, and the button alone, while the setting is off', html.count('href="' + GOOGLE + '"') == 2 and 'Keep us in your Top Stories' in html and 'preferred-card is-center' in html and 'preferred-wrap' in html, html.count(GOOGLE))
check('a plain link that opens a new tab, with no script of Google\'s', 'target="_blank" rel="noopener"' in html and 'news.google.com' not in html and 'gstatic' not in html)

# ---- on: the defaults are after the article and the footer, for posts
save_social([('preferred_enabled', '1'), ('preferred_after', '1'), ('preferred_footer', '1'), ('preferred_types[]', 'posts')])
st, html = page('/lead-story')
body, _, footer = html.partition('<footer')
check('a post has the card after the article', 'preferred-after' in body and 'preferred-card' in body and GOOGLE in body, re.findall(r'preferred-[a-z]+', body)[:4])
check('not the chip above the title', 'preferred-chip' not in html)
check('the footer has the link', 'preferred-link' in footer and GOOGLE in footer)
st, html = page('/follow-us')
check('a page has the footer link but no card of its own after the text', 'preferred-after' not in html and 'preferred-link' in html.partition('<footer')[2])
st, html = page('/lead-story')
check('in the language of the page', 'Προσθήκη ως προτιμώμενη πηγή στο Google' in html, re.findall(r'preferred-link"[^>]*>.{0,400}', html)[:1])

# ---- the chip above the title, and the types
save_social([('preferred_enabled', '1'), ('preferred_header', '1'), ('preferred_types[]', 'pages')])
st, html = page('/lead-story')
check('a post is not a chosen type any more: no card, no chip, and the footer is off', 'google.com/preferences/source' not in html, re.findall(r'preferred-[a-z]+', html)[:4])
save_social([('preferred_enabled', '1'), ('preferred_header', '1'), ('preferred_types[]', 'posts')])
st, html = page('/lead-story')
check('the chip is in the line above the title', 'byline-preferred' in html and 'preferred-chip' in html and 'preferred-after' not in html, re.findall(r'preferred-[a-z]+', html)[:4])

# ---- off again
save_social([])
st, html = page('/lead-story')
check('turned off, the post has no button', 'google.com/preferences/source' not in html)
st, html = page('/follow-us')
check('but the block still has it', GOOGLE in html)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
