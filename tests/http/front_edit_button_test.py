"""The Edit button on public pages: a signed-in editor gets a round button at the bottom left that opens the entry shown in the
editor; visitors, accounts that may not edit content, the editor's own preview and pages that are not an entry get none; a page
with the button is never kept by a cache."""
import re, sys, json
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:200]))
    if not ok: fails.append(label)

def put(path, front, body='Text.'):
    open('app/content/' + path, 'w', encoding='utf-8').write('---\n' + front + '---\n\n' + body + '\n')

root = Client(); root.login()
put('posts/edit-button-post.en.md', 'title: "Button post"\nstatus: published\ndate: "2026-11-19"\n')
anon = Client()

st, hdr, html = root.get('/en/edit-button-post')
if st != 200:
    st, hdr, html = root.get('/edit-button-post')
button = re.search(r'<a href="([^"]*)" class="gz-edit-entry" data-gz-edit-entry[^>]*>', html)
check('a signed-in editor sees the Edit button on a post', st == 200 and button is not None, st)
check('it opens that post in the editor', button is not None and button.group(1) == '/admin/edit?type=posts&amp;slug=edit-button-post&amp;lang=en', button and button.group(1))
check('it is named for screen readers with the title', button is not None and 'aria-label="Edit “Button post”"' in button.group(0))
check('it sits just before </body>, once', html.count('data-gz-edit-entry') == 1 and html.rfind('data-gz-edit-entry') < html.rfind('</body>') and html.rfind('</body>') - html.rfind('data-gz-edit-entry') < 3000)
check('and that page is not kept by any cache', 'no-store' in (hdr.get('Cache-Control') or ''), hdr.get('Cache-Control'))

st, _, html = root.get('/')
check('the home page has one for its page', st == 200 and re.search(r'href="/admin/edit\?type=pages&amp;slug=[^"&]+&amp;lang=[a-z]+" class="gz-edit-entry"', html) is not None, st)

path = '/en/edit-button-post' if anon.get('/en/edit-button-post')[0] == 200 else '/edit-button-post'
st, _, html = anon.get(path)
check('a visitor never sees it', st == 200 and 'gz-edit-entry' not in html, st)

st, _, html = root.get('/search?q=button')
check('a page that is not an entry (search) has none', 'data-gz-edit-entry' not in html, st)

st, hdr, _ = root.submit('/admin/users-edit', has_field('username'),
    {'username': 'reader1', 'email': 'reader1@example.test', 'display_name': 'Reader', 'role': 'user', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
reader = Client(); reader.login('reader1', 'Sturdy-pass-99')
st, _, html = reader.get(path)
check('an account that may not edit content gets none', st == 200 and 'data-gz-edit-entry' not in html, st)

# The editor's preview frame draws the page too; it must not carry a button to the editor it is in.
edit = '/admin/edit?type=posts&slug=edit-button-post&lang=en'
form = next(f for f in root.forms(edit) if f['attrs'].get('id') == 'entry-form' or any(x[0] == 'blocks_json' for x in f['fields']))
st, _, text = root.request('/admin/edit?preview=entry', data=list({k: v for k, v in form['fields']}.items()))
try:
    preview_html = json.loads(text).get('html', text)
except ValueError:
    preview_html = text
check('the editor\'s preview has none', st == 200 and 'data-gz-edit-entry' not in preview_html, st)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
