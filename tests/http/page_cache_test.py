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
def page_cache(c, on):
    return c.submit('/admin/system', lambda f: any(x[0] == 'system_action' and x[1] == 'page_cache' for x in f['fields']), {'page_cache': '1' if on else '0'})

root = Client(); root.login()
anon = Client()

# ---- off by default: nothing is marked, nothing is kept
st, mark, hdr, body = status(anon, '/')
check('it is off until it is turned on: the page is made each time and says nothing', st == 200 and mark is None, (st, mark))
st, _, html = root.get('/admin/system')
check('the System screen offers it, off', 'The site&#039;s own copy' in html or "The site's own copy" in html, html[:200])

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
check('a page of a language and a sitemap are kept too', [status(anon, p)[1] for p in ('/en', '/sitemap.xml')] == ['miss', 'miss'] and [status(anon, p)[1] for p in ('/en', '/sitemap.xml')] == ['hit', 'hit'])
st, mark, _, _ = status(anon, '/?x=1')
check('a query is made each time, not kept', st == 200 and mark is None, (st, mark))
st, mark, _, _ = status(anon, '/no-such-page-here')
st2, mark2, _, _ = status(anon, '/no-such-page-here')
check('a page that is not found is not kept', st == 404 and st2 == 404 and mark2 != 'hit', (st, mark, st2, mark2))
st, mark, _, _ = status(root, '/')
check('someone signed in is never given a copy (they may see drafts)', st == 200 and mark is None, (st, mark))
st, _, html = root.get('/admin/system')
check('the System screen says how many pages are kept', re.search(r'\d+ pages? kept', html) is not None, html[:200])

# ---- whatever is saved in the admin empties it
root.submit('/admin/settings', is_settings, {'title': 'A new name for the cache test'})
st, mark, _, body = status(anon, '/')
check('after a change saved in the admin the visitor gets the page as it is now', st == 200 and mark == 'miss' and 'A new name for the cache test' in body, (st, mark))
st, mark, _, body2 = status(anon, '/')
check('and that page is the copy from then on', mark == 'hit' and body2 == body, mark)
st, mark, _, _ = status(anon, '/en')
check('every page was emptied, not only the one that was seen', mark == 'miss', mark)

# ---- the button empties it by hand
status(anon, '/'); 
root.submit('/admin/system', lambda f: any(x[0] == 'system_action' and x[1] == 'purge_cache' for x in f['fields']))
st, mark, _, _ = status(anon, '/')
check('the button empties it', mark == 'miss', mark)

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
