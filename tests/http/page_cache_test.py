import re, sys
sys.path.insert(0, '.')
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

def is_settings(f): return any(x[0] == 'title' for x in f['fields']) and any(x[0] == 'date_format' for x in f['fields'])
def status(c, path):
    st, hdr, body = c.get(path)
    return st, hdr.get('X-Page-Cache'), hdr, body
CACHE = '/admin/settings?tab=cache'
def page_cache(c, on):
    # The switch is a checkbox of the Settings form (Settings > Cache): left out when it is not ticked.
    return c.submit(CACHE, is_settings, {'page_cache': '1' if on else None})
def clear_cache(c):
    return c.submit(CACHE, lambda f: any(x[0] == 'cache_action' and x[1] == 'clear' for x in f['fields']))

root = Client(); root.login()
anon = Client()

# ---- off by default: nothing is marked, nothing is kept
st, mark, hdr, body = status(anon, '/')
check('it is off until it is turned on: the page is made each time and says nothing', st == 200 and mark is None, (st, mark))
st, _, html = root.get(CACHE)
check('Settings has a Cache section that offers it, off, with its directions', st == 200 and 'data-panel="cache"' in html and 'Built-in page cache' in html and 'name="page_cache"' in html and 'Clear cache now' in html and 'How to set it up' in html, (st, html[:200]))
check('the switch is not ticked', re.search(r'name="page_cache" value="1"\s+checked', html) is None)
st, _, html = root.get('/admin/system')
check('System points to Settings > Cache', 'admin/settings?tab=cache' in html and 'name="page_cache"' not in html, html[:200])

# ---- the address of a Varnish: refused when it is not one, kept when it is, and Clear cache now says it did not answer
st, hdr, _ = root.submit(CACHE, is_settings, {'cache_address': 'not an address://x'})
check('a Varnish address that is not host:port is refused, back on the Cache section', 'tab=cache' in (hdr.get('Location') or '') and 'settings_error' in (hdr.get('Location') or ''), hdr.get('Location'))
root.submit(CACHE, is_settings, {'cache_address': '127.0.0.1:9'})
st, _, html = root.get(CACHE)
check('a Varnish address is kept and shown', 'value="127.0.0.1:9"' in html and 'At 127.0.0.1:9' in html, html[:200])
st, hdr, _ = clear_cache(root)
check('Clear cache now says the Varnish did not answer', 'cache=purge_failed' in (hdr.get('Location') or ''), hdr.get('Location'))
root.submit(CACHE, is_settings, {'cache_address': ''})
st, _, html = root.get(CACHE)
check('and the address can be removed', 'None set' in html, html[:200])

# ---- it needs the address of the site (the host it answers on)
page_cache(root, True)
st, mark, hdr, body = status(anon, '/')
check('with no address of the site set, nothing is kept even when it is on', st == 200 and mark is None, (st, mark))
root.submit('/admin/settings', is_settings, {'base_url': BASE})

# ---- on: the first visit makes the page, the next ones are the copy
st, mark, hdr, first = status(anon, '/')
check('the first visit makes the page and keeps it', st == 200 and mark == 'miss', (st, mark))
st, mark, hdr2, second = status(anon, '/')
check('the next one is the copy', st == 200 and mark == 'hit', (st, mark))
check('the same page, with the same headers that matter', second == first and hdr2.get('Content-Type') == hdr.get('Content-Type') and hdr2.get('Cache-Control') == hdr.get('Cache-Control') and hdr2.get('X-Frame-Options') == hdr.get('X-Frame-Options'), (hdr2.get('Content-Type'), hdr2.get('Cache-Control')))
check('with no cookie', hdr2.get('Set-Cookie') is None)
check('the made page says how long it took, the copy says it is the copy', (hdr.get('Server-Timing') or '').startswith('app;') and 'dur=' in (hdr.get('Server-Timing') or '') and 'page cache hit' in (hdr2.get('Server-Timing') or ''), (hdr.get('Server-Timing'), hdr2.get('Server-Timing')))
check('both name the stylesheet to fetch early', 'rel=preload; as=style' in (hdr.get('Link') or '') and hdr2.get('Link') == hdr.get('Link'), (hdr.get('Link'), hdr2.get('Link')))
st, mark, hdr3, third = status(anon, '/')
check('once nothing is due, the copy is sent before the application boots (the same page)', mark == 'hit' and 'before the application' in (hdr3.get('Server-Timing') or '') and third == second, hdr3.get('Server-Timing'))
check('a page of a language and a sitemap are kept too', [status(anon, p)[1] for p in ('/en', '/sitemap.xml')] == ['miss', 'miss'] and [status(anon, p)[1] for p in ('/en', '/sitemap.xml')] == ['hit', 'hit'])
st, mark, _, _ = status(anon, '/?x=1')
check('a query is made each time, not kept', st == 200 and mark is None, (st, mark))
st, mark, _, _ = status(anon, '/no-such-page-here')
st2, mark2, _, _ = status(anon, '/no-such-page-here')
check('a page that is not found is not kept', st == 404 and st2 == 404 and mark2 != 'hit', (st, mark, st2, mark2))
st, mark, _, _ = status(root, '/')
check('someone signed in is never given a copy (they may see drafts)', st == 200 and mark is None, (st, mark))
st, _, html = root.get(CACHE)
check('the Cache section says how many pages are kept, and the switch is ticked', re.search(r'\d+ pages? kept', html) is not None and re.search(r'name="page_cache" value="1"\s+checked', html) is not None, html[:200])

# ---- whatever is saved in the admin empties it
root.submit('/admin/settings', is_settings, {'title': 'A new name for the cache test'})
st, mark, _, body = status(anon, '/')
check('after a change saved in the admin the visitor gets the page as it is now', st == 200 and mark == 'miss' and 'A new name for the cache test' in body, (st, mark))
st, mark, _, body2 = status(anon, '/')
check('and that page is the copy from then on', mark == 'hit' and body2 == body, mark)
st, mark, _, _ = status(anon, '/en')
check('every page was emptied, not only the one that was seen', mark == 'miss', mark)
st, mark, hdr4, body4 = status(anon, '/')
check('the first page made after the change opens the front door again, with the page as it is now', mark == 'hit' and 'before the application' in (hdr4.get('Server-Timing') or '') and 'A new name for the cache test' in body4, hdr4.get('Server-Timing'))

# ---- a draft that stays a draft changes nothing a visitor sees; publishing it does
def save_page(slug, fields):
    st, hdr, _ = root.submit('/admin/edit?type=pages&slug=%s&lang=el' % slug, has_field('body'), fields, drop=['visible'])
    return st, hdr.get('Location') or ''
status(anon, '/'); status(anon, '/')
st, loc = save_page('', {'title': 'Cache test draft', 'status': 'draft', 'body': 'Hidden for now'})
m = re.search(r'slug=([^&]+)', loc); draft = m.group(1) if m else ''
st, mark, _, _ = status(anon, '/')
check('a new draft leaves the kept pages alone', draft != '' and mark == 'hit', (st, loc, mark))
save_page(draft, {'title': 'Cache test draft', 'status': 'draft', 'body': 'Still hidden'})
check('and so does saving it again as a draft', status(anon, '/')[1] == 'hit')
save_page(draft, {'title': 'Cache test draft', 'status': 'published', 'body': 'Out now'})
check('publishing it empties them', status(anon, '/')[1] == 'miss')
save_page(draft, {'title': 'Cache test draft', 'status': 'draft', 'body': 'Back to hidden'})
check('and so does taking it back to a draft', status(anon, '/')[1] == 'miss')

# ---- the button empties it by hand
status(anon, '/'); 
st, hdr, _ = clear_cache(root)
st, mark, _, _ = status(anon, '/')
check('Clear cache now empties it', mark == 'miss' and 'cache=purged' in (hdr.get('Location') or ''), (mark, hdr.get('Location')))

# ---- off again
page_cache(root, False)
st, mark, _, _ = status(anon, '/')
st2, mark2, _, _ = status(anon, '/')
check('turned off, the page is made each time again', mark is None and mark2 is None, (mark, mark2))
page_cache(root, True)
st, mark, _, _ = status(anon, '/')
check('and turned on again nothing from before comes back', mark == 'miss', mark)
page_cache(root, False)

print('\nALL PASSED' if not fails else '\n%d FAILED' % len(fails))
sys.exit(1 if fails else 0)
